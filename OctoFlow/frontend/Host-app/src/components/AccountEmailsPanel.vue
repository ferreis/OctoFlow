<script setup>
import { storeToRefs } from 'pinia'
import {
  computed,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
  ref,
  watch,
} from 'vue'
import { useI18n } from '../composables/useI18n'
import { useSessionStore } from '../stores/sessionStore'
import { extractHttpMessage } from '../utils/httpErrors'
import GoogleLogin from './GoogleLogin.vue'

const EMAIL_MAX_LENGTH = 254
const GOOGLE_CREDENTIAL_MAX_LENGTH = 4096
const FEEDBACK_MESSAGE_MAX_LENGTH = 280

const props = defineProps({
  request: {
    type: Function,
    default: null,
  },
  apiClient: {
    type: Object,
    default: null,
  },
  currentUser: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['session-updated'])

const sessionStore = useSessionStore()
const { currentUser: sessionCurrentUser } = storeToRefs(sessionStore)
const { translate } = useI18n()

const requestClient = props.request || sessionStore.authRequest
const apiClient = props.apiClient || sessionStore.apiClient
const currentUser = computed(() => props.currentUser || sessionCurrentUser.value)

const isLoading = ref(false)
const googleLinking = ref(false)
const githubLinking = ref(false)
const switchingEmail = ref('')
const feedbackError = ref('')
const feedbackSuccess = ref('')
const initialLoadDone = ref(false)
const runtimeError = ref('')
const linkedEmailCountBeforeUpdate = ref(0)

let componentDisposed = false

function translateWithFallback(messageKey, fallbackMessage, variables = {}) {
  const translatedText = translate(messageKey, variables)
  return translatedText === messageKey ? fallbackMessage : translatedText
}

function translatePanel(messageKey, fallbackMessage, variables = {}) {
  return translateWithFallback(`shared.accountEmailsPanel.${messageKey}`, fallbackMessage, variables)
}

function replaceControlCharactersWithSpaces(rawValue) {
  let sanitizedText = ''
  const inputText = String(rawValue || '')

  for (const currentCharacter of inputText) {
    const characterCode = currentCharacter.charCodeAt(0)
    const isControlCharacter = characterCode < 32 || characterCode === 127
    sanitizedText += isControlCharacter ? ' ' : currentCharacter
  }

  return sanitizedText
}

function sanitizeSingleLineText(rawValue, maxLength = 120) {
  return replaceControlCharactersWithSpaces(rawValue)
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, maxLength)
}

function sanitizeEmailAddress(rawEmail) {
  const normalizedEmail = sanitizeSingleLineText(rawEmail, EMAIL_MAX_LENGTH).toLowerCase()
  if (normalizedEmail === '') {
    return ''
  }

  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail)
    ? normalizedEmail
    : ''
}

function sanitizeProviderKey(rawProvider) {
  const normalizedProvider = sanitizeSingleLineText(rawProvider, 30).toLowerCase()
  if (normalizedProvider === '') {
    return ''
  }

  return /^[a-z0-9_-]+$/.test(normalizedProvider)
    ? normalizedProvider
    : ''
}

function sanitizeGoogleCredential(rawCredential) {
  return replaceControlCharactersWithSpaces(rawCredential)
    .replace(/\s+/g, '')
    .trim()
    .slice(0, GOOGLE_CREDENTIAL_MAX_LENGTH)
}

function sanitizeFeedbackMessage(rawMessage) {
  return sanitizeSingleLineText(rawMessage, FEEDBACK_MESSAGE_MAX_LENGTH)
}

function getSafeRequestClient() {
  if (typeof requestClient === 'function') {
    return requestClient
  }

  throw new Error(
    translatePanel(
      'errors.requestClientUnavailable',
      'Cliente de requisição não disponível para atualizar os e-mails da conta.',
    ),
  )
}

function normalizeLinkedEmails(rawLinkedEmails) {
  if (!Array.isArray(rawLinkedEmails)) {
    return []
  }

  return rawLinkedEmails
    .filter((linkedEmail) => linkedEmail && typeof linkedEmail === 'object')
    .map((linkedEmail) => {
      const email = sanitizeEmailAddress(linkedEmail.email)
      if (email === '') {
        return null
      }

      const providers = Array.isArray(linkedEmail.providers)
        ? linkedEmail.providers
          .map((provider) => sanitizeProviderKey(provider))
          .filter((provider) => provider !== '')
        : []

      return {
        email,
        providers,
        isPrimary: linkedEmail.isPrimary === true,
        isVerified: linkedEmail.isVerified === true,
      }
    })
    .filter(Boolean)
}

const linkedEmails = computed(() => normalizeLinkedEmails(currentUser.value?.linkedEmails))

