<script setup>
import axios from 'axios'
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import AppFooter from './components/layout/AppFooter.vue'
import MenuSidebar from './components/layout/MenuSidebar.vue'
import DashboardScreen from './components/screens/DashboardScreen.vue'
import ProfileScreen from './components/screens/ProfileScreen.vue'
import TasksScreen from './components/screens/TasksScreen.vue'
import GoogleLogin from './components/GoogleLogin.vue'

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/OctoFlow/api').replace(/\/$/, '')
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

const RemoteGithubWorkspaceSummaryCard = defineRemote(() => import('remoteApp/GithubWorkspaceSummaryCard'), 'GithubWorkspaceSummaryCard')
const RemoteGithubIssueComposerPanel = defineRemote(() => import('remoteApp/GithubIssueComposerPanel'), 'GithubIssueComposerPanel')
const RemoteGithubProjectsCard = defineRemote(() => import('remoteApp/GithubProjectsCard'), 'GithubProjectsCard')

const loginForm = reactive({
  email: 'admin@example.com',
  password: '',
})

const navigationItems = [
  {
    key: 'dashboard',
    short: 'DB',
    label: 'Dashboard',
    description: 'Painel central com resumo, composer e Projects',
    eyebrow: 'OctoFlow Dashboard',
    title: 'Painel operacional do OctoFlow',
    descriptionLong: 'O host monta resumo, composer e Projects separadamente e concentra a operacao principal do GitHub em um dashboard unico.',
  },
  {
    key: 'tasks',
    short: 'TK',
    label: 'Tarefas',
    description: 'Issues atribuidas ou backlog completo do repositorio',
    eyebrow: 'Issues do GitHub',
    title: 'Inbox operacional do OctoFlow',
    descriptionLong: 'Alterne entre suas issues atribuidas e todas as issues do repositorio, com troca rapida entre repositorios acessiveis.',
  },
  {
    key: 'profile',
    short: 'PR',
    label: 'Perfil',
    description: 'Emails vinculados e configuracao do GitHub',
    eyebrow: 'Perfil',
    title: 'Identidade e configuracoes',
    descriptionLong: 'Centralize emails vinculados, email padrao e configuracao do GitHub em um unico lugar.',
  },
]

const activeView = ref('dashboard')
const sidebarCollapsed = ref(false)
const isCompactViewport = ref(false)
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
const activeViewConfig = computed(() => navigationItems.find((item) => item.key === activeView.value) || navigationItems[0])
const currentDisplayEmail = computed(() => currentUser.value?.defaultEmail || currentUser.value?.email || 'Acesso publico')
const effectiveSidebarCollapsed = computed(() => !isCompactViewport.value && sidebarCollapsed.value)

let viewportMediaQuery = null
let removeViewportListener = null

onMounted(async () => {
  viewportMediaQuery = window.matchMedia('(max-width: 1180px)')
  isCompactViewport.value = viewportMediaQuery.matches
  const handleViewportChange = (event) => {
    isCompactViewport.value = event.matches
  }

  if (typeof viewportMediaQuery.addEventListener === 'function') {
    viewportMediaQuery.addEventListener('change', handleViewportChange)
    removeViewportListener = () => viewportMediaQuery?.removeEventListener('change', handleViewportChange)
  } else {
    viewportMediaQuery.addListener(handleViewportChange)
    removeViewportListener = () => viewportMediaQuery?.removeListener(handleViewportChange)
  }

  sidebarCollapsed.value = window.localStorage.getItem('host.sidebar.collapsed') === '1'

  const refreshed = await refreshToken(false)
  if (refreshed) {
    await loadCurrentUser(false)
  }
})

onBeforeUnmount(() => {
  removeViewportListener?.()
})

watch(isAuthenticated, (authenticated) => {
  if (!authenticated) {
    activeView.value = 'dashboard'
    googleError.value = ''
  }
})

