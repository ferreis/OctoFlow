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
import { useRoute, useRouter } from 'vue-router'
import GoogleLogin from '../components/GoogleLogin.vue'
import AppFooter from '../components/layout/AppFooter.vue'
import { useI18n } from '../composables/useI18n'
import { useSessionStore } from '../stores/sessionStore'
import {
  sanitizeAuthEmailInput,
  sanitizeAuthPasswordInput,
  sanitizeGoogleCredentialInput,
  sanitizeSingleLineSecurityText,
} from '../utils/authInputSanitizers'
import { sanitizeInternalRedirectPath } from '../utils/navigationSecurity'

const sessionStore = useSessionStore()
const appRoute = useRoute()
const appRouter = useRouter()
const { t } = useI18n()

const loginEmailInputElement = ref(null)
const registerEmailInputElement = ref(null)
const shouldFocusCurrentAuthInput = ref(false)

let removePageHideListener = null

const {
  authMode,
  loginForm,
  registerForm,
  loginLoading,
  actionLoading,
  authBootstrapLoading,
  requiresGooglePasswordSetup,
} = storeToRefs(sessionStore)

const isSubmitting = computed(() => {
  return loginLoading.value || actionLoading.value
})

const effectiveAuthMode = computed(() => {
  return appRoute.name === 'auth-register' ? 'register' : 'login'
})

watch(
  () => effectiveAuthMode.value,
  (nextMode) => {
    if (authMode.value !== nextMode) {
      sessionStore.setAuthMode(nextMode)
    }

    shouldFocusCurrentAuthInput.value = true
  },
  {
    immediate: true,
  },
)

watch(
  () => authBootstrapLoading.value,
  (isLoadingBootstrap) => {
    if (!isLoadingBootstrap) {
      shouldFocusCurrentAuthInput.value = true
    }
  },
)

function clearSensitiveAuthFields() {
  loginForm.value.password = ''
  registerForm.value.password = ''
  registerForm.value.confirmPassword = ''
}

function attachPageHideListener() {
  if (typeof window === 'undefined' || removePageHideListener) {
    return
  }

  const pageHideListener = () => {
    clearSensitiveAuthFields()
  }

  window.addEventListener('pagehide', pageHideListener)
  removePageHideListener = () => {
    window.removeEventListener('pagehide', pageHideListener)
  }
}

function detachPageHideListener() {
  removePageHideListener?.()
  removePageHideListener = null
}

function focusCurrentAuthInputField() {
  if (authBootstrapLoading.value || isSubmitting.value) {
    return
  }

  const targetInputElement = authMode.value === 'register'
    ? registerEmailInputElement.value
    : loginEmailInputElement.value

  if (targetInputElement instanceof HTMLElement) {
    targetInputElement.focus({ preventScroll: true })
  }
}

function sanitizeLoginPayload() {
  loginForm.value.email = sanitizeAuthEmailInput(loginForm.value.email)
  loginForm.value.password = sanitizeAuthPasswordInput(loginForm.value.password)
}

function sanitizeRegisterPayload() {
  registerForm.value.email = sanitizeAuthEmailInput(registerForm.value.email)
  registerForm.value.password = sanitizeAuthPasswordInput(registerForm.value.password)
  registerForm.value.confirmPassword = sanitizeAuthPasswordInput(registerForm.value.confirmPassword)
}

onBeforeMount(() => {
  sessionStore.setAuthMode(effectiveAuthMode.value)
})

onMounted(() => {
  attachPageHideListener()
  shouldFocusCurrentAuthInput.value = true
})

onBeforeUpdate(() => {
  if (authMode.value !== effectiveAuthMode.value) {
    sessionStore.setAuthMode(effectiveAuthMode.value)
  }
})

onUpdated(() => {
  if (!shouldFocusCurrentAuthInput.value) {
    return
  }

  focusCurrentAuthInputField()
  shouldFocusCurrentAuthInput.value = false
})

onBeforeUnmount(() => {
  detachPageHideListener()
  clearSensitiveAuthFields()
})

onUnmounted(() => {
  shouldFocusCurrentAuthInput.value = false
})

