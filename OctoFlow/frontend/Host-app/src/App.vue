<script setup>
import axios from 'axios'
import { storeToRefs } from 'pinia'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppFooter from './components/layout/AppFooter.vue'
import AppNotification from './components/layout/AppNotification.vue'
import MenuSidebar from './components/layout/MenuSidebar.vue'
import DashboardScreen from './components/screens/DashboardScreen.vue'
import FinanceScreen from './components/screens/FinanceScreen.vue'
import ProfileScreen from './components/screens/ProfileScreen.vue'
import TestScreen from './components/screens/TestScreen.vue'
import TasksScreen from './components/screens/TasksScreen.vue'
import GoogleLogin from './components/GoogleLogin.vue'
import { navigationItems } from './constants/navigation'
import { useAppNavigationStore } from './stores/appNavigationStore'
import { useAppShellStore } from './stores/appShellStore'
import { formatDateTime } from './utils/date'
import { extractHttpMessage } from './utils/httpErrors'
import {
  APP_THEME_OPTIONS,
  applyAccessibilityToDocument,
  DEFAULT_APP_THEME_KEY,
  DEFAULT_UI_SETTINGS,
  applyThemeToDocument,
  getThemeDefinition,
  hasUiSettingsLoadedInSession,
  markUiSettingsLoadedInSession,
  normalizeUiSettingsPayload,
  readStoredUiSettings,
  writeStoredUiSettings,
} from './theme'

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/OctoFlow/api').replace(/\/$/, '')
const DEFAULT_CSRF_HEADER_NAME = 'X-CSRF-Token'
const DEFAULT_CSRF_ACTION_HEADER_NAME = 'X-CSRF-Action'
const CLIENT_BROWSER_ID_STORAGE_KEY = 'octoflow.auth.browser-id'
const CLIENT_TAB_ID_STORAGE_KEY = 'octoflow.auth.tab-id'
const CLIENT_LOCATION_HINT_STORAGE_KEY = 'octoflow.auth.location-hint'
const PUBLIC_CSRF_ACTIONS = {
  'auth.login': { method: 'POST', path: '/auth/login' },
  'auth.register': { method: 'POST', path: '/auth/register' },
  'auth.google': { method: 'POST', path: '/auth/google' },
  'auth.refresh': { method: 'POST', path: '/auth/refresh' },
  'auth.logout': { method: 'POST', path: '/auth/logout' },
}

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
})

const loginForm = reactive({
  email: '',
  password: '',
})
const registerForm = reactive({
  email: '',
  password: '',
  confirmPassword: '',
})
const googlePasswordSetupForm = reactive({
  verificationCode: '',
  password: '',
  confirmPassword: '',
})

const appShellStore = useAppShellStore()
const appNavigationStore = useAppNavigationStore()
const { isCompactViewport, effectiveSidebarExpanded } = storeToRefs(appShellStore)
const { activeViewKey: activeView, isFinanceView, financeSectionByView } = storeToRefs(appNavigationStore)
const appRouter = useRouter()
const appRoute = useRoute()

const authMode = ref('login')
const accessToken = ref('')
const currentUser = ref(null)
const loginLoading = ref(false)
const actionLoading = ref(false)
const notification = ref(null)
const activeThemeKey = ref(DEFAULT_APP_THEME_KEY)
const uiSettings = ref({ ...DEFAULT_UI_SETTINGS })
const availableThemes = APP_THEME_OPTIONS
const screenAuthValidationInProgress = ref(false)
const googlePasswordSetupStep = ref('verify')
const googlePasswordSetupLoading = ref(false)
const authBootstrapLoading = ref(true)

const isAuthenticated = computed(() => Boolean(accessToken.value && currentUser.value))
const requiresGooglePasswordSetup = computed(() => currentUser.value?.passwordSetupRequired === true)
const googlePasswordSetupCodeExpiresAtLabel = computed(() => {
  const rawExpirationDateTime = typeof currentUser.value?.passwordSetupCodeExpiresAt === 'string'
    ? currentUser.value.passwordSetupCodeExpiresAt.trim()
    : ''

  if (rawExpirationDateTime === '') {
    return ''
  }

  return formatDateTime(rawExpirationDateTime)
})

let viewportMediaQuery = null
let removeViewportListener = null
let notificationSeed = 0
let uiSettingsSyncKey = ''
let uiSettingsSyncPromise = null
let refreshTokenPromise = null
let clientBrowserId = ''
let clientTabId = ''
let clientFingerprintHash = ''
let clientLocationHint = ''

