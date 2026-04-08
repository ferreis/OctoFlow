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
import { useRouter } from 'vue-router'
import AppFooter from '../components/layout/AppFooter.vue'
import { useI18n } from '../composables/useI18n'
import { useSessionStore } from '../stores/sessionStore'
import {
  sanitizeAuthPasswordInput,
  sanitizeSecurityCodeInput,
} from '../utils/authInputSanitizers'

const sessionStore = useSessionStore()
const appRouter = useRouter()
const { t } = useI18n()

const verificationCodeInputElement = ref(null)
const passwordInputElement = ref(null)
const shouldFocusCurrentStepInput = ref(false)
const lastRenderedSetupStep = ref('')

let removeEscapeListener = null

const {
  currentUser,
  actionLoading,
  googlePasswordSetupCodeExpiresAtLabel,
  googlePasswordSetupStep,
  googlePasswordSetupLoading,
  googlePasswordSetupForm,
} = storeToRefs(sessionStore)

const isSubmitting = computed(() => {
  return googlePasswordSetupLoading.value || actionLoading.value
})

watch(
  () => googlePasswordSetupStep.value,
  () => {
    shouldFocusCurrentStepInput.value = true
  },
  {
    immediate: true,
  },
)

watch(
  () => googlePasswordSetupLoading.value,
  (isLoadingSetup) => {
    if (!isLoadingSetup) {
      shouldFocusCurrentStepInput.value = true
    }
  },
)

function clearSensitiveSetupFields() {
  googlePasswordSetupForm.value.verificationCode = ''
  googlePasswordSetupForm.value.password = ''
  googlePasswordSetupForm.value.confirmPassword = ''
}

function focusCurrentStepInputField() {
  if (isSubmitting.value) {
    return
  }

  const targetInputElement = googlePasswordSetupStep.value === 'verify'
    ? verificationCodeInputElement.value
    : passwordInputElement.value

  if (targetInputElement instanceof HTMLElement) {
    targetInputElement.focus({ preventScroll: true })
  }
}

function sanitizeCodePayload() {
  googlePasswordSetupForm.value.verificationCode = sanitizeSecurityCodeInput(
    googlePasswordSetupForm.value.verificationCode,
    16,
  )
}

function sanitizePasswordPayload() {
  googlePasswordSetupForm.value.password = sanitizeAuthPasswordInput(googlePasswordSetupForm.value.password)
  googlePasswordSetupForm.value.confirmPassword = sanitizeAuthPasswordInput(googlePasswordSetupForm.value.confirmPassword)
}

function clearCurrentStepFieldsOnEscape(keyboardEvent) {
  if (keyboardEvent.key !== 'Escape' || isSubmitting.value) {
    return
  }

  if (googlePasswordSetupStep.value === 'verify') {
    googlePasswordSetupForm.value.verificationCode = ''
    focusCurrentStepInputField()
    return
  }

  googlePasswordSetupForm.value.password = ''
  googlePasswordSetupForm.value.confirmPassword = ''
  focusCurrentStepInputField()
}

function attachEscapeListener() {
  if (typeof window === 'undefined' || removeEscapeListener) {
    return
  }

  const escapeHandler = (keyboardEvent) => {
    clearCurrentStepFieldsOnEscape(keyboardEvent)
  }

  window.addEventListener('keydown', escapeHandler)
  removeEscapeListener = () => {
    window.removeEventListener('keydown', escapeHandler)
  }
}

function detachEscapeListener() {
  removeEscapeListener?.()
  removeEscapeListener = null
}

onBeforeMount(() => {
  shouldFocusCurrentStepInput.value = true
})

onMounted(() => {
  attachEscapeListener()
  shouldFocusCurrentStepInput.value = true
})

onBeforeUpdate(() => {
  const currentSetupStep = String(googlePasswordSetupStep.value || '')
  if (currentSetupStep !== lastRenderedSetupStep.value) {
    shouldFocusCurrentStepInput.value = true
  }
})

onUpdated(() => {
  lastRenderedSetupStep.value = String(googlePasswordSetupStep.value || '')

  if (!shouldFocusCurrentStepInput.value) {
    return
  }

  focusCurrentStepInputField()
  shouldFocusCurrentStepInput.value = false
})

