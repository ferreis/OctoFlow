<script setup>
import axios from 'axios'
import { computed, defineAsyncComponent, nextTick, onMounted, reactive, ref, watch } from 'vue'

// Usa caminho relativo para funcionar com proxy do Vite em dev e mesmo dominio via nginx em producao.
const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/ModFederation/api').replace(/\/$/, '')

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
  },
})

const GOOGLE_CLIENT_ID = (import.meta.env.VITE_GOOGLE_CLIENT_ID || '').trim()
const googleLoginEnabled = GOOGLE_CLIENT_ID !== ''

let googleIdentityScriptPromise

function loadGoogleIdentityScript() {
  if (typeof window === 'undefined') {
    return Promise.reject(new Error('Google Identity Services so pode ser carregado no navegador.'))
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
      reject(new Error('Google Identity Services carregou sem expor a API esperada.'))
    }
    script.onerror = () => {
      googleIdentityScriptPromise = undefined
      reject(new Error('Falha ao carregar o script do Google Identity Services.'))
    }
    document.head.appendChild(script)
  })

  return googleIdentityScriptPromise
}

const federationError = ref('')

function defineRemote(loader, moduleName) {
  return defineAsyncComponent({
    loader: async () => {
      try {
        return await loader()
      } catch (error) {
        const message = error instanceof Error ? error.message : 'erro desconhecido'
        federationError.value = `Falha ao carregar componente remoto ${moduleName}: ${message}`
        throw error
      }
    },
    delay: 150,
    timeout: 15000,
  })
}

const RemoteTaskCrudPanel = defineRemote(() => import('remoteApp/TaskCrudPanel'), 'TaskCrudPanel')

const loginForm = reactive({
  email: 'admin@example.com',
  password: '',
})

const accessToken = ref('')
const currentUser = ref(null)
const loginLoading = ref(false)
const actionLoading = ref(false)
const authError = ref('')
const googleError = ref('')
const googleLoading = ref(false)
const statusMessage = ref('')
const googleButtonContainer = ref(null)

const isAuthenticated = computed(() => Boolean(accessToken.value && currentUser.value))

onMounted(async () => {
  if (googleLoginEnabled) {
    await setupGoogleLogin()
  }

  const refreshed = await refreshToken()
  if (refreshed) {
    await loadCurrentUser(false)
  }
})

watch(isAuthenticated, async (authenticated) => {
  if (!authenticated && googleLoginEnabled) {
    await nextTick()
    renderGoogleButton()
  }
})

async function handleLogin() {
  loginLoading.value = true
  authError.value = ''
  googleError.value = ''
  statusMessage.value = ''

  try {
    const { data } = await apiClient.post('/auth/login', {
      email: loginForm.email,
      password: loginForm.password,
    })

    setAccessToken(data.token || '')
    currentUser.value = data.user || null

    if (!currentUser.value) {
      await loadCurrentUser(false)
    }

    loginForm.password = ''
    statusMessage.value = 'Login realizado com sucesso.'
  } catch (error) {
    const message = extractHttpMessage(error, 'Falha no login.')
    authError.value = `Erro de conexao com o backend: ${message}`
  } finally {
    loginLoading.value = false
  }
}

async function setupGoogleLogin() {
  googleError.value = ''

  try {
    await loadGoogleIdentityScript()

    if (!window.google?.accounts?.id) {
      throw new Error('Google Identity Services nao esta disponivel.')
    }

    window.google.accounts.id.initialize({
      client_id: GOOGLE_CLIENT_ID,
      callback: handleGoogleCredentialResponse,
      auto_select: false,
      cancel_on_tap_outside: true,
    })

    await nextTick()
    renderGoogleButton()
  } catch (error) {
    googleError.value = extractHttpMessage(error, 'Falha ao inicializar o login com Google.')
  }
}

function renderGoogleButton() {
  if (!googleLoginEnabled || !googleButtonContainer.value || !window.google?.accounts?.id) {
    return
  }

  googleButtonContainer.value.innerHTML = ''
  window.google.accounts.id.renderButton(googleButtonContainer.value, {
    theme: 'outline',
    size: 'large',
    shape: 'pill',
    text: 'continue_with',
    logo_alignment: 'left',
    width: 320,
  })
}