onMounted(async () => {
  initializeClientIdentity()
  void warmClientLocationHint()

  viewportMediaQuery = window.matchMedia('(max-width: 1180px)')
  appShellStore.setCompactViewport(viewportMediaQuery.matches)
  const handleViewportChange = (event) => {
    appShellStore.setCompactViewport(event.matches)
  }

  if (typeof viewportMediaQuery.addEventListener === 'function') {
    viewportMediaQuery.addEventListener('change', handleViewportChange)
    removeViewportListener = () => viewportMediaQuery?.removeEventListener('change', handleViewportChange)
  } else {
    viewportMediaQuery.addListener(handleViewportChange)
    removeViewportListener = () => viewportMediaQuery?.removeListener(handleViewportChange)
  }

  try {
    const restoreIsAvailable = await isRefreshSessionAvailable()
    if (!restoreIsAvailable) {
      currentUser.value = null
      return
    }

    const refreshed = await refreshToken(false)
    if (!refreshed) {
      currentUser.value = null
    }
  } finally {
    authBootstrapLoading.value = false
  }
})

onBeforeUnmount(() => {
  removeViewportListener?.()
})

watch(isAuthenticated, (authenticated) => {
  if (!authenticated) {
    void setActiveView('dashboard', { replaceHistory: true })
  }
})

watch(
  () => `${String(appRoute.name || '')}|${String(appRoute.params?.section || '')}`,
  () => {
    appNavigationStore.syncFromRoute(appRoute)
  },
  {
    immediate: true,
  },
)

watch(
  () => [
    currentUser.value?.id ?? '',
    currentUser.value?.defaultEmail ?? '',
    currentUser.value?.email ?? '',
  ].join('|'),
  () => {
    void syncUiSettingsForSession(currentUser.value)
  },
  {
    immediate: true,
  },
)

watch(
  () => [
    currentUser.value?.passwordSetupRequired === true ? 'required' : 'not-required',
    currentUser.value?.passwordSetupEmailValidated === true ? 'validated' : 'not-validated',
  ].join('|'),
  () => {
    synchronizeGooglePasswordSetupStep(currentUser.value)
  },
  {
    immediate: true,
  },
)

async function handleLogin() {
  loginLoading.value = true

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

    applyAuthenticatedSessionData(data)

    if (!currentUser.value) {
      await loadCurrentUser(false)
    }

    loginForm.password = ''
    await setActiveView('dashboard', { replaceHistory: true })
    await verifyAuthForScreenEntry('dashboard')
    showNotification('Login realizado com sucesso.', 'success')
  } catch (error) {
    showNotification(extractHttpMessage(error, 'Falha no login.'), 'error')
  } finally {
    loginLoading.value = false
  }
}

async function handleRegister() {
  loginLoading.value = true

  try {
    const { data } = await requestWithCsrf({
      url: '/auth/register',
      method: 'POST',
      csrfActionId: 'auth.register',
      data: {
        email: registerForm.email,
        password: registerForm.password,
        confirmPassword: registerForm.confirmPassword,
      },
    })

    applyAuthenticatedSessionData(data)

    if (!currentUser.value) {
      await loadCurrentUser(false)
    }

    registerForm.email = ''
    registerForm.password = ''
    registerForm.confirmPassword = ''
    loginForm.email = currentUser.value?.defaultEmail || currentUser.value?.email || ''
    loginForm.password = ''
    authMode.value = 'login'
    await setActiveView('dashboard', { replaceHistory: true })
    await verifyAuthForScreenEntry('dashboard')
    showNotification('Conta criada com sucesso.', 'success')
  } catch (error) {
    showNotification(extractHttpMessage(error, 'Falha ao criar conta.'), 'error')
  } finally {
    loginLoading.value = false
  }
}

async function handleGoogleCredential(credential) {
  const wasLoading = loginLoading.value
  loginLoading.value = true

  try {
    const { data } = await requestWithCsrf({
      url: '/auth/google',
      method: 'POST',
      csrfActionId: 'auth.google',
      data: { credential },
    })

    applyAuthenticatedSessionData(data)

    if (!currentUser.value) {
      await loadCurrentUser(false)
    }

    await setActiveView('dashboard', { replaceHistory: true })
    loginForm.password = ''
    resetGooglePasswordSetupForm()

    if (currentUser.value?.passwordSetupRequired === true) {
      synchronizeGooglePasswordSetupStep(currentUser.value)
      showNotification('Confirme o código enviado para seu e-mail e crie sua senha para habilitar login com e-mail + senha.', 'warning')
      return
    }

    await verifyAuthForScreenEntry('dashboard')
    showNotification('Login com Google realizado com sucesso.', 'success')
  } catch (error) {
    showNotification(extractHttpMessage(error, 'Falha no login com Google.'), 'error')
  } finally {
    loginLoading.value = wasLoading
  }
}

function handleGoogleLoginError(error) {
  showNotification(error, 'error')
}

