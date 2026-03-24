<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useNotification } from '../../composables/useNotification'
import {
  createGithubAccount,
  createGithubRepository,
  deleteGithubAccount,
  deleteGithubRepository,
  fetchGithubProfile,
  updateGithubAccount,
  updateGithubRepository,
} from '../../services/githubWorkspace'
import { parseGithubRepositoryUrl } from '../../utils/githubRepository'
import { extractHttpMessage } from '../../utils/httpErrors'
import AccountEmailsPanel from '../AccountEmailsPanel.vue'
import {
  COLOR_VISION_MODE_OPTIONS,
  CUSTOM_THEME_COLOR_KEYS,
  CUSTOM_THEME_KEY,
  DEFAULT_COLOR_VISION_INTENSITY,
  DEFAULT_COLOR_VISION_MODE,
  DEFAULT_CUSTOM_THEME_PALETTE,
  DEFAULT_FONT_SCALE,
  FONT_SCALE_OPTIONS,
  getColorVisionModeDefinition,
  getFontScaleDefinition,
  normalizeColorVisionIntensity,
  normalizeColorVisionMode,
  normalizeCustomThemePalette,
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
const creatingAccount = ref(false)
const savingAccountId = ref(0)
const deletingAccountId = ref(0)
const savingAccessibility = ref(false)
const savingCustomTheme = ref(false)
const savingRepositoryAccountId = ref(0)
const savingRepositoryId = ref(0)
const deletingRepositoryId = ref(0)
const collapsedSections = reactive({
  summary: false,
  github: true,
  theme: true,
  accessibility: true,
  emails: true,
})
const newAccountForm = reactive({
  accountLogin: '',
  token: '',
})
const accountForms = reactive({})
const repositoryForms = reactive({})
const repositoryPages = reactive({})
const accessibilityForm = reactive({
  colorVisionMode: DEFAULT_COLOR_VISION_MODE,
  colorVisionIntensity: DEFAULT_COLOR_VISION_INTENSITY,
  highContrastEnabled: false,
  fontScale: DEFAULT_FONT_SCALE,
})
const customThemeForm = reactive({
  ...DEFAULT_CUSTOM_THEME_PALETTE,
})
const { notifyUser } = useNotification(props.notify)

const displayEmail = computed(() => props.currentUser?.defaultEmail || props.currentUser?.email || 'Nao definido')
const linkedEmailCount = computed(() => Array.isArray(props.currentUser?.linkedEmails) ? props.currentUser.linkedEmails.length : 0)
const tokenConfigured = computed(() => Boolean(profile.value?.tokenConfigured))
const workspaceReady = computed(() => Boolean(profile.value?.workspaceReady))
const themeOptions = computed(() => Array.isArray(props.availableThemes) ? props.availableThemes : [])
const accounts = computed(() => Array.isArray(profile.value?.accounts) ? profile.value.accounts : [])
const repositories = computed(() => Array.isArray(profile.value?.repositories) ? profile.value.repositories : [])
const accountCount = computed(() => accounts.value.length)
const ignoredRepositoryCount = computed(() => repositories.value.filter((repository) => repository?.isIgnored).length)
const activeRepositoryCount = computed(() => repositories.value.filter((repository) => !repository?.isIgnored).length)
const defaultRepositoryLabel = computed(() => {
  const repositoryKey = typeof profile.value?.defaultRepositoryKey === 'string' ? profile.value.defaultRepositoryKey.trim() : ''
  return repositoryKey !== '' ? repositoryKey : 'nao definido'
})
const colorVisionModeOptions = COLOR_VISION_MODE_OPTIONS
const fontScaleOptions = FONT_SCALE_OPTIONS
const normalizedUiSettings = computed(() => normalizeUiSettingsPayload(props.uiSettings || {}))
const savedCustomThemePalette = computed(() => normalizeCustomThemePalette(normalizedUiSettings.value.customThemePalette))
const savedColorVisionMode = computed(() => normalizeColorVisionMode(normalizedUiSettings.value.colorVisionMode))
const savedColorVisionIntensity = computed(() => normalizeColorVisionIntensity(normalizedUiSettings.value.colorVisionIntensity))
const savedHighContrastEnabled = computed(() => normalizeHighContrastEnabled(normalizedUiSettings.value.highContrastEnabled))
const savedFontScale = computed(() => normalizeFontScale(normalizedUiSettings.value.fontScale))
const customThemeColorPickers = [
  { key: 'primary', label: 'Primaria' },
  { key: 'secondary', label: 'Secundaria' },
  { key: 'accent', label: 'Acento' },
  { key: 'bg', label: 'Fundo' },
  { key: 'text', label: 'Texto' },
]
const isCustomThemeActive = computed(() => normalizeUiSettingsPayload({ themeKey: props.activeThemeKey }).themeKey === CUSTOM_THEME_KEY)
const hasCustomThemeChanges = computed(() => CUSTOM_THEME_COLOR_KEYS.some((colorKey) => (
  customThemeForm[colorKey] !== savedCustomThemePalette.value[colorKey]
)))
const canSaveCustomTheme = computed(() => hasCustomThemeChanges.value || !isCustomThemeActive.value)
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
      clearGithubForms()
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
    syncCustomThemeForm()
  },
  {
    immediate: true,
    deep: true,
  },
)

