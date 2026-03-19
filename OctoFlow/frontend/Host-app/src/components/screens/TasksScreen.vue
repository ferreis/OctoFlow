<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useNotification } from '../../composables/useNotification'
import { formatDateTime } from '../../utils/date'
import { splitRepositoryKey } from '../../utils/githubRepository'
import { extractHttpMessage } from '../../utils/httpErrors'
import { fetchLocalTasks, fetchTaskUpdateTemplates } from '../../services/tasks'
import IssueCreateModal from '../tasks/IssueCreateModal.vue'
import IssueEditModal from '../tasks/IssueEditModal.vue'
import LocalTaskEditModal from '../tasks/LocalTaskEditModal.vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  notify: {
    type: Function,
    default: null,
  },
  currentUser: {
    type: Object,
    default: null,
  },
})

const { notifyUser } = useNotification(props.notify)

const issueBoard = ref(null)
const localTaskBoard = ref(null)
const loadingCache = ref(false)
const loadingLocalTasks = ref(false)
const syncing = ref(false)
const error = ref('')
const success = ref('')
const info = ref('')
const filtersOpen = ref(false)
const createModalOpen = ref(false)
const editingIssueId = ref('')
const editingLocalTaskId = ref(null)
const issueUpdateTemplates = ref([])
const selectedIssueId = ref('')
const issueScope = ref('all')
const sourceFilter = ref('all')
const stateFilter = ref('open')
const searchTerm = ref('')
const selectedLabel = ref('all')
const selectedTicketType = ref('all')
const selectedRepositoryKey = ref('all')
const draftIssueScope = ref('all')
const draftSourceFilter = ref('all')
const draftStateFilter = ref('open')
const draftSearchTerm = ref('')
const draftSelectedLabel = ref('all')
const draftSelectedTicketType = ref('all')
const draftSelectedRepositoryKey = ref('all')
const currentPage = ref(1)
const ITEMS_PER_PAGE = 10

