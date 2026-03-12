<script setup>
import axios from 'axios'
import { computed, defineAsyncComponent, onMounted, reactive, ref, watch } from 'vue'
import GoogleLogin from './components/GoogleLogin.vue'

// Usa caminho relativo para funcionar com proxy do Vite em dev e mesmo dominio via nginx em producao.
const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/ModFederation/api').replace(/\/$/, '')
const DEFAULT_CSRF_HEADER_NAME = 'X-CSRF-Token'
const DEFAULT_CSRF_ACTION_HEADER_NAME = 'X-CSRF-Action'
const PUBLIC_CSRF_ACTIONS = {
  'auth.login': { method: 'POST', path: '/auth/login' },
  'auth.google': { method: 'POST', path: '/auth/google' },
  'auth.refresh': { method: 'POST', path: '/auth/refresh' },
  'auth.logout': { method: 'POST', path: '/auth/logout' },
}

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
  },
})

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
const csrfHeaderName = ref(DEFAULT_CSRF_HEADER_NAME)
const csrfActionHeaderName = ref(DEFAULT_CSRF_ACTION_HEADER_NAME)
const currentUser = ref(null)
const loginLoading = ref(false)
const actionLoading = ref(false)
const authError = ref('')
const googleError = ref('')
const statusMessage = ref('')
const googleLoginComponent = ref(null)

const isAuthenticated = computed(() => Boolean(accessToken.value && currentUser.value))

onMounted(async () => {
  const refreshed = await refreshToken(false)
  if (refreshed) {
    await loadCurrentUser(false)
  }
})

watch(isAuthenticated, async (authenticated) => {
  if (!authenticated) {
    googleError.value = ''
  }
})

