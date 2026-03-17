<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import axios from 'axios'
import AccountEmailsPanel from '../AccountEmailsPanel.vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  apiClient: {
    type: Object,
    required: true,
  },
  currentUser: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['session-updated'])

const profile = ref(null)
const profileLoading = ref(false)
const profileError = ref('')
const profileSuccess = ref('')
const savingProfile = ref(false)
const editingProfile = ref(false)
const profileForm = reactive({
  repositoryOwner: '',
  repositoryName: '',
  token: '',
  clearToken: false,
})

const displayEmail = computed(() => props.currentUser?.defaultEmail || props.currentUser?.email || 'Nao definido')
const linkedEmailCount = computed(() => Array.isArray(props.currentUser?.linkedEmails) ? props.currentUser.linkedEmails.length : 0)
const tokenConfigured = computed(() => Boolean(profile.value?.tokenConfigured))
const workspaceReady = computed(() => Boolean(profile.value?.workspaceReady))

onMounted(async () => {
  await loadProfile()
})

watch(
  () => props.currentUser?.id,
  async (userId) => {
    if (!userId) {
      return
    }

    await loadProfile()
  },
)

async function loadProfile() {
  profileLoading.value = true
  profileError.value = ''

  try {
    const { data } = await props.request({
      url: '/github/profile',
      method: 'GET',
    })

    profile.value = data?.profile || null
    syncProfileForm()
    editingProfile.value = !workspaceReady.value
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel carregar a configuracao GitHub do perfil.')
  } finally {
    profileLoading.value = false
  }
}

function syncProfileForm() {
  profileForm.repositoryOwner = typeof profile.value?.repositoryOwner === 'string' ? profile.value.repositoryOwner : ''
  profileForm.repositoryName = typeof profile.value?.repositoryName === 'string' ? profile.value.repositoryName : ''
  profileForm.token = ''
  profileForm.clearToken = false
}

async function saveProfile() {
  savingProfile.value = true
  profileError.value = ''
  profileSuccess.value = ''

  try {
    const { data } = await props.request({
      url: '/github/profile',
      method: 'PATCH',
      csrfActionId: 'github.profile.update',
      data: {
        repositoryOwner: profileForm.repositoryOwner,
        repositoryName: profileForm.repositoryName,
        token: profileForm.token,
        clearToken: profileForm.clearToken,
      },
    })

    profile.value = data?.profile || null
    syncProfileForm()
    editingProfile.value = !workspaceReady.value
    profileSuccess.value = 'Configuracao do GitHub salva com sucesso.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel salvar a configuracao do GitHub.')
  } finally {
    savingProfile.value = false
  }
}