const repositories = computed(() => Array.isArray(issueBoard.value?.repositories) ? issueBoard.value.repositories : [])
const issues = computed(() => Array.isArray(issueBoard.value?.items) ? issueBoard.value.items : [])
const localTasks = computed(() => Array.isArray(localTaskBoard.value?.items) ? localTaskBoard.value.items : [])
const localTaskStats = computed(() => localTaskBoard.value?.stats || { total: 0, pending: 0, failed: 0 })
const editingIssue = computed(() => issues.value.find((issue) => issue.id === editingIssueId.value) || null)
const editingLocalTask = computed(() => localTasks.value.find((task) => task.id === editingLocalTaskId.value) || null)
const activeRepository = computed(() => issueBoard.value?.repository || null)
const cacheMeta = computed(() => issueBoard.value?.cache || null)
const repositoriesCount = computed(() => repositories.value.length)
const repositoryFilterOptions = computed(() => {
  const catalog = new Map()

  for (const repository of repositories.value) {
    const key = String(repository?.nameWithOwner || '').trim()
    if (key !== '') {
      catalog.set(key, key)
    }
  }

  for (const task of localTasks.value) {
    const key = String(task?.repositoryKey || '').trim()
    if (key !== '') {
      catalog.set(key, key)
    }
  }

  return Array.from(catalog.values()).sort((left, right) => left.localeCompare(right, 'pt-BR'))
})
const availableLabels = computed(() => {
  const labelsMap = new Map()

  for (const issue of issues.value) {
    for (const label of issue.labels || []) {
      if (!label?.name || labelsMap.has(label.name)) {
        continue
      }

      labelsMap.set(label.name, label)
    }
  }

  return Array.from(labelsMap.values()).sort((left, right) => left.name.localeCompare(right.name, 'pt-BR'))
})
const availableTicketTypes = computed(() => {
  const types = new Map()

  for (const issue of issues.value) {
    const type = detectTicketType(issue)
    if (type.key === 'other' || types.has(type.key)) {
      continue
    }

    types.set(type.key, type)
  }

  return Array.from(types.values())
})
const taskEntries = computed(() => {
  const githubEntries = issues.value.map((issue) => ({
    entryKey: `github:${issue.id}`,
    entryType: 'github',
    searchableText: [
      issue.title,
      issue.body,
      issue.repository?.nameWithOwner,
      issue.authorLogin,
      ...(issue.labels || []).map((label) => label.name),
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase(),
    repositoryKey: issue.repository?.nameWithOwner || '',
    state: issue.state,
    updatedAt: issue.updatedAt,
    createdAt: issue.createdAt,
    ticketType: detectTicketType(issue),
    issue,
  }))

  const localEntries = localTasks.value.map((task) => ({
    entryKey: `local:${task.id}`,
    entryType: 'local',
    searchableText: [
      task.title,
      task.body,
      task.repositoryKey,
      task.templateKey,
      task.syncError,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase(),
    repositoryKey: task.repositoryKey || '',
    state: task.state,
    updatedAt: task.updatedAt,
    createdAt: task.createdAt,
    ticketType: detectTicketType(task),
    localTask: task,
  }))

  return [...githubEntries, ...localEntries].sort((left, right) => {
    return String(right.updatedAt || '').localeCompare(String(left.updatedAt || ''))
  })
})
const filteredTaskEntries = computed(() => {
  const normalizedSearch = searchTerm.value.trim().toLowerCase()

  return taskEntries.value.filter((entry) => {
    if (sourceFilter.value !== 'all' && entry.entryType !== sourceFilter.value) {
      return false
    }

    if (stateFilter.value === 'open' && entry.state === 'CLOSED') {
      return false
    }

    if (stateFilter.value === 'closed' && entry.state !== 'CLOSED') {
      return false
    }

    if (selectedRepositoryKey.value !== 'all' && entry.repositoryKey !== selectedRepositoryKey.value) {
      return false
    }

    if (
      selectedLabel.value !== 'all'
      && entry.entryType === 'github'
      && !(entry.issue?.labels || []).some((label) => label.name === selectedLabel.value)
    ) {
      return false
    }

    if (selectedLabel.value !== 'all' && entry.entryType !== 'github') {
      return false
    }

    if (selectedTicketType.value !== 'all' && entry.ticketType.key !== selectedTicketType.value) {
      return false
    }

    if (normalizedSearch !== '' && !entry.searchableText.includes(normalizedSearch)) {
      return false
    }

    return true
  })
})
const totalPages = computed(() => Math.max(1, Math.ceil(filteredTaskEntries.value.length / ITEMS_PER_PAGE)))
const paginatedTaskEntries = computed(() => {
  const start = (currentPage.value - 1) * ITEMS_PER_PAGE
  return filteredTaskEntries.value.slice(start, start + ITEMS_PER_PAGE)
})
const paginationSummary = computed(() => {
  if (filteredTaskEntries.value.length === 0) {
    return '0 de 0 tarefas'
  }

  const start = (currentPage.value - 1) * ITEMS_PER_PAGE + 1
  const end = Math.min(currentPage.value * ITEMS_PER_PAGE, filteredTaskEntries.value.length)

  return `${start}-${end} de ${filteredTaskEntries.value.length} tarefas`
})
const visiblePages = computed(() => {
  const pages = []
  const total = totalPages.value
  const start = Math.max(1, currentPage.value - 2)
  const end = Math.min(total, start + 4)
  const normalizedStart = Math.max(1, end - 4)

  for (let page = normalizedStart; page <= end; page += 1) {
    pages.push(page)
  }

  return pages
})
const issueStats = computed(() => ({
  total: issues.value.length,
  open: issues.value.filter((issue) => issue.state !== 'CLOSED').length,
  closed: issues.value.filter((issue) => issue.state === 'CLOSED').length,
}))
const listLoading = computed(() => {
  if (sourceFilter.value === 'github') {
    return loadingCache.value
  }

  if (sourceFilter.value === 'local') {
    return loadingLocalTasks.value
  }

  return loadingCache.value || loadingLocalTasks.value
})
const scopeTitle = computed(() => {
  if (issueScope.value === 'repository') {
    return activeRepository.value?.nameWithOwner || selectedRepositoryKey.value || 'Repositorio padrao do perfil'
  }

  if (issueScope.value === 'assigned') {
    return 'Issues atribuidas a mim'
  }

  return 'Todas as issues dos seus repositorios'
})
const createRepositoryKey = computed(() => {
  if (selectedRepositoryKey.value !== 'all') {
    return selectedRepositoryKey.value
  }

  const currentRepositoryKey = String(activeRepository.value?.nameWithOwner || '').trim()
  if (currentRepositoryKey !== '') {
    return currentRepositoryKey
  }

  return String(repositories.value[0]?.nameWithOwner || '').trim()
})
const lastSyncedLabel = computed(() => {
  const lastSyncedAt = cacheMeta.value?.lastSyncedAt
  return typeof lastSyncedAt === 'string' && lastSyncedAt.trim() !== ''
    ? formatDateTime(lastSyncedAt)
    : 'sem sincronização anterior'
})

onMounted(async () => {
  await Promise.all([
    loadCachedIssues({ resetSelection: true, syncStrategy: 'auto' }),
    loadLocalTasks(),
    loadIssueUpdateTemplates(),
  ])
})

watch(
  [issueScope, sourceFilter, stateFilter, searchTerm, selectedLabel, selectedTicketType, selectedRepositoryKey],
  () => {
    currentPage.value = 1
  }
)

watch(
  () => props.currentUser?.id,
  async (userId, previousUserId) => {
    if (!userId) {
      issueBoard.value = null
      localTaskBoard.value = null
      issueUpdateTemplates.value = []
      selectedIssueId.value = ''
      editingIssueId.value = ''
      editingLocalTaskId.value = null
      return
    }

    if (userId !== previousUserId) {
      await Promise.all([
        loadCachedIssues({ resetSelection: true, syncStrategy: 'auto' }),
        loadLocalTasks(),
        loadIssueUpdateTemplates(),
      ])
    }
  },
)

watch(totalPages, (nextTotalPages) => {
  if (currentPage.value > nextTotalPages) {
    currentPage.value = nextTotalPages
  }
})

watch(error, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'error')
  error.value = ''
})