async function handleLogin() {
  loginLoading.value = true
  authError.value = ''
  googleError.value = ''
  statusMessage.value = ''

  try {
    const { data } = await requestWithCsrf({
      url: '/auth/login',
      method: 'POST',
      csrfActionId: 'auth.login',
      data: {
        email: loginForm.email,
        password: loginForm.password,
      },
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

async function handleGoogleCredential(credential) {
  const wasLoading = loginLoading.value
  loginLoading.value = true
  authError.value = ''
  googleError.value = ''
  statusMessage.value = ''

  try {
    const { data } = await requestWithCsrf({
      url: '/auth/google',
      method: 'POST',
      csrfActionId: 'auth.google',
      data: { credential },
    })

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
    loginLoading.value = wasLoading
  }
}

function handleGoogleLoginError(error) {
  googleError.value = error
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

async function refreshToken(useLoading = true) {
  if (useLoading) {
    actionLoading.value = true
  }

  try {
    const { data } = await requestWithCsrf(
      {
        url: '/auth/refresh',
        method: 'POST',
        csrfActionId: 'auth.refresh',
      },
      true,
      false,
    )

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
    if (useLoading) {
      actionLoading.value = false
    }
  }
}

async function logout() {
  actionLoading.value = true

  try {
    await requestWithCsrf({
      url: '/auth/logout',
      method: 'POST',
      csrfActionId: 'auth.logout',
    })
  } finally {
    clearAuth()
    authError.value = ''
    googleError.value = ''
    statusMessage.value = 'Sessao encerrada com sucesso.'
    actionLoading.value = false
  }
}

function isMutatingMethod(method) {
  return ['POST', 'PUT', 'PATCH', 'DELETE'].includes(String(method || 'GET').toUpperCase())
}

function normalizeApiPath(url) {
  const rawUrl = typeof url === 'string' && url.trim() !== '' ? url.trim() : '/'

  try {
    const resolvedUrl = new URL(rawUrl, window.location.origin)
    const apiBasePath = new URL(API_BASE_URL, window.location.origin).pathname.replace(/\/$/, '')
    let normalizedPath = resolvedUrl.pathname || '/'

    if (apiBasePath && normalizedPath.startsWith(apiBasePath)) {
      normalizedPath = normalizedPath.slice(apiBasePath.length) || '/'
    }

    return normalizedPath.startsWith('/') ? normalizedPath : `/${normalizedPath}`
  } catch {
    return rawUrl.startsWith('/') ? rawUrl : `/${rawUrl}`
  }
}

function getCsrfActionId(config, method) {
  const actionId = typeof config?.csrfActionId === 'string' ? config.csrfActionId.trim() : ''

  if (isMutatingMethod(method) && actionId === '') {
    throw new Error('Toda requisicao mutavel precisa informar csrfActionId.')
  }

  return actionId
}

function isPublicCsrfAction(actionId, method, path) {
  const definition = PUBLIC_CSRF_ACTIONS[actionId]

  return Boolean(definition && definition.method === method && definition.path === path)
}

function buildAuthorizedHeaders(headers = {}) {
  return accessToken.value
    ? {
        ...headers,
        Authorization: `Bearer ${accessToken.value}`,
      }
    : { ...headers }
}

async function authorizedRequestWithoutCsrf(config, canRetry = true) {
  try {
    return await apiClient.request({
      ...config,
      headers: buildAuthorizedHeaders(config?.headers || {}),
    })
  } catch (error) {
    if (canRetry && axios.isAxiosError(error) && error.response?.status === 401) {
      const refreshed = await refreshToken(false)
      if (refreshed) {
        return authorizedRequestWithoutCsrf(config, false)
      }
    }

    throw error
  }
}

async function requestCsrfChallenge(config, canRetryAuth = true) {
  const method = String(config?.method || 'GET').toUpperCase()
  const path = normalizeApiPath(config?.url)
  const actionId = getCsrfActionId(config, method)
  const payload = { method, path, actionId }

  if (isPublicCsrfAction(actionId, method, path)) {
    const response = await apiClient.post('/auth/csrf/challenge', payload)
    return response.data || {}
  }

  const response = await authorizedRequestWithoutCsrf(
    {
      url: '/csrf/challenge',
      method: 'POST',
      data: payload,
    },
    canRetryAuth,
  )

  return response.data || {}
}

function shouldRetryWithNewCsrf(error, method) {
  if (!isMutatingMethod(method) || !axios.isAxiosError(error)) {
    return false
  }

  const status = error.response?.status
  const message = error.response?.data?.message

  return status === 403 && typeof message === 'string' && message.toLowerCase().includes('csrf')
}

async function requestWithCsrf(config, canRetryCsrf = true, canRetryAuth = true) {
  const method = String(config?.method || 'GET').toUpperCase()
  const path = normalizeApiPath(config?.url)
  const actionId = getCsrfActionId(config, method)
  const publicCsrfAction = isPublicCsrfAction(actionId, method, path)
  const headers = {
    ...(config?.headers || {}),
  }

  if (isMutatingMethod(method)) {
    const challenge = await requestCsrfChallenge(config, canRetryAuth)
    const token = typeof challenge?.csrfToken === 'string' ? challenge.csrfToken.trim() : ''
    const issuedHeaderName = typeof challenge?.headerName === 'string' && challenge.headerName.trim() !== ''
      ? challenge.headerName.trim()
      : DEFAULT_CSRF_HEADER_NAME
    const issuedActionHeaderName = typeof challenge?.actionHeaderName === 'string' && challenge.actionHeaderName.trim() !== ''
      ? challenge.actionHeaderName.trim()
      : DEFAULT_CSRF_ACTION_HEADER_NAME

    if (token === '') {
      throw new Error('O backend nao retornou um CSRF token valido para esta acao.')
    }

    csrfHeaderName.value = issuedHeaderName
    csrfActionHeaderName.value = issuedActionHeaderName
    headers[issuedHeaderName] = token
    headers[issuedActionHeaderName] = actionId
  }

  try {
    return await apiClient.request({
      ...config,
      method,
      headers: publicCsrfAction ? headers : buildAuthorizedHeaders(headers),
    })
  } catch (error) {
    if (canRetryCsrf && shouldRetryWithNewCsrf(error, method)) {
      return requestWithCsrf(config, false, canRetryAuth)
    }

    if (!publicCsrfAction && canRetryAuth && axios.isAxiosError(error) && error.response?.status === 401) {
      const refreshed = await refreshToken(false)
      if (refreshed) {
        return requestWithCsrf(config, canRetryCsrf, false)
      }
    }

    throw error
  }
}

async function authRequest(config, canRetry = true) {
  if (isMutatingMethod(config?.method)) {
    return requestWithCsrf(config, true, canRetry)
  }

  try {
    return await authorizedRequestWithoutCsrf(config, canRetry)
  } catch (error) {
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

            <button type="submit" :disabled="loginLoading || actionLoading">
              {{ loginLoading ? 'Entrando...' : 'Entrar' }}
            </button>

            <div v-if="true" class="social-login">
              <GoogleLogin
                ref="googleLoginComponent"
                :api-client="apiClient"
                :is-loading="loginLoading || actionLoading"
                @credential="handleGoogleCredential"
                @error="handleGoogleLoginError"
              />
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