const googleLinkedEmail = computed(() => {
  const googleEntry = linkedEmails.value.find((linkedEmail) => linkedEmail.providers.includes('google'))
  return googleEntry?.email || ''
})

const defaultEmail = computed(() => {
  const preferredDefaultEmail = sanitizeEmailAddress(currentUser.value?.defaultEmail)
  if (preferredDefaultEmail !== '') {
    return preferredDefaultEmail
  }

  return sanitizeEmailAddress(currentUser.value?.email)
})

const githubTokenConfigured = computed(() => Boolean(currentUser.value?.githubTokenConfigured))
const githubLinked = computed(() => Boolean(currentUser.value?.githubLinked))
const isBusy = computed(() => (
  isLoading.value
  || googleLinking.value
  || githubLinking.value
  || switchingEmail.value !== ''
))

function setFeedbackError(rawMessage) {
  const normalizedMessage = sanitizeFeedbackMessage(rawMessage)
  feedbackError.value = normalizedMessage
}

function clearFeedbackMessages() {
  feedbackError.value = ''
  feedbackSuccess.value = ''
}

function ensureComponentIsActive() {
  return !componentDisposed
}

async function loadLinkedEmails() {
  if (!ensureComponentIsActive()) {
    return
  }

  isLoading.value = true
  feedbackError.value = ''

  try {
    const requestHandler = getSafeRequestClient()
    const { data } = await requestHandler({
      url: '/auth/emails',
      method: 'GET',
    })

    if (!ensureComponentIsActive()) {
      return
    }

    applySessionPayload(data, '')
  } catch (requestError) {
    if (!ensureComponentIsActive()) {
      return
    }

    setFeedbackError(
      extractHttpMessage(
        requestError,
        translatePanel('errors.loadEmailsFailed', 'Não foi possível carregar os e-mails vinculados.'),
      ),
    )
  } finally {
    if (ensureComponentIsActive()) {
      isLoading.value = false
      initialLoadDone.value = true
    }
  }
}

async function handleGoogleCredential(credential) {
  const normalizedCredential = sanitizeGoogleCredential(credential)
  if (normalizedCredential === '') {
    setFeedbackError(
      translatePanel('errors.invalidGoogleCredential', 'Credencial do Google inválida.'),
    )
    return
  }

  googleLinking.value = true
  clearFeedbackMessages()

  try {
    const requestHandler = getSafeRequestClient()
    const { data } = await requestHandler({
      url: '/auth/google/link',
      method: 'POST',
      csrfActionId: 'auth.google.link',
      data: {
        credential: normalizedCredential,
      },
    })

    if (!ensureComponentIsActive()) {
      return
    }

    const successMessage = String(data?.message || '').trim()
    applySessionPayload(
      data,
      successMessage !== ''
        ? successMessage
        : translatePanel('success.googleLinked', 'Conta Google vinculada com sucesso.'),
    )
  } catch (requestError) {
    if (!ensureComponentIsActive()) {
      return
    }

    setFeedbackError(
      extractHttpMessage(
        requestError,
        translatePanel('errors.googleLinkFailed', 'Não foi possível vincular a conta Google.'),
      ),
    )
  } finally {
    if (ensureComponentIsActive()) {
      googleLinking.value = false
    }
  }
}

async function syncGithubEmails() {
  githubLinking.value = true
  clearFeedbackMessages()

  try {
    const requestHandler = getSafeRequestClient()
    const { data } = await requestHandler({
      url: '/github/emails/link',
      method: 'POST',
      csrfActionId: 'github.emails.link',
    })

    if (!ensureComponentIsActive()) {
      return
    }

    const importedCount = Number(data?.importedCount || 0)
    const successMessage = importedCount > 0
      ? translatePanel('success.githubSyncedWithCount', 'GitHub sincronizado: {count} e-mail(s) vinculado(s).', {
        count: importedCount,
      })
      : translatePanel('success.githubSynced', 'E-mails do GitHub sincronizados com sucesso.')

    applySessionPayload(data, successMessage)
  } catch (requestError) {
    if (!ensureComponentIsActive()) {
      return
    }

    setFeedbackError(
      extractHttpMessage(
        requestError,
        translatePanel('errors.githubSyncFailed', 'Não foi possível vincular os e-mails do GitHub.'),
      ),
    )
  } finally {
    if (ensureComponentIsActive()) {
      githubLinking.value = false
    }
  }
}