watch(success, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'success')
  success.value = ''
})

watch(info, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'info')
  info.value = ''
})

function syncDraftFilters() {
  draftIssueScope.value = issueScope.value
  draftSourceFilter.value = sourceFilter.value
  draftStateFilter.value = stateFilter.value
  draftSearchTerm.value = searchTerm.value
  draftSelectedLabel.value = selectedLabel.value
  draftSelectedTicketType.value = selectedTicketType.value
  draftSelectedRepositoryKey.value = selectedRepositoryKey.value
}

async function loadCachedIssues(options = {}) {
  const resetSelection = Boolean(options.resetSelection)
  const syncStrategy = options.syncStrategy || 'auto'

  loadingCache.value = true
  error.value = ''

  if (resetSelection) {
    selectedIssueId.value = ''
  }

  try {
    const { data } = await props.request({
      url: '/github/issues/cache',
      method: 'GET',
      params: buildIssueParams(),
    })

    issueBoard.value = data || null

    if (issueScope.value === 'repository' && selectedRepositoryKey.value === 'all' && data?.repository?.nameWithOwner) {
      selectedRepositoryKey.value = data.repository.nameWithOwner
    }

    alignSelection(resetSelection)
    updateCacheInfo(data, syncStrategy)

    if (syncStrategy === 'force') {
      void syncIssues({ announceRefresh: true })
      return
    }

    if (syncStrategy === 'auto' && data?.cache?.needsRefresh) {
      void syncIssues({
        announceRefresh: !(Array.isArray(data?.items) && data.items.length > 0),
      })
    }
  } catch (requestError) {
    issueBoard.value = null
    selectedIssueId.value = ''
    error.value = extractHttpMessage(requestError, 'Nao foi possivel carregar o cache local das issues.')
  } finally {
    loadingCache.value = false
  }
}

