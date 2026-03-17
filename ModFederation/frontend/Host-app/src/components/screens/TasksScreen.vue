<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
})

const updateTemplates = [
  {
    key: 'status-update',
    label: 'Atualizacao de status',
    description: 'Resumo rapido do andamento atual do chamado.',
    build: () => [
      '## Atualizacao de status',
      `- Data: ${new Date().toLocaleString('pt-BR')}`,
      '- Situacao atual:',
      '- Proximo passo:',
      '- Responsavel:',
      '',
    ].join('\n'),
  },
  {
    key: 'blocker-update',
    label: 'Bloqueio',
    description: 'Padrao para registrar impedimento e acao necessaria.',
    build: () => [
      '## Bloqueio',
      `- Data: ${new Date().toLocaleString('pt-BR')}`,
      '- Bloqueio identificado:',
      '- Impacto:',
      '- Acao necessaria:',
      '',
    ].join('\n'),
  },
  {
    key: 'handoff-update',
    label: 'Repasse',
    description: 'Contexto pronto para troca de responsavel.',
    build: () => [
      '## Repasse de atendimento',
      `- Data: ${new Date().toLocaleString('pt-BR')}`,
      '- Contexto atual:',
      '- Pendencias:',
      '- Proximo responsavel:',
      '',
    ].join('\n'),
  },
  {
    key: 'resolution-update',
    label: 'Resolucao',
    description: 'Fechamento estruturado do chamado.',
    build: () => [
      '## Resolucao do chamado',
      `- Data: ${new Date().toLocaleString('pt-BR')}`,
      '- Causa raiz:',
      '- Ajuste aplicado:',
      '- Validacao realizada:',
      '',
    ].join('\n'),
  },
]

