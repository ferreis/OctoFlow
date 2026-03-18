<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import axios from 'axios'
import AccountEmailsPanel from '../AccountEmailsPanel.vue'
import {
  COLOR_VISION_MODE_OPTIONS,
  DEFAULT_COLOR_VISION_INTENSITY,
  DEFAULT_COLOR_VISION_MODE,
  DEFAULT_FONT_SCALE,
  FONT_SCALE_OPTIONS,
  getColorVisionModeDefinition,
  getFontScaleDefinition,
  normalizeColorVisionIntensity,
  normalizeColorVisionMode,
  normalizeFontScale,
  normalizeHighContrastEnabled,
  normalizeUiSettingsPayload,
} from '../../theme'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  activeThemeKey: {
    type: String,
    default: 'original',
  },
  availableThemes: {
    type: Array,
    default: () => [],
  },
  notify: {
    type: Function,
    default: null,
  },
  apiClient: {
    type: Object,
    required: true,
  },
  currentUser: {
    type: Object,
    default: null,
  },
  uiSettings: {
    type: Object,
    default: () => ({}),
  },
  setTheme: {
    type: Function,
    default: null,
  },
  updateUiSettings: {
    type: Function,
    default: null,
  },
})

const emit = defineEmits(['session-updated'])

const REPOSITORY_PAGE_SIZE = 10

const profile = ref(null)
const profileLoading = ref(false)
const profileError = ref('')
const profileSuccess = ref('')
const savingProfile = ref(false)
const savingAccessibility = ref(false)
const savingRepository = ref(false)
const savingRepositoryId = ref(0)
const deletingRepositoryId = ref(0)
const repositoryPage = ref(1)
const collapsedSections = reactive({
  summary: false,
  github: true,
  repositories: true,
  theme: true,
  accessibility: true,
  emails: true,
})
const profileForm = reactive({
  repositoryOwner: '',
  token: '',
  clearToken: false,
})
const repositoryForm = reactive({
  ownerLogin: '',
  name: '',
  url: '',
  isIgnored: false,
})
const accessibilityForm = reactive({
  colorVisionMode: DEFAULT_COLOR_VISION_MODE,
  colorVisionIntensity: DEFAULT_COLOR_VISION_INTENSITY,
  highContrastEnabled: false,
  fontScale: DEFAULT_FONT_SCALE,
})

