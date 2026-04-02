<script setup>
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import AppFooter from '../components/layout/AppFooter.vue'
import { useSessionStore } from '../stores/sessionStore'

const sessionStore = useSessionStore()
const appRouter = useRouter()

const {
  currentUser,
  actionLoading,
  googlePasswordSetupCodeExpiresAtLabel,
  googlePasswordSetupStep,
  googlePasswordSetupLoading,
  googlePasswordSetupForm,
} = storeToRefs(sessionStore)

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

        <form v-if="googlePasswordSetupStep === 'verify'" class="auth-form" @submit.prevent="verifyCode">
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
              @click="resendCode"
            >
              Reenviar código
            </button>
          </div>
        </form>

        <form v-else class="auth-form" @submit.prevent="createPassword">
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
            <button class="button-secondary" type="button" :disabled="googlePasswordSetupLoading || actionLoading" @click="resendCode">
              Reenviar código
            </button>
          </div>
        </form>

        <button class="button-secondary" type="button" :disabled="googlePasswordSetupLoading || actionLoading" @click="logoutSession">
          Sair
        </button>
      </article>
    </section>

    <AppFooter />
  </div>
</template>