function forwardSessionUpdate(session) {
  emit('session-updated', session)
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
</script>

<template>
  <section class="profile-screen screen-grid">
    <article class="surface-card profile-summary-card">
      <div>
        <p class="section-kicker">Perfil</p>
        <h2>Identidade da conta</h2>
      </div>

      <div class="profile-hero">
        <div class="profile-avatar">{{ displayEmail.slice(0, 1).toUpperCase() }}</div>
        <div>
          <strong>{{ displayEmail }}</strong>
          <p class="muted-copy">Email padrao atualmente usado pelo usuario autenticado.</p>
        </div>
      </div>

      <div class="profile-stats">
        <div class="stat-chip">
          <span>Emails vinculados</span>
          <strong>{{ linkedEmailCount }}</strong>
        </div>
        <div class="stat-chip">
          <span>Google</span>
          <strong>{{ currentUser?.googleLinked ? 'Vinculado' : 'Pendente' }}</strong>
        </div>
        <div class="stat-chip">
          <span>GitHub</span>
          <strong>{{ currentUser?.githubLinked ? 'Sincronizado' : 'Pendente' }}</strong>
        </div>
      </div>

      <div class="role-cluster">
        <span v-for="role in currentUser?.roles || []" :key="role" class="role-pill">{{ role }}</span>
      </div>
    </article>

    <article class="surface-card github-settings-card">
      <div class="panel-head-inline">
        <div>
          <p class="section-kicker">GitHub</p>
          <h2>Configuracao do GitHub</h2>
        </div>

        <button
          v-if="workspaceReady && !editingProfile"
          class="button-secondary"
          type="button"
          @click="editingProfile = true"
        >
          Editar
        </button>
      </div>

      <div v-if="profileLoading" class="inline-note">Carregando configuracao do GitHub...</div>

      <template v-else>
        <div class="profile-stats">
          <div class="stat-chip">
            <span>Repositorio</span>
            <strong>{{ profile?.repositoryOwner || 'nao configurado' }}/{{ profile?.repositoryName || 'nao configurado' }}</strong>
          </div>
          <div class="stat-chip">
            <span>Token</span>
            <strong>{{ tokenConfigured ? 'Salvo' : 'Ausente' }}</strong>
          </div>
          <div class="stat-chip">
            <span>Status</span>
            <strong>{{ workspaceReady ? 'Pronto' : 'Incompleto' }}</strong>
          </div>
        </div>

        <p v-if="profileError" class="feedback-banner error">{{ profileError }}</p>
        <p v-if="profileSuccess" class="feedback-banner success">{{ profileSuccess }}</p>

        <form class="settings-form" @submit.prevent="saveProfile">
          <div class="field-grid">
            <label class="field">
              <span>Repository owner</span>
              <input v-model="profileForm.repositoryOwner" type="text" placeholder="sua-org-ou-usuario" required>
            </label>

            <label class="field">
              <span>Repository name</span>
              <input v-model="profileForm.repositoryName" type="text" placeholder="nome-do-repositorio" required>
            </label>
          </div>

          <label class="field">
            <span>GitHub token</span>
            <input
              v-model="profileForm.token"
              type="password"
              :placeholder="tokenConfigured ? 'Deixe vazio para manter o token atual' : 'ghp_xxxxxxxxxxxxxxxxxxxx'"
            >
            <small class="field-help">O token salvo fica criptografado no backend.</small>
          </label>

          <label class="checkbox-row">
            <input v-model="profileForm.clearToken" type="checkbox">
            <span>Remover o token salvo ao atualizar o perfil</span>
          </label>

          <div class="form-actions">
            <button class="button-primary" type="submit" :disabled="savingProfile">
              {{ savingProfile ? 'Salvando...' : 'Salvar configuracao' }}
            </button>
            <button
              v-if="workspaceReady && editingProfile"
              class="button-secondary"
              type="button"
              :disabled="savingProfile"
              @click="editingProfile = false; syncProfileForm()"
            >
              Cancelar
            </button>
          </div>
        </form>
      </template>
    </article>

    <article class="surface-card profile-emails-card">
      <AccountEmailsPanel
        :request="request"
        :api-client="apiClient"
        :current-user="currentUser"
        @session-updated="forwardSessionUpdate"
      />
    </article>
  </section>
</template>

<style scoped>
.screen-grid {
  display: grid;
  gap: 18px;
}

.profile-summary-card,
.github-settings-card,
.profile-emails-card {
  display: grid;
  gap: 18px;
}

.profile-hero {
  align-items: center;
  display: flex;
  gap: 16px;
}

.profile-avatar {
  align-items: center;
  background: linear-gradient(135deg, rgba(15, 118, 110, 0.18), rgba(234, 88, 12, 0.14));
  border: 1px solid rgba(15, 118, 110, 0.18);
  border-radius: 22px;
  display: inline-flex;
  font-size: 1.6rem;
  font-weight: 900;
  height: 68px;
  justify-content: center;
  width: 68px;
}

.profile-stats {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.stat-chip {
  background: rgba(15, 23, 42, 0.04);
  border: 1px solid rgba(148, 163, 184, 0.2);
  border-radius: 20px;
  display: grid;
  gap: 6px;
  padding: 16px;
}

.stat-chip span {
  color: var(--muted);
  font-size: 0.82rem;
}

.role-cluster {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.role-pill {
  background: rgba(14, 165, 233, 0.12);
  border-radius: 999px;
  color: #0369a1;
  font-size: 0.8rem;
  font-weight: 800;
  padding: 8px 12px;
}

.panel-head-inline {
  align-items: start;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
}

.settings-form {
  display: grid;
  gap: 16px;
}

.field-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field-help {
  color: var(--muted);
  font-size: 0.86rem;
}

.checkbox-row {
  align-items: center;
  display: flex;
  gap: 10px;
}

@media (max-width: 980px) {
  .profile-stats,
  .field-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .profile-hero {
    align-items: start;
    flex-direction: column;
  }
}
</style>