watch(sidebarCollapsed, (collapsed) => {
  window.localStorage.setItem('host.sidebar.collapsed', collapsed ? '1' : '0')
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
    activeView.value = 'dashboard'
    statusMessage.value = 'Login realizado com sucesso.'
  } catch (error) {
    authError.value = extractHttpMessage(error, 'Falha no login.')
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

    activeView.value = 'dashboard'
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

function navigateTo(viewKey) {
  if (!isAuthenticated.value) {
    return
  }

  activeView.value = viewKey
  statusMessage.value = ''
}

function toggleSidebar() {
  if (isCompactViewport.value) {
    return
  }

  sidebarCollapsed.value = !sidebarCollapsed.value
}

function handleSessionUpdated(session) {
  if (typeof session?.token === 'string' && session.token.trim() !== '') {
    setAccessToken(session.token.trim())
  }

  if (session?.user) {
    currentUser.value = session.user
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
  <div class="app-shell" :class="{ collapsed: effectiveSidebarCollapsed, compact: isCompactViewport }">
    <MenuSidebar
      :items="navigationItems"
      :active-key="activeView"
      :authenticated="isAuthenticated"
      :current-user="currentUser"
      :collapsed="effectiveSidebarCollapsed"
      :collapsible="!isCompactViewport"
      @navigate="navigateTo"
      @toggle-collapse="toggleSidebar"
      @refresh="refreshToken"
      @logout="logout"
    />

    <div class="app-main">
      <header v-if="!isAuthenticated || activeView !== 'tasks'" class="page-header surface-card">
        <div>
          <p class="section-kicker">{{ activeViewConfig.eyebrow }}</p>
          <h1>{{ isAuthenticated ? activeViewConfig.title : 'Autenticacao e acesso' }}</h1>
          <p class="muted-copy">
            {{ isAuthenticated ? activeViewConfig.descriptionLong : 'Entre com email/senha ou Google para liberar Dashboard, Tarefas e Perfil.' }}
          </p>
        </div>

        <div class="header-spotlight">
          <div class="stat-chip">
            <span>Usuario</span>
            <strong>{{ currentDisplayEmail }}</strong>
          </div>
          <div class="stat-chip">
            <span>CSRF header</span>
            <strong>{{ csrfHeaderName }}</strong>
          </div>
        </div>
      </header>

      <main class="page-stage">
        <section v-if="!isAuthenticated" class="auth-stage">
          <article class="surface-card auth-copy-card">
            <p class="section-kicker">OctoFlow</p>
            <h2>Um shell mais limpo para trabalhar com perfil, tarefas e GitHub</h2>
            <p class="muted-copy">
              O frontend agora organiza a sessao em areas claras e monta o dashboard remoto por componentes, em vez de puxar uma tela inteira do federado.
            </p>

            <div class="highlight-grid">
              <div class="highlight-card">
                <strong>Perfil</strong>
                <p>Emails vinculados, email padrao e configuracao do GitHub do usuario.</p>
              </div>
              <div class="highlight-card">
                <strong>Tarefas</strong>
                <p>Issues atribuidas a voce com selecao de repositorio e atualizacao do conteudo.</p>
              </div>
              <div class="highlight-card">
                <strong>Dashboard</strong>
                <p>Resumo do repositorio, criacao de issue e leitura dos Projects em um unico painel.</p>
              </div>
            </div>
          </article>

          <article class="surface-card auth-form-card">
            <div>
              <p class="section-kicker">Sessao</p>
              <h2>Entrar na aplicacao</h2>
            </div>

            <form class="auth-form" @submit.prevent="handleLogin">
              <label class="field">
                <span>Email</span>
                <input v-model="loginForm.email" type="email" autocomplete="username" required>
              </label>

              <label class="field">
                <span>Senha</span>
                <input v-model="loginForm.password" type="password" autocomplete="current-password" required>
              </label>

              <button class="button-primary" type="submit" :disabled="loginLoading || actionLoading">
                {{ loginLoading ? 'Entrando...' : 'Entrar' }}
              </button>

              <GoogleLogin
                :api-client="apiClient"
                :is-loading="loginLoading || actionLoading"
                :button-width="280"
                @credential="handleGoogleCredential"
                @error="handleGoogleLoginError"
              />
            </form>

            <p v-if="authError" class="feedback-banner error">{{ authError }}</p>
            <p v-if="googleError" class="feedback-banner error">{{ googleError }}</p>
            <p v-if="statusMessage" class="feedback-banner success">{{ statusMessage }}</p>
          </article>
        </section>

        <DashboardScreen
          v-else-if="activeView === 'dashboard'"
          :request="authRequest"
          :current-user="currentUser"
          :summary-component="RemoteGithubWorkspaceSummaryCard"
          :composer-component="RemoteGithubIssueComposerPanel"
          :projects-component="RemoteGithubProjectsCard"
          :federation-error="federationError"
        />

        <TasksScreen
          v-else-if="activeView === 'tasks'"
          :request="authRequest"
          :current-user="currentUser"
        />

        <ProfileScreen
          v-else
          :request="authRequest"
          :api-client="apiClient"
          :current-user="currentUser"
          @session-updated="handleSessionUpdated"
        />
      </main>

      <AppFooter
        :current-user="currentUser"
        :active-label="isAuthenticated ? activeViewConfig.label : 'Acesso publico'"
        :api-base="API_BASE_URL"
      />
    </div>
  </div>
</template>