async function loadProfile() {
  profileLoading.value = true
  profileError.value = ''

  try {
    const { data } = await fetchGithubProfile(props.request)
    profile.value = data?.profile || null
    syncGithubForms()
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel carregar a configuracao GitHub do perfil.')
  } finally {
    profileLoading.value = false
  }
}

function clearObjectMap(target) {
  Object.keys(target).forEach((key) => {
    delete target[key]
  })
}

function resetNewAccountForm() {
  newAccountForm.accountLogin = ''
  newAccountForm.token = ''
}

function clearGithubForms() {
  resetNewAccountForm()
  clearObjectMap(accountForms)
  clearObjectMap(repositoryForms)
  clearObjectMap(repositoryPages)
}

function getAccountId(account) {
  return Number(account?.id || 0)
}

function getAccountById(accountId) {
  return accounts.value.find((account) => getAccountId(account) === Number(accountId)) || null
}

function getAccountRepositories(account) {
  return Array.isArray(account?.repositories) ? account.repositories : []
}

function getAccountDefaultRepositoryLabel(account) {
  const repositoryKey = typeof account?.defaultRepositoryKey === 'string' ? account.defaultRepositoryKey.trim() : ''
  return repositoryKey !== '' ? repositoryKey : 'nao definido'
}

function getAccountActiveRepositoryCount(account) {
  return getAccountRepositories(account).filter((repository) => !repository?.isIgnored).length
}

function getAccountIgnoredRepositoryCount(account) {
  return getAccountRepositories(account).filter((repository) => repository?.isIgnored).length
}

function getAccountRepositoryPage(accountId) {
  return Math.max(1, Number(repositoryPages[accountId] || 1))
}

function getAccountRepositoryPageCount(account) {
  return Math.max(1, Math.ceil(getAccountRepositories(account).length / REPOSITORY_PAGE_SIZE))
}

function getPaginatedRepositories(account) {
  const accountId = getAccountId(account)
  const page = getAccountRepositoryPage(accountId)
  const startIndex = (page - 1) * REPOSITORY_PAGE_SIZE

  return getAccountRepositories(account).slice(startIndex, startIndex + REPOSITORY_PAGE_SIZE)
}

function getRepositoryPageStart(account) {
  const repositoriesForAccount = getAccountRepositories(account)
  if (repositoriesForAccount.length === 0) {
    return 0
  }

  return (getAccountRepositoryPage(getAccountId(account)) - 1) * REPOSITORY_PAGE_SIZE + 1
}

function getRepositoryPageEnd(account) {
  const repositoriesForAccount = getAccountRepositories(account)
  if (repositoriesForAccount.length === 0) {
    return 0
  }

  return Math.min(
    getAccountRepositoryPage(getAccountId(account)) * REPOSITORY_PAGE_SIZE,
    repositoriesForAccount.length,
  )
}