async function handleGoogleCredentialResponse(response) {
  const credential = typeof response?.credential === 'string' ? response.credential.trim() : ''
  if (!credential) {
    googleError.value = 'Google nao retornou uma credencial valida.'
    return
  }

  googleLoading.value = true
  authError.value = ''
  googleError.value = ''
  statusMessage.value = ''

  try {
    const { data } = await apiClient.post('/auth/google', { credential })

    setAccessToken(data.token || '')
    currentUser.value = data.user || null

    if (!currentUser.value) {
      await loadCurrentUser(false)
    }

    loginForm.password = ''
    statusMessage.value = 'Login com Google realizado com sucesso.'
  } catch (error) {
    googleError.value = extractHttpMessage(error, 'Falha no login com Google.')
  } finally {
    googleLoading.value = false
  }
}

async function loadCurrentUser(canRetry = true) {
  try {
    const response = await authRequest(
      {
        url: '/auth/me',
        method: 'GET',
      },
      canRetry,
    )

    currentUser.value = response.data?.user || null
    return Boolean(currentUser.value)
  } catch {
    return false
  }
}

async function refreshToken() {
  actionLoading.value = true

  try {
    const { data } = await apiClient.post('/auth/refresh')
    if (!data?.token) {
      clearAuth()
      return false
    }

    setAccessToken(data.token)
    currentUser.value = data.user || currentUser.value
    return true
  } catch {
    clearAuth()
    return false
  } finally {
    actionLoading.value = false
  }
}

async function logout() {
  actionLoading.value = true

  try {
    await apiClient.post('/auth/logout')
  } finally {
    if (window.google?.accounts?.id?.disableAutoSelect) {
      window.google.accounts.id.disableAutoSelect()
    }

    clearAuth()
    authError.value = ''
    googleError.value = ''
    statusMessage.value = 'Sessao encerrada com sucesso.'
    actionLoading.value = false
  }
}