async function loadLocalTasks() {
  loadingLocalTasks.value = true

  try {
    const { data } = await fetchLocalTasks(props.request)
    localTaskBoard.value = data || null
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel carregar as tarefas locais pendentes.')
  } finally {
    loadingLocalTasks.value = false
  }
}

async function loadIssueUpdateTemplates() {
  try {
    const { data } = await fetchTaskUpdateTemplates(props.request)
    issueUpdateTemplates.value = Array.isArray(data?.items) ? data.items : []
  } catch (requestError) {
    issueUpdateTemplates.value = []
    error.value = extractHttpMessage(requestError, 'Nao foi possivel carregar os templates de atualizacao.')
  }
}

async function syncIssues(options = {}) {
  const announceRefresh = Boolean(options.announceRefresh)

  syncing.value = true
  error.value = ''

  if (announceRefresh) {
    info.value = 'Sincronizando a listagem com o GitHub...'
  }

  try {
    const { data } = await props.request({
      url: '/github/issues/assigned',
      method: 'GET',
      params: buildIssueParams(),
    })

    issueBoard.value = data || null

    if (issueScope.value === 'repository' && selectedRepositoryKey.value === 'all' && data?.repository?.nameWithOwner) {
      selectedRepositoryKey.value = data.repository.nameWithOwner
    }

    alignSelection(false)
    await loadLocalTasks()
    info.value = 'Listagem atualizada a partir do GitHub e persistida no banco local.'
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel sincronizar as issues com o GitHub.')

    if (issues.value.length > 0) {
      info.value = 'Mantendo a listagem do banco local enquanto a sincronização do GitHub nao responde.'
    }
  } finally {
    syncing.value = false
  }
}

function buildIssueParams() {
  const params = {
    scope: issueScope.value,
  }

  if (issueScope.value === 'repository') {
    const repositorySelection = splitRepositoryKey(selectedRepositoryKey.value)
    if (repositorySelection.owner !== '' && repositorySelection.name !== '') {
      params.repositoryOwner = repositorySelection.owner
      params.repositoryName = repositorySelection.name
    }
  }

  return params
}

function alignSelection(resetSelection) {
  if (resetSelection) {
    selectedIssueId.value = ''
    return
  }

  if (!issues.value.some((issue) => issue.id === selectedIssueId.value)) {
    selectedIssueId.value = ''
  }
}

function updateCacheInfo(data, syncStrategy) {
  if (!data?.cache?.available && (!Array.isArray(data?.items) || data.items.length === 0)) {
    info.value = syncStrategy === 'force'
      ? 'Ainda nao existe cache local. O sistema vai buscar tudo no GitHub.'
      : 'Cache local vazio. Se necessario, a tela sincroniza com o GitHub.'
    return
  }

  if (data?.cache?.needsRefresh) {
    info.value = 'Listagem preenchida pelo banco local. O cache ja pode ser renovado no GitHub.'
    return
  }

  info.value = `Listagem servida do banco local. Ultima sincronização: ${lastSyncedLabel.value}.`
}

function openCreateModal() {
  createModalOpen.value = true
  success.value = ''
  error.value = ''
}

function openIssueModal(issue) {
  selectedIssueId.value = issue.id
  editingIssueId.value = issue.id
  editingLocalTaskId.value = null
  success.value = ''
  error.value = ''
}

function closeIssueModal() {
  editingIssueId.value = ''
}

function openLocalTaskModal(task) {
  editingLocalTaskId.value = task.id
  editingIssueId.value = ''
  selectedIssueId.value = ''
  success.value = ''
  error.value = ''
}

function closeLocalTaskModal() {
  editingLocalTaskId.value = null
}

async function handleIssueCreated(payload) {
  createModalOpen.value = false
  if (payload?.mode === 'local') {
    success.value = 'Tarefa local criada com sucesso e adicionada na fila de sincronização.'
    await loadLocalTasks()
    return
  }

  selectedIssueId.value = payload?.issue?.id || selectedIssueId.value
  success.value = typeof payload?.issue?.number === 'number'
    ? `Issue #${payload.issue.number} criada com sucesso${payload?.repository?.nameWithOwner ? ` em ${payload.repository.nameWithOwner}` : ''}.`
    : 'Issue criada com sucesso.'

  await loadCachedIssues({ resetSelection: false, syncStrategy: 'force' })
}

