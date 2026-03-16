<script setup>
import axios from 'axios'
import { computed, defineAsyncComponent, onMounted, reactive, ref, watch } from 'vue'
import GoogleLogin from './components/GoogleLogin.vue'

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

const RemoteGithubWorkspacePanel = defineRemote(() => import('remoteApp/GithubWorkspacePanel'), 'GithubWorkspacePanel')

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

  return authorizedRequestWithoutCsrf(config, canRetry)
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
      <div class="hero-copy">
        <p class="eyebrow">GitHub Delivery Desk</p>
        <h1>Uma aplicacao de verdade para transformar backlog em issue no GitHub</h1>
        <p class="subtitle">
          Login local ou Google no host, workspace remoto via Module Federation e backend Symfony
          falando com a API GraphQL do GitHub para criar issues e sincronizar Projects.
        </p>
      </div>

      <div class="hero-aside">
        <div class="hero-metric">
          <span>Backend</span>
          <strong>Symfony + GraphQL</strong>
        </div>
        <div class="hero-metric">
          <span>Entrega</span>
          <strong>Remote Workspace</strong>
        </div>
        <div class="hero-metric">
          <span>API Base</span>
          <code>{{ API_BASE_URL }}</code>
        </div>
      </div>
    </header>

    <main class="layout-grid">
      <aside class="sidebar">
        <article class="card auth-card">
          <div class="card-head">
            <div>
              <p class="section-kicker">Sessao</p>
              <h2>Autenticacao</h2>
            </div>
          </div>

          <form v-if="!isAuthenticated" class="auth-form" @submit.prevent="handleLogin">
            <label class="field">
              <span>Email</span>
              <input v-model="loginForm.email" type="email" autocomplete="username" required>
            </label>

            <label class="field">
              <span>Senha</span>
              <input v-model="loginForm.password" type="password" autocomplete="current-password" required>
            </label>

            <button class="primary" type="submit" :disabled="loginLoading || actionLoading">
              {{ loginLoading ? 'Entrando...' : 'Entrar' }}
            </button>

            <div class="social-login">
              <GoogleLogin
                :api-client="apiClient"
                :is-loading="loginLoading || actionLoading"
                @credential="handleGoogleCredential"
                @error="handleGoogleLoginError"
              />
              <p v-if="googleError" class="feedback error">{{ googleError }}</p>
            </div>
          </form>

          <div v-else class="session">
            <div class="session-banner">
              <span>Conectado como</span>
              <strong>{{ currentUser.email }}</strong>
            </div>

            <p><strong>Roles:</strong> {{ currentUser.roles.join(', ') }}</p>

            <div class="actions">
              <button class="primary" type="button" :disabled="actionLoading" @click="refreshToken">
                Renovar token
              </button>
              <button class="ghost" type="button" :disabled="actionLoading" @click="logout">
                Sair
              </button>
            </div>
          </div>

          <p v-if="authError" class="feedback error">{{ authError }}</p>
          <p v-if="statusMessage" class="feedback success">{{ statusMessage }}</p>
        </article>

        <article class="card meta-card">
          <p class="section-kicker">Fluxo</p>
          <h2>Como o app trabalha</h2>
          <ul>
            <li>Autenticacao JWT + refresh token HttpOnly ja existente.</li>
            <li>Workspace remoto so monta depois do login.</li>
            <li>Issues sobem via backend para proteger o token do GitHub.</li>
            <li>Projects usam GraphQL para adicionar item e atualizar status.</li>
          </ul>
        </article>
      </aside>

      <section class="workspace-column">
        <article class="card workspace-card">
          <div class="card-head workspace-head">
            <div>
              <p class="section-kicker">Remote App</p>
              <h2>GitHub Workspace</h2>
            </div>
            <p class="head-note">O painel abaixo e carregado do remote Vue sob demanda.</p>
          </div>

          <Suspense v-if="isAuthenticated">
            <template #default>
              <RemoteGithubWorkspacePanel :request="authRequest" :current-user="currentUser" />
            </template>
            <template #fallback>
              <p class="empty">Carregando workspace remoto do GitHub...</p>
            </template>
          </Suspense>

          <div v-else class="locked-state">
            <h3>Faca login para destravar o workspace</h3>
            <p>
              Depois da autenticacao, o remote carrega o painel de templates, preview, labels e Projects
              sem expor credenciais do GitHub no navegador.
            </p>
          </div>

          <p v-if="federationError" class="feedback error">{{ federationError }}</p>
        </article>
      </section>
    </main>
  </div>
</template>

