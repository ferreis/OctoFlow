<script setup>
import { storeToRefs } from 'pinia'
import {
  computed,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  ref,
  watch,
} from 'vue'
import { useI18n } from '../../composables/useI18n'
import { useNotification } from '../../composables/useNotification'
import { formatDateTime } from '../../utils/date'
import { splitRepositoryKey } from '../../utils/githubRepository'
import { extractHttpMessage } from '../../utils/httpErrors'
import { resolveTaskEntryBadgeToneClass } from '../../utils/statusTone'
import { fetchLocalTasks, fetchTaskUpdateTemplates } from '../../services/tasks'
import IssueCreateModal from '../tasks/IssueCreateModal.vue'
import WorkItemEditModal from '../tasks/WorkItemEditModal.vue'
import { useSessionStore } from '../../stores/sessionStore'

const props = defineProps({
  request: {
    type: Function,
    default: null,
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
const sessionStore = useSessionStore()
const { currentUser: sessionCurrentUser } = storeToRefs(sessionStore)
const requestClient = props.request || sessionStore.authRequest
const effectiveCurrentUser = computed(() => props.currentUser || sessionCurrentUser.value)
const { translate, currentLocale } = useI18n()

const { notifyUser } = useNotification(props.notify)

const ALLOWED_ISSUE_SCOPES = Object.freeze(['all', 'assigned', 'repository'])
const ALLOWED_SOURCE_FILTERS = Object.freeze(['all', 'github', 'local'])
const ALLOWED_STATE_FILTERS = Object.freeze(['open', 'closed', 'all'])
const TASK_SEARCH_MAX_LENGTH = 180
const TASK_REPOSITORY_KEY_MAX_LENGTH = 160

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
const tasksKeepAlivePaused = ref(false)
const tasksScreenRuntimeError = ref('')

function translateTasks(messageKey, fallbackMessage = '', variables = {}) {
  const translatedMessage = translate(messageKey, variables)
  return translatedMessage === messageKey ? fallbackMessage : translatedMessage
}

function replaceControlCharactersWithSpaces(rawValue) {
  let sanitizedText = ''
  const inputText = String(rawValue || '')

  for (const currentCharacter of inputText) {
    const characterCode = currentCharacter.charCodeAt(0)
    const isControlCharacter = characterCode < 32 || characterCode === 127
    sanitizedText += isControlCharacter ? ' ' : currentCharacter
  }

  return sanitizedText
}

function sanitizeSingleLineText(rawValue, maxLength = TASK_SEARCH_MAX_LENGTH) {
  const normalizedValue = replaceControlCharactersWithSpaces(rawValue)
    .replace(/\s+/g, ' ')
    .trim()

  return normalizedValue.slice(0, maxLength)
}

function sanitizeSelectionValue(rawValue, allowedValues, fallbackValue) {
  const normalizedValue = String(rawValue || '').trim().toLowerCase()
  return allowedValues.includes(normalizedValue) ? normalizedValue : fallbackValue
}

function sanitizeRepositorySelection(rawRepositoryKey) {
  const normalizedRepositoryKey = sanitizeSingleLineText(rawRepositoryKey, TASK_REPOSITORY_KEY_MAX_LENGTH)
  if (normalizedRepositoryKey === '' || normalizedRepositoryKey === 'all') {
    return 'all'
  }

  const repositoryKeyIsValid = /^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/.test(normalizedRepositoryKey)
  return repositoryKeyIsValid ? normalizedRepositoryKey : 'all'
}

function resolveSortLocaleCode() {
  const normalizedLocaleCode = String(currentLocale.value || '').trim()
  return normalizedLocaleCode !== '' ? normalizedLocaleCode : 'pt-BR'
}

function notifyTaskError(messageText) {
  notifyUser(messageText, 'error')
}

function notifyTaskSuccess(messageText) {
  notifyUser(messageText, 'success')
}

function notifyTaskInfo(messageText) {
  notifyUser(messageText, 'info')
}

function notifyTaskWarning(messageText) {
  notifyUser(messageText, 'warning')
}

function buildSearchableText(valuesList) {
  if (!Array.isArray(valuesList)) {
    return ''
  }

  return sanitizeSingleLineText(valuesList.filter(Boolean).join(' '), 2600).toLowerCase()
}

const repositories = computed(() => Array.isArray(issueBoard.value?.repositories) ? issueBoard.value.repositories : [])
const issues = computed(() => Array.isArray(issueBoard.value?.items) ? issueBoard.value.items : [])
const localTasks = computed(() => Array.isArray(localTaskBoard.value?.items) ? localTaskBoard.value.items : [])
const localTaskStats = computed(() => localTaskBoard.value?.stats || { total: 0, pending: 0, failed: 0 })
const editingIssue = computed(() => issues.value.find((issue) => issue.id === editingIssueId.value) || null)
const editingLocalTask = computed(() => localTasks.value.find((task) => task.id === editingLocalTaskId.value) || null)
const activeEditingMode = computed(() => {
  if (editingIssue.value) {
    return 'github'
  }

  if (editingLocalTask.value) {
    return 'local'
  }

  return ''
})
const activeEditingKey = computed(() => {
  if (activeEditingMode.value === 'github') {
    return `github:${String(editingIssue.value?.id || '')}`
  }

  if (activeEditingMode.value === 'local') {
    return `local:${String(editingLocalTask.value?.id || '')}`
  }

  return ''
})
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

  return Array.from(catalog.values()).sort((left, right) => left.localeCompare(right, resolveSortLocaleCode()))
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

  return Array.from(labelsMap.values()).sort((left, right) => left.name.localeCompare(right.name, resolveSortLocaleCode()))
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
    searchableText: buildSearchableText([
      issue.title,
      issue.body,
      issue.repository?.nameWithOwner,
      issue.authorLogin,
      ...(issue.labels || []).map((label) => label.name),
    ]),
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
    searchableText: buildSearchableText([
      task.title,
      task.body,
      task.repositoryKey,
      task.templateKey,
      task.syncError,
    ]),
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
  const normalizedSearch = sanitizeSingleLineText(searchTerm.value, TASK_SEARCH_MAX_LENGTH).toLowerCase()
  const normalizedSourceFilter = sanitizeSelectionValue(sourceFilter.value, ALLOWED_SOURCE_FILTERS, 'all')
  const normalizedStateFilter = sanitizeSelectionValue(stateFilter.value, ALLOWED_STATE_FILTERS, 'open')
  const normalizedRepositoryKey = sanitizeRepositorySelection(selectedRepositoryKey.value)
  const normalizedSelectedLabel = sanitizeSingleLineText(selectedLabel.value, 120)
  const normalizedSelectedTicketType = sanitizeSingleLineText(selectedTicketType.value, 60)

  return taskEntries.value.filter((entry) => {
    if (normalizedSourceFilter !== 'all' && entry.entryType !== normalizedSourceFilter) {
      return false
    }

    if (normalizedStateFilter === 'open' && entry.state === 'CLOSED') {
      return false
    }

    if (normalizedStateFilter === 'closed' && entry.state !== 'CLOSED') {
      return false
    }

    if (normalizedRepositoryKey !== 'all' && entry.repositoryKey !== normalizedRepositoryKey) {
      return false
    }

    if (
      normalizedSelectedLabel !== 'all'
      && entry.entryType === 'github'
      && !(entry.issue?.labels || []).some((label) => label.name === normalizedSelectedLabel)
    ) {
      return false
    }

    if (normalizedSelectedLabel !== 'all' && entry.entryType !== 'github') {
      return false
    }

    if (normalizedSelectedTicketType !== 'all' && entry.ticketType.key !== normalizedSelectedTicketType) {
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
    return translateTasks('tasksScreen.pagination.empty', '0 de 0 tarefas')
  }

  const start = (currentPage.value - 1) * ITEMS_PER_PAGE + 1
  const end = Math.min(currentPage.value * ITEMS_PER_PAGE, filteredTaskEntries.value.length)

  return translateTasks(
    'tasksScreen.pagination.summary',
    `${start}-${end} de ${filteredTaskEntries.value.length} tarefas`,
    {
      start,
      end,
      total: filteredTaskEntries.value.length,
    },
  )
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
    return activeRepository.value?.nameWithOwner
      || selectedRepositoryKey.value
      || translateTasks('tasksScreen.scope.defaultRepository', 'Repositório padrão do perfil')
  }

  if (issueScope.value === 'assigned') {
    return translateTasks('tasksScreen.scope.assigned', 'Issues atribuídas a mim')
  }

  return translateTasks('tasksScreen.scope.all', 'Todas as issues dos seus repositórios')
})
const scopeDescription = computed(() => {
  if (issueScope.value === 'repository') {
    return translateTasks(
      'tasksScreen.scopeDescription.repository',
      'Visualize e atualize itens de um repositório específico com filtros de estado, label e tipo.',
    )
  }

  if (issueScope.value === 'assigned') {
    return translateTasks(
      'tasksScreen.scopeDescription.assigned',
      'Mostra apenas issues atribuídas ao seu usuário para foco operacional.',
    )
  }

  return translateTasks(
    'tasksScreen.scopeDescription.all',
    'Visão consolidada de issues do GitHub e tarefas locais pendentes de sincronização.',
  )
})
const createRepositoryKey = computed(() => {
  const normalizedRepositoryKey = sanitizeRepositorySelection(selectedRepositoryKey.value)
  if (normalizedRepositoryKey !== 'all') {
    return normalizedRepositoryKey
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
    : translateTasks('tasksScreen.lastSync.none', 'sem sincronização anterior')
})

onBeforeMount(() => {
  syncDraftFilters()
})

onMounted(async () => {
  await loadInitialTasksData()
})

onActivated(async () => {
  if (!tasksKeepAlivePaused.value) {
    return
  }

  tasksKeepAlivePaused.value = false

  if (!effectiveCurrentUser.value?.id) {
    return
  }

  await loadInitialTasksData()
})

onDeactivated(() => {
  tasksKeepAlivePaused.value = true
})

onBeforeUnmount(() => {
  tasksKeepAlivePaused.value = true
  selectedIssueId.value = ''
  editingIssueId.value = ''
  editingLocalTaskId.value = null
  tasksScreenRuntimeError.value = ''
})

onErrorCaptured((capturedError) => {
  tasksScreenRuntimeError.value = String(capturedError?.message || capturedError || '')
  return false
})

watch(
  [issueScope, sourceFilter, stateFilter, searchTerm, selectedLabel, selectedTicketType, selectedRepositoryKey],
  () => {
    currentPage.value = 1
  }
)

watch(
  () => effectiveCurrentUser.value?.id,
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
      await loadInitialTasksData()
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

  notifyTaskError(message)
  error.value = ''
})

watch(success, (message) => {
  if (!message) {
    return
  }

  notifyTaskSuccess(message)
  success.value = ''
})

watch(info, (message) => {
  if (!message) {
    return
  }

  notifyTaskInfo(message)
  info.value = ''
})

watch(tasksScreenRuntimeError, (runtimeErrorMessage) => {
  if (!runtimeErrorMessage) {
    return
  }

  notifyTaskError(
    translateTasks(
      'tasksScreen.errors.unexpectedChild',
      'Ocorreu um erro inesperado na tela de tarefas.',
    ),
  )
  tasksScreenRuntimeError.value = ''
})

function applySanitizedFilters() {
  issueScope.value = sanitizeSelectionValue(issueScope.value, ALLOWED_ISSUE_SCOPES, 'all')
  sourceFilter.value = sanitizeSelectionValue(sourceFilter.value, ALLOWED_SOURCE_FILTERS, 'all')
  stateFilter.value = sanitizeSelectionValue(stateFilter.value, ALLOWED_STATE_FILTERS, 'open')
  searchTerm.value = sanitizeSingleLineText(searchTerm.value, TASK_SEARCH_MAX_LENGTH)
  selectedRepositoryKey.value = sanitizeRepositorySelection(selectedRepositoryKey.value)
  selectedLabel.value = sanitizeSingleLineText(selectedLabel.value, 120) || 'all'
  selectedTicketType.value = sanitizeSingleLineText(selectedTicketType.value, 60) || 'all'
}

async function loadInitialTasksData() {
  if (!effectiveCurrentUser.value?.id) {
    return
  }

  applySanitizedFilters()
  await Promise.all([
    loadCachedIssues({ resetSelection: true, syncStrategy: 'auto' }),
    loadLocalTasks(),
    loadIssueUpdateTemplates(),
  ])
}

function syncDraftFilters() {
  applySanitizedFilters()
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
  const syncStrategy = options.syncStrategy === 'force' ? 'force' : 'auto'

  loadingCache.value = true
  error.value = ''
  applySanitizedFilters()

  if (resetSelection) {
    selectedIssueId.value = ''
  }

  try {
    const { data } = await requestClient({
      url: '/github/issues/cache',
      method: 'GET',
      params: buildIssueParams(),
    })

    issueBoard.value = data || null

    if (issueScope.value === 'repository' && selectedRepositoryKey.value === 'all' && data?.repository?.nameWithOwner) {
      selectedRepositoryKey.value = sanitizeRepositorySelection(data.repository.nameWithOwner)
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
    error.value = extractHttpMessage(
      requestError,
      translateTasks('tasksScreen.errors.loadCache', 'Não foi possível carregar o cache local das issues.'),
    )
  } finally {
    loadingCache.value = false
  }
}

async function loadLocalTasks() {
  loadingLocalTasks.value = true

  try {
    const { data } = await fetchLocalTasks()
    localTaskBoard.value = data || null
  } catch (requestError) {
    error.value = extractHttpMessage(
      requestError,
      translateTasks('tasksScreen.errors.loadLocalTasks', 'Não foi possível carregar as tarefas locais pendentes.'),
    )
  } finally {
    loadingLocalTasks.value = false
  }
}

async function loadIssueUpdateTemplates() {
  try {
    const { data } = await fetchTaskUpdateTemplates()
    issueUpdateTemplates.value = Array.isArray(data?.items) ? data.items : []
  } catch (requestError) {
    issueUpdateTemplates.value = []
    error.value = extractHttpMessage(
      requestError,
      translateTasks('tasksScreen.errors.loadTemplates', 'Não foi possível carregar os templates de atualização.'),
    )
  }
}

async function syncIssues(options = {}) {
  const announceRefresh = options.announceRefresh === true
  syncing.value = true
  error.value = ''
  if (announceRefresh) {
    info.value = translateTasks('tasksScreen.info.syncStarted', 'Sincronização iniciada com o GitHub.')
  }
  applySanitizedFilters()
  try {
    const { data } = await requestClient({
      url: '/github/issues/assigned',
      method: 'GET',
      params: buildIssueParams(),
    })

    issueBoard.value = data || null

    if (issueScope.value === 'repository' && selectedRepositoryKey.value === 'all' && data?.repository?.nameWithOwner) {
      selectedRepositoryKey.value = sanitizeRepositorySelection(data.repository.nameWithOwner)
    }

    alignSelection(false)
    await loadLocalTasks()
  } catch (requestError) {
    error.value = extractHttpMessage(
      requestError,
      translateTasks('tasksScreen.errors.syncGithub', 'Não foi possível sincronizar as issues com o GitHub.'),
    )
  } finally {
    syncing.value = false
  }
}

function buildIssueParams() {
  const normalizedScope = sanitizeSelectionValue(issueScope.value, ALLOWED_ISSUE_SCOPES, 'all')

  const params = {
    scope: normalizedScope,
  }

  if (normalizedScope === 'repository') {
    const repositorySelection = splitRepositoryKey(sanitizeRepositorySelection(selectedRepositoryKey.value))
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
      ? translateTasks(
        'tasksScreen.info.cacheMissingForce',
        'Ainda não existe cache local. O sistema vai buscar tudo no GitHub.',
      )
      : translateTasks(
        'tasksScreen.info.cacheMissingAuto',
        'Cache local vazio. Se necessário, a tela sincroniza com o GitHub.',
      )
    return
  }

  if (data?.cache?.needsRefresh) {
    return
  }
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

function openLocalTaskModal(task) {
  editingLocalTaskId.value = task.id
  editingIssueId.value = ''
  selectedIssueId.value = ''
  success.value = ''
  error.value = ''
}

function closeWorkItemModal() {
  editingIssueId.value = ''
  editingLocalTaskId.value = null
}

async function handleIssueCreated(payload) {
  createModalOpen.value = false
  if (payload?.mode === 'local') {
    success.value = translateTasks(
      'tasksScreen.success.localTaskCreated',
      'Tarefa local criada com sucesso e adicionada na fila de sincronização.',
    )
    await loadLocalTasks()
    return
  }

  selectedIssueId.value = payload?.issue?.id || selectedIssueId.value
  const repositoryNameWithOwner = sanitizeSingleLineText(payload?.repository?.nameWithOwner || '', TASK_REPOSITORY_KEY_MAX_LENGTH)
  success.value = typeof payload?.issue?.number === 'number'
    ? translateTasks(
      'tasksScreen.success.issueCreatedWithNumber',
      `Issue #${payload.issue.number} criada com sucesso${repositoryNameWithOwner ? ` em ${repositoryNameWithOwner}` : ''}.`,
      {
        number: payload.issue.number,
        repository: repositoryNameWithOwner,
      },
    )
    : translateTasks('tasksScreen.success.issueCreated', 'Issue criada com sucesso.')

  await loadCachedIssues({ resetSelection: false, syncStrategy: 'force' })
}

function handleIssueUpdated(updatedIssue) {
  if (updatedIssue?.id) {
    selectedIssueId.value = updatedIssue.id
    mergeIssueIntoBoard(updatedIssue)
  }

  success.value = updatedIssue?.number
    ? translateTasks('tasksScreen.success.issueUpdatedWithNumber', `Issue #${updatedIssue.number} atualizada com sucesso.`, {
      number: updatedIssue.number,
    })
    : translateTasks('tasksScreen.success.issueUpdated', 'Issue atualizada com sucesso.')
  info.value = translateTasks(
    'tasksScreen.info.issueUpdated',
    'A issue aberta foi atualizada no GitHub e no banco local.',
  )
}

async function handleLocalTaskUpdated(payload) {
  if (payload?.item?.id) {
    editingLocalTaskId.value = payload.item.id
  }

  success.value = translateTasks('tasksScreen.success.localTaskUpdated', 'Tarefa local atualizada com sucesso.')
  await loadLocalTasks()
}

async function handleLocalTaskSynced(payload) {
  const githubIssue = payload?.github?.item || payload?.github?.issue || null
  if (githubIssue?.id) {
    selectedIssueId.value = githubIssue.id
  }

  editingLocalTaskId.value = null
  success.value = payload?.github?.issue?.number
    ? translateTasks(
      'tasksScreen.success.localTaskSyncedWithIssue',
      `Tarefa local enviada para a issue #${payload.github.issue.number} com sucesso.`,
      {
        number: payload.github.issue.number,
      },
    )
    : translateTasks('tasksScreen.success.localTaskSynced', 'Tarefa local enviada ao GitHub com sucesso.')

  await loadLocalTasks()
  await loadCachedIssues({ resetSelection: false, syncStrategy: 'force' })
}

async function handleWorkItemUpdated(eventPayload) {
  if (eventPayload?.mode === 'github') {
    handleIssueUpdated(eventPayload.payload || null)
    return
  }

  if (eventPayload?.mode === 'local') {
    await handleLocalTaskUpdated(eventPayload.payload || null)
  }
}

function handleOpenGithubIssueFromModal(issue) {
  if (!issue?.id) {
    return
  }

  mergeIssueIntoBoard(issue)
  selectedIssueId.value = issue.id
  editingIssueId.value = issue.id
  editingLocalTaskId.value = null
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
  applySanitizedFilters()
  syncDraftFilters()
  success.value = ''
  error.value = ''
  void loadCachedIssues({ resetSelection: true, syncStrategy: 'auto' })
}

async function applyFilters() {
  const nextIssueScope = sanitizeSelectionValue(draftIssueScope.value, ALLOWED_ISSUE_SCOPES, 'all')
  const scopeChanged = issueScope.value !== nextIssueScope

  issueScope.value = nextIssueScope
  sourceFilter.value = sanitizeSelectionValue(draftSourceFilter.value, ALLOWED_SOURCE_FILTERS, 'all')
  stateFilter.value = sanitizeSelectionValue(draftStateFilter.value, ALLOWED_STATE_FILTERS, 'open')
  searchTerm.value = sanitizeSingleLineText(draftSearchTerm.value, TASK_SEARCH_MAX_LENGTH)
  selectedLabel.value = sanitizeSingleLineText(draftSelectedLabel.value, 120) || 'all'
  selectedTicketType.value = sanitizeSingleLineText(draftSelectedTicketType.value, 60) || 'all'
  selectedRepositoryKey.value = sanitizeRepositorySelection(draftSelectedRepositoryKey.value)
  success.value = ''
  error.value = ''
  applySanitizedFilters()

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
    return translateTasks('tasksScreen.labels.unassigned', 'Sem responsável')
  }

  const names = assignees
    .map((assignee) => assignee?.name || assignee?.login || '')
    .filter(Boolean)

  if (names.length === 0) {
    return translateTasks('tasksScreen.labels.unassigned', 'Sem responsável')
  }

  if (names.length <= 2) {
    return names.join(', ')
  }

  return `${names.slice(0, 2).join(', ')} +${names.length - 2}`
}

function formatLabels(issue) {
  const labels = Array.isArray(issue?.labels) ? issue.labels : []

  if (labels.length === 0) {
    return translateTasks('tasksScreen.labels.noLabels', 'Sem labels')
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
    support: translateTasks('tasksScreen.ticketType.support', 'Suporte'),
    incident: translateTasks('tasksScreen.ticketType.incident', 'Incidente'),
    service: translateTasks('tasksScreen.ticketType.service', 'Serviço'),
    feat: translateTasks('tasksScreen.ticketType.feature', 'Melhoria'),
    bug: translateTasks('tasksScreen.ticketType.bug', 'Bug'),
    chore: translateTasks('tasksScreen.ticketType.technical', 'Técnico'),
  }

  if (!rawKey || !catalog[rawKey]) {
    return { key: 'other', label: translateTasks('tasksScreen.ticketType.other', 'Outros') }
  }

  return { key: rawKey, label: catalog[rawKey] }
}

function formatLocalTaskStatus(task) {
  if (task?.syncState === 'FAILED') {
    return translateTasks('tasksScreen.status.localFailed', 'Falhou ao sincronizar')
  }

  if (task?.state === 'CLOSED') {
    return translateTasks('tasksScreen.status.closed', 'Fechada')
  }

  return translateTasks('tasksScreen.status.open', 'Aberta')
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
  return isGithubEntry(entry)
    ? entry.issue.title
    : entry.localTask?.title || translateTasks('tasksScreen.labels.localTask', 'Tarefa local')
}

function resolveEntryNumberLabel(entry) {
  if (isGithubEntry(entry)) {
    return `#${entry.issue.number}`
  }

  const localTaskIdentifier = Number(entry?.localTask?.id || 0)
  if (Number.isInteger(localTaskIdentifier) && localTaskIdentifier > 0) {
    return `#${localTaskIdentifier}`
  }

  return '-'
}

function resolveEntryStatusLabel(entry) {
  if (isGithubEntry(entry)) {
    return entry.issue.state === 'CLOSED'
      ? translateTasks('tasksScreen.status.closed', 'Fechada')
      : translateTasks('tasksScreen.status.open', 'Aberta')
  }

  return formatLocalTaskStatus(entry.localTask)
}

function resolveEntryStatusClass(entry) {
  const entryType = isGithubEntry(entry) ? 'github' : 'local'
  const issueState = isGithubEntry(entry) ? entry.issue.state : ''
  const localSyncState = isGithubEntry(entry) ? '' : entry.localTask?.syncState

  return resolveTaskEntryBadgeToneClass(entryType, issueState, localSyncState)
}

function resolveEntryAssignees(entry) {
  if (isGithubEntry(entry)) {
    return formatAssignees(entry.issue)
  }

  return translateTasks('tasksScreen.labels.notAssigned', 'Não atribuído')
}

function resolveEntryRepository(entry) {
  if (isGithubEntry(entry)) {
    return entry.issue.repository?.nameWithOwner || translateTasks('tasksScreen.labels.currentRepository', 'Repositório atual')
  }

  const localRepositoryKey = String(entry?.localTask?.repositoryKey || '').trim()

  if (entry?.localTask?.syncState === 'PENDING') {
    return localRepositoryKey !== ''
      ? translateTasks(
        'tasksScreen.labels.pendingSyncWithRepository',
        `${localRepositoryKey} - Pendente de sincronização`,
        { repository: localRepositoryKey },
      )
      : translateTasks('tasksScreen.labels.pendingSync', 'Pendente de sincronização')
  }

  return localRepositoryKey || translateTasks('tasksScreen.labels.repositoryMissing', 'Sem repositório definido')
}

function resolveEntryLabels(entry) {
  if (isGithubEntry(entry)) {
    return formatLabels(entry.issue)
  }

  return entry.localTask?.templateKey || translateTasks('tasksScreen.labels.customTemplate', 'personalizado')
}

function resolveEntryOriginLabel(entry) {
  return isGithubEntry(entry)
    ? translateTasks('tasksScreen.origin.github', 'GitHub')
    : translateTasks('tasksScreen.origin.local', 'Local')
}

</script>

<template>
  <section class="tasks-screen grid gap-5">
    <article class="tasks-screen-panel rounded-[24px] border border-white/60 bg-white/80 p-4 app-depth-soft backdrop-blur">
      <div class="flex flex-col gap-3">
        <div class="min-w-0">
          <h2 class="text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">{{ scopeTitle }}</h2>
          <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ scopeDescription }}</p>
        </div>
      </div>

      <div class="mt-4 grid gap-2 sm:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-3">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ translateTasks('tasksScreen.kpis.total', 'Total') }}</span>
          <strong class="mt-1.5 block text-xl font-semibold text-slate-950">{{ issueStats.total }}</strong>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 p-3">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">{{ translateTasks('tasksScreen.kpis.open', 'Abertas') }}</span>
          <strong class="mt-1.5 block text-xl font-semibold text-emerald-950">{{ issueStats.open }}</strong>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-100/80 p-3">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ translateTasks('tasksScreen.kpis.closed', 'Fechadas') }}</span>
          <strong class="mt-1.5 block text-xl font-semibold text-slate-950">{{ issueStats.closed }}</strong>
        </div>
        <div class="rounded-2xl border border-cyan-200 bg-cyan-50/80 p-3">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-700">{{ translateTasks('tasksScreen.kpis.repositories', 'Repositórios') }}</span>
          <strong class="mt-1.5 block text-xl font-semibold text-cyan-950">{{ repositoriesCount }}</strong>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap gap-2">
        <button
          type="button"
          class="app-btn app-btn-primary"
          @click="openCreateModal"
        >
          {{ translateTasks('tasksScreen.actions.newTask', 'Nova tarefa') }}
        </button>
        <button
          type="button"
          class="app-btn app-btn-secondary"
          :disabled="syncing"
          @click="syncIssues({ announceRefresh: true })"
        >
          {{ syncing
            ? translateTasks('tasksScreen.actions.syncing', 'Sincronizando...')
            : translateTasks('tasksScreen.actions.refreshList', 'Atualizar lista')
          }}
        </button>
      </div>
    </article>

    <article
      class="tasks-screen-panel rounded-[28px] border border-white/60 bg-white/80 p-5 app-depth-soft backdrop-blur">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <h3 class="mt-1 text-2xl font-semibold text-slate-950">{{ translateTasks('tasksScreen.filters.title', 'Filtros') }}</h3>
        </div>

        <button type="button"
          class="app-btn app-btn-secondary shrink-0"
          @click="filtersOpen = !filtersOpen">
          {{ filtersOpen
            ? translateTasks('tasksScreen.filters.hide', 'Ocultar filtros')
            : translateTasks('tasksScreen.filters.show', 'Mostrar filtros')
          }}
        </button>
      </div>

      <div v-if="filtersOpen" class="mt-5 grid gap-5">
        <div class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
          <p class="text-sm font-semibold text-slate-900">{{ translateTasks('tasksScreen.filters.scope', 'Escopo') }}</p>

          <div class="flex flex-wrap gap-3">
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
              <input
                v-model="draftIssueScope"
                type="radio"
                class="h-4 w-4 accent-cyan-600"
                value="all"
              >
              <span>{{ translateTasks('tasksScreen.filters.scopeAll', 'Todas as issues') }}</span>
            </label>

            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
              <input
                v-model="draftIssueScope"
                type="radio"
                class="h-4 w-4 accent-cyan-600"
                value="assigned"
              >
              <span>{{ translateTasks('tasksScreen.filters.scopeAssigned', 'Atribuídas a mim') }}</span>
            </label>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <label class="grid min-w-0 gap-2 xl:col-span-2">
            <span class="text-sm font-semibold text-slate-900">{{ translateTasks('tasksScreen.filters.search', 'Buscar') }}</span>
            <input v-model="draftSearchTerm" type="text" :placeholder="translateTasks('tasksScreen.filters.searchPlaceholder', 'Título, label, autor, repositório...')"
              class="app-field-control h-11 w-full min-w-0 px-3 text-sm text-slate-900">
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">{{ translateTasks('tasksScreen.filters.source', 'Origem') }}</span>
            <select v-model="draftSourceFilter"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">{{ translateTasks('tasksScreen.filters.sourceAll', 'Local e GitHub') }}</option>
              <option value="github">{{ translateTasks('tasksScreen.origin.github', 'GitHub') }}</option>
              <option value="local">{{ translateTasks('tasksScreen.origin.localSystem', 'Sistema') }}</option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">{{ translateTasks('tasksScreen.filters.state', 'Estado') }}</span>
            <select v-model="draftStateFilter"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">{{ translateTasks('tasksScreen.filters.stateAll', 'Todos') }}</option>
              <option value="open">{{ translateTasks('tasksScreen.status.openPlural', 'Abertas') }}</option>
              <option value="closed">{{ translateTasks('tasksScreen.status.closedPlural', 'Fechadas') }}</option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">{{ translateTasks('tasksScreen.filters.repository', 'Repositório') }}</span>
            <select v-model="draftSelectedRepositoryKey"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">{{ translateTasks('tasksScreen.filters.repositoryAll', 'Todos os repositórios') }}</option>
              <option v-for="repositoryKey in repositoryFilterOptions" :key="repositoryKey"
                :value="repositoryKey">
                {{ repositoryKey }}
              </option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">{{ translateTasks('tasksScreen.filters.label', 'Label') }}</span>
            <select v-model="draftSelectedLabel"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">{{ translateTasks('tasksScreen.filters.labelAll', 'Todas as labels') }}</option>
              <option v-for="label in availableLabels" :key="label.id" :value="label.name">
                {{ label.name }}
              </option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">{{ translateTasks('tasksScreen.filters.ticketType', 'Tipo de chamado') }}</span>
            <select v-model="draftSelectedTicketType"
              class="app-field-control h-11 w-full min-w-0 appearance-none px-3 text-sm text-slate-900">
              <option value="all">{{ translateTasks('tasksScreen.filters.ticketTypeAll', 'Todos os tipos') }}</option>
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
            {{ translateTasks('tasksScreen.actions.applyFilters', 'Filtrar') }}
          </button>
          <button type="button"
            class="app-btn app-btn-secondary"
            :disabled="loadingCache" @click="resetFilters">
            {{ translateTasks('tasksScreen.actions.resetFilters', 'Restaurar padrão') }}
          </button>
        </div>
      </div>
    </article>

    <article class="tasks-screen-panel rounded-[24px] border border-white/60 bg-white/80 p-4 app-depth-soft backdrop-blur">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h3 class="text-xl font-semibold text-slate-950">{{ translateTasks('tasksScreen.list.title', 'Listagem de tarefas') }}</h3>
        </div>

        <div class="flex flex-wrap gap-2">
          <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold text-slate-700">
            {{ translateTasks('tasksScreen.list.scopeLabel', 'Escopo') }}:
            {{ issueScope === 'all'
              ? translateTasks('tasksScreen.filters.repositoryAll', 'Todos os repositórios')
              : issueScope === 'assigned'
                ? translateTasks('tasksScreen.filters.scopeAssigned', 'Atribuídas a mim')
                : translateTasks('tasksScreen.scope.repositorySpecific', 'Repositório específico')
            }}
          </span>
          <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-800">
            {{ translateTasks('tasksScreen.list.pendingLocal', 'Locais pendentes') }}: {{ localTaskStats.pending }}
          </span>
          <span class="inline-flex items-center rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-[11px] font-semibold text-cyan-800">
            {{ translateTasks('tasksScreen.list.lastSync', 'Última sync') }}: {{ lastSyncedLabel }}
          </span>
        </div>
      </div>

      <div v-if="listLoading && filteredTaskEntries.length === 0" class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500">
        {{ translateTasks('tasksScreen.loading.list', 'Carregando tarefas...') }}
      </div>

      <div v-else class="mt-4 grid gap-2">
        <button v-for="entry in paginatedTaskEntries" :key="entry.entryKey" type="button"
          class="w-full rounded-xl border px-4 py-3 text-left transition-all" :class="isGithubEntry(entry) && entry.issue.id === selectedIssueId
              ? 'border-cyan-300 bg-cyan-50/70 app-task-row-selected'
              : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
            " @click="openTaskEntry(entry)">
          <div class="space-y-3">
            <div class="grid grid-cols-[10%_52%_18%_20%] gap-x-4 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ translateTasks('tasksScreen.columns.number', 'Número') }}
                </p>
                <strong class="mt-1 block text-base font-bold leading-none text-slate-950">
                  {{ resolveEntryNumberLabel(entry) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ translateTasks('tasksScreen.columns.title', 'Título') }}
                </p>
                <strong class="mt-1 block truncate text-base font-bold leading-5 text-slate-950">
                  {{ resolveEntryTitle(entry) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ translateTasks('tasksScreen.columns.status', 'Status') }}
                </p>
                <span class="app-status-badge mt-1" :class="resolveEntryStatusClass(entry)">
                  {{ resolveEntryStatusLabel(entry) }}
                </span>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ translateTasks('tasksScreen.columns.origin', 'Origem') }}
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ resolveEntryOriginLabel(entry) }}
                </strong>
              </div>
            </div>

            <div class="grid grid-cols-[35%_35%_30%] gap-x-4 gap-y-2 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ translateTasks('tasksScreen.columns.createdAt', 'Data de abertura') }}
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ formatDateTime(entry.createdAt) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ translateTasks('tasksScreen.columns.updatedAt', 'Data de atualização') }}
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ formatDateTime(entry.updatedAt) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ translateTasks('tasksScreen.columns.assignee', 'Responsável') }}
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ resolveEntryAssignees(entry) }}
                </strong>
              </div>
            </div>

            <div class="grid grid-cols-[65%_35%] gap-x-4 gap-y-2 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ translateTasks('tasksScreen.columns.repository', 'Repositório') }}
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ resolveEntryRepository(entry) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  {{ isGithubEntry(entry)
                    ? translateTasks('tasksScreen.columns.labels', 'Labels')
                    : translateTasks('tasksScreen.columns.template', 'Template')
                  }}
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
          {{ translateTasks('tasksScreen.empty.filtered', 'Nenhuma tarefa encontrada para os filtros atuais.') }}
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
              {{ translateTasks('tasksScreen.pagination.previous', 'Anterior') }}
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
              {{ translateTasks('tasksScreen.pagination.next', 'Próxima') }}
            </button>
          </div>
        </div>
      </div>
    </article>

    <IssueCreateModal
      v-if="createModalOpen"
      :repositories="repositories"
      :initial-repository-key="createRepositoryKey"
      @close="createModalOpen = false"
      @issue-created="handleIssueCreated"
    />

    <WorkItemEditModal
      v-if="activeEditingMode"
      :key="activeEditingKey"
      :mode="activeEditingMode"
      :issue="editingIssue"
      :task="editingLocalTask"
      :update-templates="issueUpdateTemplates"
      :repositories="repositories"
      @close="closeWorkItemModal"
      @item-updated="handleWorkItemUpdated"
      @item-synced="handleLocalTaskSynced"
      @open-github-issue="handleOpenGithubIssueFromModal"
    />
  </section>
</template>
