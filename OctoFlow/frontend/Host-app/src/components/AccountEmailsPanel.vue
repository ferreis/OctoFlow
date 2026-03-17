<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import GoogleLogin from './GoogleLogin.vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  apiClient: {
    type: Object,
    required: true,
  },
  currentUser: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['session-updated'])

const isLoading = ref(false)
const googleLinking = ref(false)
const githubLinking = ref(false)
const switchingEmail = ref('')
const feedbackError = ref('')
const feedbackSuccess = ref('')
const initialLoadDone = ref(false)

const linkedEmails = computed(() => Array.isArray(props.currentUser?.linkedEmails) ? props.currentUser.linkedEmails : [])
const googleLinkedEmail = computed(() => {
  const googleEntry = linkedEmails.value.find((linkedEmail) => Array.isArray(linkedEmail?.providers) && linkedEmail.providers.includes('google'))

  return typeof googleEntry?.email === 'string' ? googleEntry.email.trim() : ''
})
const defaultEmail = computed(() => typeof props.currentUser?.defaultEmail === 'string' && props.currentUser.defaultEmail.trim() !== ''
  ? props.currentUser.defaultEmail.trim()
  : typeof props.currentUser?.email === 'string'
    ? props.currentUser.email.trim()
    : '')
const githubTokenConfigured = computed(() => Boolean(props.currentUser?.githubTokenConfigured))
const githubLinked = computed(() => Boolean(props.currentUser?.githubLinked))
const isBusy = computed(() => isLoading.value || googleLinking.value || githubLinking.value || switchingEmail.value !== '')

onMounted(async () => {
  if (linkedEmails.value.length === 0) {
    await loadLinkedEmails()
    return
  }

  initialLoadDone.value = true
})

watch(
  () => props.currentUser?.id,
  async (userId) => {
    if (!userId || initialLoadDone.value || linkedEmails.value.length > 0) {
      return
    }

    await loadLinkedEmails()
  },
)

async function loadLinkedEmails() {
  isLoading.value = true
  feedbackError.value = ''

  try {
    const { data } = await props.request({
      url: '/auth/emails',
      method: 'GET',
    })

    applySessionPayload(data, '')
  } catch (error) {
    feedbackError.value = extractHttpMessage(error, 'Nao foi possivel carregar os emails vinculados.')
  } finally {
    isLoading.value = false
    initialLoadDone.value = true
  }
}

async function handleGoogleCredential(credential) {
  googleLinking.value = true
  feedbackError.value = ''
  feedbackSuccess.value = ''

  try {
    const { data } = await props.request({
      url: '/auth/google/link',
      method: 'POST',
      csrfActionId: 'auth.google.link',
      data: { credential },
    })

    applySessionPayload(data, data?.message || 'Conta Google vinculada com sucesso.')
  } catch (error) {
    feedbackError.value = extractHttpMessage(error, 'Nao foi possivel vincular a conta Google.')
  } finally {
    googleLinking.value = false
  }
}

async function syncGithubEmails() {
  githubLinking.value = true
  feedbackError.value = ''
  feedbackSuccess.value = ''

  try {
    const { data } = await props.request({
      url: '/github/emails/link',
      method: 'POST',
      csrfActionId: 'github.emails.link',
    })

    const importedCount = Number(data?.importedCount || 0)
    const suffix = importedCount === 1 ? 'email vinculado' : 'emails vinculados'
    applySessionPayload(data, importedCount > 0 ? `GitHub sincronizado: ${importedCount} ${suffix}.` : 'Emails do GitHub sincronizados com sucesso.')
  } catch (error) {
    feedbackError.value = extractHttpMessage(error, 'Nao foi possivel vincular os emails do GitHub.')
  } finally {
    githubLinking.value = false
  }
}

async function setDefaultEmail(email) {
  switchingEmail.value = email
  feedbackError.value = ''
  feedbackSuccess.value = ''

  try {
    const { data } = await props.request({
      url: '/auth/emails/default',
      method: 'PATCH',
      csrfActionId: 'auth.emails.default',
      data: { email },
    })

    applySessionPayload(data, `Email padrao atualizado para ${email}.`)
  } catch (error) {
    feedbackError.value = extractHttpMessage(error, 'Nao foi possivel atualizar o email padrao.')
  } finally {
    switchingEmail.value = ''
  }
}