const issueBoard = ref(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const selectedIssueId = ref('')
const filter = ref('open')
const issueScope = ref('assigned')
const searchTerm = ref('')
const selectedLabel = ref('all')
const selectedTicketType = ref('all')
const selectedUpdateTemplate = ref(updateTemplates[0].key)
const repositoryForm = reactive({
  owner: '',
  name: '',
})
const issueForm = reactive({
  title: '',
  body: '',
  state: 'OPEN',
})

const repository = computed(() => issueBoard.value?.repository || null)
const repositories = computed(() => Array.isArray(issueBoard.value?.repositories) ? issueBoard.value.repositories : [])
const viewerLogin = computed(() => issueBoard.value?.viewer?.login || '')
const issues = computed(() => Array.isArray(issueBoard.value?.items) ? issueBoard.value.items : [])
const selectedIssue = computed(() => issues.value.find((issue) => issue.id === selectedIssueId.value) || null)
const selectedRepositoryKey = computed(() => {
  const owner = repositoryForm.owner.trim()
  const name = repositoryForm.name.trim()

  return owner !== '' && name !== '' ? `${owner}/${name}` : ''
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
const filteredIssues = computed(() => {
  const normalizedSearch = searchTerm.value.trim().toLowerCase()

  return issues.value.filter((issue) => {
    if (filter.value === 'open' && issue.state === 'CLOSED') {
      return false
    }

    if (filter.value === 'closed' && issue.state !== 'CLOSED') {
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
const issueStats = computed(() => ({
  total: issues.value.length,
  open: issues.value.filter((issue) => issue.state !== 'CLOSED').length,
  closed: issues.value.filter((issue) => issue.state === 'CLOSED').length,
}))
const canEditSelectedIssue = computed(() => Boolean(selectedIssue.value?.viewerCanUpdate))
const scopeTitle = computed(() => (issueScope.value === 'repository' ? 'Todas as issues do repositorio' : 'Issues atribuidas a mim'))
const scopeDescription = computed(() => (
  issueScope.value === 'repository'
    ? 'Veja o backlog completo do repositorio selecionado, mesmo quando a issue nao estiver atribuida a voce.'
    : 'Veja apenas as issues em que voce esta atribuido, usando o repositorio atual ou um repositorio alternativo.'
))

onMounted(async () => {
  await loadIssues()
})

async function loadIssues(options = {}) {
  const useRepositoryOverride = Boolean(options.useRepositoryOverride)
  const resetSelection = Boolean(options.resetSelection)

  loading.value = true
  error.value = ''

  if (resetSelection) {
    selectedIssueId.value = ''
  }

  try {
    const owner = repositoryForm.owner.trim()
    const name = repositoryForm.name.trim()
    const params = {
      scope: issueScope.value,
    }

    if (useRepositoryOverride || (owner !== '' && name !== '')) {
      if (owner === '' || name === '') {
        throw new Error('Informe owner e nome do repositorio para consultar um repositorio alternativo.')
      }

      params.repositoryOwner = owner
      params.repositoryName = name
    }

    const { data } = await props.request({
      url: '/github/issues/assigned',
      method: 'GET',
      params,
    })

    issueBoard.value = data || null
    issueScope.value = data?.scope === 'repository' ? 'repository' : 'assigned'
    repositoryForm.owner = data?.repository?.ownerLogin || ''
    repositoryForm.name = data?.repository?.name || ''

    const currentSelection = issues.value.find((issue) => issue.id === selectedIssueId.value)
    if (currentSelection) {
      syncIssueForm(currentSelection)
      return
    }

    if (issues.value.length > 0) {
      selectIssue(issues.value[0])
      return
    }

    clearIssueForm()
  } catch (requestError) {
    clearIssueForm()
    error.value = extractHttpMessage(requestError, 'Nao foi possivel carregar as issues do GitHub.')
  } finally {
    loading.value = false
  }
}

function selectIssue(issue) {
  selectedIssueId.value = issue.id
  syncIssueForm(issue)
  success.value = ''
  error.value = ''
}

function syncIssueForm(issue) {
  issueForm.title = issue?.title || ''
  issueForm.body = issue?.body || ''
  issueForm.state = issue?.state === 'CLOSED' ? 'CLOSED' : 'OPEN'
}

function clearIssueForm() {
  selectedIssueId.value = ''
  issueForm.title = ''
  issueForm.body = ''
  issueForm.state = 'OPEN'
}

async function saveIssue() {
  if (!selectedIssue.value) {
    error.value = 'Selecione uma issue para atualizar.'
    return
  }

  if (issueForm.title.trim() === '') {
    error.value = 'O titulo da issue e obrigatorio.'
    return
  }

  saving.value = true
  error.value = ''
  success.value = ''

  try {
    const { data } = await props.request({
      url: `/github/issues/${encodeURIComponent(selectedIssue.value.id)}`,
      method: 'PATCH',
      csrfActionId: 'github.issue.update',
      data: {
        title: issueForm.title,
        body: issueForm.body,
        state: issueForm.state,
      },
    })

    const updatedIssue = data?.item
    if (updatedIssue) {
      issueBoard.value = {
        ...(issueBoard.value || {}),
        items: issues.value.map((issue) => (issue.id === updatedIssue.id ? updatedIssue : issue)),
      }

      selectIssue(updatedIssue)
    }

    success.value = 'Issue atualizada com sucesso.'
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel atualizar a issue.')
  } finally {
    saving.value = false
  }
}

function applyRepositoryPreset(event) {
  const value = String(event?.target?.value || '').trim()
  if (value === '') {
    return
  }

  const separatorIndex = value.indexOf('/')
  if (separatorIndex === -1) {
    return
  }

  repositoryForm.owner = value.slice(0, separatorIndex)
  repositoryForm.name = value.slice(separatorIndex + 1)
  success.value = ''
  error.value = ''
  loadIssues({
    useRepositoryOverride: true,
    resetSelection: true,
  })
}

function loadConfiguredRepository() {
  repositoryForm.owner = ''
  repositoryForm.name = ''
  success.value = ''
  error.value = ''

  loadIssues({
    useRepositoryOverride: false,
    resetSelection: true,
  })
}

function switchScope(scope) {
  if (scope !== 'assigned' && scope !== 'repository') {
    return
  }

  issueScope.value = scope
  success.value = ''
  error.value = ''

  loadIssues({
    useRepositoryOverride: selectedRepositoryKey.value !== '',
    resetSelection: true,
  })
}

function applyUpdateTemplate() {
  const template = updateTemplates.find((candidate) => candidate.key === selectedUpdateTemplate.value)
  if (!template) {
    return
  }

  const block = template.build()
  issueForm.body = issueForm.body.trim() === ''
    ? block
    : `${issueForm.body.trim()}\n\n${block}`
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
    <article class="rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Issues GitHub</p>
          <h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{{ scopeTitle }}</h2>
          <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600 sm:text-base">{{ scopeDescription }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition"
            :class="issueScope === 'assigned' ? 'border-cyan-300 bg-cyan-50 text-cyan-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
            :disabled="loading"
            @click="switchScope('assigned')"
          >
            Atribuidas a mim
          </button>
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition"
            :class="issueScope === 'repository' ? 'border-cyan-300 bg-cyan-50 text-cyan-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
            :disabled="loading"
            @click="switchScope('repository')"
          >
            Todas do repositorio
          </button>
        </div>
      </div>

      <div class="mt-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Total</span>
          <strong class="mt-2 block text-2xl font-semibold text-slate-950">{{ issueStats.total }}</strong>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Abertas</span>
          <strong class="mt-2 block text-2xl font-semibold text-emerald-950">{{ issueStats.open }}</strong>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-100/80 p-4">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Fechadas</span>
          <strong class="mt-2 block text-2xl font-semibold text-slate-950">{{ issueStats.closed }}</strong>
        </div>
      </div>
    </article>

    <article class="rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Repositorio</p>
          <h2 class="mt-1 break-all text-2xl font-semibold text-slate-950">{{ repository?.nameWithOwner || 'Repositorio padrao do perfil' }}</h2>
          <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">
            {{ repository?.description || 'Escolha um repositorio do seu perfil GitHub ou informe outro owner/nome abaixo.' }}
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
            {{ repository?.isPrivate ? 'Privado' : 'Publico' }}
          </span>
          <span class="inline-flex items-center rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-800">
            @{{ viewerLogin || 'sem-login' }}
          </span>
        </div>
      </div>

      <div class="mt-5 grid gap-4">
        <label class="grid gap-2">
          <span class="text-sm font-semibold text-slate-900">Repositorios acessiveis</span>
          <select
            class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none ring-0 transition focus:border-cyan-300"
            :value="selectedRepositoryKey"
            @change="applyRepositoryPreset"
          >
            <option value="">Selecione um repositorio</option>
            <option
              v-for="availableRepository in repositories"
              :key="availableRepository.nameWithOwner"
              :value="availableRepository.nameWithOwner"
            >
              {{ availableRepository.nameWithOwner }}
            </option>
          </select>
        </label>

        <div class="grid gap-4 md:grid-cols-2">
          <label class="grid gap-2">
            <span class="text-sm font-semibold text-slate-900">Repository owner</span>
            <input
              v-model="repositoryForm.owner"
              type="text"
              placeholder="org-ou-usuario"
              class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
            >
          </label>

          <label class="grid gap-2">
            <span class="text-sm font-semibold text-slate-900">Repository name</span>
            <input
              v-model="repositoryForm.name"
              type="text"
              placeholder="nome-do-repositorio"
              class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
            >
          </label>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="inline-flex max-w-full items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/15 transition hover:brightness-105"
            :disabled="loading"
            @click="loadIssues({ useRepositoryOverride: true, resetSelection: true })"
          >
            {{ loading ? 'Carregando...' : 'Ver este repositorio' }}
          </button>
          <button
            type="button"
            class="inline-flex max-w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            :disabled="loading"
            @click="loadConfiguredRepository"
          >
            Voltar ao repositorio do perfil
          </button>
          <a
            v-if="repository?.url"
            class="inline-flex max-w-full items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
            :href="repository.url"
            target="_blank"
            rel="noreferrer noopener"
          >
            Abrir repositorio
          </a>
        </div>
      </div>
    </article>

    <div class="grid gap-5 xl:grid-cols-[minmax(300px,0.92fr),minmax(0,1.08fr)]">
      <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
        <div class="flex flex-col gap-4">
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Fila</p>
            <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ scopeTitle }}</h2>
          </div>

          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="inline-flex items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition"
              :class="filter === 'all' ? 'border-cyan-300 bg-cyan-50 text-cyan-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
              @click="filter = 'all'"
            >
              Todas
            </button>
            <button
              type="button"
              class="inline-flex items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition"
              :class="filter === 'open' ? 'border-cyan-300 bg-cyan-50 text-cyan-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
              @click="filter = 'open'"
            >
              Abertas
            </button>
            <button
              type="button"
              class="inline-flex items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition"
              :class="filter === 'closed' ? 'border-cyan-300 bg-cyan-50 text-cyan-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
              @click="filter = 'closed'"
            >
              Fechadas
            </button>
          </div>
        </div>

        <div class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
          <div class="grid gap-3 md:grid-cols-2">
            <label class="grid gap-2">
              <span class="text-sm font-semibold text-slate-900">Buscar</span>
              <input
                v-model="searchTerm"
                type="text"
                placeholder="Titulo, label, autor, repositorio..."
                class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
              >
            </label>

            <label class="grid gap-2">
              <span class="text-sm font-semibold text-slate-900">Label</span>
              <select
                v-model="selectedLabel"
                class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
              >
                <option value="all">Todas as labels</option>
                <option v-for="label in availableLabels" :key="label.id" :value="label.name">
                  {{ label.name }}
                </option>
              </select>
            </label>
          </div>

          <div class="grid gap-3 md:grid-cols-[minmax(0,1fr),auto]">
            <label class="grid gap-2">
              <span class="text-sm font-semibold text-slate-900">Tipo de chamado</span>
              <select
                v-model="selectedTicketType"
                class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
              >
                <option value="all">Todos os tipos</option>
                <option v-for="type in availableTicketTypes" :key="type.key" :value="type.key">
                  {{ type.label }}
                </option>
              </select>
            </label>

            <div class="flex items-end">
              <button
                type="button"
                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                :disabled="loading"
                @click="loadIssues({ useRepositoryOverride: selectedRepositoryKey !== '' })"
              >
                Atualizar lista
              </button>
            </div>
          </div>
        </div>

        <p
          v-if="loading"
          class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500"
        >
          Carregando issues...
        </p>
        <p
          v-if="error"
          class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
        >
          {{ error }}
        </p>
        <p
          v-if="success"
          class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
        >
          {{ success }}
        </p>

        <div v-if="!loading" class="grid gap-3">
          <button
            v-for="issue in filteredIssues"
            :key="issue.id"
            type="button"
            class="grid gap-3 rounded-2xl border p-4 text-left transition"
            :class="issue.id === selectedIssueId ? 'border-cyan-300 bg-cyan-50/70 shadow-[0_16px_34px_rgba(34,211,238,0.08)]' : 'border-slate-200 bg-white hover:bg-slate-50'"
            @click="selectIssue(issue)"
          >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <strong class="min-w-0 break-words text-lg font-semibold text-slate-950">
                #{{ issue.number }} {{ issue.title }}
              </strong>
              <span
                class="inline-flex w-fit items-center rounded-full px-3 py-1 text-xs font-semibold"
                :class="issue.state === 'CLOSED' ? 'bg-slate-200 text-slate-700' : 'bg-emerald-100 text-emerald-800'"
              >
                {{ issue.state === 'CLOSED' ? 'Fechada' : 'Aberta' }}
              </span>
            </div>

            <div class="flex flex-wrap gap-2">
              <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                {{ issue.repository?.nameWithOwner || repository?.nameWithOwner || 'Repositorio atual' }}
              </span>
              <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                {{ detectTicketType(issue).label }}
              </span>
            </div>

            <div class="flex flex-wrap gap-2">
              <span
                v-for="label in issue.labels || []"
                :key="label.id"
                class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600"
              >
                {{ label.name }}
              </span>
            </div>

            <small class="text-sm text-slate-500">Atualizada em {{ formatDate(issue.updatedAt) }}</small>
          </button>

          <p
            v-if="filteredIssues.length === 0"
            class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
          >
            Nenhuma issue encontrada para os filtros atuais.
          </p>
        </div>
      </article>

      <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Detalhes</p>
            <h2 class="mt-1 text-2xl font-semibold text-slate-950">
              {{ selectedIssue ? `Issue #${selectedIssue.number}` : 'Selecione uma issue' }}
            </h2>
          </div>

          <a
            v-if="selectedIssue?.url"
            class="inline-flex max-w-full items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
            :href="selectedIssue.url"
            target="_blank"
            rel="noreferrer noopener"
          >
            Abrir no GitHub
          </a>
        </div>

        <template v-if="selectedIssue">
          <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
              <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repositorio</span>
              <strong class="mt-2 block break-all text-sm font-semibold text-slate-950">
                {{ selectedIssue.repository?.nameWithOwner || repository?.nameWithOwner }}
              </strong>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
              <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Autor</span>
              <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ selectedIssue.authorLogin || 'desconhecido' }}</strong>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
              <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Atualizada</span>
              <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDate(selectedIssue.updatedAt) }}</strong>
            </div>
          </div>

          <div v-if="selectedIssue.labels?.length" class="flex flex-wrap gap-2">
            <span
              v-for="label in selectedIssue.labels"
              :key="label.id"
              class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600"
            >
              {{ label.name }}
            </span>
          </div>

          <div v-if="selectedIssue.assignees?.length" class="flex flex-wrap gap-2">
            <span
              v-for="assignee in selectedIssue.assignees"
              :key="assignee.login"
              class="inline-flex items-center rounded-full bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-800"
            >
              {{ assignee.name || assignee.login }}
            </span>
          </div>

          <p
            v-if="!canEditSelectedIssue"
            class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
          >
            Sua conta consegue visualizar esta issue, mas nao tem permissao para atualiza-la.
          </p>

          <div class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900">Templates de atualizacao</p>
                <p class="text-sm text-slate-500">Aplique blocos padrao para andamento, bloqueio, repasse ou resolucao.</p>
              </div>

              <div class="flex flex-wrap gap-2">
                <select
                  v-model="selectedUpdateTemplate"
                  class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                >
                  <option v-for="template in updateTemplates" :key="template.key" :value="template.key">
                    {{ template.label }}
                  </option>
                </select>
                <button
                  type="button"
                  class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                  @click="applyUpdateTemplate"
                >
                  Aplicar template
                </button>
              </div>
            </div>

            <p class="text-sm text-slate-500">
              {{ updateTemplates.find((template) => template.key === selectedUpdateTemplate)?.description }}
            </p>
          </div>

          <form class="grid gap-4" @submit.prevent="saveIssue">
            <label class="grid gap-2">
              <span class="text-sm font-semibold text-slate-900">Titulo</span>
              <input
                v-model="issueForm.title"
                type="text"
                :disabled="!canEditSelectedIssue || saving"
                class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
                required
              >
            </label>

            <label class="grid gap-2">
              <span class="text-sm font-semibold text-slate-900">Descricao</span>
              <textarea
                v-model="issueForm.body"
                rows="14"
                :disabled="!canEditSelectedIssue || saving"
                class="min-h-[260px] rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm leading-6 text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
              />
            </label>

            <label class="grid gap-2 sm:max-w-xs">
              <span class="text-sm font-semibold text-slate-900">Status</span>
              <select
                v-model="issueForm.state"
                :disabled="!canEditSelectedIssue || saving"
                class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
              >
                <option value="OPEN">Aberta</option>
                <option value="CLOSED">Fechada</option>
              </select>
            </label>

            <div class="flex flex-wrap gap-2">
              <button
                type="submit"
                :disabled="!canEditSelectedIssue || saving"
                class="inline-flex max-w-full items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/15 transition hover:brightness-105 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {{ saving ? 'Salvando...' : 'Salvar issue' }}
              </button>
              <button
                type="button"
                :disabled="saving"
                class="inline-flex max-w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                @click="syncIssueForm(selectedIssue)"
              >
                Desfazer alteracoes
              </button>
            </div>
          </form>
        </template>

        <p
          v-else
          class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
        >
          Selecione uma issue para visualizar os detalhes, filtrar melhor o backlog e aplicar templates de atualizacao.
        </p>
      </article>
    </div>
  </section>
</template>
