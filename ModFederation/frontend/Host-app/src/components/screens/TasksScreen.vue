<script setup>
import axios from 'axios'
import { computed, onMounted, ref, watch } from 'vue'
import IssueCreateModal from '../tasks/IssueCreateModal.vue'
import IssueEditModal from '../tasks/IssueEditModal.vue'

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

const updateTemplates = [
  {
    key: 'status-update',
    label: 'Atualizacao de status',
    description: 'Resumo rapido do andamento atual do chamado.',
    markdownTitle: 'Atualizacao de status',
    fields: [
      {
        key: 'date',
        label: 'Data',
        type: 'text',
        renderAs: 'bullet',
        defaultValue: () => new Date().toLocaleString('pt-BR'),
      },
      {
        key: 'owner',
        label: 'Responsavel',
        type: 'text',
        renderAs: 'bullet',
        placeholder: 'Quem esta conduzindo o atendimento',
      },
      {
        key: 'currentStatus',
        label: 'Situacao atual',
        type: 'textarea',
        placeholder: 'Descreva o estado atual do chamado.',
      },
      {
        key: 'nextStep',
        label: 'Proximo passo',
        type: 'textarea',
        placeholder: 'Informe a proxima acao prevista.',
      },
      {
        key: 'notes',
        label: 'Observacoes',
        type: 'textarea',
        placeholder: 'Riscos, alinhamentos ou contexto adicional.',
      },
    ],
  },
  {
    key: 'blocker-update',
    label: 'Bloqueio',
    description: 'Padrao para registrar impedimento e acao necessaria.',
    markdownTitle: 'Bloqueio',
    fields: [
      {
        key: 'date',
        label: 'Data',
        type: 'text',
        renderAs: 'bullet',
        defaultValue: () => new Date().toLocaleString('pt-BR'),
      },
      {
        key: 'owner',
        label: 'Responsavel',
        type: 'text',
        renderAs: 'bullet',
        placeholder: 'Quem esta sinalizando o bloqueio',
      },
      {
        key: 'blocker',
        label: 'Bloqueio identificado',
        type: 'textarea',
        placeholder: 'Explique claramente o impedimento.',
      },
      {
        key: 'impact',
        label: 'Impacto',
        type: 'textarea',
        placeholder: 'O que esta sendo afetado por este bloqueio.',
      },
      {
        key: 'requiredAction',
        label: 'Acao necessaria',
        type: 'textarea',
        placeholder: 'O que precisa acontecer para liberar o fluxo.',
      },
    ],
  },
  {
    key: 'handoff-update',
    label: 'Repasse',
    description: 'Contexto pronto para troca de responsavel.',
    markdownTitle: 'Repasse de atendimento',
    fields: [
      {
        key: 'date',
        label: 'Data',
        type: 'text',
        renderAs: 'bullet',
        defaultValue: () => new Date().toLocaleString('pt-BR'),
      },
      {
        key: 'nextOwner',
        label: 'Proximo responsavel',
        type: 'text',
        renderAs: 'bullet',
        placeholder: 'Pessoa ou time que assume a issue',
      },
      {
        key: 'currentContext',
        label: 'Contexto atual',
        type: 'textarea',
        placeholder: 'Resumo do que ja foi feito e da situacao atual.',
      },
      {
        key: 'doneItems',
        label: 'Itens concluidos',
        type: 'list',
        listStyle: 'bullet',
        placeholder: 'Um item por linha.',
      },
      {
        key: 'pendingItems',
        label: 'Pendencias',
        type: 'list',
        listStyle: 'checklist',
        placeholder: 'Uma pendencia por linha.',
      },
    ],
  },
  {
    key: 'resolution-update',
    label: 'Resolucao',
    description: 'Fechamento estruturado do chamado.',
    markdownTitle: 'Resolucao do chamado',
    fields: [
      {
        key: 'date',
        label: 'Data',
        type: 'text',
        renderAs: 'bullet',
        defaultValue: () => new Date().toLocaleString('pt-BR'),
      },
      {
        key: 'owner',
        label: 'Responsavel',
        type: 'text',
        renderAs: 'bullet',
        placeholder: 'Quem aplicou a correcao',
      },
      {
        key: 'rootCause',
        label: 'Causa raiz',
        type: 'textarea',
        placeholder: 'Descreva a origem do problema.',
      },
      {
        key: 'appliedFix',
        label: 'Ajuste aplicado',
        type: 'textarea',
        placeholder: 'Explique a mudanca realizada.',
      },
      {
        key: 'validation',
        label: 'Validacao realizada',
        type: 'list',
        listStyle: 'checklist',
        placeholder: 'Um teste ou validacao por linha.',
      },
      {
        key: 'followUp',
        label: 'Acompanhamentos',
        type: 'list',
        listStyle: 'bullet',
        placeholder: 'Itens adicionais ou monitoramentos futuros.',
      },
    ],
  },
]