function handleIssueUpdated(updatedIssue) {
  if (updatedIssue?.id) {
    selectedIssueId.value = updatedIssue.id
    mergeIssueIntoBoard(updatedIssue)
  }

  success.value = updatedIssue?.number
    ? `Issue #${updatedIssue.number} atualizada com sucesso.`
    : 'Issue atualizada com sucesso.'
  info.value = 'A issue aberta foi atualizada no GitHub e no banco local.'
}

async function handleLocalTaskUpdated(payload) {
  if (payload?.item?.id) {
    editingLocalTaskId.value = payload.item.id
  }

  success.value = 'Tarefa local atualizada com sucesso.'
  await loadLocalTasks()
}

async function handleLocalTaskSynced(payload) {
  const githubIssue = payload?.github?.item || payload?.github?.issue || null
  if (githubIssue?.id) {
    selectedIssueId.value = githubIssue.id
  }

  editingLocalTaskId.value = null
  success.value = payload?.github?.issue?.number
    ? `Tarefa local enviada para a issue #${payload.github.issue.number} com sucesso.`
    : 'Tarefa local enviada ao GitHub com sucesso.'

  await loadLocalTasks()
  await loadCachedIssues({ resetSelection: false, syncStrategy: 'force' })
}

function mergeIssueIntoBoard(updatedIssue) {
  const currentItems = issues.value
  const issueExists = currentItems.some((issue) => issue.id === updatedIssue.id)
  const nextItems = issueExists
    ? currentItems.map((issue) => (issue.id === updatedIssue.id ? updatedIssue : issue))
    : [updatedIssue, ...currentItems]

  issueBoard.value = {
    ...(issueBoard.value || {}),
    items: nextItems,
  }
}

function resetFilters() {
  issueScope.value = 'all'
  sourceFilter.value = 'all'
  stateFilter.value = 'open'
  searchTerm.value = ''
  selectedLabel.value = 'all'
  selectedTicketType.value = 'all'
  selectedRepositoryKey.value = 'all'
  syncDraftFilters()
  success.value = ''
  error.value = ''
  void loadCachedIssues({ resetSelection: true, syncStrategy: 'auto' })
}

async function applyFilters() {
  const nextIssueScope = draftIssueScope.value
  const scopeChanged = issueScope.value !== nextIssueScope

  issueScope.value = nextIssueScope
  sourceFilter.value = draftSourceFilter.value
  stateFilter.value = draftStateFilter.value
  searchTerm.value = draftSearchTerm.value
  selectedLabel.value = draftSelectedLabel.value
  selectedTicketType.value = draftSelectedTicketType.value
  selectedRepositoryKey.value = draftSelectedRepositoryKey.value
  success.value = ''
  error.value = ''

  if (scopeChanged) {
    await loadCachedIssues({ resetSelection: true, syncStrategy: 'auto' })
  }
}

function goToPage(page) {
  const normalizedPage = Number(page)
  if (!Number.isInteger(normalizedPage)) {
    return
  }

  currentPage.value = Math.min(Math.max(normalizedPage, 1), totalPages.value)
}

function formatAssignees(issue) {
  const assignees = Array.isArray(issue?.assignees) ? issue.assignees : []

  if (assignees.length === 0) {
    return 'Sem responsavel'
  }

  const names = assignees
    .map((assignee) => assignee?.name || assignee?.login || '')
    .filter(Boolean)

  if (names.length === 0) {
    return 'Sem responsavel'
  }

  if (names.length <= 2) {
    return names.join(', ')
  }

  return `${names.slice(0, 2).join(', ')} +${names.length - 2}`
}

function formatLabels(issue) {
  const labels = Array.isArray(issue?.labels) ? issue.labels : []

  if (labels.length === 0) {
    return 'Sem labels'
  }

  const names = labels
    .map((label) => label?.name || '')
    .filter(Boolean)

  if (names.length <= 3) {
    return names.join(', ')
  }

  return `${names.slice(0, 3).join(', ')} +${names.length - 3}`
}

