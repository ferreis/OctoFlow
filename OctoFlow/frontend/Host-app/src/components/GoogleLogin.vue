<script setup>
import {
  computed,
  nextTick,
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

const GOOGLE_CREDENTIAL_MAX_LENGTH = 4096
const GOOGLE_CLIENT_ID_MAX_LENGTH = 220
const FEEDBACK_MESSAGE_MAX_LENGTH = 280

let googleIdentityScriptPromise

const props = defineProps({
  apiClient: {
    type: [Object, Function],
    default: null,
  },
  isLoading: {
    type: Boolean,
    default: false,
  },
  showSeparator: {
    type: Boolean,
    default: true,
  },
  separatorLabel: {
    type: String,
    default: '',
  },
  unavailableMessage: {
    type: String,
    default: '',
  },
  loadingHint: {
    type: String,
    default: '',
  },
  buttonWidth: {
    type: Number,
    default: 280,
  },
  variant: {
    type: String,
    default: 'native',
  },
  buttonLabel: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['credential', 'error'])

const googleButtonContainer = ref(null)
const error = ref('')
const localLoading = ref(false)
const googleClientId = ref('')
const cachedContainerWidth = ref(0)
const shouldInitializeWhenActive = ref(true)

const { translate } = useI18n()
const sessionStore = useSessionStore()

let containerResizeObserver = null

const useSystemVariant = computed(() => props.variant === 'system')
const hasGoogleClientId = computed(() => googleClientId.value !== '')

function translateWithFallback(messageKey, fallbackMessage, variables = {}) {
  const translatedText = translate(messageKey, variables)
  return translatedText === messageKey ? fallbackMessage : translatedText
}

const resolvedSeparatorLabel = computed(() => {
  const customLabel = sanitizeSingleLineText(props.separatorLabel, 30)
  return customLabel !== ''
    ? customLabel
    : translateWithFallback('shared.googleLogin.separatorLabel', 'ou')
})

const resolvedUnavailableMessage = computed(() => {
  const customMessage = sanitizeSingleLineText(props.unavailableMessage, FEEDBACK_MESSAGE_MAX_LENGTH)
  return customMessage !== ''
    ? customMessage
    : translateWithFallback(
      'shared.googleLogin.unavailableMessage',
      'Google Sign-In não está disponível. Faça login com email e senha.',
    )
})

const resolvedLoadingHint = computed(() => {
  const customMessage = sanitizeSingleLineText(props.loadingHint, FEEDBACK_MESSAGE_MAX_LENGTH)
  return customMessage !== ''
    ? customMessage
    : translateWithFallback('shared.googleLogin.loadingHint', 'Validando conta Google...')
})

const resolvedButtonLabel = computed(() => {
  const customLabel = sanitizeSingleLineText(props.buttonLabel, 80)
  return customLabel !== ''
    ? customLabel
    : translateWithFallback('shared.googleLogin.buttonLabel', 'Continuar com Google')
})

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

function sanitizeGoogleCredential(rawCredential) {
  return replaceControlCharactersWithSpaces(rawCredential)
    .replace(/\s+/g, '')
    .trim()
    .slice(0, GOOGLE_CREDENTIAL_MAX_LENGTH)
}

function sanitizeGoogleClientId(rawClientId) {
  const normalizedClientId = sanitizeSingleLineText(rawClientId, GOOGLE_CLIENT_ID_MAX_LENGTH)
  if (normalizedClientId === '') {
    return ''
  }

  return /^[a-zA-Z0-9._-]+$/.test(normalizedClientId)
    ? normalizedClientId
    : ''
}

function setErrorMessage(rawMessage) {
  const normalizedMessage = sanitizeSingleLineText(rawMessage, FEEDBACK_MESSAGE_MAX_LENGTH)
  error.value = normalizedMessage
  emit('error', normalizedMessage)
}

function clearErrorMessage() {
  error.value = ''
}

function disableAutoSelect() {
  if (window.google?.accounts?.id?.disableAutoSelect) {
    window.google.accounts.id.disableAutoSelect()
  }
}

function disconnectContainerResizeObserver() {
  if (!containerResizeObserver) {
    return
  }

  containerResizeObserver.disconnect()
  containerResizeObserver = null
}

function observeButtonContainerWidth() {
  disconnectContainerResizeObserver()

  if (!useSystemVariant.value || typeof ResizeObserver === 'undefined') {
    return
  }

  const buttonContainerElement = googleButtonContainer.value
  if (!(buttonContainerElement instanceof HTMLElement)) {
    return
  }

  containerResizeObserver = new ResizeObserver(() => {
    void renderGoogleButton()
  })

  containerResizeObserver.observe(buttonContainerElement)
}

async function executeClientRequest(config) {
  const requestClient = props.apiClient || sessionStore.apiClient

  if (typeof requestClient === 'function') {
    return requestClient(config)
  }

  if (requestClient && typeof requestClient.request === 'function') {
    return requestClient.request(config)
  }

  if (
    requestClient
    && typeof requestClient.get === 'function'
    && String(config?.method || 'GET').toUpperCase() === 'GET'
  ) {
    return requestClient.get(config.url, {
      params: config.params,
      headers: config.headers,
    })
  }

  return sessionStore.requestWithCsrf(config)
}

async function loadAuthConfig() {
  try {
    const { data } = await executeClientRequest({
      url: '/auth/config',
      method: 'GET',
    })

    const normalizedClientId = sanitizeGoogleClientId(data?.googleClientId)
    googleClientId.value = normalizedClientId

    if (!normalizedClientId) {
      setErrorMessage(
        translateWithFallback(
          'shared.googleLogin.errors.clientIdMissing',
          'Google Sign-In não está configurado no backend.',
        ),
      )
      return false
    }

    return true
  } catch (requestError) {
    const requestErrorMessage = sanitizeSingleLineText(requestError?.message, 120)
    setErrorMessage(
      translateWithFallback(
        'shared.googleLogin.errors.configLoadFailed',
        'Erro ao carregar configurações: {message}',
        {
          message: requestErrorMessage !== '' ? requestErrorMessage : 'falha ao consultar /auth/config',
        },
      ),
    )
    return false
  }
}

function loadGoogleIdentityScript() {
  if (typeof window === 'undefined') {
    return Promise.reject(
      new Error(
        translateWithFallback(
          'shared.googleLogin.errors.browserOnly',
          'Google Identity Services só pode ser carregado no navegador.',
        ),
      ),
    )
  }

  if (window.google?.accounts?.id) {
    return Promise.resolve(window.google)
  }

  if (googleIdentityScriptPromise) {
    return googleIdentityScriptPromise
  }

  googleIdentityScriptPromise = new Promise((resolve, reject) => {
    const scriptElement = document.createElement('script')
    scriptElement.src = 'https://accounts.google.com/gsi/client'
    scriptElement.async = true
    scriptElement.defer = true
    scriptElement.onload = () => {
      if (window.google?.accounts?.id) {
        resolve(window.google)
        return
      }

      googleIdentityScriptPromise = undefined
      reject(
        new Error(
          translateWithFallback(
            'shared.googleLogin.errors.unexpectedApiShape',
            'Google Identity Services carregou sem expor a API esperada.',
          ),
        ),
      )
    }
    scriptElement.onerror = () => {
      googleIdentityScriptPromise = undefined
      reject(
        new Error(
          translateWithFallback(
            'shared.googleLogin.errors.scriptLoadFailed',
            'Falha ao carregar o script do Google Identity Services.',
          ),
        ),
      )
    }
    document.head.appendChild(scriptElement)
  })

  return googleIdentityScriptPromise
}

async function renderGoogleButton() {
  if (!shouldInitializeWhenActive.value) {
    return
  }

  const buttonContainerElement = googleButtonContainer.value
  if (!(buttonContainerElement instanceof HTMLElement) || !window.google?.accounts?.id) {
    return
  }

  const currentContainerWidth = Math.max(Math.round(buttonContainerElement.getBoundingClientRect().width || 0), 220)
  cachedContainerWidth.value = currentContainerWidth

  const renderedWidth = useSystemVariant.value
    ? currentContainerWidth
    : Math.max(220, Math.round(Number(props.buttonWidth || 280)))

  buttonContainerElement.textContent = ''

  window.google.accounts.id.renderButton(buttonContainerElement, {
    theme: 'outline',
    size: 'large',
    shape: 'pill',
    text: 'continue_with',
    logo_alignment: 'left',
    width: renderedWidth,
  })
}

async function setupGoogleLogin() {
  if (!googleClientId.value) {
    setErrorMessage(
      translateWithFallback(
        'shared.googleLogin.errors.clientIdUnavailable',
        'Google Client ID não disponível.',
      ),
    )
    return
  }

  try {
    await loadGoogleIdentityScript()

    if (!window.google?.accounts?.id) {
      throw new Error(
        translateWithFallback(
          'shared.googleLogin.errors.serviceUnavailable',
          'Google Identity Services não está disponível.',
        ),
      )
    }

    window.google.accounts.id.initialize({
      client_id: googleClientId.value,
      callback: handleGoogleCredentialResponse,
      auto_select: false,
      cancel_on_tap_outside: true,
    })

    clearErrorMessage()
    await nextTick()
    await renderGoogleButton()
    observeButtonContainerWidth()
  } catch (initializationError) {
    const initializationErrorMessage = sanitizeSingleLineText(initializationError?.message, 120)
    setErrorMessage(
      translateWithFallback(
        'shared.googleLogin.errors.initializationFailed',
        'Erro ao inicializar Google Login: {message}',
        {
          message: initializationErrorMessage !== ''
            ? initializationErrorMessage
            : translateWithFallback('shared.googleLogin.errors.unknown', 'erro desconhecido'),
        },
      ),
    )
  }
}

async function initializeGoogleLoginFlow() {
  if (!shouldInitializeWhenActive.value) {
    return
  }

  const configLoaded = googleClientId.value !== ''
    ? true
    : await loadAuthConfig()

  if (!configLoaded) {
    return
  }

  await setupGoogleLogin()
}

async function handleGoogleCredentialResponse(response) {
  const credential = sanitizeGoogleCredential(response?.credential)

  if (!credential) {
    setErrorMessage(
      translateWithFallback(
        'shared.googleLogin.errors.invalidCredential',
        'Google não retornou uma credencial válida.',
      ),
    )
    return
  }

  localLoading.value = true
  clearErrorMessage()

  try {
    emit('credential', credential)
  } finally {
    localLoading.value = false
  }
}

function resetGoogleComponentState() {
  clearErrorMessage()
  localLoading.value = false
}

onBeforeMount(() => {
  resetGoogleComponentState()
})

onMounted(async () => {
  shouldInitializeWhenActive.value = true
  await initializeGoogleLoginFlow()
})

onBeforeUpdate(() => {
  const buttonContainerElement = googleButtonContainer.value
  if (!(buttonContainerElement instanceof HTMLElement)) {
    return
  }

  cachedContainerWidth.value = Math.max(
    0,
    Math.round(buttonContainerElement.getBoundingClientRect().width || 0),
  )
})

onUpdated(() => {
  if (!useSystemVariant.value || !shouldInitializeWhenActive.value) {
    return
  }

  const buttonContainerElement = googleButtonContainer.value
  if (!(buttonContainerElement instanceof HTMLElement)) {
    return
  }

  const currentContainerWidth = Math.max(
    0,
    Math.round(buttonContainerElement.getBoundingClientRect().width || 0),
  )

  if (currentContainerWidth !== cachedContainerWidth.value) {
    void renderGoogleButton()
  }
})

onActivated(async () => {
  shouldInitializeWhenActive.value = true
  await initializeGoogleLoginFlow()
})

onDeactivated(() => {
  shouldInitializeWhenActive.value = false
  disconnectContainerResizeObserver()
})

onBeforeUnmount(() => {
  shouldInitializeWhenActive.value = false
  disconnectContainerResizeObserver()
  disableAutoSelect()
})

onUnmounted(() => {
  googleButtonContainer.value = null
})

onErrorCaptured((runtimeError) => {
  const runtimeErrorMessage = sanitizeSingleLineText(runtimeError?.message, 120)
  setErrorMessage(
    translateWithFallback(
      'shared.googleLogin.errors.runtimeError',
      'Falha no login social: {message}',
      {
        message: runtimeErrorMessage !== ''
          ? runtimeErrorMessage
          : translateWithFallback('shared.googleLogin.errors.unknown', 'erro desconhecido'),
      },
    ),
  )

  return false
})

watch(
  () => [props.buttonWidth, props.variant],
  () => {
    void renderGoogleButton()
  },
)

defineExpose({
  disableAutoSelect,
  error,
})
</script>

<template>
  <div class="app-google-login-container">
    <div v-if="hasGoogleClientId" class="app-google-login-social">
      <div v-if="showSeparator" class="app-google-login-separator">
        <span>{{ resolvedSeparatorLabel }}</span>
      </div>

      <div
        v-if="useSystemVariant"
        class="app-google-login-shell"
        :class="{ 'is-loading': props.isLoading || localLoading }"
      >
        <div class="app-google-login-shell-copy">
          <span class="app-google-login-shell-badge" aria-hidden="true">G</span>
          <span>{{ resolvedButtonLabel }}</span>
        </div>
        <div
          ref="googleButtonContainer"
          class="app-google-login-button app-google-login-button-overlay"
          :class="{ 'is-loading': props.isLoading || localLoading }"
        ></div>
      </div>

      <div
        v-else
        ref="googleButtonContainer"
        class="app-google-login-button"
        :class="{ 'is-loading': props.isLoading || localLoading }"
      ></div>

      <p v-if="props.isLoading || localLoading" class="app-google-login-hint">{{ resolvedLoadingHint }}</p>
      <p v-if="error" class="app-google-login-feedback app-google-login-feedback-error">{{ error }}</p>
    </div>

    <p v-else class="app-google-login-feedback app-google-login-feedback-info">{{ resolvedUnavailableMessage }}</p>
  </div>
</template>