async function resendGooglePasswordSetupCode() {
  if (!isAuthenticated.value || googlePasswordSetupLoading.value) {
    return
  }

  googlePasswordSetupLoading.value = true

  try {
    const { data } = await authRequest({
      url: '/auth/google/password-setup/send-code',
      method: 'POST',
      csrfActionId: 'auth.google.password-setup.send-code',
    })

    if (data?.token) {
      setAccessToken(data.token)
    }

    if (data?.user) {
      currentUser.value = data.user
    }

    googlePasswordSetupStep.value = 'verify'
    googlePasswordSetupForm.verificationCode = ''
    googlePasswordSetupForm.password = ''
    googlePasswordSetupForm.confirmPassword = ''
    showNotification(data?.message || 'Código reenviado com sucesso.', 'success')
  } catch (error) {
    showNotification(extractHttpMessage(error, 'Não foi possível reenviar o código de validação.'), 'error')
  } finally {
    googlePasswordSetupLoading.value = false
  }
}

async function verifyGooglePasswordSetupCode() {
  if (!isAuthenticated.value || googlePasswordSetupLoading.value) {
    return
  }

  const verificationCode = googlePasswordSetupForm.verificationCode.trim()
  if (verificationCode === '') {
    showNotification('Informe o código recebido por e-mail.', 'warning')
    return
  }

  googlePasswordSetupLoading.value = true

  try {
    const { data } = await authRequest({
      url: '/auth/google/password-setup/verify-code',
      method: 'POST',
      csrfActionId: 'auth.google.password-setup.verify-code',
      data: { code: verificationCode },
    })

    if (data?.token) {
      setAccessToken(data.token)
    }

    if (data?.user) {
      currentUser.value = data.user
    }

    googlePasswordSetupStep.value = 'password'
    googlePasswordSetupForm.verificationCode = ''
    showNotification(data?.message || 'Código validado com sucesso.', 'success')
  } catch (error) {
    showNotification(extractHttpMessage(error, 'Não foi possível validar o código informado.'), 'error')
  } finally {
    googlePasswordSetupLoading.value = false
  }
}

async function createGooglePasswordSetupPassword() {
  if (!isAuthenticated.value || googlePasswordSetupLoading.value) {
    return
  }

  if (googlePasswordSetupForm.password.trim() === '') {
    showNotification('Informe a senha que deseja cadastrar.', 'warning')
    return
  }

  googlePasswordSetupLoading.value = true

  try {
    const { data } = await authRequest({
      url: '/auth/google/password-setup/set-password',
      method: 'POST',
      csrfActionId: 'auth.google.password-setup.set-password',
      data: {
        password: googlePasswordSetupForm.password,
        confirmPassword: googlePasswordSetupForm.confirmPassword,
      },
    })

    if (data?.token) {
      setAccessToken(data.token)
    }

    if (data?.user) {
      currentUser.value = data.user
    }

    resetGooglePasswordSetupForm()
    googlePasswordSetupStep.value = 'verify'
    await setActiveView('dashboard', { replaceHistory: true })
    await verifyAuthForScreenEntry('dashboard')
    showNotification(data?.message || 'Senha criada com sucesso.', 'success')
  } catch (error) {
    showNotification(extractHttpMessage(error, 'Não foi possível criar a senha.'), 'error')
  } finally {
    googlePasswordSetupLoading.value = false
  }
}

function switchAuthMode(mode) {
  authMode.value = mode === 'register' ? 'register' : 'login'
}

function resetGooglePasswordSetupForm() {
  googlePasswordSetupForm.verificationCode = ''
  googlePasswordSetupForm.password = ''
  googlePasswordSetupForm.confirmPassword = ''
}

function synchronizeGooglePasswordSetupStep(userPayload = currentUser.value) {
  if (userPayload?.passwordSetupRequired !== true) {
    googlePasswordSetupStep.value = 'verify'
    resetGooglePasswordSetupForm()
    return
  }

  googlePasswordSetupStep.value = userPayload?.passwordSetupEmailValidated === true ? 'password' : 'verify'

  if (googlePasswordSetupStep.value === 'password') {
    googlePasswordSetupForm.verificationCode = ''
  } else {
    googlePasswordSetupForm.password = ''
    googlePasswordSetupForm.confirmPassword = ''
  }
}

function applyAuthenticatedSessionData(sessionData) {
  const nextToken = typeof sessionData?.token === 'string' ? sessionData.token : ''
  const nextUser = sessionData?.user || null

  setAccessToken(nextToken)
  currentUser.value = nextUser
}