function applySessionPayload(payload, successMessage) {
  const user = payload?.user
  if (!user || typeof user !== 'object') {
    return
  }

  const token = typeof payload?.token === 'string' && payload.token.trim() !== '' ? payload.token.trim() : ''

  emit('session-updated', {
    user,
    token,
  })
  feedbackError.value = ''
  feedbackSuccess.value = successMessage
  initialLoadDone.value = true
}

function handleGoogleError(message) {
  feedbackError.value = message
}

function providerLabel(provider) {
  switch (provider) {
    case 'system':
      return 'Sistema'
    case 'google':
      return 'Google'
    case 'github':
      return 'GitHub'
    default:
      return provider
  }
}

function extractHttpMessage(error, fallback) {
  if (axios.isAxiosError(error)) {
    const responseMessage = error.response?.data?.message
    if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
      return responseMessage
    }

    return error.message || fallback
  }

  if (error instanceof Error) {
    return error.message
  }

  return fallback
}
</script>

<template>
  <section class="account-emails-panel">
    <div class="panel-head">
      <div>
        <p class="section-kicker">Identidade</p>
        <h2>Emails vinculados</h2>
      </div>
      <span class="summary-chip">{{ linkedEmails.length }} email(s)</span>
    </div>

    <p class="panel-copy">
      Defina qual email deve ser o padrao da conta e puxe os emails verificados do Google e do GitHub para o mesmo usuario.
    </p>

    <div class="default-banner">
      <span>Email padrao atual</span>
      <strong>{{ defaultEmail || 'Nao definido' }}</strong>
    </div>

    <div v-if="isLoading && linkedEmails.length === 0" class="loading-state">
      Carregando emails vinculados...
    </div>

    <div v-else class="email-list">
      <article v-for="linkedEmail in linkedEmails" :key="linkedEmail.email" class="email-row">
        <div class="email-copy">
          <div class="email-title">
            <strong>{{ linkedEmail.email }}</strong>
            <span v-if="linkedEmail.isPrimary" class="primary-pill">Padrao</span>
          </div>

          <div class="badges">
            <span
              v-for="provider in linkedEmail.providers || []"
              :key="`${linkedEmail.email}-${provider}`"
              class="provider-pill"
            >
              {{ providerLabel(provider) }}
            </span>
            <span v-if="linkedEmail.isVerified" class="verified-pill">Verificado</span>
          </div>
        </div>

        <button
          class="ghost small"
          type="button"
          :disabled="isBusy || linkedEmail.isPrimary"
          @click="setDefaultEmail(linkedEmail.email)"
        >
          {{
            switchingEmail === linkedEmail.email
              ? 'Salvando...'
              : linkedEmail.isPrimary
                ? 'Email padrao'
                : 'Tornar padrao'
          }}
        </button>
      </article>

      <p v-if="linkedEmails.length === 0" class="empty-state">
        Nenhum email vinculado foi encontrado ainda.
      </p>
    </div>

    <div class="link-actions">
      <div class="action-card">
        <div class="action-copy">
          <strong>Google</strong>
          <p>Use o mesmo fluxo do login social para anexar o email da conta Google ao usuario atual.</p>
        </div>

        <div v-if="googleLinkedEmail" class="linked-account-state">
          <strong>Usuario ja vinculado</strong>
          <span>email: {{ googleLinkedEmail }}</span>
        </div>

        <GoogleLogin
          v-else
          :api-client="apiClient"
          :is-loading="googleLinking || isLoading"
          :show-separator="false"
          unavailable-message="Google Sign-In nao esta disponivel para vincular contas."
          loading-hint="Validando conta Google para vinculo..."
          variant="system"
          button-label="Vincular conta Google"
          :button-width="260"
          @credential="handleGoogleCredential"
          @error="handleGoogleError"
        />
      </div>

      <div class="action-card">
        <div class="action-copy">
          <strong>GitHub</strong>
          <p>
            {{ githubLinked ? 'Sincronize novamente' : 'Sincronize' }} os emails verificados da conta GitHub usando o token salvo no perfil.
          </p>
        </div>

        <button class="primary" type="button" :disabled="isBusy || !githubTokenConfigured" @click="syncGithubEmails">
          {{ githubLinking ? 'Sincronizando...' : githubLinked ? 'Atualizar emails do GitHub' : 'Vincular emails do GitHub' }}
        </button>

        <p v-if="!githubTokenConfigured" class="hint">
          Configure primeiro o token do GitHub no painel de perfil para habilitar a sincronizacao.
        </p>
      </div>
    </div>

    <p v-if="feedbackError" class="feedback error">{{ feedbackError }}</p>
    <p v-if="feedbackSuccess" class="feedback success">{{ feedbackSuccess }}</p>
  </section>