async function authRequest(config, canRetry = true) {
  try {
    return await apiClient.request({
      ...config,
      headers: {
        ...(config.headers || {}),
        ...(accessToken.value ? { Authorization: `Bearer ${accessToken.value}` } : {}),
      },
    })
  } catch (error) {
    if (canRetry && axios.isAxiosError(error) && error.response?.status === 401) {
      const refreshed = await refreshToken()
      if (refreshed) {
        return authRequest(config, false)
      }
    }

    throw error
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

function setAccessToken(token) {
  accessToken.value = token
}

function clearAuth() {
  setAccessToken('')
  currentUser.value = null
}
</script>

<template>
  <div class="host-shell">
    <header class="hero">
      <p class="eyebrow">Module Federation</p>
      <h1>Somente modulo remoto de Task</h1>
      <p class="subtitle">Os componentes de task so sao montados quando voce clica na acao. Ao fechar, o componente e destruido.</p>
      <p class="endpoint">API Base: <code>{{ API_BASE_URL }}</code></p>
    </header>

    <main class="layout-grid">
      <section>
        <article class="card">
          <h2>Autenticacao</h2>

          <form v-if="!isAuthenticated" class="auth-form" @submit.prevent="handleLogin">
            <label class="field">
              <span>Email</span>
              <input v-model="loginForm.email" type="email" autocomplete="username" required>
            </label>

            <label class="field">
              <span>Senha</span>
              <input v-model="loginForm.password" type="password" autocomplete="current-password" required>
            </label>

            <button type="submit" :disabled="loginLoading || googleLoading">
              {{ loginLoading ? 'Entrando...' : 'Entrar' }}
            </button>

            <div v-if="googleLoginEnabled" class="social-login">
              <div class="separator">
                <span>ou</span>
              </div>

              <div ref="googleButtonContainer" class="google-button" :class="{ 'is-loading': googleLoading }"></div>
              <p v-if="googleLoading" class="hint">Validando conta Google...</p>
              <p v-if="googleError" class="feedback error social-feedback">{{ googleError }}</p>
            </div>
          </form>

          <div v-else class="session">
            <p><strong>Email:</strong> {{ currentUser.email }}</p>
            <p><strong>Roles:</strong> {{ currentUser.roles.join(', ') }}</p>

            <div class="actions">
              <button type="button" :disabled="actionLoading" @click="refreshToken">Renovar Token</button>
              <button type="button" class="ghost" :disabled="actionLoading" @click="logout">Sair</button>
            </div>
          </div>

          <p v-if="authError" class="feedback error">{{ authError }}</p>
          <p v-if="statusMessage" class="feedback success">{{ statusMessage }}</p>
        </article>
      </section>

      <section>
        <article class="card">
          <h2>Task Remoto</h2>

          <Suspense v-if="isAuthenticated">
            <template #default>
              <RemoteTaskCrudPanel :request="authRequest" endpoint="/tasks" />
            </template>
            <template #fallback>
              <p class="empty">Carregando modulo remoto de task...</p>
            </template>
          </Suspense>

          <p v-else class="empty">Faca login para usar o modulo remoto de task.</p>
          <p v-if="federationError" class="feedback error">{{ federationError }}</p>
        </article>
      </section>
    </main>
  </div>
</template>

<style scoped>
.host-shell {
  background:
    radial-gradient(circle at 10% 15%, rgba(20, 184, 166, 0.14), transparent 36%),
    radial-gradient(circle at 85% 10%, rgba(249, 115, 22, 0.11), transparent 34%),
    linear-gradient(180deg, #f8fafc 0%, #ecfeff 52%, #f8fafc 100%);
  color: #0f172a;
  font-family: 'Trebuchet MS', 'Segoe UI', sans-serif;
  min-height: 100vh;
  padding: 24px;
}

.hero {
  margin: 0 auto 22px;
  max-width: 1160px;
}

.eyebrow {
  color: #0f766e;
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  margin: 0 0 8px;
  text-transform: uppercase;
}

.hero h1 {
  font-size: clamp(1.7rem, 4vw, 2.4rem);
  line-height: 1.1;
  margin: 0;
}

.subtitle {
  color: #334155;
  margin: 12px 0 8px;
}

.endpoint {
  color: #0369a1;
  font-size: 0.9rem;
  font-weight: 600;
  margin: 0;
}

.layout-grid {
  display: grid;
  gap: 20px;
  grid-template-columns: minmax(300px, 380px) minmax(420px, 1fr);
  margin: 0 auto;
  max-width: 1160px;
}

.card {
  background: #ffffff;
  border: 1px solid #d8e3f0;
  border-radius: 18px;
  box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
  padding: 24px;
}

.card h2 {
  margin: 0 0 12px;
}

.auth-form {
  display: grid;
  gap: 12px;
}

.social-login {
  display: grid;
  gap: 10px;
}

.separator {
  align-items: center;
  color: #64748b;
  display: flex;
  font-size: 0.9rem;
  gap: 12px;
}

.separator::before,
.separator::after {
  border-top: 1px solid #dbe4ee;
  content: '';
  flex: 1;
}

.separator span {
  font-weight: 600;
  text-transform: lowercase;
}

.google-button {
  min-height: 44px;
}

.google-button.is-loading {
  opacity: 0.72;
  pointer-events: none;
}

.field {
  display: grid;
  gap: 6px;
}

.field span {
  color: #0f172a;
  font-weight: 600;
}

.field input {
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #111827;
  padding: 10px 12px;
}

button {
  background: linear-gradient(90deg, #0f766e 0%, #0369a1 100%);
  border: 0;
  border-radius: 10px;
  color: #ffffff;
  cursor: pointer;
  font-weight: 700;
  padding: 10px 14px;
}

button.ghost {
  background: #e2e8f0;
  color: #0f172a;
}

button:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.session p {
  color: #334155;
  margin: 8px 0;
}

.actions {
  display: flex;
  gap: 10px;
  margin-top: 12px;
}

.feedback {
  border-radius: 10px;
  font-weight: 600;
  margin-top: 12px;
  padding: 10px 12px;
}

.feedback.error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
}

.feedback.success {
  background: #ecfeff;
  border: 1px solid #bae6fd;
  color: #075985;
}

.social-feedback {
  margin-top: 0;
}

.hint {
  color: #64748b;
  font-size: 0.9rem;
  margin: 0;
}

.empty {
  color: #64748b;
}

@media (max-width: 980px) {
  .layout-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .host-shell {
    padding: 14px;
  }

  .card {
    border-radius: 14px;
    padding: 16px;
  }

  .actions {
    flex-direction: column;
  }
}
</style>