function syncAccountForm(account) {
  const accountId = getAccountId(account)
  if (!accountId) {
    return
  }

  accountForms[accountId] = {
    accountLogin: typeof account?.accountLogin === 'string' ? account.accountLogin : '',
    token: '',
    clearToken: false,
  }
}

function resetRepositoryForm(accountId) {
  const account = getAccountById(accountId)

  repositoryForms[accountId] = {
    ownerLogin: typeof account?.accountLogin === 'string' ? account.accountLogin : '',
    name: '',
    url: '',
    isIgnored: false,
  }
}

function syncGithubForms() {
  const activeAccountIds = new Set()

  for (const account of accounts.value) {
    const accountId = getAccountId(account)
    if (!accountId) {
      continue
    }

    activeAccountIds.add(String(accountId))
    syncAccountForm(account)
    resetRepositoryForm(accountId)
    repositoryPages[accountId] = Math.min(
      getAccountRepositoryPageCount(account),
      Math.max(1, Number(repositoryPages[accountId] || 1)),
    )
  }

  Object.keys(accountForms).forEach((key) => {
    if (!activeAccountIds.has(String(key))) {
      delete accountForms[key]
    }
  })

  Object.keys(repositoryForms).forEach((key) => {
    if (!activeAccountIds.has(String(key))) {
      delete repositoryForms[key]
    }
  })

  Object.keys(repositoryPages).forEach((key) => {
    if (!activeAccountIds.has(String(key))) {
      delete repositoryPages[key]
    }
  })
}

function syncAccessibilityForm() {
  accessibilityForm.colorVisionMode = savedColorVisionMode.value
  accessibilityForm.colorVisionIntensity = savedColorVisionIntensity.value
  accessibilityForm.highContrastEnabled = savedHighContrastEnabled.value
  accessibilityForm.fontScale = savedFontScale.value
}

function syncCustomThemeForm() {
  const normalizedCustomThemePalette = savedCustomThemePalette.value

  for (const colorKey of CUSTOM_THEME_COLOR_KEYS) {
    customThemeForm[colorKey] = normalizedCustomThemePalette[colorKey]
  }
}

async function createAccount() {
  creatingAccount.value = true
  profileError.value = ''
  profileSuccess.value = ''

  try {
    const { data } = await createGithubAccount(props.request, {
      accountLogin: newAccountForm.accountLogin,
      token: newAccountForm.token,
    })
    profile.value = data?.profile || null
    resetNewAccountForm()
    syncGithubForms()
    profileSuccess.value = 'Conta GitHub adicionada com sucesso.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel adicionar a conta GitHub.')
  } finally {
    creatingAccount.value = false
  }
}

async function saveAccount(account) {
  const accountId = getAccountId(account)
  if (!accountId || !accountForms[accountId]) {
    return
  }

  savingAccountId.value = accountId
  profileError.value = ''
  profileSuccess.value = ''

  try {
    const { data } = await updateGithubAccount(props.request, accountId, {
      accountLogin: accountForms[accountId].accountLogin,
      token: accountForms[accountId].token,
      clearToken: accountForms[accountId].clearToken,
    })

    profile.value = data?.profile || null
    syncGithubForms()
    profileSuccess.value = 'Conta GitHub atualizada com sucesso.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel atualizar a conta GitHub.')
  } finally {
    savingAccountId.value = 0
  }
}

async function removeAccount(account) {
  const accountId = getAccountId(account)
  if (!accountId) {
    return
  }

  const accountLabel = String(account?.accountLogin || '').trim()
  if (typeof window !== 'undefined') {
    const confirmed = window.confirm(`Remover a conta GitHub ${accountLabel || 'selecionada'} e todos os repositorios vinculados?`)
    if (!confirmed) {
      return
    }
  }

  deletingAccountId.value = accountId
  profileError.value = ''
  profileSuccess.value = ''

  try {
    await deleteGithubAccount(props.request, accountId)
    await loadProfile()
    profileSuccess.value = 'Conta GitHub removida com sucesso.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel remover a conta GitHub.')
  } finally {
    deletingAccountId.value = 0
  }
}