async function setDefaultEmail(rawEmail) {
  const email = sanitizeEmailAddress(rawEmail)
  if (email === '') {
    setFeedbackError(
      translatePanel('errors.invalidEmailSelection', 'Selecione um e-mail válido para definir como padrão.'),
    )
    return
  }

  switchingEmail.value = email
  clearFeedbackMessages()

  try {
    const requestHandler = getSafeRequestClient()
    const { data } = await requestHandler({
      url: '/auth/emails/default',
      method: 'PATCH',
      csrfActionId: 'auth.emails.default',
      data: { email },
    })

    if (!ensureComponentIsActive()) {
      return
    }

    applySessionPayload(
      data,
      translatePanel('success.defaultEmailUpdated', 'E-mail padrão atualizado para {email}.', {
        email,
      }),
    )
  } catch (requestError) {
    if (!ensureComponentIsActive()) {
      return
    }

    setFeedbackError(
      extractHttpMessage(
        requestError,
        translatePanel('errors.defaultEmailUpdateFailed', 'Não foi possível atualizar o e-mail padrão.'),
      ),
    )
  } finally {
    if (ensureComponentIsActive()) {
      switchingEmail.value = ''
    }
  }
}

function applySessionPayload(payload, successMessage) {
  const user = payload?.user
  if (!user || typeof user !== 'object') {
    return
  }

  const token = sanitizeSingleLineText(payload?.token, GOOGLE_CREDENTIAL_MAX_LENGTH)

  emit('session-updated', {
    user,
    token,
  })

  feedbackError.value = ''
  feedbackSuccess.value = sanitizeFeedbackMessage(successMessage)
  initialLoadDone.value = true
}

function handleGoogleError(message) {
  setFeedbackError(message)
}

function providerLabel(rawProvider) {
  const normalizedProvider = sanitizeProviderKey(rawProvider)

  if (normalizedProvider === 'system') {
    return translatePanel('providers.system', 'Sistema')
  }

  if (normalizedProvider === 'google') {
    return translatePanel('providers.google', 'Google')
  }

  if (normalizedProvider === 'github') {
    return translatePanel('providers.github', 'GitHub')
  }

  if (normalizedProvider === '') {
    return translatePanel('providers.unknown', 'Provedor')
  }

  return normalizedProvider
}

function shouldLoadEmailsForCurrentUser(rawUserId) {
  const normalizedUserId = sanitizeSingleLineText(rawUserId, 120)
  return normalizedUserId !== '' && !initialLoadDone.value && linkedEmails.value.length === 0
}

onBeforeMount(() => {
  componentDisposed = false
  linkedEmailCountBeforeUpdate.value = 0
  runtimeError.value = ''
  clearFeedbackMessages()
})

onMounted(async () => {
  if (linkedEmails.value.length === 0) {
    await loadLinkedEmails()
    return
  }

  initialLoadDone.value = true
})

onBeforeUpdate(() => {
  linkedEmailCountBeforeUpdate.value = linkedEmails.value.length
})

onUpdated(() => {
  if (linkedEmailCountBeforeUpdate.value === 0 && linkedEmails.value.length > 0) {
    initialLoadDone.value = true
  }
})

onActivated(async () => {
  if (!initialLoadDone.value && linkedEmails.value.length === 0) {
    await loadLinkedEmails()
  }
})

onDeactivated(() => {
  feedbackSuccess.value = ''
})

onBeforeUnmount(() => {
  componentDisposed = true
})

onUnmounted(() => {
  clearFeedbackMessages()
  switchingEmail.value = ''
})

onErrorCaptured((capturedError) => {
  runtimeError.value = sanitizeFeedbackMessage(capturedError?.message)
  setFeedbackError(
    runtimeError.value !== ''
      ? runtimeError.value
      : translatePanel('errors.runtimeError', 'Erro inesperado no painel de e-mails vinculados.'),
  )

  return false
})

watch(
  () => currentUser.value?.id,
  async (userId) => {
    if (!shouldLoadEmailsForCurrentUser(userId)) {
      return
    }

    await loadLinkedEmails()
  },
)
</script>

