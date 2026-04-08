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
  watch,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import GoogleLogin from '../components/GoogleLogin.vue'
import AppFooter from '../components/layout/AppFooter.vue'
import { useI18n } from '../composables/useI18n'
import { useSessionStore } from '../stores/sessionStore'
import { sanitizeInternalRedirectPath } from '../utils/navigationSecurity'

const sessionStore = useSessionStore()
const appRoute = useRoute()
const appRouter = useRouter()
const { t } = useI18n()

const {
  authMode,
  loginForm,
  registerForm,
  loginLoading,
  actionLoading,
  authBootstrapLoading,
  requiresGooglePasswordSetup,
} = storeToRefs(sessionStore)

const effectiveAuthMode = computed(() => {
  return appRoute.name === 'auth-register' ? 'register' : 'login'
})

watch(
  () => effectiveAuthMode.value,
  (nextMode) => {
    sessionStore.setAuthMode(nextMode)
  },
  {
    immediate: true,
  },
)

// -- Vue Lifecycle (Padrão e Segurança) --
onBeforeMount(() => {
  // Lógica antes de renderizar (sem DOM ainda)
})

onMounted(() => {
  // DOM pronto: preenche modo inicial
  sessionStore.setAuthMode(effectiveAuthMode.value)
})

onBeforeUpdate(() => {
  // Antes de atualizar DOM (estado mudou)
})

onUpdated(() => {
  // Depois que DOM atualizou
})

onBeforeUnmount(() => {
  // Preparar limpeza - Remover senhas em memória se fechar subita
  loginForm.value.password = ''
  registerForm.value.password = ''
  registerForm.value.confirmPassword = ''
})

onUnmounted(() => {
  // Limpar tudo (eventos)
})

onActivated(() => {
  // Componente reativado
})

onDeactivated(() => {
  // Componente pausado
})

onErrorCaptured((error) => {
  console.error('Erro na view de Autenticação:', error)
  return false
})
// ----------------------------------------