const displayEmail = computed(() => props.currentUser?.defaultEmail || props.currentUser?.email || 'Nao definido')
const linkedEmailCount = computed(() => Array.isArray(props.currentUser?.linkedEmails) ? props.currentUser.linkedEmails.length : 0)
const tokenConfigured = computed(() => Boolean(profile.value?.tokenConfigured))
const workspaceReady = computed(() => Boolean(profile.value?.workspaceReady))
const themeOptions = computed(() => Array.isArray(props.availableThemes) ? props.availableThemes : [])
const repositories = computed(() => Array.isArray(profile.value?.repositories) ? profile.value.repositories : [])
const ignoredRepositoryCount = computed(() => repositories.value.filter((repository) => repository?.isIgnored).length)
const activeRepositoryCount = computed(() => repositories.value.filter((repository) => !repository?.isIgnored).length)
const repositoryPageCount = computed(() => Math.max(1, Math.ceil(repositories.value.length / REPOSITORY_PAGE_SIZE)))
const paginatedRepositories = computed(() => {
  const startIndex = (repositoryPage.value - 1) * REPOSITORY_PAGE_SIZE
  return repositories.value.slice(startIndex, startIndex + REPOSITORY_PAGE_SIZE)
})
const repositoryPageStart = computed(() => (
  repositories.value.length === 0
    ? 0
    : (repositoryPage.value - 1) * REPOSITORY_PAGE_SIZE + 1
))
const repositoryPageEnd = computed(() => (
  repositories.value.length === 0
    ? 0
    : Math.min(repositoryPage.value * REPOSITORY_PAGE_SIZE, repositories.value.length)
))
const defaultRepositoryLabel = computed(() => {
  const repositoryKey = typeof profile.value?.defaultRepositoryKey === 'string' ? profile.value.defaultRepositoryKey.trim() : ''
  return repositoryKey !== '' ? repositoryKey : 'nao definido'
})
const colorVisionModeOptions = COLOR_VISION_MODE_OPTIONS
const fontScaleOptions = FONT_SCALE_OPTIONS
const normalizedUiSettings = computed(() => normalizeUiSettingsPayload(props.uiSettings || {}))
const savedColorVisionMode = computed(() => normalizeColorVisionMode(normalizedUiSettings.value.colorVisionMode))
const savedColorVisionIntensity = computed(() => normalizeColorVisionIntensity(normalizedUiSettings.value.colorVisionIntensity))
const savedHighContrastEnabled = computed(() => normalizeHighContrastEnabled(normalizedUiSettings.value.highContrastEnabled))
const savedFontScale = computed(() => normalizeFontScale(normalizedUiSettings.value.fontScale))
const selectedColorVisionDefinition = computed(() => getColorVisionModeDefinition(accessibilityForm.colorVisionMode))
const selectedFontScaleDefinition = computed(() => getFontScaleDefinition(accessibilityForm.fontScale))
const accessibilityIntensityLabel = computed(() => (
  accessibilityForm.colorVisionMode === DEFAULT_COLOR_VISION_MODE
    ? 'Desativado'
    : `${accessibilityForm.colorVisionIntensity}%`
))
const hasAccessibilityChanges = computed(() => (
  accessibilityForm.colorVisionMode !== savedColorVisionMode.value
  || accessibilityForm.colorVisionIntensity !== savedColorVisionIntensity.value
  || accessibilityForm.highContrastEnabled !== savedHighContrastEnabled.value
  || accessibilityForm.fontScale !== savedFontScale.value
))
const isDefaultAccessibilityForm = computed(() => (
  accessibilityForm.colorVisionMode === DEFAULT_COLOR_VISION_MODE
  && accessibilityForm.colorVisionIntensity === DEFAULT_COLOR_VISION_INTENSITY
  && accessibilityForm.highContrastEnabled === false
  && accessibilityForm.fontScale === DEFAULT_FONT_SCALE
))

onMounted(async () => {
  await loadProfile()
})

watch(
  () => props.currentUser?.id,
  async (userId) => {
    if (!userId) {
      profile.value = null
      resetProfileForm()
      resetRepositoryForm()
      return
    }

    await loadProfile()
  },
)

watch(profileError, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'error')
  profileError.value = ''
})

watch(profileSuccess, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'success')
  profileSuccess.value = ''
})

watch(
  () => props.uiSettings,
  () => {
    syncAccessibilityForm()
  },
  {
    immediate: true,
    deep: true,
  },
)

watch(
  () => repositories.value.length,
  () => {
    if (repositoryPage.value > repositoryPageCount.value) {
      repositoryPage.value = repositoryPageCount.value
      return
    }

    if (repositoryPage.value < 1) {
      repositoryPage.value = 1
    }
  },
)

watch(
  () => repositoryForm.url,
  (nextUrl) => {
    const parsedRepository = parseGithubRepositoryUrl(nextUrl)
    if (!parsedRepository) {
      return
    }

    repositoryForm.ownerLogin = parsedRepository.ownerLogin
    repositoryForm.name = parsedRepository.name
  },
)

function notifyUser(message, type = 'info') {
  const normalizedMessage = String(message || '').trim()

  if (normalizedMessage === '' || typeof props.notify !== 'function') {
    return
  }

  props.notify({
    message: normalizedMessage,
    type,
  })
}

async function loadProfile() {
  profileLoading.value = true
  profileError.value = ''

  try {
    const { data } = await props.request({
      url: '/github/profile',
      method: 'GET',
    })

    profile.value = data?.profile || null
    repositoryPage.value = 1
    syncProfileForm()
    resetRepositoryForm()
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel carregar a configuracao GitHub do perfil.')
  } finally {
    profileLoading.value = false
  }
}

function resetProfileForm() {
  profileForm.repositoryOwner = ''
  profileForm.token = ''
  profileForm.clearToken = false
}

function syncProfileForm() {
  profileForm.repositoryOwner = typeof profile.value?.repositoryOwner === 'string' ? profile.value.repositoryOwner : ''
  profileForm.token = ''
  profileForm.clearToken = false
}

