<script setup>
import { storeToRefs } from 'pinia'
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import GoogleLogin from '../components/GoogleLogin.vue'
import AppFooter from '../components/layout/AppFooter.vue'
import { useSessionStore } from '../stores/sessionStore'

const sessionStore = useSessionStore()
const appRoute = useRoute()
const appRouter = useRouter()

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

onMounted(() => {
  sessionStore.setAuthMode(effectiveAuthMode.value)
})

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

  await appRouter.replace({ name: 'dashboard' })
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

  await appRouter.replace({ name: 'dashboard' })
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

  await appRouter.replace({ name: 'dashboard' })
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
  const redirectPath = typeof appRoute.query.redirect === 'string'
    ? appRoute.query.redirect.trim()
    : ''

  if (redirectPath === '') {
    return ''
  }

  return redirectPath
}
</script>

<template>
  <div class="auth-page-shell">
    <section v-if="authBootstrapLoading" class="auth-stage">
      <article class="surface-card auth-form-card">
        <div>
          <p class="section-kicker">Sessao</p>
          <h2>Restaurando sessão...</h2>
        </div>
        <p class="auth-help-text">Aguarde enquanto validamos seu acesso.</p>
      </article>
    </section>

    <section v-else class="auth-stage">
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
            @click="navigateToAuthMode('login')"
          >
            Entrar
          </button>
          <button
            class="auth-mode-option"
            :class="{ active: authMode === 'register' }"
            type="button"
            @click="navigateToAuthMode('register')"
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

    <AppFooter />
  </div>
</template>