async function handleLogin() {
  const success = await sessionStore.handleLogin()
  if (!success) {
    return
  }

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

async function handleRegister() {
  const success = await sessionStore.handleRegister()
  if (!success) {
    return
  }

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

async function handleGoogleCredential(credential) {
  const success = await sessionStore.handleGoogleCredential(credential)
  if (!success) {
    return
  }

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

function handleGoogleLoginError(error) {
  sessionStore.showNotification(error, 'error')
}

async function navigateToAuthMode(mode) {
  const targetName = mode === 'register' ? 'auth-register' : 'auth-login'

  if (appRoute.name === targetName) {
    return
  }

  await appRouter.replace({ name: targetName })
}

function resolvePostAuthRedirect() {
  return sanitizeInternalRedirectPath(appRoute.query.redirect, {
    fallbackPath: '',
  })
}
</script>

<template>
  <div class="min-h-screen flex flex-col justify-between py-8 px-4 sm:px-6 lg:px-8 bg-transparent">
    
    <main class="flex-grow flex items-center justify-center w-full mt-4 mb-4">
      
      <!-- Bootstrap Loading State -->
      <div v-if="authBootstrapLoading" class="w-full max-w-md animate-pulse bg-[var(--surface-strong)] rounded-3xl p-10 border border-[var(--line)] shadow-2xl text-center">
         <p class="text-[var(--color-secondary)] text-sm font-bold tracking-widest uppercase mb-4">{{ t('auth.bootstrap.kicker') }}</p>
         <h2 class="text-2xl font-bold text-[var(--ink)] mb-4">{{ t('auth.bootstrap.title') }}</h2>
         <p class="text-[var(--muted)]">{{ t('auth.bootstrap.helpText') }}</p>
      </div>

      <!-- Main Shell -->
      <div v-else class="w-full max-w-6xl flex flex-col lg:flex-row bg-[var(--surface)] sm:rounded-[32px] overflow-hidden border border-[var(--line)] shadow-[var(--shadow-lg)] relative backdrop-blur-xl">
        
        <!-- Left Side: Hero Info -->
        <section class="lg:w-1/2 relative p-8 sm:p-10 lg:p-14 xl:p-16 flex flex-col justify-center" style="background: var(--hero-surface);">
          <div class="relative z-10 h-full flex flex-col justify-center">
            <div>
              <p class="text-[var(--color-secondary)] text-xs font-black tracking-[0.2em] uppercase mb-4">{{ t('auth.hero.kicker') }}</p>
              <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-[var(--ink)] leading-tight tracking-tight">{{ t('auth.hero.title') }}</h2>
              <p class="mt-4 sm:mt-6 text-base sm:text-lg text-[var(--muted)] max-w-lg leading-relaxed">
                {{ t('auth.hero.description') }}
              </p>
            </div>

            <div class="mt-10 sm:mt-14 grid gap-4 sm:gap-5 sm:grid-cols-2">
              <div class="bg-[var(--nav-card)] hover:bg-[var(--nav-card-hover)] p-5 sm:p-6 rounded-2xl border border-[var(--nav-border)] transition-all duration-300">
                <strong class="text-[var(--ink)] block mb-2 font-bold text-base sm:text-lg">{{ t('auth.hero.featureProfile') }}</strong>
                <p class="text-xs sm:text-sm text-[var(--muted)] leading-relaxed">{{ t('auth.hero.featureProfileDesc') }}</p>
              </div>
              <div class="bg-[var(--nav-card)] hover:bg-[var(--nav-card-hover)] p-5 sm:p-6 rounded-2xl border border-[var(--nav-border)] transition-all duration-300">
                <strong class="text-[var(--ink)] block mb-2 font-bold text-base sm:text-lg">{{ t('auth.hero.featureTasks') }}</strong>
                <p class="text-xs sm:text-sm text-[var(--muted)] leading-relaxed">{{ t('auth.hero.featureTasksDesc') }}</p>
              </div>
              <div class="bg-[var(--nav-card)] hover:bg-[var(--nav-card-hover)] p-5 sm:p-6 rounded-2xl border border-[var(--nav-border)] transition-all duration-300 sm:col-span-2">
                <strong class="text-[var(--ink)] block mb-2 font-bold text-base sm:text-lg">{{ t('auth.hero.featureDashboard') }}</strong>
                <p class="text-xs sm:text-sm text-[var(--muted)] leading-relaxed">{{ t('auth.hero.featureDashboardDesc') }}</p>
              </div>
            </div>
          </div>
        </section>

        <!-- Right Side: Forms -->
        <section class="lg:w-1/2 bg-white/5 p-8 sm:p-10 lg:p-16 flex flex-col justify-center items-center relative z-20 border-t lg:border-t-0 lg:border-l border-[var(--line)] backdrop-blur-3xl">
          <div class="w-full max-w-[380px]">
            
            <div class="text-center mb-8 sm:mb-10">
              <h2 class="text-2xl sm:text-3xl font-extrabold text-[var(--ink)] tracking-tight">
                {{ authMode === 'register' ? t('auth.register.title') : t('auth.login.title') }}
              </h2>
              <p class="mt-2 text-[var(--muted)] text-sm">
                {{ authMode === 'register' ? t('auth.register.subtitleSession') : t('auth.login.subtitleSession') }}
              </p>
            </div>

            <!-- Mode Switcher -->
            <div class="flex p-1 bg-[var(--surface-strong)] rounded-full mb-8 sm:mb-10 border border-[var(--line)] backdrop-blur-md">
              <button
                class="flex-1 py-2 sm:py-2.5 text-xs sm:text-sm font-bold rounded-full transition-all duration-200 ease-out focus:outline-none"
                :class="authMode === 'login' ? 'bg-[var(--color-primary)] text-white shadow-lg' : 'text-[var(--muted)] hover:text-[var(--ink)]'"
                type="button"
                @click="navigateToAuthMode('login')"
              >
                {{ t('auth.modeSwitch.login') }}
              </button>
              <button
                class="flex-1 py-2 sm:py-2.5 text-xs sm:text-sm font-bold rounded-full transition-all duration-200 ease-out focus:outline-none"
                :class="authMode === 'register' ? 'bg-[var(--color-primary)] text-white shadow-lg' : 'text-[var(--muted)] hover:text-[var(--ink)]'"
                type="button"
                @click="navigateToAuthMode('register')"
              >
                {{ t('auth.modeSwitch.register') }}
              </button>
            </div>

            <!-- LOGIN FORM -->
            <form v-if="authMode === 'login'" class="flex flex-col gap-5" @submit.prevent="handleLogin">
              <label class="flex flex-col gap-2">
                <span class="text-xs sm:text-sm font-bold text-[var(--ink)]">{{ t('auth.login.emailLabel') }}</span>
                <input 
                  v-model.trim="loginForm.email" 
                  type="email" 
                  autocomplete="username" 
                  pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                  required
                  class="w-full bg-[var(--app-field-bg)] border border-[var(--app-field-border)] rounded-2xl px-4 py-3 sm:py-3.5 text-[var(--ink)] placeholder-[var(--muted)] text-sm focus:outline-none focus:border-[var(--color-secondary)] focus:ring-[3px] focus:ring-[var(--color-secondary)]/20 transition-all shadow-inner"
                  placeholder="exemplo@email.com"
                >
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-xs sm:text-sm font-bold text-[var(--ink)]">{{ t('auth.login.passwordLabel') }}</span>
                <input 
                  v-model="loginForm.password" 
                  type="password" 
                  autocomplete="current-password" 
                  required
                  class="w-full bg-[var(--app-field-bg)] border border-[var(--app-field-border)] rounded-2xl px-4 py-3 sm:py-3.5 text-[var(--ink)] placeholder-[var(--muted)] text-sm focus:outline-none focus:border-[var(--color-secondary)] focus:ring-[3px] focus:ring-[var(--color-secondary)]/20 transition-all shadow-inner"
                  placeholder="••••••••"
                >
              </label>

              <div class="mt-2 flex flex-col gap-4">
                <button 
                  class="w-full flex items-center justify-center py-3 sm:py-3.5 px-6 rounded-2xl sm:rounded-full font-bold text-sm bg-gradient-to-b from-[var(--color-primary)] to-[var(--theme-primary)] text-white shadow-[0_10px_20px_rgba(79,70,229,0.2)] hover:shadow-[0_14px_30px_rgba(79,70,229,0.3)] border border-[var(--color-primary)]/30 transition-all hover:-translate-y-0.5 active:translate-y-0 disabled:opacity-60 disabled:cursor-not-allowed" 
                  type="submit" 
                  :disabled="loginLoading || actionLoading"
                >
                  {{ loginLoading ? t('auth.login.buttonLoading') : t('auth.login.button') }}
                </button>
      
                <div class="flex items-center gap-3 sm:gap-4 my-1 opacity-50">
                  <div class="h-[1px] bg-[var(--ink)] flex-1"></div>
                  <span class="text-[10px] sm:text-xs font-bold text-[var(--ink)] uppercase tracking-wider">OU</span>
                  <div class="h-[1px] bg-[var(--ink)] flex-1"></div>
                </div>
      
                <div class="w-full flex justify-center">
                  <GoogleLogin
                    :is-loading="loginLoading || actionLoading"
                    variant="system"
                    :button-label="t('auth.login.googleButton')"
                    :button-width="380"
                    @credential="handleGoogleCredential"
                    @error="handleGoogleLoginError"
                  />
                </div>
              </div>
            </form>

            <!-- REGISTER FORM -->
            <form v-else class="flex flex-col gap-5" @submit.prevent="handleRegister">
              <label class="flex flex-col gap-2">
                <span class="text-xs sm:text-sm font-bold text-[var(--ink)]">{{ t('auth.register.emailLabel') }}</span>
                <input 
                  v-model.trim="registerForm.email" 
                  type="email" 
                  autocomplete="email" 
                  pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                  required
                  class="w-full bg-[var(--app-field-bg)] border border-[var(--app-field-border)] rounded-2xl px-4 py-3 sm:py-3.5 text-[var(--ink)] placeholder-[var(--muted)] text-sm focus:outline-none focus:border-[var(--color-secondary)] focus:ring-[3px] focus:ring-[var(--color-secondary)]/20 transition-all shadow-inner"
                  placeholder="exemplo@email.com"
                >
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-xs sm:text-sm font-bold text-[var(--ink)]">{{ t('auth.register.passwordLabel') }}</span>
                <input 
                  v-model="registerForm.password" 
                  type="password" 
                  autocomplete="new-password" 
                  minlength="8" 
                  required
                  class="w-full bg-[var(--app-field-bg)] border border-[var(--app-field-border)] rounded-2xl px-4 py-3 sm:py-3.5 text-[var(--ink)] placeholder-[var(--muted)] text-sm focus:outline-none focus:border-[var(--color-secondary)] focus:ring-[3px] focus:ring-[var(--color-secondary)]/20 transition-all shadow-inner"
                  placeholder="Mínimo 8 caracteres"
                >
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-xs sm:text-sm font-bold text-[var(--ink)]">{{ t('auth.register.confirmPasswordLabel') }}</span>
                <input 
                  v-model="registerForm.confirmPassword" 
                  type="password" 
                  autocomplete="new-password" 
                  minlength="8" 
                  required
                  class="w-full bg-[var(--app-field-bg)] border border-[var(--app-field-border)] rounded-2xl px-4 py-3 sm:py-3.5 text-[var(--ink)] placeholder-[var(--muted)] text-sm focus:outline-none focus:border-[var(--color-secondary)] focus:ring-[3px] focus:ring-[var(--color-secondary)]/20 transition-all shadow-inner"
                  placeholder="Confirme sua senha"
                >
              </label>

              <div class="mt-2 flex flex-col gap-4">
                <button 
                  class="w-full flex items-center justify-center py-3 sm:py-3.5 px-6 rounded-2xl sm:rounded-full font-bold text-sm bg-gradient-to-b from-[var(--color-primary)] to-[var(--theme-primary)] text-white shadow-[0_10px_20px_rgba(79,70,229,0.2)] hover:shadow-[0_14px_30px_rgba(79,70,229,0.3)] border border-[var(--color-primary)]/30 transition-all hover:-translate-y-0.5 active:translate-y-0 disabled:opacity-60 disabled:cursor-not-allowed" 
                  type="submit" 
                  :disabled="loginLoading || actionLoading"
                >
                  {{ loginLoading ? t('auth.register.buttonLoading') : t('auth.register.button') }}
                </button>

                <p class="text-[11px] sm:text-xs text-center text-[var(--muted)] opacity-80 leading-relaxed max-w-[280px] mx-auto">
                  {{ t('auth.register.helpText') }}
                </p>
              </div>
            </form>
          </div>
        </section>
      </div>

    </main>
    <AppFooter class="w-full opacity-60 hover:opacity-100 transition-opacity" />
  </div>
</template>