</template>

<style scoped>
.account-emails-panel {
  display: grid;
  gap: 18px;
}

.panel-head {
  align-items: start;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
}

.panel-head h2 {
  margin: 4px 0 0;
}

.summary-chip {
  background: color-mix(in srgb, var(--color-primary) 14%, transparent);
  border: 1px solid color-mix(in srgb, var(--color-primary) 18%, transparent);
  border-radius: 999px;
  color: var(--accent-strong);
  font-size: 0.82rem;
  font-weight: 700;
  padding: 8px 12px;
}

.panel-copy,
.hint,
.empty-state,
.loading-state {
  color: var(--muted);
  margin: 0;
}

.default-banner {
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--color-primary) 14%, transparent),
    color-mix(in srgb, var(--color-secondary) 12%, transparent)
  );
  border: 1px solid color-mix(in srgb, var(--color-primary) 16%, transparent);
  border-radius: 20px;
  display: grid;
  gap: 6px;
  padding: 16px 18px;
}

.default-banner span {
  color: var(--muted);
  font-size: 0.82rem;
  font-weight: 700;
  text-transform: uppercase;
}

.default-banner strong {
  font-size: 1.02rem;
}

.email-list {
  display: grid;
  gap: 12px;
}

.email-row {
  align-items: center;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 20px;
  display: flex;
  gap: 16px;
  justify-content: space-between;
  padding: 16px;
}

.email-copy {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.email-title {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.email-title strong {
  overflow-wrap: anywhere;
}

.badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.primary-pill,
.provider-pill,
.verified-pill {
  border-radius: 999px;
  font-size: 0.76rem;
  font-weight: 700;
  padding: 6px 10px;
}

.primary-pill {
  background: var(--color-primary);
  color: var(--button-primary-text);
}

.provider-pill {
  background: var(--surface-muted);
  color: var(--ink);
}

.verified-pill {
  background: color-mix(in srgb, var(--color-secondary) 14%, transparent);
  color: var(--color-secondary);
}

.link-actions {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.action-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 22px;
  display: grid;
  gap: 14px;
  min-width: 0;
  overflow: hidden;
  padding: 16px;
}

.action-copy {
  display: grid;
  gap: 6px;
}

.action-copy p {
  color: var(--muted);
  margin: 0;
}

.linked-account-state {
  background: color-mix(in srgb, var(--color-secondary) 12%, var(--surface-strong));
  border: 1px solid color-mix(in srgb, var(--color-secondary) 26%, transparent);
  border-radius: 18px;
  display: grid;
  gap: 6px;
  padding: 14px 16px;
}

.linked-account-state strong {
  color: var(--accent-strong);
}

.linked-account-state span {
  color: var(--muted);
  overflow-wrap: anywhere;
}

.small {
  min-width: 136px;
}

.feedback {
  border-radius: 14px;
  font-weight: 700;
  margin: 0;
  padding: 12px 14px;
}

.feedback.error {
  background: var(--danger-bg);
  border: 1px solid color-mix(in srgb, var(--danger) 28%, transparent);
  color: var(--danger);
}

.feedback.success {
  background: var(--success-bg);
  border: 1px solid color-mix(in srgb, var(--success) 28%, transparent);
  color: var(--success);
}

button.primary,
button.ghost {
  border-radius: 999px;
  cursor: pointer;
  font-weight: 700;
  min-height: 44px;
  padding: 0 18px;
  transition: transform 0.2s ease, opacity 0.2s ease, box-shadow 0.2s ease;
}

button.primary {
  background: var(--button-gradient);
  border: none;
  box-shadow: var(--button-shadow);
  color: var(--button-primary-text);
}

button.ghost {
  background: transparent;
  border: 1px solid var(--line);
  color: var(--ink);
}

button.primary:hover:not(:disabled),
button.ghost:hover:not(:disabled) {
  transform: translateY(-1px);
}

button.primary:disabled,
button.ghost:disabled {
  cursor: not-allowed;
  opacity: 0.58;
}

@media (max-width: 980px) {
  .link-actions {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .email-row {
    align-items: stretch;
    flex-direction: column;
  }

  .small,
  button.primary {
    width: 100%;
  }
}
</style>
