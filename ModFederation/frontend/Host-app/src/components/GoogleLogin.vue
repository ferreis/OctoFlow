<script setup>
import { nextTick, onMounted, ref } from 'vue'

const props = defineProps({
  apiClient: {
    type: Object,
    required: true,
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
    default: 'ou',
  },
  unavailableMessage: {
    type: String,
    default: 'Google Sign-In não está disponível. Faça login com email/senha.',
  },
  loadingHint: {
    type: String,
    default: 'Validando conta Google...',
  },
  buttonWidth: {
    type: Number,
    default: 280,
  },
})

const emit = defineEmits(['credential', 'error', 'config-loaded'])

const googleButtonContainer = ref(null)
const error = ref('')
const isLoading = ref(false)
const googleClientId = ref('')
let googleIdentityScriptPromise

async function loadAuthConfig() {
  try {
    const { data } = await props.apiClient.get('/auth/config')
    googleClientId.value = data.googleClientId || ''

    if (!googleClientId.value) {
      error.value = 'Google Sign-In não está configurado no backend.'
      emit('error', error.value)
      return false
    }

    emit('config-loaded', { googleClientId: googleClientId.value })
    return true
  } catch (err) {
    error.value = `Erro ao carregar configurações: ${err.message}`
    console.warn(error.value)
    emit('error', error.value)
    return false
  }
}

function loadGoogleIdentityScript() {
  if (typeof window === 'undefined') {
    return Promise.reject(
      new Error('Google Identity Services só pode ser carregado no navegador.'),
    )
  }

  if (window.google?.accounts?.id) {
    return Promise.resolve(window.google)
  }

  if (googleIdentityScriptPromise) {
    return googleIdentityScriptPromise
  }

  googleIdentityScriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = 'https://accounts.google.com/gsi/client'
    script.async = true
    script.defer = true
    script.onload = () => {
      if (window.google?.accounts?.id) {
        resolve(window.google)
        return
      }

      googleIdentityScriptPromise = undefined
      reject(
        new Error('Google Identity Services carregou sem expor a API esperada.'),
      )
    }
    script.onerror = () => {
      googleIdentityScriptPromise = undefined
      reject(new Error('Falha ao carregar o script do Google Identity Services.'))
    }
    document.head.appendChild(script)
  })

  return googleIdentityScriptPromise
}

async function setupGoogleLogin() {
  if (!googleClientId.value) {
    error.value = 'Google Client ID não disponível.'
    emit('error', error.value)
    return
  }

  try {
    await loadGoogleIdentityScript()

    if (!window.google?.accounts?.id) {
      throw new Error('Google Identity Services não está disponível.')
    }

    window.google.accounts.id.initialize({
      client_id: googleClientId.value,
      callback: handleGoogleCredentialResponse,
      auto_select: false,
      cancel_on_tap_outside: true,
    })

    error.value = ''
    await nextTick()
    renderGoogleButton()
  } catch (err) {
    error.value = `Erro ao inicializar Google Login: ${err.message}`
    emit('error', error.value)
  }
}

function renderGoogleButton() {
  if (!googleButtonContainer.value || !window.google?.accounts?.id) {
    return
  }

  googleButtonContainer.value.innerHTML = ''
  window.google.accounts.id.renderButton(googleButtonContainer.value, {
    theme: 'outline',
    size: 'large',
    shape: 'pill',
    text: 'continue_with',
    logo_alignment: 'left',
    width: props.buttonWidth,
  })
}

async function handleGoogleCredentialResponse(response) {
  const credential = typeof response?.credential === 'string' ? response.credential.trim() : ''

  if (!credential) {
    error.value = 'Google não retornou uma credencial válida.'
    emit('error', error.value)
    return
  }

  isLoading.value = true
  error.value = ''

  try {
    emit('credential', credential)
  } finally {
    isLoading.value = false
  }
}

function disableAutoSelect() {
  if (window.google?.accounts?.id?.disableAutoSelect) {
    window.google.accounts.id.disableAutoSelect()
  }
}

onMounted(async () => {
  const configLoaded = await loadAuthConfig()
  if (configLoaded) {
    await setupGoogleLogin()
  }
})

defineExpose({
  disableAutoSelect,
  error,
})
</script>

<template>
  <div class="google-login-container">
    <div v-if="googleClientId" class="social-login">
      <div v-if="showSeparator" class="separator">
        <span>{{ separatorLabel }}</span>
      </div>

      <div ref="googleButtonContainer" class="google-button" :class="{ 'is-loading': isLoading || isLoading }"></div>
      <p v-if="isLoading || isLoading" class="hint">{{ loadingHint }}</p>
      <p v-if="error" class="feedback error">{{ error }}</p>
    </div>

    <p v-else class="feedback info">{{ unavailableMessage }}</p>
  </div>
</template>

<style scoped>
.google-login-container {
  width: 100%;
}

.social-login {
  display: grid;
  gap: 10px;
}

.separator {
  align-items: center;
  color: var(--muted);
  display: flex;
  font-size: 0.9rem;
  gap: 12px;
}

.separator::before,
.separator::after {
  border-top: 1px solid var(--line);
  content: '';
  flex: 1;
}

.separator span {
  font-weight: 600;
  text-transform: lowercase;
}

.google-button {
  max-width: 100%;
  min-height: 44px;
  overflow: hidden;
}

.google-button.is-loading {
  opacity: 0.72;
  pointer-events: none;
}

.hint {
  color: var(--muted);
  font-size: 0.9rem;
  margin: 0;
}

.feedback {
  border-radius: 10px;
  font-weight: 600;
  margin: 0;
  padding: 10px 12px;
}

.feedback.error {
  background: var(--danger-bg);
  border: 1px solid color-mix(in srgb, var(--danger) 28%, transparent);
  color: var(--danger);
}

.feedback.info {
  background: var(--secondary-soft);
  border: 1px solid color-mix(in srgb, var(--color-secondary) 28%, transparent);
  color: var(--color-secondary);
}
</style>