function detectTicketType(issue) {
  const match = String(issue?.title || '').match(/^\[([^\]]+)\]/)
  const rawKey = match?.[1]?.trim().toLowerCase()

  const catalog = {
    support: 'Suporte',
    incident: 'Incidente',
    service: 'Servico',
    feat: 'Melhoria',
    bug: 'Bug',
    chore: 'Tecnico',
  }

  if (!rawKey || !catalog[rawKey]) {
    return { key: 'other', label: 'Outros' }
  }

  return { key: rawKey, label: catalog[rawKey] }
}

function formatLocalTaskStatus(task) {
  if (task?.syncState === 'FAILED') {
    return 'Falhou ao sincronizar'
  }

  if (task?.syncState === 'PENDING') {
    return 'Pendente de sincronização'
  }

  return 'Sincronizada'
}

function isGithubEntry(entry) {
  return entry?.entryType === 'github' && entry?.issue
}

function openTaskEntry(entry) {
  if (isGithubEntry(entry)) {
    openIssueModal(entry.issue)
    return
  }

  openLocalTaskModal(entry.localTask)
}

function resolveEntryTitle(entry) {
  return isGithubEntry(entry) ? entry.issue.title : entry.localTask?.title || 'Tarefa local'
}

function resolveEntryNumberLabel(entry) {
  if (isGithubEntry(entry)) {
    return `#${entry.issue.number}`
  }

  return `LOCAL-${entry.localTask?.id || ''}`
}

function resolveEntryStatusLabel(entry) {
  if (isGithubEntry(entry)) {
    return entry.issue.state === 'CLOSED' ? 'Fechada' : 'Aberta'
  }

  return formatLocalTaskStatus(entry.localTask)
}

function resolveEntryStatusClass(entry) {
  if (isGithubEntry(entry)) {
    return entry.issue.state === 'CLOSED'
      ? 'bg-rose-100 text-rose-700 ring-1 ring-inset ring-rose-200'
      : 'bg-emerald-100 text-emerald-700 ring-1 ring-inset ring-emerald-200'
  }

  return entry.localTask?.syncState === 'FAILED'
    ? 'bg-rose-100 text-rose-700 ring-1 ring-inset ring-rose-200'
    : 'bg-amber-100 text-amber-700 ring-1 ring-inset ring-amber-200'
}

function resolveEntryAssignees(entry) {
  if (isGithubEntry(entry)) {
    return formatAssignees(entry.issue)
  }

  return 'Tarefa local'
}

function resolveEntryRepository(entry) {
  if (isGithubEntry(entry)) {
    return entry.issue.repository?.nameWithOwner || 'Repositorio atual'
  }

  return entry.localTask?.repositoryKey || 'Sem repositorio definido'
}

function resolveEntryLabels(entry) {
  if (isGithubEntry(entry)) {
    return formatLabels(entry.issue)
  }

  return entry.localTask?.templateKey || 'personalizado'
}

</script>