function resetRepositoryForm() {
  repositoryForm.ownerLogin = profileForm.repositoryOwner.trim() || ''
  repositoryForm.name = ''
  repositoryForm.url = ''
  repositoryForm.isIgnored = false
}

function syncAccessibilityForm() {
  accessibilityForm.colorVisionMode = savedColorVisionMode.value
  accessibilityForm.colorVisionIntensity = savedColorVisionIntensity.value
  accessibilityForm.highContrastEnabled = savedHighContrastEnabled.value
  accessibilityForm.fontScale = savedFontScale.value
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
        token: profileForm.token,
        clearToken: profileForm.clearToken,
      },
    })

    profile.value = data?.profile || null
    syncProfileForm()
    profileSuccess.value = 'Configuracao do GitHub salva com sucesso.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel salvar a configuracao do GitHub.')
  } finally {
    savingProfile.value = false
  }
}

async function createRepository() {
  savingRepository.value = true
  profileError.value = ''
  profileSuccess.value = ''

  try {
    const parsedRepository = parseGithubRepositoryUrl(repositoryForm.url)

    await props.request({
      url: '/github/repositories',
      method: 'POST',
      csrfActionId: 'github.repository.create',
      data: {
        ownerLogin: parsedRepository?.ownerLogin || repositoryForm.ownerLogin,
        name: parsedRepository?.name || repositoryForm.name,
        url: repositoryForm.url,
        isIgnored: repositoryForm.isIgnored,
      },
    })

    await loadProfile()
    resetRepositoryForm()
    profileSuccess.value = 'Repositorio cadastrado com sucesso.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel cadastrar o repositorio.')
  } finally {
    savingRepository.value = false
  }
}

async function toggleRepositoryIgnored(repository) {
  const repositoryId = Number(repository?.id || 0)
  if (!repositoryId) {
    return
  }

  savingRepositoryId.value = repositoryId
  profileError.value = ''
  profileSuccess.value = ''

  try {
    await props.request({
      url: `/github/repositories/${repositoryId}`,
      method: 'PATCH',
      csrfActionId: 'github.repository.update',
      data: {
        isIgnored: !repository?.isIgnored,
      },
    })

    await loadProfile()
    profileSuccess.value = repository?.isIgnored ? 'Repositorio reativado.' : 'Repositorio ignorado.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel atualizar o repositorio.')
  } finally {
    savingRepositoryId.value = 0
  }
}

async function removeRepository(repository) {
  const repositoryId = Number(repository?.id || 0)
  if (!repositoryId) {
    return
  }

  const repositoryName = String(repository?.nameWithOwner || '').trim()
  if (typeof window !== 'undefined') {
    const confirmed = window.confirm(`Remover o repositorio ${repositoryName || 'selecionado'} do sistema?`)
    if (!confirmed) {
      return
    }
  }

  deletingRepositoryId.value = repositoryId
  profileError.value = ''
  profileSuccess.value = ''

  try {
    await props.request({
      url: `/github/repositories/${repositoryId}`,
      method: 'DELETE',
      csrfActionId: 'github.repository.delete',
    })

    await loadProfile()
    profileSuccess.value = 'Repositorio removido do sistema.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel remover o repositorio.')
  } finally {
    deletingRepositoryId.value = 0
  }
}

function forwardSessionUpdate(session) {
  emit('session-updated', session)
}

function selectTheme(themeKey) {
  if (typeof props.setTheme === 'function') {
    props.setTheme(themeKey)
  }
}

async function saveAccessibilitySettings() {
  if (typeof props.updateUiSettings !== 'function') {
    return
  }

  savingAccessibility.value = true

  try {
    await props.updateUiSettings(
      {
        colorVisionMode: accessibilityForm.colorVisionMode,
        colorVisionIntensity: accessibilityForm.colorVisionIntensity,
        highContrastEnabled: accessibilityForm.highContrastEnabled,
        fontScale: accessibilityForm.fontScale,
      },
      {
        successMessage: 'Preferencias de acessibilidade atualizadas.',
      },
    )
  } finally {
    savingAccessibility.value = false
  }
}