onActivated(() => {
  attachPageHideListener()
  shouldFocusCurrentAuthInput.value = true
})

onDeactivated(() => {
  detachPageHideListener()
})

onErrorCaptured((capturedError) => {
  console.error('Erro na view de autenticação:', capturedError)
  sessionStore.showNotification(t('auth.errors.viewRuntime'), 'error')
  return false
})

async function redirectAfterAuthenticatedFlow() {
  if (requiresGooglePasswordSetup.value) {
    await appRouter.replace({ name: 'auth-google-password-setup' })
    return
  }

  const postAuthRedirect = resolvePostAuthRedirect()
  if (postAuthRedirect !== '') {
    await appRouter.replace(postAuthRedirect)
    return
  }

  await appRouter.replace({
    name: 'dashboard',
    query: {
      tab: 'tasks',
    },
  })
}

async function handleLogin() {
  if (isSubmitting.value) {
    return
  }

  sanitizeLoginPayload()

  const loginSucceeded = await sessionStore.handleLogin()
  if (!loginSucceeded) {
    return
  }

  await redirectAfterAuthenticatedFlow()
}

async function handleRegister() {
  if (isSubmitting.value) {
    return
  }

  sanitizeRegisterPayload()

  const registerSucceeded = await sessionStore.handleRegister()
  if (!registerSucceeded) {
    return
  }

  await redirectAfterAuthenticatedFlow()
}

async function handleGoogleCredential(rawCredential) {
  if (isSubmitting.value) {
    return
  }

  const sanitizedCredential = sanitizeGoogleCredentialInput(rawCredential)
  if (sanitizedCredential === '') {
    sessionStore.showNotification(t('auth.errors.invalidGoogleCredential'), 'error')
    return
  }

  const googleAuthSucceeded = await sessionStore.handleGoogleCredential(sanitizedCredential)
  if (!googleAuthSucceeded) {
    return
  }

  await redirectAfterAuthenticatedFlow()
}

function handleGoogleLoginError(rawErrorMessage) {
  const sanitizedErrorMessage = sanitizeSingleLineSecurityText(rawErrorMessage, 220)
  sessionStore.showNotification(
    sanitizedErrorMessage !== '' ? sanitizedErrorMessage : t('auth.errors.loginSocialFailed'),
    'error',
  )
}

async function navigateToAuthMode(mode) {
  if (isSubmitting.value || authBootstrapLoading.value) {
    return
  }

  const targetRouteName = mode === 'register' ? 'auth-register' : 'auth-login'

  if (appRoute.name === targetRouteName) {
    return
  }

  await appRouter.replace({ name: targetRouteName })
}

function resolvePostAuthRedirect() {
  return sanitizeInternalRedirectPath(appRoute.query.redirect, {
    fallbackPath: '',
  })
}
</script>