<template>
  <section class="account-emails-panel">
    <div class="account-emails-panel-head">
      <div>
        <p class="section-kicker">{{ translatePanel('kicker', 'Identidade') }}</p>
        <h2>{{ translatePanel('title', 'E-mails vinculados') }}</h2>
      </div>
      <span class="account-emails-summary-chip">
        {{ translatePanel('summaryChip', '{count} e-mail(s)', { count: linkedEmails.length }) }}
      </span>
    </div>

    <p class="account-emails-panel-copy">
      {{ translatePanel('description', 'Defina qual e-mail deve ser o padrão da conta e sincronize os e-mails verificados do Google e GitHub para o mesmo usuário.') }}
    </p>

    <div class="account-emails-default-banner">
      <span>{{ translatePanel('defaultEmail.label', 'E-mail padrão atual') }}</span>
      <strong>{{ defaultEmail || translatePanel('defaultEmail.notDefined', 'Não definido') }}</strong>
    </div>

    <div v-if="isLoading && linkedEmails.length === 0" class="account-emails-loading-state">
      {{ translatePanel('loading', 'Carregando e-mails vinculados...') }}
    </div>

    <div v-else class="account-emails-list">
      <article v-for="linkedEmail in linkedEmails" :key="linkedEmail.email" class="account-emails-row">
        <div class="account-emails-copy">
          <div class="account-emails-title">
            <strong>{{ linkedEmail.email }}</strong>
            <span v-if="linkedEmail.isPrimary" class="account-emails-primary-pill">
              {{ translatePanel('labels.default', 'Padrão') }}
            </span>
          </div>

          <div class="account-emails-badges">
            <span
              v-for="provider in linkedEmail.providers"
              :key="`${linkedEmail.email}-${provider}`"
              class="account-emails-provider-pill"
            >
              {{ providerLabel(provider) }}
            </span>
            <span v-if="linkedEmail.isVerified" class="account-emails-verified-pill">
              {{ translatePanel('labels.verified', 'Verificado') }}
            </span>
          </div>
        </div>

        <button
          class="ghost account-emails-button-small"
          type="button"
          :disabled="isBusy || linkedEmail.isPrimary"
          @click="setDefaultEmail(linkedEmail.email)"
        >
          {{
            switchingEmail === linkedEmail.email
              ? translatePanel('actions.saving', 'Salvando...')
              : linkedEmail.isPrimary
                ? translatePanel('actions.defaultEmail', 'E-mail padrão')
                : translatePanel('actions.setAsDefault', 'Tornar padrão')
          }}
        </button>
      </article>

      <p v-if="linkedEmails.length === 0" class="account-emails-empty-state">
        {{ translatePanel('empty', 'Nenhum e-mail vinculado foi encontrado.') }}
      </p>
    </div>

    <div class="account-emails-link-actions">
      <div class="account-emails-action-card">
        <div class="account-emails-action-copy">
          <strong>{{ translatePanel('providers.google', 'Google') }}</strong>
          <p>{{ translatePanel('google.description', 'Use o fluxo de login social para anexar o e-mail da conta Google ao usuário atual.') }}</p>
        </div>

        <div v-if="googleLinkedEmail" class="account-emails-linked-account-state">
          <strong>{{ translatePanel('google.alreadyLinkedTitle', 'Usuário já vinculado') }}</strong>
          <span>{{ translatePanel('google.alreadyLinkedEmail', 'e-mail: {email}', { email: googleLinkedEmail }) }}</span>
        </div>

        <GoogleLogin
          v-else
          :api-client="apiClient"
          :is-loading="googleLinking || isLoading"
          :show-separator="false"
          :unavailable-message="translatePanel('google.unavailable', 'Google Sign-In não está disponível para vincular contas.')"
          :loading-hint="translatePanel('google.loading', 'Validando conta Google para vínculo...')"
          variant="system"
          :button-label="translatePanel('google.button', 'Vincular conta Google')"
          :button-width="260"
          @credential="handleGoogleCredential"
          @error="handleGoogleError"
        />
      </div>

      <div class="account-emails-action-card">
        <div class="account-emails-action-copy">
          <strong>{{ translatePanel('providers.github', 'GitHub') }}</strong>
          <p>
            {{ githubLinked
              ? translatePanel('github.descriptionResync', 'Sincronize novamente os e-mails verificados da conta GitHub usando o token salvo no perfil.')
              : translatePanel('github.descriptionSync', 'Sincronize os e-mails verificados da conta GitHub usando o token salvo no perfil.') }}
          </p>
        </div>

        <button class="primary" type="button" :disabled="isBusy || !githubTokenConfigured" @click="syncGithubEmails">
          {{ githubLinking
            ? translatePanel('github.syncing', 'Sincronizando...')
            : githubLinked
              ? translatePanel('github.updateButton', 'Atualizar e-mails do GitHub')
              : translatePanel('github.linkButton', 'Vincular e-mails do GitHub') }}
        </button>

        <p v-if="!githubTokenConfigured" class="account-emails-hint">
          {{ translatePanel('github.tokenHint', 'Configure primeiro o token do GitHub no perfil para habilitar a sincronização.') }}
        </p>
      </div>
    </div>

    <p v-if="feedbackError" class="account-emails-feedback account-emails-feedback-error">{{ feedbackError }}</p>
    <p v-if="feedbackSuccess" class="account-emails-feedback account-emails-feedback-success">{{ feedbackSuccess }}</p>
  </section>
</template>