const issueBoard = ref(null)
const loadingCache = ref(false)
const syncing = ref(false)
const error = ref('')
const success = ref('')
const info = ref('')
const filtersOpen = ref(false)
const createModalOpen = ref(false)
const editingIssueId = ref('')
const selectedIssueId = ref('')
const issueScope = ref('all')
const stateFilter = ref('open')
const searchTerm = ref('')
const selectedLabel = ref('all')
const selectedTicketType = ref('all')
const selectedRepositoryKey = ref('all')
const draftIssueScope = ref('all')
const draftStateFilter = ref('open')
const draftSearchTerm = ref('')
const draftSelectedLabel = ref('all')
const draftSelectedTicketType = ref('all')
const draftSelectedRepositoryKey = ref('all')
const currentPage = ref(1)
const ITEMS_PER_PAGE = 10

const repositories = computed(() => Array.isArray(issueBoard.value?.repositories) ? issueBoard.value.repositories : [])
const issues = computed(() => Array.isArray(issueBoard.value?.items) ? issueBoard.value.items : [])
const editingIssue = computed(() => issues.value.find((issue) => issue.id === editingIssueId.value) || null)
const activeRepository = computed(() => issueBoard.value?.repository || null)
const cacheMeta = computed(() => issueBoard.value?.cache || null)
const repositoriesCount = computed(() => repositories.value.length)
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
const filteredIssues = computed(() => {
  const normalizedSearch = searchTerm.value.trim().toLowerCase()

  return issues.value.filter((issue) => {
    if (stateFilter.value === 'open' && issue.state === 'CLOSED') {
      return false
    }

    if (stateFilter.value === 'closed' && issue.state !== 'CLOSED') {
      return false
    }

    if (selectedRepositoryKey.value !== 'all' && issue.repository?.nameWithOwner !== selectedRepositoryKey.value) {
      return false
    }

    if (selectedLabel.value !== 'all' && !(issue.labels || []).some((label) => label.name === selectedLabel.value)) {
      return false
    }

    const ticketType = detectTicketType(issue)
    if (selectedTicketType.value !== 'all' && ticketType.key !== selectedTicketType.value) {
      return false
    }

    if (normalizedSearch !== '') {
      const haystack = [
        issue.title,
        issue.body,
        issue.repository?.nameWithOwner,
        issue.authorLogin,
        ...(issue.labels || []).map((label) => label.name),
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()

      if (!haystack.includes(normalizedSearch)) {
        return false
      }
    }

    return true
  })
})
const totalPages = computed(() => Math.max(1, Math.ceil(filteredIssues.value.length / ITEMS_PER_PAGE)))
const paginatedIssues = computed(() => {
  const start = (currentPage.value - 1) * ITEMS_PER_PAGE
  return filteredIssues.value.slice(start, start + ITEMS_PER_PAGE)
})
const paginationSummary = computed(() => {
  if (filteredIssues.value.length === 0) {
    return '0 de 0 issues'
  }

  const start = (currentPage.value - 1) * ITEMS_PER_PAGE + 1
  const end = Math.min(currentPage.value * ITEMS_PER_PAGE, filteredIssues.value.length)

  return `${start}-${end} de ${filteredIssues.value.length} issues`
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
const scopeTitle = computed(() => {
  if (issueScope.value === 'repository') {
    return activeRepository.value?.nameWithOwner || selectedRepositoryKey.value || 'Repositorio padrao do perfil'
  }

  if (issueScope.value === 'assigned') {
    return 'Issues atribuidas a mim'
  }

  return 'Todas as issues dos seus repositorios'
})
const scopeDescription = computed(() => {
  if (issueScope.value === 'repository') {
    return 'O sistema preenche a lista primeiro com o cache local do repositorio e depois sincroniza com o GitHub quando o TTL expira ou quando voce pedir atualizacao.'
  }

  if (issueScope.value === 'assigned') {
    return 'As issues atribuidas tambem aproveitam o banco local primeiro, sem depender de uma consulta completa ao GitHub em toda abertura da tela.'
  }

  return 'Na primeira entrada o sistema salva as issues do usuario no banco. Nas proximas, a tela preenche pelo banco e so volta ao GitHub de tempos em tempos.'
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
    ? formatDate(lastSyncedAt)
    : 'sem sincronizacao anterior'
})

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

onMounted(async () => {
  await loadCachedIssues({ resetSelection: true, syncStrategy: 'auto' })
})

watch(
  [issueScope, stateFilter, searchTerm, selectedLabel, selectedTicketType, selectedRepositoryKey],
  () => {
    currentPage.value = 1
  }
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
    info.value = 'Listagem atualizada a partir do GitHub e persistida no banco local.'
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel sincronizar as issues com o GitHub.')

    if (issues.value.length > 0) {
      info.value = 'Mantendo a listagem do banco local enquanto a sincronizacao do GitHub nao responde.'
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

  info.value = `Listagem servida do banco local. Ultima sincronizacao: ${lastSyncedLabel.value}.`
}

function openCreateModal() {
  createModalOpen.value = true
  success.value = ''
  error.value = ''
}

function openIssueModal(issue) {
  selectedIssueId.value = issue.id
  editingIssueId.value = issue.id
  success.value = ''
  error.value = ''
}

function closeIssueModal() {
  editingIssueId.value = ''
}

async function handleIssueCreated(payload) {
  createModalOpen.value = false
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

function formatDate(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return 'sem data'
  }

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(value))
}

function splitRepositoryKey(value) {
  const normalizedValue = String(value || '').trim()
  const separatorIndex = normalizedValue.indexOf('/')

  if (separatorIndex <= 0) {
    return { owner: '', name: '' }
  }

  return {
    owner: normalizedValue.slice(0, separatorIndex),
    name: normalizedValue.slice(separatorIndex + 1),
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
          class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/15 transition hover:brightness-105"
          @click="openCreateModal"
        >
          Nova issue
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
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
          class="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
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
              class="h-11 w-full min-w-0 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300">
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Estado</span>
            <select v-model="draftStateFilter"
              class="h-11 w-full min-w-0 appearance-none rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300">
              <option value="all">Todos</option>
              <option value="open">Abertas</option>
              <option value="closed">Fechadas</option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Repositorio</span>
            <select v-model="draftSelectedRepositoryKey"
              class="h-11 w-full min-w-0 appearance-none rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300">
              <option value="all">Todos os repositorios</option>
              <option v-for="repository in repositories" :key="repository.nameWithOwner"
                :value="repository.nameWithOwner">
                {{ repository.nameWithOwner }}
              </option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Label</span>
            <select v-model="draftSelectedLabel"
              class="h-11 w-full min-w-0 appearance-none rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300">
              <option value="all">Todas as labels</option>
              <option v-for="label in availableLabels" :key="label.id" :value="label.name">
                {{ label.name }}
              </option>
            </select>
          </label>

          <label class="grid min-w-0 gap-2">
            <span class="text-sm font-semibold text-slate-900">Tipo de chamado</span>
            <select v-model="draftSelectedTicketType"
              class="h-11 w-full min-w-0 appearance-none rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300">
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
            class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/15 transition hover:brightness-105"
            :disabled="loadingCache"
            @click="applyFilters"
          >
            Filtrar
          </button>
          <button type="button"
            class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
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
          <span class="inline-flex items-center rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-[11px] font-semibold text-cyan-800">
            Ultima sync: {{ lastSyncedLabel }}
          </span>
        </div>
      </div>

      <div v-if="!loadingCache || issues.length > 0" class="mt-4 grid gap-2">
        <button v-for="issue in paginatedIssues" :key="issue.id" type="button"
          class="w-full rounded-xl border px-4 py-3 text-left transition-all" :class="issue.id === selectedIssueId
              ? 'border-cyan-300 bg-cyan-50/70 shadow-[0_12px_28px_rgba(34,211,238,0.10)]'
              : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
            " @click="openIssueModal(issue)">
          <div class="space-y-3">
            <div class="grid grid-cols-[10%_65%_25%] gap-x-4 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Número
                </p>
                <strong class="mt-1 block text-base font-bold leading-none text-slate-950">
                  #{{ issue.number }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Título
                </p>
                <strong class="mt-1 block truncate text-base font-bold leading-5 text-slate-950">
                  {{ issue.title }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Status
                </p>
                <span class="mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="issue.state === 'CLOSED'
                    ? 'bg-rose-100 text-rose-700 ring-1 ring-inset ring-rose-200'
                    : 'bg-emerald-100 text-emerald-700 ring-1 ring-inset ring-emerald-200'
                  ">
                  {{ issue.state === 'CLOSED' ? 'Fechada' : 'Aberta' }}
                </span>
              </div>
            </div>

            <div class="grid grid-cols-[35%_35%_30%] gap-x-4 gap-y-2 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Data de abertura
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ formatDate(issue.createdAt) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Data de atualização
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ formatDate(issue.updatedAt) }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Responsável
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ formatAssignees(issue) }}
                </strong>
              </div>
            </div>

            <div class="grid grid-cols-[65%_35%] gap-x-4 gap-y-2 items-start">
              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Repositório
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ issue.repository?.nameWithOwner || 'Repositório atual' }}
                </strong>
              </div>

              <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                  Labels
                </p>
                <strong class="mt-0.5 block truncate text-sm font-semibold text-slate-700">
                  {{ formatLabels(issue) }}
                </strong>
              </div>
            </div>
          </div>
        </button>

        <p
          v-if="filteredIssues.length === 0"
          class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
        >
          Nenhuma issue encontrada para os filtros atuais.
        </p>

        <div
          v-if="filteredIssues.length > 0"
          class="mt-2 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
          <p class="text-sm font-medium text-slate-600">{{ paginationSummary }}</p>

          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="currentPage === 1"
              @click.stop="goToPage(currentPage - 1)"
            >
              Anterior
            </button>

            <button
              v-for="page in visiblePages"
              :key="page"
              type="button"
              class="inline-flex h-10 min-w-10 items-center justify-center rounded-xl border px-3 text-sm font-semibold transition"
              :class="page === currentPage ? 'border-cyan-300 bg-cyan-50 text-cyan-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-100'"
              @click.stop="goToPage(page)"
            >
              {{ page }}
            </button>

            <button
              type="button"
              class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
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
      :issue="editingIssue"
      :update-templates="updateTemplates"
      @close="closeIssueModal"
      @issue-updated="handleIssueUpdated"
    />
  </section>
</template>