async function createRepository(account) {
  const accountId = getAccountId(account)
  const repositoryForm = repositoryForms[accountId]
  if (!accountId || !repositoryForm) {
    return
  }

  savingRepositoryAccountId.value = accountId
  profileError.value = ''
  profileSuccess.value = ''

  try {
    const parsedRepository = parseGithubRepositoryUrl(repositoryForm.url)

    await createGithubRepository(props.request, {
      accountId,
      ownerLogin: parsedRepository?.ownerLogin || repositoryForm.ownerLogin,
      name: parsedRepository?.name || repositoryForm.name,
      url: repositoryForm.url,
      isIgnored: repositoryForm.isIgnored,
    })

    await loadProfile()
    resetRepositoryForm(accountId)
    profileSuccess.value = 'Repositorio cadastrado com sucesso.'
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel cadastrar o repositorio.')
  } finally {
    savingRepositoryAccountId.value = 0
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
    await updateGithubRepository(props.request, repositoryId, {
      isIgnored: !repository?.isIgnored,
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
    await deleteGithubRepository(props.request, repositoryId)
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

async function selectTheme(themeKey) {
  try {
    if (themeKey === CUSTOM_THEME_KEY && typeof props.updateUiSettings === 'function') {
      await props.updateUiSettings({
        themeKey: CUSTOM_THEME_KEY,
        customThemePalette: normalizeCustomThemePalette(customThemeForm),
      })
      return
    }

    if (typeof props.setTheme === 'function') {
      await props.setTheme(themeKey)
      return
    }

    if (typeof props.updateUiSettings === 'function') {
      await props.updateUiSettings({ themeKey })
    }
  } catch {
    // updateUiSettings ja exibe feedback de erro.
  }
}

function restoreCustomThemeDefaults() {
  const normalizedDefaultPalette = normalizeCustomThemePalette(DEFAULT_CUSTOM_THEME_PALETTE)

  for (const colorKey of CUSTOM_THEME_COLOR_KEYS) {
    customThemeForm[colorKey] = normalizedDefaultPalette[colorKey]
  }
}

async function saveCustomTheme() {
  if (typeof props.updateUiSettings !== 'function') {
    return
  }

  savingCustomTheme.value = true

  try {
    await props.updateUiSettings(
      {
        themeKey: CUSTOM_THEME_KEY,
        customThemePalette: normalizeCustomThemePalette(customThemeForm),
      },
      {
        successMessage: 'Tema personalizado salvo e aplicado.',
      },
    )
  } finally {
    savingCustomTheme.value = false
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

function setRepositoryPage(accountId, page, repositoryPageCount) {
  const normalizedPage = Number(page)
  if (!Number.isFinite(normalizedPage)) {
    return
  }

  repositoryPages[accountId] = Math.min(
    repositoryPageCount,
    Math.max(1, Math.trunc(normalizedPage)),
  )
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
          <h2>Contas GitHub e repositorios</h2>
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

        <div v-else class="github-section-stack">
          <div class="profile-stats">
            <div class="stat-chip">
              <span>Contas GitHub</span>
              <strong>{{ accountCount }}</strong>
            </div>
            <div class="stat-chip">
              <span>Tokens</span>
              <strong>{{ tokenConfigured ? 'Configurados' : 'Ausentes' }}</strong>
            </div>
            <div class="stat-chip">
              <span>Repositorios</span>
              <strong>{{ repositories.length }}</strong>
            </div>
            <div class="stat-chip">
              <span>Ativos</span>
              <strong>{{ activeRepositoryCount }}</strong>
            </div>
            <div class="stat-chip">
              <span>Ignorados</span>
              <strong>{{ ignoredRepositoryCount }}</strong>
            </div>
            <div class="stat-chip">
              <span>Workspace</span>
              <strong>{{ workspaceReady ? 'Pronto' : 'Incompleto' }}</strong>
            </div>
            <div class="stat-chip">
              <span>Padrao atual</span>
              <strong>{{ defaultRepositoryLabel }}</strong>
            </div>
          </div>

          <section class="github-account-create-card">
            <div class="panel-head-inline account-panel-head">
              <div class="account-copy">
                <p class="section-kicker">GitHub</p>
                <h3>{{ accountCount > 0 ? 'Adicionar outra conta GitHub' : 'Cadastrar primeira conta GitHub' }}</h3>
                <p>Cadastre quantas contas precisar. Cada conta tera seu proprio container de repositorios.</p>
              </div>
            </div>

            <form class="settings-form" @submit.prevent="createAccount">
              <div class="field-grid account-form-grid">
                <label class="field">
                  <span>Login da conta</span>
                  <input v-model="newAccountForm.accountLogin" type="text" placeholder="org-ou-usuario">
                </label>

                <label class="field">
                  <span>GitHub token</span>
                  <input v-model="newAccountForm.token" type="password" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx">
                </label>
              </div>

              <div class="form-actions form-actions-between">
                <p class="inline-note">
                  O token fica vinculado apenas a esta conta GitHub e sera usado pelos repositorios cadastrados dentro dela.
                </p>

                <div class="form-actions-inline">
                  <button class="button-secondary" type="button" :disabled="creatingAccount" @click="resetNewAccountForm">
                    Limpar
                  </button>
                  <button class="button-primary" type="submit" :disabled="creatingAccount">
                    {{ creatingAccount ? 'Adicionando...' : 'Adicionar conta GitHub' }}
                  </button>
                </div>
              </div>
            </form>
          </section>

          <div v-if="accountCount === 0" class="inline-note">
            Nenhuma conta GitHub cadastrada. Adicione uma conta para liberar seus repositorios.
          </div>

          <div v-else class="github-accounts-grid">
            <section
              v-for="account in accounts"
              :key="account.id || account.accountLogin"
              class="github-account-panel"
            >
              <div class="panel-head-inline account-panel-head">
                <div class="account-copy">
                  <p class="section-kicker">GitHub</p>
                  <h3>{{ account.accountLogin || 'Conta GitHub' }}</h3>
                  <p>Esta conta controla seus repositorios, token e disponibilidade do workspace.</p>
                </div>

                <span class="repository-state" :class="{ ignored: !account.workspaceReady }">
                  {{ account.workspaceReady ? 'Pronto' : 'Incompleto' }}
                </span>
              </div>

              <div class="profile-stats">
                <div class="stat-chip">
                  <span>Token</span>
                  <strong>{{ account.tokenConfigured ? 'Salvo' : 'Ausente' }}</strong>
                </div>
                <div class="stat-chip">
                  <span>Repositorios</span>
                  <strong>{{ getAccountRepositories(account).length }}</strong>
                </div>
                <div class="stat-chip">
                  <span>Ativos</span>
                  <strong>{{ getAccountActiveRepositoryCount(account) }}</strong>
                </div>
                <div class="stat-chip">
                  <span>Ignorados</span>
                  <strong>{{ getAccountIgnoredRepositoryCount(account) }}</strong>
                </div>
                <div class="stat-chip">
                  <span>Padrao desta conta</span>
                  <strong>{{ getAccountDefaultRepositoryLabel(account) }}</strong>
                </div>
              </div>

              <form class="settings-form" @submit.prevent="saveAccount(account)">
                <div class="field-grid account-form-grid">
                  <label class="field">
                    <span>Login da conta</span>
                    <input
                      v-model="accountForms[account.id].accountLogin"
                      type="text"
                      placeholder="org-ou-usuario"
                    >
                  </label>

                  <label class="field">
                    <span>Novo token</span>
                    <input
                      v-model="accountForms[account.id].token"
                      type="password"
                      :placeholder="account.tokenConfigured ? 'Deixe vazio para manter o token atual' : 'ghp_xxxxxxxxxxxxxxxxxxxx'"
                    >
                  </label>
                </div>

                <div class="form-actions form-actions-between">
                  <label class="checkbox-row">
                    <input v-model="accountForms[account.id].clearToken" type="checkbox">
                    <span>Remover token salvo desta conta</span>
                  </label>

                  <div class="form-actions-inline">
                    <button
                      class="button-danger"
                      type="button"
                      :disabled="deletingAccountId === account.id || savingAccountId === account.id"
                      @click="removeAccount(account)"
                    >
                      {{ deletingAccountId === account.id ? 'Removendo...' : 'Remover conta' }}
                    </button>
                    <button
                      class="button-primary"
                      type="submit"
                      :disabled="savingAccountId === account.id || deletingAccountId === account.id"
                    >
                      {{ savingAccountId === account.id ? 'Salvando...' : 'Salvar conta' }}
                    </button>
                  </div>
                </div>
              </form>

              <section class="repository-group-card">
                <div class="panel-head-inline account-panel-head">
                  <div class="account-copy">
                    <p class="section-kicker">Repositorios</p>
                    <h4>Repositorios da conta {{ account.accountLogin }}</h4>
                    <p>Cadastre aqui apenas os repositorios que devem usar este token e este owner principal.</p>
                  </div>
                </div>

                <form class="settings-form repository-register-form" @submit.prevent="createRepository(account)">
                  <div class="field-grid repository-form-grid">
                    <label class="field">
                      <span>Owner</span>
                      <input
                        v-model="repositoryForms[account.id].ownerLogin"
                        type="text"
                        placeholder="sua-org"
                      >
                    </label>

                    <label class="field">
                      <span>Repositorio</span>
                      <input
                        v-model="repositoryForms[account.id].name"
                        type="text"
                        placeholder="nome-do-repo"
                      >
                    </label>

                    <label class="field">
                      <span>URL</span>
                      <input
                        v-model="repositoryForms[account.id].url"
                        type="url"
                        placeholder="https://github.com/sua-org/nome-do-repo"
                      >
                    </label>
                  </div>

                  <div class="form-actions form-actions-between">
                    <label class="checkbox-row">
                      <input v-model="repositoryForms[account.id].isIgnored" type="checkbox">
                      <span>Cadastrar ja como ignorado</span>
                    </label>

                    <div class="form-actions-inline">
                      <button
                        class="button-secondary"
                        type="button"
                        :disabled="savingRepositoryAccountId === account.id"
                        @click="resetRepositoryForm(account.id)"
                      >
                        Limpar
                      </button>
                      <button
                        class="button-primary"
                        type="submit"
                        :disabled="savingRepositoryAccountId === account.id"
                      >
                        {{ savingRepositoryAccountId === account.id ? 'Cadastrando...' : 'Cadastrar repositorio' }}
                      </button>
                    </div>
                  </div>
                </form>

                <div v-if="getAccountRepositories(account).length === 0" class="inline-note">
                  Nenhum repositorio cadastrado para esta conta.
                </div>

                <div v-else class="repository-list">
                  <div
                    v-for="repository in getPaginatedRepositories(account)"
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
                      {{ getRepositoryPageStart(account) }}-{{ getRepositoryPageEnd(account) }} de {{ getAccountRepositories(account).length }} repositorios
                    </span>

                    <div
                      v-if="getAccountRepositoryPageCount(account) > 1"
                      class="repository-pagination-actions"
                    >
                      <button
                        class="button-secondary pagination-button"
                        type="button"
                        :disabled="getAccountRepositoryPage(account.id) <= 1"
                        @click="setRepositoryPage(account.id, getAccountRepositoryPage(account.id) - 1, getAccountRepositoryPageCount(account))"
                      >
                        Anterior
                      </button>

                      <span class="repository-pagination-page">
                        Pagina {{ getAccountRepositoryPage(account.id) }} de {{ getAccountRepositoryPageCount(account) }}
                      </span>

                      <button
                        class="button-secondary pagination-button"
                        type="button"
                        :disabled="getAccountRepositoryPage(account.id) >= getAccountRepositoryPageCount(account)"
                        @click="setRepositoryPage(account.id, getAccountRepositoryPage(account.id) + 1, getAccountRepositoryPageCount(account))"
                      >
                        Proxima
                      </button>
                    </div>
                  </div>
                </div>
              </section>
            </section>
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

      <div v-if="isSectionOpen('theme')" class="theme-stack">
        <div class="theme-grid">
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

        <section v-if="isCustomThemeActive" class="custom-theme-card">
          <div class="panel-head-inline">
            <div>
              <p class="section-kicker">Personalizado</p>
            </div>
            <span class="theme-badge">{{ isCustomThemeActive ? 'Tema ativo' : 'Tema inativo' }}</span>
          </div>
          <div class="custom-theme-grid">
            <label
              v-for="picker in customThemeColorPickers"
              :key="picker.key"
              class="field custom-theme-field"
            >
              <span>{{ picker.label }}</span>
              <div class="custom-theme-color-row">
                <input
                  v-model="customThemeForm[picker.key]"
                  class="custom-theme-color-picker"
                  type="color"
                  :aria-label="`Cor ${picker.label}`"
                >
                <code class="custom-theme-color-value">{{ customThemeForm[picker.key] }}</code>
              </div>
            </label>
          </div>

          <div class="form-actions form-actions-between">
            <div class="form-actions-inline">
              <button
                class="button-secondary"
                type="button"
                :disabled="savingCustomTheme || !hasCustomThemeChanges"
                @click="syncCustomThemeForm"
              >
                Descartar alteracoes
              </button>
              <button
                class="button-secondary"
                type="button"
                :disabled="savingCustomTheme"
                @click="restoreCustomThemeDefaults"
              >
                Restaurar 5 cores padrao
              </button>
            </div>

            <button
              class="button-primary"
              type="button"
              :disabled="savingCustomTheme || !canSaveCustomTheme"
              @click="saveCustomTheme"
            >
              {{ savingCustomTheme ? 'Salvando...' : 'Salvar e aplicar personalizado' }}
            </button>
          </div>
        </section>
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
.profile-theme-card,
.profile-accessibility-card,
.profile-emails-card {
  display: grid;
  gap: 18px;
}

.github-section-stack,
.github-accounts-grid {
  display: grid;
  gap: 18px;
}

.github-account-create-card,
.github-account-panel,
.repository-group-card {
  background: var(--surface-muted);
  border: 1px solid var(--line);
  border-radius: 24px;
  display: grid;
  gap: 18px;
  padding: 18px;
}

.github-account-panel {
  background: color-mix(in srgb, var(--color-primary) 4%, var(--surface));
}

.repository-group-card {
  background: var(--surface);
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

.theme-stack {
  display: grid;
  gap: 16px;
}

.custom-theme-card {
  background: var(--surface-muted);
  border: 1px solid var(--line);
  border-radius: 24px;
  display: grid;
  gap: 14px;
  padding: 16px;
}

.custom-theme-card h3 {
  margin: 0;
}

.custom-theme-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

.custom-theme-field {
  gap: 8px;
}

.custom-theme-color-row {
  align-items: center;
  display: flex;
  gap: 10px;
}

.custom-theme-color-picker {
  background: transparent;
  border: 1px solid var(--line);
  border-radius: 12px;
  cursor: pointer;
  height: 42px;
  padding: 4px;
  width: 52px;
}

.custom-theme-color-value {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 999px;
  color: var(--ink);
  display: inline-flex;
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  min-height: 34px;
  padding: 0 12px;
  text-transform: lowercase;
  align-items: center;
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

.account-form-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.account-copy {
  display: grid;
  gap: 6px;
}

.account-copy h3,
.account-copy h4,
.account-copy p {
  margin: 0;
}

.account-copy p:last-child {
  color: var(--muted);
  line-height: 1.6;
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
  background: color-mix(in srgb, var(--warning) 14%, transparent);
  color: var(--warning);
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
  background: color-mix(in srgb, var(--danger) 10%, var(--surface));
  border: 1px solid color-mix(in srgb, var(--danger) 24%, var(--line));
  color: var(--danger);
}

.button-danger:hover:not(:disabled) {
  background: color-mix(in srgb, var(--danger) 14%, var(--surface));
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
  .account-form-grid,
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
