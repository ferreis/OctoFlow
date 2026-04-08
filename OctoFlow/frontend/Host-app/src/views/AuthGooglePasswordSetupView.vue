<script setup>
import { storeToRefs } from 'pinia'
import {
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
} from 'vue'
import { useRouter } from 'vue-router'
import AppFooter from '../components/layout/AppFooter.vue'
import { useI18n } from '../composables/useI18n'
import { useSessionStore } from '../stores/sessionStore'

const sessionStore = useSessionStore()
const appRouter = useRouter()
const { t } = useI18n()

const {
  currentUser,
  actionLoading,
  googlePasswordSetupCodeExpiresAtLabel,
  googlePasswordSetupStep,
  googlePasswordSetupLoading,
  googlePasswordSetupForm,
} = storeToRefs(sessionStore)

// -- Vue Lifecycle (Padrão e Segurança) --
onBeforeMount(() => {
  // Lógica antes de renderizar
})

onMounted(() => {
  // DOM pronto e views seguras
})

onBeforeUpdate(() => {
  // Antes de atualizar DOM
})

onUpdated(() => {
  // Depois que DOM atualizou
})

onBeforeUnmount(() => {
  // Preparar limpeza - Remover senhas em memória
  googlePasswordSetupForm.value.password = ''
  googlePasswordSetupForm.value.confirmPassword = ''
  googlePasswordSetupForm.value.verificationCode = ''
})

onUnmounted(() => {
  // Limpar eventos
})

onActivated(() => {
  // Componente reativado (keep-alive)
})

onDeactivated(() => {
  // Componente pausado (keep-alive)
})

onErrorCaptured((error) => {
  console.error('Erro de Autenticação Segura (Setup):', error)
  return false
})
// ----------------------------------------

async function resendCode() {
  await sessionStore.resendGooglePasswordSetupCode()
}

async function verifyCode() {
  await sessionStore.verifyGooglePasswordSetupCode()
}

async function createPassword() {
  const success = await sessionStore.createGooglePasswordSetupPassword()
  if (!success) {
    return
  }

  await appRouter.replace({ name: 'dashboard' })
}

async function logoutSession() {
  await sessionStore.logout()
  await appRouter.replace({ name: 'auth-login' })
}
</script>

<template>
  <div class="auth-page-shell">
    <section class="auth-stage">
      <article class="surface-card auth-copy-card">
        <p class="section-kicker">{{ t('auth.passwordSetup.kickerMandatory') }}</p>
        <h2>{{ t('auth.passwordSetup.titleMain') }}</h2>
        <p class="muted-copy">
          {{ t('auth.passwordSetup.description') }} <strong>{{ currentUser?.email }}</strong> {{ t('auth.passwordSetup.andThen') }}
        </p>
        <div class="highlight-grid">
          <div class="highlight-card">
            <strong>{{ t('auth.passwordSetup.step1Title') }}</strong>
            <p>{{ t('auth.passwordSetup.step1Desc') }}</p>
          </div>
          <div class="highlight-card">
            <strong>{{ t('auth.passwordSetup.step2Title') }}</strong>
            <p>{{ t('auth.passwordSetup.step2Desc') }}</p>
          </div>
        </div>
        <p v-if="googlePasswordSetupCodeExpiresAtLabel" class="auth-help-text">
          {{ t('auth.passwordSetup.expiresIn') }} {{ googlePasswordSetupCodeExpiresAtLabel }}
        </p>
      </article>

      <article class="surface-card auth-form-card">
        <div>
          <p class="section-kicker">{{ t('auth.passwordSetup.kickerSecurity') }}</p>
          <h2>{{ googlePasswordSetupStep === 'verify' ? t('auth.passwordSetup.verifyStepTitle') : t('auth.passwordSetup.createStepTitle') }}</h2>
        </div>

        <form v-if="googlePasswordSetupStep === 'verify'" class="auth-form" @submit.prevent="verifyCode">
          <label class="field">
            <span>{{ t('auth.passwordSetup.verifyCodeLabel') }}</span>
            <input
              v-model.trim="googlePasswordSetupForm.verificationCode"
              type="text"
              autocomplete="one-time-code"
              minlength="6"
              maxlength="16"
              pattern="[a-zA-Z0-9-]+"
              required
            >
          </label>

          <div class="form-actions">
            <button class="button-primary" type="submit" :disabled="googlePasswordSetupLoading || actionLoading">
              {{ googlePasswordSetupLoading ? t('auth.passwordSetup.verifyButtonLoading') : t('auth.passwordSetup.verifyButton') }}
            </button>
            <button
              class="button-secondary"
              type="button"
              :disabled="googlePasswordSetupLoading || actionLoading"
              @click="resendCode"
            >
              {{ t('auth.passwordSetup.resendCode') }}
            </button>
          </div>
        </form>

        <form v-else class="auth-form" @submit.prevent="createPassword">
          <label class="field">
            <span>{{ t('auth.passwordSetup.passwordLabel') }}</span>
            <input v-model="googlePasswordSetupForm.password" type="password" autocomplete="new-password" minlength="8" required>
          </label>

          <label class="field">
            <span>{{ t('auth.passwordSetup.confirmPasswordLabel') }}</span>
            <input v-model="googlePasswordSetupForm.confirmPassword" type="password" autocomplete="new-password" minlength="8" required>
          </label>

          <div class="form-actions">
            <button class="button-primary" type="submit" :disabled="googlePasswordSetupLoading || actionLoading">
              {{ googlePasswordSetupLoading ? t('auth.passwordSetup.createButtonLoading') : t('auth.passwordSetup.createButton') }}
            </button>
            <button class="button-secondary" type="button" :disabled="googlePasswordSetupLoading || actionLoading" @click="resendCode">
              {{ t('auth.passwordSetup.resendCode') }}
            </button>
          </div>
        </form>

        <button class="button-secondary" type="button" :disabled="googlePasswordSetupLoading || actionLoading" @click="logoutSession">
          {{ t('auth.passwordSetup.logoutButton') }}
        </button>
      </article>
    </section>

    <AppFooter />
  </div>
</template>