async function restoreAccessibilityDefaults() {
  if (typeof props.updateUiSettings !== 'function') {
    return
  }

  savingAccessibility.value = true

  try {
    await props.updateUiSettings(
      {
        colorVisionMode: DEFAULT_COLOR_VISION_MODE,
        colorVisionIntensity: DEFAULT_COLOR_VISION_INTENSITY,
        highContrastEnabled: false,
        fontScale: DEFAULT_FONT_SCALE,
      },
      {
        successMessage: 'Acessibilidade restaurada para o padrao.',
      },
    )
  } finally {
    savingAccessibility.value = false
  }
}

function toggleSection(sectionKey) {
  if (!Object.prototype.hasOwnProperty.call(collapsedSections, sectionKey)) {
    return
  }

  collapsedSections[sectionKey] = !collapsedSections[sectionKey]
}

function isSectionOpen(sectionKey) {
  return !collapsedSections[sectionKey]
}

function setRepositoryPage(page) {
  const normalizedPage = Number(page)
  if (!Number.isFinite(normalizedPage)) {
    return
  }

  repositoryPage.value = Math.min(
    repositoryPageCount.value,
    Math.max(1, Math.trunc(normalizedPage)),
  )
}

function parseGithubRepositoryUrl(value) {
  const normalizedValue = String(value || '').trim()
  if (normalizedValue === '') {
    return null
  }

  const match = normalizedValue.match(/github\.com[:/]+([^/\s]+)\/([^/\s?#]+)/i)
  if (!match) {
    return null
  }

  const ownerLogin = String(match[1] || '').trim()
  const repositoryName = String(match[2] || '').replace(/\.git$/i, '').trim()

  if (ownerLogin === '' || repositoryName === '') {
    return null
  }

  return {
    ownerLogin,
    name: repositoryName,
  }
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
      <div class="panel-head-inline collapsible-head">
        <div>
          <p class="section-kicker">Perfil</p>
          <h2>Identidade da conta</h2>
        </div>

        <button
          class="panel-toggle-button"
          type="button"
          :aria-expanded="isSectionOpen('summary')"
          @click="toggleSection('summary')"
        >
          {{ isSectionOpen('summary') ? 'Recolher' : 'Abrir' }}
        </button>
      </div>

      <template v-if="isSectionOpen('summary')">
        <div class="profile-hero">
          <div class="profile-avatar">{{ displayEmail.slice(0, 1).toUpperCase() }}</div>
          <div>
            <strong>{{ displayEmail }}</strong>
            <p class="muted-copy">Conta autenticada no momento.</p>
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
      </template>
    </article>

    <article class="surface-card github-settings-card">
      <div class="panel-head-inline collapsible-head">
        <div>
          <p class="section-kicker">GitHub</p>
          <h2>Configuracao do GitHub</h2>
        </div>

        <button
          class="panel-toggle-button"
          type="button"
          :aria-expanded="isSectionOpen('github')"
          @click="toggleSection('github')"
        >
          {{ isSectionOpen('github') ? 'Recolher' : 'Abrir' }}
        </button>
      </div>

      <template v-if="isSectionOpen('github')">
        <div v-if="profileLoading" class="inline-note">Carregando configuracao do GitHub...</div>

        <template v-else>
          <div class="profile-stats">
            <div class="stat-chip">
              <span>Owner padrao</span>
              <strong>{{ profile?.repositoryOwner || 'nao definido' }}</strong>
            </div>
            <div class="stat-chip">
              <span>Token</span>
              <strong>{{ tokenConfigured ? 'Salvo' : 'Ausente' }}</strong>
            </div>
            <div class="stat-chip">
              <span>Repositorios</span>
              <strong>{{ repositories.length }}</strong>
            </div>
            <div class="stat-chip">
              <span>Status</span>
              <strong>{{ workspaceReady ? 'Pronto' : 'Incompleto' }}</strong>
            </div>
            <div class="stat-chip">
              <span>Padrao atual</span>
              <strong>{{ defaultRepositoryLabel }}</strong>
            </div>
          </div>

          <form class="settings-form" @submit.prevent="saveProfile">
            <label class="field">
              <span>Owner padrao</span>
              <input v-model="profileForm.repositoryOwner" type="text" placeholder="org-ou-usuario">
            </label>

            <label class="field">
              <span>GitHub token</span>
              <input
                v-model="profileForm.token"
                type="password"
                :placeholder="tokenConfigured ? 'Deixe vazio para manter o token atual' : 'ghp_xxxxxxxxxxxxxxxxxxxx'"
              >
            </label>

            <label class="checkbox-row">
              <input v-model="profileForm.clearToken" type="checkbox">
              <span>Remover token salvo</span>
            </label>

            <div class="form-actions">
              <button class="button-primary" type="submit" :disabled="savingProfile">
                {{ savingProfile ? 'Salvando...' : 'Salvar configuracao' }}
              </button>
            </div>
          </form>
        </template>
      </template>
    </article>

    <article class="surface-card repositories-card">
      <div class="panel-head-inline collapsible-head">
        <div>
          <p class="section-kicker">Repositorios</p>
          <h2>Repositorios cadastrados</h2>
        </div>

        <button
          class="panel-toggle-button"
          type="button"
          :aria-expanded="isSectionOpen('repositories')"
          @click="toggleSection('repositories')"
        >
          {{ isSectionOpen('repositories') ? 'Recolher' : 'Abrir' }}
        </button>
      </div>

      <template v-if="isSectionOpen('repositories')">
        <div class="profile-stats">
          <div class="stat-chip">
            <span>Ativos</span>
            <strong>{{ activeRepositoryCount }}</strong>
          </div>
          <div class="stat-chip">
            <span>Ignorados</span>
            <strong>{{ ignoredRepositoryCount }}</strong>
          </div>
          <div class="stat-chip">
            <span>Padrao atual</span>
            <strong>{{ defaultRepositoryLabel }}</strong>
          </div>
        </div>

        <form class="settings-form repository-register-form" @submit.prevent="createRepository">
          <div class="field-grid repository-form-grid">
            <label class="field">
              <span>Owner</span>
              <input v-model="repositoryForm.ownerLogin" type="text" placeholder="sua-org">
            </label>

            <label class="field">
              <span>Repositorio</span>
              <input v-model="repositoryForm.name" type="text" placeholder="nome-do-repo">
            </label>

            <label class="field">
              <span>URL</span>
              <input v-model="repositoryForm.url" type="url" placeholder="https://github.com/sua-org/nome-do-repo">
            </label>
          </div>

          <div class="form-actions form-actions-between">
            <label class="checkbox-row">
              <input v-model="repositoryForm.isIgnored" type="checkbox">
              <span>Cadastrar ja como ignorado</span>
            </label>

            <div class="form-actions-inline">
              <button class="button-secondary" type="button" :disabled="savingRepository" @click="resetRepositoryForm">
                Limpar
              </button>
              <button class="button-primary" type="submit" :disabled="savingRepository">
                {{ savingRepository ? 'Cadastrando...' : 'Cadastrar repositorio' }}
              </button>
            </div>
          </div>
        </form>

        <div v-if="repositories.length === 0" class="inline-note">
          Nenhum repositorio cadastrado.
        </div>

        <div v-else class="repository-list">
          <div
            v-for="repository in paginatedRepositories"
            :key="repository.id || repository.nameWithOwner"
            class="repository-row"
          >
            <div class="repository-copy">
              <strong>{{ repository.nameWithOwner }}</strong>
              <span>{{ repository.url || 'URL nao informada' }}</span>
            </div>

            <div class="repository-meta">
              <span>Owner: {{ repository.ownerLogin }}</span>
              <span>Nome: {{ repository.name }}</span>
            </div>

            <span class="repository-state" :class="{ ignored: repository.isIgnored }">
              {{ repository.isIgnored ? 'Ignorado' : 'Ativo' }}
            </span>

            <div class="repository-actions">
              <button
                class="button-secondary repository-action"
                type="button"
                :disabled="savingRepositoryId === repository.id || deletingRepositoryId === repository.id"
                @click="toggleRepositoryIgnored(repository)"
              >
                {{ savingRepositoryId === repository.id
                  ? 'Salvando...'
                  : repository.isIgnored
                    ? 'Reativar'
                    : 'Ignorar' }}
              </button>

              <button
                class="button-danger repository-action"
                type="button"
                :disabled="deletingRepositoryId === repository.id || savingRepositoryId === repository.id"
                @click="removeRepository(repository)"
              >
                {{ deletingRepositoryId === repository.id ? 'Removendo...' : 'Remover' }}
              </button>
            </div>
          </div>

          <div class="repository-pagination">
            <span class="repository-pagination-summary">
              {{ repositoryPageStart }}-{{ repositoryPageEnd }} de {{ repositories.length }} repositorios
            </span>

            <div v-if="repositoryPageCount > 1" class="repository-pagination-actions">
              <button
                class="button-secondary pagination-button"
                type="button"
                :disabled="repositoryPage <= 1"
                @click="setRepositoryPage(repositoryPage - 1)"
              >
                Anterior
              </button>

              <span class="repository-pagination-page">
                Pagina {{ repositoryPage }} de {{ repositoryPageCount }}
              </span>

              <button
                class="button-secondary pagination-button"
                type="button"
                :disabled="repositoryPage >= repositoryPageCount"
                @click="setRepositoryPage(repositoryPage + 1)"
              >
                Proxima
              </button>
            </div>
          </div>
        </div>
      </template>
    </article>

    <article class="surface-card profile-theme-card">
      <div class="panel-head-inline collapsible-head">
        <div>
          <p class="section-kicker">Tema</p>
          <h2>Aparencia do sistema</h2>
        </div>

        <button
          class="panel-toggle-button"
          type="button"
          :aria-expanded="isSectionOpen('theme')"
          @click="toggleSection('theme')"
        >
          {{ isSectionOpen('theme') ? 'Recolher' : 'Abrir' }}
        </button>
      </div>

      <div v-if="isSectionOpen('theme')" class="theme-grid">
        <button
          v-for="theme in themeOptions"
          :key="theme.key"
          type="button"
          class="theme-option"
          :class="{ selected: theme.key === activeThemeKey }"
          :aria-pressed="theme.key === activeThemeKey"
          @click="selectTheme(theme.key)"
        >
          <div class="theme-swatch-row" aria-hidden="true">
            <span class="theme-swatch" :style="{ background: theme.colors.primary }" />
            <span class="theme-swatch" :style="{ background: theme.colors.secondary }" />
            <span class="theme-swatch" :style="{ background: theme.colors.accent }" />
            <span class="theme-swatch theme-swatch-large" :style="{ background: theme.colors.bg }" />
            <span class="theme-swatch theme-swatch-large" :style="{ background: theme.colors.text }" />
          </div>

          <div class="theme-copy">
            <div class="theme-copy-head">
              <strong>{{ theme.label }}</strong>
              <span class="theme-badge">{{ theme.key === activeThemeKey ? 'Ativo' : 'Aplicar' }}</span>
            </div>
            <p>{{ theme.description }}</p>
          </div>
        </button>
      </div>
    </article>

    <article class="surface-card profile-accessibility-card">
      <div class="panel-head-inline collapsible-head">
        <div>
          <p class="section-kicker">Acessibilidade</p>
          <h2>Painel de acessibilidade</h2>
        </div>

        <button
          class="panel-toggle-button"
          type="button"
          :aria-expanded="isSectionOpen('accessibility')"
          @click="toggleSection('accessibility')"
        >
          {{ isSectionOpen('accessibility') ? 'Recolher' : 'Abrir' }}
        </button>
      </div>

      <template v-if="isSectionOpen('accessibility')">
        <div class="profile-stats accessibility-status-grid">
          <div class="stat-chip">
            <span>Daltonismo</span>
            <strong>{{ selectedColorVisionDefinition.label }}</strong>
          </div>
          <div class="stat-chip">
            <span>Intensidade</span>
            <strong>{{ accessibilityIntensityLabel }}</strong>
          </div>
          <div class="stat-chip">
            <span>Contraste</span>
            <strong>{{ accessibilityForm.highContrastEnabled ? 'Alto' : 'Padrao' }}</strong>
          </div>
          <div class="stat-chip">
            <span>Fonte</span>
            <strong>{{ selectedFontScaleDefinition.label }}</strong>
          </div>
        </div>

        <p class="inline-note accessibility-note">
          Esses ajustes sao aplicados globalmente em todo o sistema e ficam salvos na sua conta para as proximas sessoes.
        </p>

        <form class="settings-form accessibility-form" @submit.prevent="saveAccessibilitySettings">
          <div class="field-grid accessibility-grid">
            <label class="field">
              <span>Modo de daltonismo</span>
              <select v-model="accessibilityForm.colorVisionMode">
                <option v-for="option in colorVisionModeOptions" :key="option.key" :value="option.key">
                  {{ option.label }}
                </option>
              </select>
              <small class="field-help">{{ selectedColorVisionDefinition.description }}</small>
            </label>

            <label class="field">
              <span>Tamanho da fonte</span>
              <select v-model="accessibilityForm.fontScale">
                <option v-for="option in fontScaleOptions" :key="option.key" :value="option.key">
                  {{ option.label }}
                </option>
              </select>
              <small class="field-help">Ajusta a tipografia de toda a interface mantendo o layout responsivo.</small>
            </label>
          </div>

          <label class="field">
            <span>Intensidade do ajuste visual</span>
            <div class="slider-row">
              <input
                v-model.number="accessibilityForm.colorVisionIntensity"
                class="range-input"
                type="range"
                min="0"
                max="100"
                step="1"
                :disabled="accessibilityForm.colorVisionMode === DEFAULT_COLOR_VISION_MODE"
              >
              <strong>{{ accessibilityIntensityLabel }}</strong>
            </div>
            <small class="field-help">
              0 remove o filtro. 100 aplica a adaptacao visual completa para o modo selecionado.
            </small>
          </label>

          <label class="checkbox-row accessibility-checkbox">
            <input v-model="accessibilityForm.highContrastEnabled" type="checkbox">
            <span>Ativar alto contraste em toda a interface</span>
          </label>

          <div class="form-actions form-actions-between accessibility-actions">
            <p class="inline-note accessibility-note">
              Sempre e possivel voltar para o padrao do sistema usando o botao de restauracao.
            </p>

            <div class="form-actions-inline">
              <button
                class="button-secondary"
                type="button"
                :disabled="savingAccessibility || !hasAccessibilityChanges"
                @click="syncAccessibilityForm"
              >
                Descartar alteracoes
              </button>

              <button
                class="button-secondary"
                type="button"
                :disabled="savingAccessibility || isDefaultAccessibilityForm"
                @click="restoreAccessibilityDefaults"
              >
                Restaurar padrao
              </button>

              <button
                class="button-primary"
                type="submit"
                :disabled="savingAccessibility || !hasAccessibilityChanges"
              >
                {{ savingAccessibility ? 'Aplicando...' : 'Aplicar acessibilidade' }}
              </button>
            </div>
          </div>
        </form>
      </template>
    </article>

    <article class="surface-card profile-emails-card">
      <div class="panel-head-inline collapsible-head">
        <div>
          <p class="section-kicker">Conta</p>
          <h2>Emails vinculados</h2>
        </div>

        <button
          class="panel-toggle-button"
          type="button"
          :aria-expanded="isSectionOpen('emails')"
          @click="toggleSection('emails')"
        >
          {{ isSectionOpen('emails') ? 'Recolher' : 'Abrir' }}
        </button>
      </div>

      <AccountEmailsPanel
        v-if="isSectionOpen('emails')"
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
.repositories-card,
.profile-theme-card,
.profile-accessibility-card,
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
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--color-primary) 20%, transparent),
    color-mix(in srgb, var(--color-secondary) 18%, transparent)
  );
  border: 1px solid color-mix(in srgb, var(--color-primary) 22%, transparent);
  border-radius: 22px;
  color: var(--ink);
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
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.stat-chip {
  background: var(--surface-muted);
  border: 1px solid var(--line);
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
  background: color-mix(in srgb, var(--color-secondary) 16%, transparent);
  border-radius: 999px;
  color: var(--accent-strong);
  font-size: 0.8rem;
  font-weight: 800;
  padding: 8px 12px;
}

.theme-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.accessibility-status-grid {
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
}

.accessibility-form {
  gap: 18px;
}

.accessibility-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field-help {
  color: var(--muted);
  font-size: 0.84rem;
  line-height: 1.55;
}

.slider-row {
  align-items: center;
  display: grid;
  gap: 14px;
  grid-template-columns: minmax(0, 1fr) auto;
}

.range-input {
  accent-color: var(--accent);
  cursor: pointer;
  width: 100%;
}

.range-input:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.accessibility-checkbox {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 14px 16px;
}

.accessibility-note {
  line-height: 1.6;
}

.accessibility-actions {
  align-items: end;
}

.theme-option {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 24px;
  cursor: pointer;
  display: grid;
  gap: 14px;
  padding: 16px;
  text-align: left;
  transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.theme-option:hover {
  transform: translateY(-1px);
}

.theme-option.selected {
  background: color-mix(in srgb, var(--color-primary) 10%, var(--surface-strong));
  border-color: color-mix(in srgb, var(--color-primary) 68%, transparent);
  box-shadow: 0 14px 30px color-mix(in srgb, var(--color-primary) 14%, transparent);
}

.theme-swatch-row {
  display: grid;
  gap: 8px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.theme-swatch {
  border: 1px solid color-mix(in srgb, var(--color-bg) 12%, transparent);
  border-radius: 999px;
  display: block;
  height: 14px;
}

.theme-swatch-large {
  height: 18px;
}

.theme-copy {
  display: grid;
  gap: 8px;
}

.theme-copy p {
  color: var(--muted);
  margin: 0;
}

.theme-copy-head {
  align-items: center;
  display: flex;
  gap: 10px;
  justify-content: space-between;
}

.theme-badge {
  background: color-mix(in srgb, var(--color-secondary) 14%, transparent);
  border-radius: 999px;
  color: var(--accent-strong);
  font-size: 0.74rem;
  font-weight: 800;
  padding: 6px 10px;
  white-space: nowrap;
}

.panel-head-inline {
  align-items: start;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
}

.collapsible-head {
  align-items: center;
}

.panel-toggle-button {
  align-items: center;
  align-self: flex-start;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 999px;
  color: var(--ink);
  cursor: pointer;
  display: inline-flex;
  font-size: 0.84rem;
  font-weight: 700;
  min-height: 40px;
  padding: 0 14px;
  transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
}

.panel-toggle-button:hover {
  background: color-mix(in srgb, var(--color-primary) 8%, var(--surface));
  border-color: color-mix(in srgb, var(--color-primary) 30%, var(--line));
  transform: translateY(-1px);
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

.repository-form-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.checkbox-row {
  align-items: center;
  display: flex;
  gap: 10px;
}

.form-actions-inline {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.form-actions-between {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
}

.repository-list {
  display: grid;
  gap: 12px;
}

.repository-row {
  align-items: center;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 20px;
  display: grid;
  gap: 14px;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) auto auto;
  padding: 16px;
}

.repository-copy,
.repository-meta {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.repository-copy strong,
.repository-copy span,
.repository-meta span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.repository-copy span,
.repository-meta span {
  color: var(--muted);
  font-size: 0.88rem;
}

.repository-state {
  background: color-mix(in srgb, var(--color-secondary) 12%, transparent);
  border-radius: 999px;
  color: var(--accent-strong);
  display: inline-flex;
  font-size: 0.76rem;
  font-weight: 800;
  padding: 8px 12px;
  white-space: nowrap;
}

.repository-state.ignored {
  background: color-mix(in srgb, #f59e0b 14%, transparent);
  color: #b45309;
}

.repository-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  justify-content: flex-end;
}

.repository-action {
  min-width: 108px;
}

.button-danger {
  background: color-mix(in srgb, #ef4444 10%, var(--surface));
  border: 1px solid color-mix(in srgb, #ef4444 24%, var(--line));
  color: #b91c1c;
}

.button-danger:hover:not(:disabled) {
  background: color-mix(in srgb, #ef4444 14%, var(--surface));
}

.repository-pagination {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
}

.repository-pagination-summary,
.repository-pagination-page {
  color: var(--muted);
  font-size: 0.88rem;
}

.repository-pagination-actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.pagination-button {
  min-width: 96px;
}

@media (max-width: 1100px) {
  .repository-form-grid,
  .theme-grid,
  .accessibility-grid {
    grid-template-columns: 1fr;
  }

  .repository-row {
    grid-template-columns: 1fr;
  }

  .repository-actions {
    justify-content: flex-start;
  }
}

@media (max-width: 720px) {
  .profile-hero,
  .form-actions-between {
    align-items: start;
    flex-direction: column;
  }

  .profile-stats,
  .field-grid,
  .theme-grid,
  .accessibility-grid {
    grid-template-columns: 1fr;
  }

  .slider-row {
    grid-template-columns: 1fr;
  }
}
</style>