onBeforeUnmount(() => {
  detachEscapeListener()
  clearSensitiveSetupFields()
})

onUnmounted(() => {
  shouldFocusCurrentStepInput.value = false
  lastRenderedSetupStep.value = ''
})

onActivated(() => {
  attachEscapeListener()
  shouldFocusCurrentStepInput.value = true
})

onDeactivated(() => {
  detachEscapeListener()
})

onErrorCaptured((capturedError) => {
  console.error('Erro na tela de definição de senha:', capturedError)
  sessionStore.showNotification(t('auth.passwordSetup.errors.viewRuntime'), 'error')
  return false
})

async function resendCode() {
  if (isSubmitting.value) {
    return
  }

  await sessionStore.resendGooglePasswordSetupCode()
}

async function verifyCode() {
  if (isSubmitting.value) {
    return
  }

  sanitizeCodePayload()

  if (googlePasswordSetupForm.value.verificationCode === '') {
    sessionStore.showNotification(t('auth.passwordSetup.errors.invalidCode'), 'warning')
    return
  }

  await sessionStore.verifyGooglePasswordSetupCode()
}

async function createPassword() {
  if (isSubmitting.value) {
    return
  }

  sanitizePasswordPayload()

  const passwordCreated = await sessionStore.createGooglePasswordSetupPassword()
  if (!passwordCreated) {
    return
  }

  await appRouter.replace({
    name: 'dashboard',
    query: {
      tab: 'tasks',
    },
  })
}

async function logoutSession() {
  if (isSubmitting.value) {
    return
  }

  clearSensitiveSetupFields()
  await sessionStore.logout()
  await appRouter.replace({ name: 'auth-login' })
}
</script>

<template>
  <div class="auth-page-shell">
    <section class="auth-stage">
      <article class="surface-card auth-copy-card themed-hero-surface">
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
              ref="verificationCodeInputElement"
              v-model="googlePasswordSetupForm.verificationCode"
              type="text"
              autocomplete="one-time-code"
              inputmode="text"
              autocapitalize="characters"
              spellcheck="false"
              minlength="6"
              maxlength="16"
              pattern="[a-zA-Z0-9-]+"
              required
              :placeholder="t('auth.passwordSetup.verifyCodePlaceholder')"
            >
          </label>

          <div class="form-actions">
            <button class="button-primary" type="submit" :disabled="isSubmitting">
              {{ googlePasswordSetupLoading ? t('auth.passwordSetup.verifyButtonLoading') : t('auth.passwordSetup.verifyButton') }}
            </button>
            <button class="button-secondary" type="button" :disabled="isSubmitting" @click="resendCode">
              {{ t('auth.passwordSetup.resendCode') }}
            </button>
          </div>
        </form>

        <form v-else class="auth-form" @submit.prevent="createPassword">
          <label class="field">
            <span>{{ t('auth.passwordSetup.passwordLabel') }}</span>
            <input
              ref="passwordInputElement"
              v-model="googlePasswordSetupForm.password"
              type="password"
              autocomplete="new-password"
              minlength="8"
              maxlength="160"
              required
              :placeholder="t('auth.passwordSetup.passwordPlaceholder')"
            >
          </label>

          <label class="field">
            <span>{{ t('auth.passwordSetup.confirmPasswordLabel') }}</span>
            <input
              v-model="googlePasswordSetupForm.confirmPassword"
              type="password"
              autocomplete="new-password"
              minlength="8"
              maxlength="160"
              required
              :placeholder="t('auth.passwordSetup.confirmPasswordPlaceholder')"
            >
          </label>

          <div class="form-actions">
            <button class="button-primary" type="submit" :disabled="isSubmitting">
              {{ googlePasswordSetupLoading ? t('auth.passwordSetup.createButtonLoading') : t('auth.passwordSetup.createButton') }}
            </button>
            <button class="button-secondary" type="button" :disabled="isSubmitting" @click="resendCode">
              {{ t('auth.passwordSetup.resendCode') }}
            </button>
          </div>
        </form>

        <button class="button-secondary" type="button" :disabled="isSubmitting" @click="logoutSession">
          {{ t('auth.passwordSetup.logoutButton') }}
        </button>
      </article>
    </section>

    <AppFooter />
  </div>
</template>