<template>
  <div class="auth-page-shell auth-entry-shell">
    <main class="auth-stage auth-entry-stage">
      <article v-if="authBootstrapLoading" class="surface-card auth-form-card auth-entry-bootstrap-card" aria-live="polite">
        <p class="section-kicker">{{ t('auth.bootstrap.kicker') }}</p>
        <h2>{{ t('auth.bootstrap.title') }}</h2>
        <p class="muted-copy">{{ t('auth.bootstrap.helpText') }}</p>
      </article>

      <template v-else>
        <article class="surface-card auth-copy-card themed-hero-surface">
          <div>
            <p class="section-kicker">{{ t('auth.hero.kicker') }}</p>
            <h2>{{ t('auth.hero.title') }}</h2>
            <p class="muted-copy auth-entry-hero-description">{{ t('auth.hero.description') }}</p>
          </div>

          <div class="highlight-grid auth-entry-highlight-grid">
            <div class="highlight-card">
              <strong>{{ t('auth.hero.featureProfile') }}</strong>
              <p>{{ t('auth.hero.featureProfileDesc') }}</p>
            </div>
            <div class="highlight-card">
              <strong>{{ t('auth.hero.featureTasks') }}</strong>
              <p>{{ t('auth.hero.featureTasksDesc') }}</p>
            </div>
            <div class="highlight-card">
              <strong>{{ t('auth.hero.featureDashboard') }}</strong>
              <p>{{ t('auth.hero.featureDashboardDesc') }}</p>
            </div>
          </div>
        </article>

        <article class="surface-card auth-form-card auth-entry-form-card">
          <header class="auth-entry-header">
            <h2>{{ authMode === 'register' ? t('auth.register.title') : t('auth.login.title') }}</h2>
            <p class="muted-copy">
              {{ authMode === 'register' ? t('auth.register.subtitleSession') : t('auth.login.subtitleSession') }}
            </p>
          </header>

          <div class="auth-mode-switch" role="tablist" :aria-label="t('auth.modeSwitch.ariaLabel')">
            <button
              class="auth-mode-option"
              :class="{ active: authMode === 'login' }"
              type="button"
              role="tab"
              :aria-selected="authMode === 'login'"
              @click="navigateToAuthMode('login')"
            >
              {{ t('auth.modeSwitch.login') }}
            </button>
            <button
              class="auth-mode-option"
              :class="{ active: authMode === 'register' }"
              type="button"
              role="tab"
              :aria-selected="authMode === 'register'"
              @click="navigateToAuthMode('register')"
            >
              {{ t('auth.modeSwitch.register') }}
            </button>
          </div>

          <form v-if="authMode === 'login'" class="auth-form" @submit.prevent="handleLogin">
            <label class="field">
              <span>{{ t('auth.login.emailLabel') }}</span>
              <input
                ref="loginEmailInputElement"
                v-model="loginForm.email"
                type="email"
                autocomplete="username"
                maxlength="254"
                pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                required
                :placeholder="t('auth.login.emailPlaceholder')"
              >
            </label>

            <label class="field">
              <span>{{ t('auth.login.passwordLabel') }}</span>
              <input
                v-model="loginForm.password"
                type="password"
                autocomplete="current-password"
                maxlength="160"
                required
                :placeholder="t('auth.login.passwordPlaceholder')"
              >
            </label>

            <div class="auth-entry-action-stack">
              <button class="button-primary auth-entry-submit" type="submit" :disabled="isSubmitting">
                {{ loginLoading ? t('auth.login.buttonLoading') : t('auth.login.button') }}
              </button>

              <div class="app-google-login-separator auth-entry-divider" aria-hidden="true">
                <span>{{ t('auth.shared.separator') }}</span>
              </div>

              <div class="auth-entry-google-slot">
                <GoogleLogin
                  :is-loading="isSubmitting"
                  variant="system"
                  :button-label="t('auth.login.googleButton')"
                  :button-width="360"
                  @credential="handleGoogleCredential"
                  @error="handleGoogleLoginError"
                />
              </div>
            </div>
          </form>

          <form v-else class="auth-form" @submit.prevent="handleRegister">
            <label class="field">
              <span>{{ t('auth.register.emailLabel') }}</span>
              <input
                ref="registerEmailInputElement"
                v-model="registerForm.email"
                type="email"
                autocomplete="email"
                maxlength="254"
                pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                required
                :placeholder="t('auth.register.emailPlaceholder')"
              >
            </label>

            <label class="field">
              <span>{{ t('auth.register.passwordLabel') }}</span>
              <input
                v-model="registerForm.password"
                type="password"
                autocomplete="new-password"
                minlength="8"
                maxlength="160"
                required
                :placeholder="t('auth.register.passwordPlaceholder')"
              >
            </label>

            <label class="field">
              <span>{{ t('auth.register.confirmPasswordLabel') }}</span>
              <input
                v-model="registerForm.confirmPassword"
                type="password"
                autocomplete="new-password"
                minlength="8"
                maxlength="160"
                required
                :placeholder="t('auth.register.confirmPasswordPlaceholder')"
              >
            </label>

            <div class="auth-entry-action-stack">
              <button class="button-primary auth-entry-submit" type="submit" :disabled="isSubmitting">
                {{ loginLoading ? t('auth.register.buttonLoading') : t('auth.register.button') }}
              </button>

              <p class="auth-entry-help-copy">{{ t('auth.register.helpText') }}</p>
            </div>
          </form>
        </article>
      </template>
    </main>

    <AppFooter class="auth-entry-footer" />
  </div>
</template>