<style scoped>
.host-shell {
  background:
    radial-gradient(circle at 10% 10%, rgba(15, 118, 110, 0.18), transparent 26%),
    radial-gradient(circle at 88% 12%, rgba(234, 88, 12, 0.16), transparent 24%),
    linear-gradient(180deg, #f8fafc 0%, #ecfeff 48%, #fff7ed 100%);
  color: #0f172a;
  min-height: 100vh;
  padding: 24px;
}

.hero {
  align-items: end;
  display: grid;
  gap: 18px;
  grid-template-columns: minmax(0, 1.6fr) minmax(260px, 0.9fr);
  margin: 0 auto 22px;
  max-width: 1400px;
}

.eyebrow,
.section-kicker {
  color: #b45309;
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  margin: 0 0 8px;
  text-transform: uppercase;
}

.hero h1 {
  font-size: clamp(2rem, 4vw, 3.4rem);
  line-height: 1.02;
  margin: 0;
  max-width: 860px;
}

.subtitle {
  color: #334155;
  font-size: 1.02rem;
  margin: 14px 0 0;
  max-width: 760px;
}

.hero-aside {
  display: grid;
  gap: 10px;
}

.hero-metric {
  background: rgba(255, 255, 255, 0.82);
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 18px;
  display: grid;
  gap: 4px;
  padding: 14px 16px;
}

.hero-metric span {
  color: #64748b;
  font-size: 0.82rem;
}

.hero-metric strong,
.hero-metric code {
  font-size: 0.96rem;
}

.layout-grid {
  display: grid;
  gap: 18px;
  grid-template-columns: minmax(300px, 360px) minmax(0, 1fr);
  margin: 0 auto;
  max-width: 1400px;
}

.sidebar,
.workspace-column {
  display: grid;
  gap: 18px;
}

.card {
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(241, 245, 249, 0.94)),
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.08), transparent 28%);
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-radius: 28px;
  box-shadow: 0 20px 42px rgba(15, 23, 42, 0.08);
  padding: 22px;
}

.card-head {
  align-items: start;
  display: flex;
  justify-content: space-between;
  margin-bottom: 18px;
}

.card-head h2 {
  margin: 0;
}

.head-note {
  color: #64748b;
  margin: 0;
  max-width: 260px;
  text-align: right;
}

.auth-form,
.session {
  display: grid;
  gap: 14px;
}

.field {
  display: grid;
  gap: 8px;
}

.field span {
  font-weight: 700;
}

.field input {
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(148, 163, 184, 0.5);
  border-radius: 14px;
  color: #0f172a;
  font: inherit;
  padding: 12px 14px;
}

.primary,
.ghost {
  border: 0;
  border-radius: 14px;
  cursor: pointer;
  font: inherit;
  font-weight: 800;
  padding: 12px 16px;
}

.primary {
  background: linear-gradient(90deg, #0f766e, #0369a1);
  color: #fff;
}

.ghost {
  background: #e2e8f0;
  color: #0f172a;
}

.primary:disabled,
.ghost:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.social-login {
  display: grid;
  gap: 10px;
}

.session-banner {
  background: linear-gradient(135deg, rgba(15, 118, 110, 0.14), rgba(14, 165, 233, 0.12));
  border: 1px solid rgba(15, 118, 110, 0.18);
  border-radius: 18px;
  display: grid;
  gap: 4px;
  padding: 14px 16px;
}

.session-banner span {
  color: #64748b;
  font-size: 0.82rem;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.feedback {
  border-radius: 16px;
  margin: 12px 0 0;
  padding: 12px 14px;
}

.feedback.error {
  background: rgba(220, 38, 38, 0.08);
  border: 1px solid rgba(220, 38, 38, 0.2);
  color: #991b1b;
}

.feedback.success {
  background: rgba(15, 118, 110, 0.1);
  border: 1px solid rgba(15, 118, 110, 0.22);
  color: #115e59;
}

.meta-card h2 {
  margin: 0 0 10px;
}

.meta-card ul {
  color: #334155;
  margin: 0;
  padding-left: 18px;
}

.meta-card li + li {
  margin-top: 10px;
}

.workspace-card {
  min-height: 720px;
}

.locked-state,
.empty {
  align-items: center;
  background: linear-gradient(180deg, rgba(15, 23, 42, 0.03), rgba(15, 118, 110, 0.05));
  border: 1px dashed rgba(15, 23, 42, 0.14);
  border-radius: 22px;
  display: grid;
  justify-items: center;
  margin: 0;
  min-height: 260px;
  padding: 28px;
  text-align: center;
}

.locked-state h3 {
  margin: 0;
}

.locked-state p {
  color: #475569;
  margin: 8px 0 0;
  max-width: 560px;
}

@media (max-width: 1120px) {
  .hero,
  .layout-grid {
    grid-template-columns: 1fr;
  }

  .head-note {
    max-width: none;
    text-align: left;
  }
}

@media (max-width: 720px) {
  .host-shell {
    padding: 18px;
  }

  .actions {
    flex-direction: column;
  }

  .primary,
  .ghost {
    width: 100%;
  }
}
</style>