async function verifyAuthForScreenEntry(screenKey = activeView.value) {
  if (!isAuthenticated.value || screenAuthValidationInProgress.value) {
    return false
  }

  screenAuthValidationInProgress.value = true

  try {
    const response = await authRequest(
      {
        url: '/auth/me',
        method: 'GET',
      },
      true,
    )

    const validatedUser = response.data?.user || null
    if (!validatedUser) {
      clearAuth()
      showNotification('Sessao invalida. Faça login novamente.', 'warning')
      return false
    }

    currentUser.value = validatedUser
    return true
  } catch (requestError) {
    if (axios.isAxiosError(requestError) && requestError.response?.status === 401) {
      clearAuth()
      showNotification('Sessao expirada. Faça login novamente.', 'warning')
      return false
    }

    showNotification(
      extractHttpMessage(requestError, `Nao foi possivel validar a autenticação ao abrir a tela ${screenKey}.`),
      'warning',
    )
    return false
  } finally {
    screenAuthValidationInProgress.value = false
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

async function refreshToken(useLoading = true) {
  if (refreshTokenPromise) {
    return refreshTokenPromise
  }

  if (useLoading) {
    actionLoading.value = true
  }

  refreshTokenPromise = (async () => {
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
    } catch (error) {
      if (shouldClearAuthAfterRefreshFailure(error)) {
        clearAuth()
      }

      return false
    } finally {
      if (useLoading) {
        actionLoading.value = false
      }

      refreshTokenPromise = null
    }
  })()

  return refreshTokenPromise
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
    clearBrowserState()
    clearAuth()
    showNotification('Sessao encerrada com sucesso.', 'success')
    actionLoading.value = false
  }
}

async function setActiveView(viewKey, options = {}) {
  await appNavigationStore.navigateToView(appRouter, viewKey, options)
}

async function navigateTo(viewKey) {
  if (!isAuthenticated.value) {
    return
  }

  const normalizedViewKey = appNavigationStore.normalizeViewKey(viewKey)

  const screenAuthIsValid = await verifyAuthForScreenEntry(normalizedViewKey)
  if (!screenAuthIsValid) {
    return
  }

  await setActiveView(normalizedViewKey)
}

function expandSidebar() {
  appShellStore.expandSidebar()
}

function collapseSidebar() {
  appShellStore.collapseSidebar()
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
    throw new Error('Toda requisição mutavel precisa informar csrfActionId.')
  }

  return actionId
}

function isPublicCsrfAction(actionId, method, path) {
  const definition = PUBLIC_CSRF_ACTIONS[actionId]

  return Boolean(definition && definition.method === method && definition.path === path)
}

function shouldClearAuthAfterRefreshFailure(error) {
  if (!axios.isAxiosError(error)) {
    return false
  }

  return error.response?.status === 401
}