<template>
  <section class="grid gap-5">
    <article class="rounded-[24px] border border-white/60 bg-white/80 p-4 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div class="flex flex-col gap-3">
        <div class="min-w-0">
          <h2 class="text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">{{ scopeTitle }}</h2>
          <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ scopeDescription }}</p>
        </div>
      </div>

      <div class="mt-4 grid gap-2 sm:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Total</span>
          <strong class="mt-1.5 block text-xl font-semibold text-slate-950">{{ issueStats.total }}</strong>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 p-3">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Abertas</span>
          <strong class="mt-1.5 block text-xl font-semibold text-emerald-950">{{ issueStats.open }}</strong>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-100/80 p-3">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Fechadas</span>
          <strong class="mt-1.5 block text-xl font-semibold text-slate-950">{{ issueStats.closed }}</strong>
        </div>
        <div class="rounded-2xl border border-cyan-200 bg-cyan-50/80 p-3">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-700">Repositorios</span>
          <strong class="mt-1.5 block text-xl font-semibold text-cyan-950">{{ repositoriesCount }}</strong>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap gap-2">
        <button
          type="button"
          class="app-btn app-btn-primary"
          @click="openCreateModal"
        >
          Nova tarefa
        </button>
        <button
          type="button"
          class="app-btn app-btn-secondary"
          :disabled="syncing"
          @click="syncIssues({ announceRefresh: true })"
        >
          {{ syncing ? 'Sincronizando...' : 'Atualizar lista' }}
        </button>
      </div>
    </article>

    <article
      class="rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <h3 class="mt-1 text-2xl font-semibold text-slate-950">Filtros</h3>
        </div>

        <button type="button"
          class="app-btn app-btn-secondary shrink-0"
          @click="filtersOpen = !filtersOpen">
          {{ filtersOpen ? 'Ocultar filtros' : 'Mostrar filtros' }}
        </button>
      </div>

      <div v-if="filtersOpen" class="mt-5 grid gap-5">
        <div class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
          <p class="text-sm font-semibold text-slate-900">Escopo</p>

          <div class="flex flex-wrap gap-3">
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
              <input
                v-model="draftIssueScope"
                type="radio"
                class="h-4 w-4 accent-cyan-600"
                value="all"
              >
              <span>Todas as issues</span>
            </label>

            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
              <input
                v-model="draftIssueScope"
                type="radio"
                class="h-4 w-4 accent-cyan-600"
                value="assigned"
              >
              <span>Atribuidas a mim</span>
            </label>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <label class="grid min-w-0 gap-2 xl:col-span-2">
            <span class="text-sm font-semibold text-slate-900">Buscar</span>
            <input v-model="draftSearchTerm" type="text" placeholder="Titulo, label, autor, repositorio..."
              class="app-field-control h-11 w-full min-w-0 px-3 text-sm text-slate-900">
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Origem</span>
            <select v-model="draftSourceFilter"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">Local e GitHub</option>
              <option value="github">Apenas GitHub</option>
              <option value="local">Apenas local</option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Estado</span>
            <select v-model="draftStateFilter"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">Todos</option>
              <option value="open">Abertas</option>
              <option value="closed">Fechadas</option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Repositorio</span>
            <select v-model="draftSelectedRepositoryKey"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">Todos os repositorios</option>
              <option v-for="repositoryKey in repositoryFilterOptions" :key="repositoryKey"
                :value="repositoryKey">
                {{ repositoryKey }}
              </option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Label</span>
            <select v-model="draftSelectedLabel"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">Todas as labels</option>
              <option v-for="label in availableLabels" :key="label.id" :value="label.name">
                {{ label.name }}
              </option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Tipo de chamado</span>
            <select v-model="draftSelectedTicketType"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">Todos os tipos</option>
              <option v-for="type in availableTicketTypes" :key="type.key" :value="type.key">
                {{ type.label }}
              </option>
            </select>
          </label>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="app-btn app-btn-primary"
            :disabled="loadingCache"
            @click="applyFilters"
          >
            Filtrar
          </button>
          <button type="button"
            class="app-btn app-btn-secondary"
            :disabled="loadingCache" @click="resetFilters">
            Restaurar padrao
          </button>
        </div>
      </div>
    </article>

    <article class="rounded-[24px] border border-white/60 bg-white/80 p-4 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h3 class="text-xl font-semibold text-slate-950">Listagem de tarefas</h3>
        </div>

        <div class="flex flex-wrap gap-2">
          <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold text-slate-700">
            Escopo: {{ issueScope === 'all' ? 'Todos os repositorios' : issueScope === 'assigned' ? 'Atribuidas a mim' : 'Repositorio especifico' }}
          </span>
          <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-800">
            Locais pendentes: {{ localTaskStats.pending }}
          </span>
          <span class="inline-flex items-center rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-[11px] font-semibold text-cyan-800">
            Ultima sync: {{ lastSyncedLabel }}
          </span>
        </div>
      </div>

      <div v-if="listLoading && filteredTaskEntries.length === 0" class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500">
        Carregando tarefas...
      </div>

      <div v-else class="mt-4 grid gap-2">
        <button v-for="entry in paginatedTaskEntries" :key="entry.entryKey" type="button"
          class="w-full rounded-xl border px-4 py-3 text-left transition-all" :class="isGithubEntry(entry) && entry.issue.id === selectedIssueId
              ? 'border-cyan-300 bg-cyan-50/70 shadow-[0_12px_28px_rgba(34,211,238,0.10)]'
              : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
            " @click="openTaskEntry(entry)">
          <div class="space-y-3">
            <div class="grid grid-cols-[10%_65%_25%] gap-x-4 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Número
                </p>
                <strong class="mt-1 block text-base font-bold leading-none text-slate-950">
                  {{ resolveEntryNumberLabel(entry) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Título
                </p>
                <strong class="mt-1 block truncate text-base font-bold leading-5 text-slate-950">
                  {{ resolveEntryTitle(entry) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Status
                </p>
                <span class="mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="resolveEntryStatusClass(entry)">
                  {{ resolveEntryStatusLabel(entry) }}
                </span>
              </div>
            </div>

            <div class="grid grid-cols-[35%_35%_30%] gap-x-4 gap-y-2 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Data de abertura
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ formatDateTime(entry.createdAt) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Data de atualização
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ formatDateTime(entry.updatedAt) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ isGithubEntry(entry) ? 'Responsável' : 'Origem' }}
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ resolveEntryAssignees(entry) }}
                </strong>
              </div>
            </div>

            <div class="grid grid-cols-[65%_35%] gap-x-4 gap-y-2 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Repositório
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ resolveEntryRepository(entry) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ isGithubEntry(entry) ? 'Labels' : 'Template' }}
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ resolveEntryLabels(entry) }}
                </strong>
              </div>
            </div>

            <p
              v-if="!isGithubEntry(entry) && entry.localTask?.syncError"
              class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"
            >
              {{ entry.localTask.syncError }}
            </p>
          </div>
        </button>

        <p
          v-if="filteredTaskEntries.length === 0"
          class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
        >
          Nenhuma tarefa encontrada para os filtros atuais.
        </p>

        <div
          v-if="filteredTaskEntries.length > 0"
          class="mt-2 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
          <p class="text-sm font-medium text-slate-600">{{ paginationSummary }}</p>

          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="app-btn app-btn-secondary app-btn-sm"
              :disabled="currentPage === 1"
              @click.stop="goToPage(currentPage - 1)"
            >
              Anterior
            </button>

            <button
              v-for="page in visiblePages"
              :key="page"
              type="button"
              class="app-btn app-btn-sm min-w-10"
              :class="page === currentPage ? 'app-btn-tab-active' : 'app-btn-secondary'"
              @click.stop="goToPage(page)"
            >
              {{ page }}
            </button>

            <button
              type="button"
              class="app-btn app-btn-secondary app-btn-sm"
              :disabled="currentPage === totalPages"
              @click.stop="goToPage(currentPage + 1)"
            >
              Proxima
            </button>
          </div>
        </div>
      </div>
    </article>

    <IssueCreateModal
      v-if="createModalOpen"
      :request="props.request"
      :current-user="props.currentUser"
      :repositories="repositories"
      :initial-repository-key="createRepositoryKey"
      @close="createModalOpen = false"
      @issue-created="handleIssueCreated"
    />

    <IssueEditModal
      v-if="editingIssue"
      :key="editingIssue.id"
      :request="props.request"
      :notify="props.notify"
      :issue="editingIssue"
      :update-templates="issueUpdateTemplates"
      @close="closeIssueModal"
      @issue-updated="handleIssueUpdated"
    />

    <LocalTaskEditModal
      v-if="editingLocalTask"
      :key="editingLocalTask.id"
      :request="props.request"
      :notify="props.notify"
      :task="editingLocalTask"
      :repositories="repositories"
      @close="closeLocalTaskModal"
      @task-updated="handleLocalTaskUpdated"
      @task-synced="handleLocalTaskSynced"
    />
  </section>
</template>