function buildRandomIdentifier(prefixLabel) {
  if (typeof window !== 'undefined' && window.crypto && typeof window.crypto.randomUUID === 'function') {
    return `${prefixLabel}-${window.crypto.randomUUID()}`
  }

  const randomSuffix = `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
  return `${prefixLabel}-${randomSuffix}`
}

function computeClientFingerprintHash() {
  if (typeof window === 'undefined') {
    return ''
  }

  const language = String(window.navigator?.language || '').trim().toLowerCase()
  const timezone = String(Intl.DateTimeFormat().resolvedOptions().timeZone || '').trim().toLowerCase()
  const platform = String(window.navigator?.platform || '').trim().toLowerCase()
  const userAgent = String(window.navigator?.userAgent || '').trim().toLowerCase()
  const screenWidth = Number(window.screen?.width || 0)
  const screenHeight = Number(window.screen?.height || 0)
  const pixelRatio = Number(window.devicePixelRatio || 1)
  const rawFingerprint = `${language}|${timezone}|${platform}|${screenWidth}x${screenHeight}|${pixelRatio}|${userAgent}`

  if (rawFingerprint === '') {
    return ''
  }

  let accumulatedHash = 0
  for (let characterIndex = 0; characterIndex < rawFingerprint.length; characterIndex += 1) {
    const currentCharacterCode = rawFingerprint.charCodeAt(characterIndex)
    accumulatedHash = ((accumulatedHash << 5) - accumulatedHash) + currentCharacterCode
    accumulatedHash |= 0
  }

  return `fp-${Math.abs(accumulatedHash)}`
}

function buildFallbackLocationHint() {
  if (typeof window === 'undefined') {
    return 'none'
  }

  const timezone = String(Intl.DateTimeFormat().resolvedOptions().timeZone || '').trim().toLowerCase()
  const language = String(window.navigator?.language || '').trim().toLowerCase()

  if (timezone === '' && language === '') {
    return 'none'
  }

  return `tz:${timezone || 'unknown'}|lang:${language || 'unknown'}`
}

function normalizeLocationCoordinate(rawCoordinate) {
  const numericCoordinate = Number(rawCoordinate)
  if (!Number.isFinite(numericCoordinate)) {
    return null
  }

  return (Math.round(numericCoordinate * 10) / 10).toFixed(1)
}

async function warmClientLocationHint() {
  if (typeof window === 'undefined') {
    return
  }

  const geolocationApi = window.navigator?.geolocation
  if (!geolocationApi || typeof geolocationApi.getCurrentPosition !== 'function') {
    return
  }

  const permissionsApi = window.navigator?.permissions
  if (!permissionsApi || typeof permissionsApi.query !== 'function') {
    return
  }

  try {
    const geolocationPermission = await permissionsApi.query({ name: 'geolocation' })
    if (geolocationPermission.state !== 'granted') {
      return
    }
  } catch {
    return
  }

  await new Promise((resolveLocation) => {
    geolocationApi.getCurrentPosition(
      (position) => {
        const latitude = normalizeLocationCoordinate(position.coords?.latitude)
        const longitude = normalizeLocationCoordinate(position.coords?.longitude)

        if (latitude !== null && longitude !== null) {
          clientLocationHint = `geo:${latitude},${longitude}`

          try {
            window.localStorage.setItem(CLIENT_LOCATION_HINT_STORAGE_KEY, clientLocationHint)
          } catch {
            // localStorage pode estar indisponível em modos restritos do navegador.
          }
        }

        resolveLocation()
      },
      () => resolveLocation(),
      {
        enableHighAccuracy: false,
        timeout: 2500,
        maximumAge: 900000,
      },
    )
  })
}

function initializeClientIdentity() {
  if (typeof window === 'undefined') {
    return
  }

  if (clientBrowserId === '') {
    let persistedBrowserIdentifier = buildRandomIdentifier('browser')

    try {
      persistedBrowserIdentifier = String(window.localStorage.getItem(CLIENT_BROWSER_ID_STORAGE_KEY) || '').trim()
      if (persistedBrowserIdentifier === '') {
        persistedBrowserIdentifier = buildRandomIdentifier('browser')
        window.localStorage.setItem(CLIENT_BROWSER_ID_STORAGE_KEY, persistedBrowserIdentifier)
      }
    } catch {
      // localStorage pode estar indisponível em modos restritos do navegador.
    }

    clientBrowserId = persistedBrowserIdentifier
  }

  if (clientTabId === '') {
    let persistedTabIdentifier = buildRandomIdentifier('tab')

    try {
      persistedTabIdentifier = String(window.sessionStorage.getItem(CLIENT_TAB_ID_STORAGE_KEY) || '').trim()
      if (persistedTabIdentifier === '') {
        persistedTabIdentifier = buildRandomIdentifier('tab')
        window.sessionStorage.setItem(CLIENT_TAB_ID_STORAGE_KEY, persistedTabIdentifier)
      }
    } catch {
      // sessionStorage pode estar indisponível em modos restritos do navegador.
    }

    clientTabId = persistedTabIdentifier
  }

  if (clientFingerprintHash === '') {
    clientFingerprintHash = computeClientFingerprintHash()
  }

  if (clientLocationHint === '') {
    clientLocationHint = buildFallbackLocationHint()

    try {
      const persistedLocationHint = String(window.localStorage.getItem(CLIENT_LOCATION_HINT_STORAGE_KEY) || '').trim()
      if (persistedLocationHint !== '') {
        clientLocationHint = persistedLocationHint
      }
    } catch {
      // localStorage pode estar indisponível em modos restritos do navegador.
    }
  }
}

function buildClientIdentityHeaders(headers = {}) {
  initializeClientIdentity()

  const identityHeaders = {}
  if (clientTabId !== '') {
    identityHeaders['X-Tab-Id'] = clientTabId
  }
  if (clientBrowserId !== '') {
    identityHeaders['X-Browser-Id'] = clientBrowserId
  }
  if (clientFingerprintHash !== '') {
    identityHeaders['X-Browser-Fingerprint'] = clientFingerprintHash
  }
  if (clientLocationHint !== '') {
    identityHeaders['X-Client-Location'] = clientLocationHint
  }

  return {
    ...identityHeaders,
    ...headers,
  }
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
      headers: buildAuthorizedHeaders(buildClientIdentityHeaders(config?.headers || {})),
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
    const response = await apiClient.post('/auth/csrf/challenge', payload, {
      headers: buildClientIdentityHeaders(),
    })
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
  const headers = buildClientIdentityHeaders(config?.headers || {})

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
      throw new Error('O backend nao retornou um CSRF token valido para esta ação.')
    }

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

async function isRefreshSessionAvailable() {
  try {
    const { data } = await apiClient.request({
      url: '/auth/session/restore-available',
      method: 'GET',
      headers: buildClientIdentityHeaders(),
    })

    return data?.restoreAvailable === true
  } catch {
    return false
  }
}

function setAccessToken(token) {
  accessToken.value = token
}

function clearAuth() {
  setAccessToken('')
  currentUser.value = null
  authMode.value = 'login'
  loginForm.password = ''
  registerForm.email = ''
  registerForm.password = ''
  registerForm.confirmPassword = ''
  googlePasswordSetupLoading.value = false
  googlePasswordSetupStep.value = 'verify'
  resetGooglePasswordSetupForm()
  uiSettingsSyncKey = ''
  uiSettingsSyncPromise = null
  applyUiSettingsLocally(DEFAULT_UI_SETTINGS)
}

function normalizeUiSettings(settings = {}, baseSettings = uiSettings.value) {
  return normalizeUiSettingsPayload(settings, baseSettings)
}

function applyUiSettingsLocally(settings = {}, baseSettings = uiSettings.value) {
  const nextSettings = normalizeUiSettings(settings, baseSettings)
  uiSettings.value = nextSettings
  activeThemeKey.value = nextSettings.themeKey
  applyThemeToDocument(nextSettings.themeKey, nextSettings.customThemePalette)
  applyAccessibilityToDocument(nextSettings)

  return nextSettings
}

function applyCachedUiSettings(user = currentUser.value) {
  const cachedSettings = readStoredUiSettings(user)

  return applyUiSettingsLocally(
    cachedSettings || DEFAULT_UI_SETTINGS,
    DEFAULT_UI_SETTINGS,
  )
}

async function syncUiSettingsForSession(user = currentUser.value, options = {}) {
  if (!user) {
    applyUiSettingsLocally(DEFAULT_UI_SETTINGS)
    return null
  }

  const cachedSettings = applyCachedUiSettings(user)
  if (!options.forceServer && hasUiSettingsLoadedInSession(user)) {
    return cachedSettings
  }

  const requestKey = String(user.id || user.email || '')
  if (uiSettingsSyncPromise && uiSettingsSyncKey === requestKey) {
    return uiSettingsSyncPromise
  }

  uiSettingsSyncKey = requestKey
  uiSettingsSyncPromise = (async () => {
    try {
      const { data } = await authRequest({
        url: '/ui/settings',
        method: 'GET',
      })

      const nextSettings = normalizeUiSettings(data?.settings || {})

      writeStoredUiSettings(user, nextSettings)
      markUiSettingsLoadedInSession(user)
      applyUiSettingsLocally(nextSettings)

      return nextSettings
    } catch {
      return cachedSettings
    } finally {
      uiSettingsSyncKey = ''
      uiSettingsSyncPromise = null
    }
  })()

  return uiSettingsSyncPromise
}

async function updateUiSettings(partialSettings = {}, options = {}) {
  const previousSettings = normalizeUiSettings(uiSettings.value, DEFAULT_UI_SETTINGS)
  const nextSettings = applyUiSettingsLocally(partialSettings, previousSettings)
  const currentSettingsUser = currentUser.value
  const payload = {}

  for (const fieldKey of ['themeKey', 'colorVisionMode', 'colorVisionIntensity', 'highContrastEnabled', 'fontScale', 'layoutDensityMode', 'layoutDensityScale', 'customThemePalette']) {
    if (Object.prototype.hasOwnProperty.call(partialSettings, fieldKey)) {
      payload[fieldKey] = nextSettings[fieldKey]
    }
  }

  writeStoredUiSettings(currentSettingsUser, nextSettings)
  if (currentSettingsUser) {
    markUiSettingsLoadedInSession(currentSettingsUser)
  }

  if (currentSettingsUser) {
    try {
      const { data } = await authRequest({
        url: '/ui/settings',
        method: 'PATCH',
        csrfActionId: 'ui.settings.update',
        data: payload,
      })

      const persistedSettings = normalizeUiSettings({
        ...nextSettings,
        ...(data?.settings || {}),
      })

      writeStoredUiSettings(currentSettingsUser, persistedSettings)
      applyUiSettingsLocally(persistedSettings)

      if (options.notify !== false) {
        if (typeof options.successMessage === 'string' && options.successMessage.trim() !== '') {
          showNotification(options.successMessage.trim(), 'success')
        } else if (Object.prototype.hasOwnProperty.call(payload, 'themeKey')) {
          showNotification(`Tema ${getThemeDefinition(persistedSettings.themeKey).label} aplicado.`, 'success')
        } else if (Object.keys(payload).length > 0) {
          showNotification('Preferencias de acessibilidade atualizadas.', 'success')
        }
      }

      return persistedSettings
    } catch (error) {
      writeStoredUiSettings(currentSettingsUser, previousSettings)
      applyUiSettingsLocally(previousSettings)
      showNotification(extractHttpMessage(error, 'Nao foi possivel salvar as preferencias de interface.'), 'error')

      throw error
    }
  }

  if (options.notify !== false) {
    if (typeof options.successMessage === 'string' && options.successMessage.trim() !== '') {
      showNotification(options.successMessage.trim(), 'success')
    } else if (Object.prototype.hasOwnProperty.call(payload, 'themeKey')) {
      showNotification(`Tema ${getThemeDefinition(nextSettings.themeKey).label} aplicado.`, 'success')
    } else if (Object.keys(payload).length > 0) {
      showNotification('Preferencias de acessibilidade atualizadas.', 'success')
    }
  }

  return nextSettings
}

function setAppTheme(themeKey, options = {}) {
  return updateUiSettings({ themeKey }, options)
}

function clearBrowserState() {
  if (typeof window !== 'undefined') {
    try {
      window.localStorage.clear()
    } catch {
      // noop
    }

    try {
      window.sessionStorage.clear()
    } catch {
      // noop
    }
  }

  if (typeof document === 'undefined') {
    return
  }

  const cookieEntries = document.cookie.split(';')
  const hostname = typeof window !== 'undefined' ? window.location.hostname : ''
  const domainVariants = hostname
    ? hostname
        .split('.')
        .map((_, index, parts) => `.${parts.slice(index).join('.')}`)
    : []

  for (const entry of cookieEntries) {
    const [rawName] = entry.split('=')
    const name = rawName?.trim()

    if (!name) {
      continue
    }

    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT`

    for (const domain of domainVariants) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=${domain}`
    }
  }
}

function showNotification(payload, type = 'info') {
  const message = typeof payload === 'string'
    ? payload.trim()
    : typeof payload?.message === 'string'
      ? payload.message.trim()
      : ''

  if (message === '') {
    return
  }

  const notificationType = typeof payload?.type === 'string' && payload.type.trim() !== ''
    ? payload.type.trim()
    : type
  const notificationDuration = Number(payload?.duration)

  notification.value = {
    id: ++notificationSeed,
    message,
    type: notificationType,
    duration: Number.isFinite(notificationDuration) && notificationDuration > 0
      ? notificationDuration
      : null,
  }
}

function clearNotification() {
  notification.value = null
}
</script>

<template>
  <div class="app-shell" :class="{ collapsed: isAuthenticated && !effectiveSidebarExpanded && !requiresGooglePasswordSetup, compact: isCompactViewport, 'no-sidebar': !isAuthenticated || requiresGooglePasswordSetup }">
    <AppNotification :notification="notification" @close="clearNotification" />

    <MenuSidebar
      v-if="isAuthenticated && !requiresGooglePasswordSetup"
      :items="navigationItems"
      :active-key="activeView"
      :authenticated="isAuthenticated"
      :current-user="currentUser"
      :compact="isCompactViewport"
      :expanded="effectiveSidebarExpanded"
      @navigate="navigateTo"
      @expand="expandSidebar"
      @collapse="collapseSidebar"
      @refresh="refreshToken"
      @logout="logout"
    />

    <div class="app-main">
      <main class="page-stage">
        <section v-if="authBootstrapLoading" class="auth-stage">
          <article class="surface-card auth-form-card">
            <div>
              <p class="section-kicker">Sessao</p>
              <h2>Restaurando sessão...</h2>
            </div>
            <p class="auth-help-text">Aguarde enquanto validamos seu acesso.</p>
          </article>
        </section>

        <section v-else-if="!isAuthenticated" class="auth-stage">
          <article class="surface-card auth-copy-card">
            <p class="section-kicker">OctoFlow</p>
            <h2>Um shell mais limpo para trabalhar com perfil, tarefas e GitHub</h2>
            <p class="muted-copy">
              O frontend agora organiza a sessao em areas claras e monta o dashboard remoto por componentes, em vez de puxar uma tela inteira do federado.
            </p>

            <div class="highlight-grid">
              <div class="highlight-card">
                <strong>Perfil</strong>
                <p>Emails vinculados, email padrao e configuração do GitHub do usuario.</p>
              </div>
              <div class="highlight-card">
                <strong>Tarefas</strong>
                <p>Issues atribuidas a voce com seleção de repositorio e atualização do conteudo.</p>
              </div>
              <div class="highlight-card">
                <strong>Dashboard</strong>
                <p>Resumo do repositorio, criação de issue e leitura dos Projects em um unico painel.</p>
              </div>
            </div>
          </article>

          <article class="surface-card auth-form-card">
            <div>
              <p class="section-kicker">Sessao</p>
              <h2>{{ authMode === 'register' ? 'Criar conta' : 'Entrar na aplicação' }}</h2>
            </div>

            <div class="auth-mode-switch" role="tablist" aria-label="Modos de autenticação">
              <button
                class="auth-mode-option"
                :class="{ active: authMode === 'login' }"
                type="button"
                @click="switchAuthMode('login')"
              >
                Entrar
              </button>
              <button
                class="auth-mode-option"
                :class="{ active: authMode === 'register' }"
                type="button"
                @click="switchAuthMode('register')"
              >
                Criar conta
              </button>
            </div>

            <form v-if="authMode === 'login'" class="auth-form" @submit.prevent="handleLogin">
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
                variant="system"
                button-label="Entrar com Google"
                :button-width="280"
                @credential="handleGoogleCredential"
                @error="handleGoogleLoginError"
              />
            </form>

            <form v-else class="auth-form" @submit.prevent="handleRegister">
              <label class="field">
                <span>Email</span>
                <input v-model="registerForm.email" type="email" autocomplete="email" required>
              </label>

              <label class="field">
                <span>Senha</span>
                <input v-model="registerForm.password" type="password" autocomplete="new-password" minlength="8" required>
              </label>

              <label class="field">
                <span>Confirmar senha</span>
                <input v-model="registerForm.confirmPassword" type="password" autocomplete="new-password" minlength="8" required>
              </label>

              <button class="button-primary" type="submit" :disabled="loginLoading || actionLoading">
                {{ loginLoading ? 'Criando conta...' : 'Criar conta' }}
              </button>

              <p class="auth-help-text">
                A conta e criada com email e senha, e a sessao e aberta automaticamente ao concluir.
              </p>
            </form>
          </article>
        </section>

        <section v-else-if="requiresGooglePasswordSetup" class="auth-stage">
          <article class="surface-card auth-copy-card">
            <p class="section-kicker">Validação obrigatória</p>
            <h2>Ative sua senha local</h2>
            <p class="muted-copy">
              Para liberar o login com e-mail e senha, valide o código enviado para <strong>{{ currentUser?.email }}</strong> e depois defina sua senha.
            </p>
            <div class="highlight-grid">
              <div class="highlight-card">
                <strong>1. Validar e-mail</strong>
                <p>Informe o código único enviado por e-mail.</p>
              </div>
              <div class="highlight-card">
                <strong>2. Criar senha</strong>
                <p>Após validar o código, você poderá cadastrar sua senha local.</p>
              </div>
            </div>
            <p v-if="googlePasswordSetupCodeExpiresAtLabel" class="auth-help-text">
              Código atual expira em: {{ googlePasswordSetupCodeExpiresAtLabel }}
            </p>
          </article>

          <article class="surface-card auth-form-card">
            <div>
              <p class="section-kicker">Segurança da conta</p>
              <h2>{{ googlePasswordSetupStep === 'verify' ? 'Validar código do e-mail' : 'Criar senha local' }}</h2>
            </div>

            <form v-if="googlePasswordSetupStep === 'verify'" class="auth-form" @submit.prevent="verifyGooglePasswordSetupCode">
              <label class="field">
                <span>Código de validação</span>
                <input
                  v-model="googlePasswordSetupForm.verificationCode"
                  type="text"
                  autocomplete="one-time-code"
                  minlength="6"
                  maxlength="16"
                  required
                >
              </label>

              <div class="form-actions">
                <button class="button-primary" type="submit" :disabled="googlePasswordSetupLoading || actionLoading">
                  {{ googlePasswordSetupLoading ? 'Validando...' : 'Validar código' }}
                </button>
                <button
                  class="button-secondary"
                  type="button"
                  :disabled="googlePasswordSetupLoading || actionLoading"
                  @click="resendGooglePasswordSetupCode"
                >
                  Reenviar código
                </button>
              </div>
            </form>

            <form v-else class="auth-form" @submit.prevent="createGooglePasswordSetupPassword">
              <label class="field">
                <span>Nova senha</span>
                <input v-model="googlePasswordSetupForm.password" type="password" autocomplete="new-password" minlength="8" required>
              </label>

              <label class="field">
                <span>Confirmar senha</span>
                <input v-model="googlePasswordSetupForm.confirmPassword" type="password" autocomplete="new-password" minlength="8" required>
              </label>

              <div class="form-actions">
                <button class="button-primary" type="submit" :disabled="googlePasswordSetupLoading || actionLoading">
                  {{ googlePasswordSetupLoading ? 'Salvando...' : 'Criar senha' }}
                </button>
                <button class="button-secondary" type="button" :disabled="googlePasswordSetupLoading || actionLoading" @click="resendGooglePasswordSetupCode">
                  Reenviar código
                </button>
              </div>
            </form>

            <button class="button-secondary" type="button" :disabled="googlePasswordSetupLoading || actionLoading" @click="logout">
              Sair
            </button>
          </article>
        </section>

        <DashboardScreen
          v-else-if="activeView === 'dashboard'"
          :request="authRequest"
          :current-user="currentUser"
          :notify="showNotification"
        />

        <TasksScreen
          v-else-if="activeView === 'tasks'"
          :request="authRequest"
          :current-user="currentUser"
          :notify="showNotification"
        />

        <TestScreen
          v-else-if="activeView === 'test'"
          :request="authRequest"
          :notify="showNotification"
        />

        <FinanceScreen
          v-else-if="isFinanceView"
          :request="authRequest"
          :current-user="currentUser"
          :initial-section="financeSectionByView"
          :notify="showNotification"
        />

        <ProfileScreen
          v-else
          :request="authRequest"
          :api-client="apiClient"
          :current-user="currentUser"
          :ui-settings="uiSettings"
          :active-theme-key="activeThemeKey"
          :available-themes="availableThemes"
          :notify="showNotification"
          :set-theme="setAppTheme"
          :update-ui-settings="updateUiSettings"
          @session-updated="handleSessionUpdated"
        />
      </main>

      <AppFooter />
    </div>
  </div>
</template>
