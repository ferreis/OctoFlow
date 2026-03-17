<script setup>
import axios from 'axios'
import { computed, onMounted, ref, watch } from 'vue'

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

const profile = ref(null)
const issueBoard = ref(null)
const loading = ref(false)
const syncing = ref(false)
const error = ref('')
const status = ref('')
const activeTab = ref('tasks')

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

const workspaceReady = computed(() => Boolean(profile.value?.workspaceReady))
const repositoryLabel = computed(() => {
  const repositories = Array.isArray(profile.value?.repositories) ? profile.value.repositories : []
  const owner = String(profile.value?.repositoryOwner || '').trim()

  if (repositories.length > 0) {
    return `${repositories.length} repositorios cadastrados`
  }

  if (owner !== '') {
    return `Owner padrao: ${owner}`
  }

  return 'Nenhum repositorio cadastrado'
})
const issues = computed(() => Array.isArray(issueBoard.value?.items) ? issueBoard.value.items : [])
const repositories = computed(() => Array.isArray(issueBoard.value?.repositories) ? issueBoard.value.repositories : [])
const openIssues = computed(() => issues.value.filter((issue) => issue.state !== 'CLOSED'))
const closedIssues = computed(() => issues.value.filter((issue) => issue.state === 'CLOSED'))
const taskOverviewCards = computed(() => [
  {
    label: 'Issues totais',
    value: String(issues.value.length),
    note: 'volume atual analisado',
    cardClass: 'border-slate-200 bg-white/85',
    labelClass: 'text-slate-500',
    valueClass: 'text-slate-950',
  },
  {
    label: 'Abertas',
    value: String(openIssues.value.length),
    note: 'backlog em andamento',
    cardClass: 'border-emerald-200 bg-emerald-50/80',
    labelClass: 'text-emerald-700',
    valueClass: 'text-emerald-950',
  },
  {
    label: 'Fechadas',
    value: String(closedIssues.value.length),
    note: 'tarefas concluidas',
    cardClass: 'border-slate-200 bg-slate-100/80',
    labelClass: 'text-slate-500',
    valueClass: 'text-slate-950',
  },
  {
    label: 'Repositorios',
    value: String(repositories.value.length),
    note: 'fontes em observacao',
    cardClass: 'border-cyan-200 bg-cyan-50/80',
    labelClass: 'text-cyan-700',
    valueClass: 'text-cyan-950',
  },
])
const issueStatusSeries = computed(() => {
  const total = issues.value.length || 1

  return [
    {
      label: 'Abertas',
      value: openIssues.value.length,
      share: (openIssues.value.length / total) * 100,
      cardClass: 'border-emerald-200 bg-emerald-50/80',
      labelClass: 'text-emerald-700',
      valueClass: 'text-emerald-950',
      barClass: 'bg-gradient-to-r from-emerald-500 to-teal-500',
    },
    {
      label: 'Fechadas',
      value: closedIssues.value.length,
      share: (closedIssues.value.length / total) * 100,
      cardClass: 'border-slate-200 bg-slate-100/80',
      labelClass: 'text-slate-500',
      valueClass: 'text-slate-950',
      barClass: 'bg-gradient-to-r from-slate-500 to-slate-700',
    },
  ]
})
const closureWindowSeries = computed(() => withPercent([
  {
    label: 'Semana',
    value: countClosedWithinDays(closedIssues.value, 7),
  },
  {
    label: 'Mes',
    value: countClosedWithinDays(closedIssues.value, 30),
  },
  {
    label: 'Ano',
    value: countClosedWithinDays(closedIssues.value, 365),
  },
]))
const averageResolutionHours = computed(() => averageDurationHours(closedIssues.value))
const averageOpenAgeHours = computed(() => averageAgeHours(openIssues.value))
const cycleTimeCards = computed(() => [
  {
    label: 'Media para finalizar',
    value: formatDuration(averageResolutionHours.value),
    note: closedIssues.value.length > 0
      ? `${closedIssues.value.length} issues fechadas analisadas`
      : 'sem base de issues fechadas ainda',
    cardClass: 'border-cyan-200 bg-cyan-50/80',
    valueClass: 'text-cyan-950',
  },
  {
    label: 'Tempo medio sem update',
    value: formatDuration(averageOpenAgeHours.value),
    note: openIssues.value.length > 0
      ? `${openIssues.value.length} issues abertas consideradas`
      : 'sem issues abertas no momento',
    cardClass: 'border-violet-200 bg-violet-50/80',
    valueClass: 'text-violet-950',
  },
])
const updateFreshnessSeries = computed(() => withPercent([
  {
    label: 'Ate 24h',
    value: countIssuesByUpdateAge(openIssues.value, 0, 24),
  },
  {
    label: '1-7 dias',
    value: countIssuesByUpdateAge(openIssues.value, 24, 24 * 7),
  },
  {
    label: '8-30 dias',
    value: countIssuesByUpdateAge(openIssues.value, 24 * 7, 24 * 30),
  },
  {
    label: 'Mais de 30 dias',
    value: countIssuesByUpdateAge(openIssues.value, 24 * 30, Number.POSITIVE_INFINITY),
  },
]))
const mostOpenTypesSeries = computed(() => {
  const counters = new Map()

  for (const issue of openIssues.value) {
    const label = detectTaskType(issue)
    counters.set(label, (counters.get(label) || 0) + 1)
  }

  return withPercent(
    Array.from(counters.entries())
      .map(([label, value]) => ({ label, value }))
      .sort((left, right) => right.value - left.value)
      .slice(0, 6)
  )
})
const responseByTypeSeries = computed(() => {
  const sourceIssues = closedIssues.value.length > 0 ? closedIssues.value : issues.value
  const grouped = new Map()

  for (const issue of sourceIssues) {
    const label = detectTaskType(issue)
    const hours = calculateLifecycleHours(issue)

    if (hours === null) {
      continue
    }

    if (!grouped.has(label)) {
      grouped.set(label, [])
    }

    grouped.get(label).push(hours)
  }

  return withPercent(
    Array.from(grouped.entries())
      .map(([label, hours]) => ({
        label,
        hours: average(hours),
        formatted: formatDuration(average(hours)),
      }))
      .filter((item) => item.hours !== null)
      .sort((left, right) => (right.hours || 0) - (left.hours || 0))
      .slice(0, 6),
    'hours'
  )
})
onMounted(async () => {
  await loadDashboardContext(false)
})

watch(
  () => props.currentUser?.id,
  async (userId, previousUserId) => {
    if (!userId) {
      profile.value = null
      issueBoard.value = null
      error.value = ''
      status.value = ''
      return
    }

    if (userId !== previousUserId) {
      await loadDashboardContext(false)
    }
  },
)

watch(error, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'error')
  error.value = ''
})

watch(status, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'info')
  status.value = ''
})

async function loadDashboardContext(showStatus = false) {
  if (!props.currentUser?.id) {
    return
  }

  loading.value = true
  error.value = ''

  if (showStatus) {
    status.value = ''
  }

  try {
    const profileResponse = await props.request({
      url: '/github/profile',
      method: 'GET',
    })

    profile.value = profileResponse.data?.profile || null

    if (!workspaceReady.value) {
      issueBoard.value = null

      if (showStatus) {
        status.value = 'Perfil GitHub carregado. Finalize a configuracao no Perfil para liberar as analises.'
      }

      return
    }

    await loadIssueAnalytics(showStatus)
  } catch (requestError) {
    issueBoard.value = null
    error.value = extractHttpMessage(requestError, 'Nao foi possivel carregar o dashboard analitico.')
  } finally {
    loading.value = false
  }
}

async function loadIssueAnalytics(showStatus = false) {
  const cacheResponse = await props.request({
    url: '/github/issues/cache',
    method: 'GET',
    params: {
      scope: 'all',
    },
  })

  issueBoard.value = cacheResponse.data || null

  const hasItems = Array.isArray(cacheResponse.data?.items) && cacheResponse.data.items.length > 0
  const needsRefresh = Boolean(cacheResponse.data?.cache?.needsRefresh)

  if (!hasItems) {
    await syncIssueAnalytics(showStatus)
    return
  }

  if (showStatus) {
    status.value = 'Dashboard carregado do banco local.'
  }

  if (needsRefresh) {
    void syncIssueAnalytics(false)
  }
}

async function syncIssueAnalytics(showStatus = false) {
  syncing.value = true
  error.value = ''

  try {
    const response = await props.request({
      url: '/github/issues/assigned',
      method: 'GET',
      params: {
        scope: 'all',
      },
    })

    issueBoard.value = response.data || null

    if (showStatus || issues.value.length === 0) {
      status.value = 'Analises atualizadas com os dados mais recentes do GitHub.'
    }
  } catch (requestError) {
    if (issues.value.length > 0) {
      status.value = 'Mantendo as analises do banco local enquanto a sincronizacao do GitHub nao responde.'
      return
    }

    error.value = extractHttpMessage(requestError, 'Nao foi possivel sincronizar as analises do GitHub.')
  } finally {
    syncing.value = false
  }
}

function detectTaskType(issue) {
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

  return catalog[rawKey] || 'Outros'
}

function countClosedWithinDays(issueList, days) {
  const limitInHours = days * 24
  let total = 0

  for (const issue of issueList) {
    const updatedAt = parseTimestamp(issue?.updatedAt)
    if (!updatedAt) {
      continue
    }

    const hours = diffHours(updatedAt, new Date())
    if (hours !== null && hours <= limitInHours) {
      total += 1
    }
  }

  return total
}

function countIssuesByUpdateAge(issueList, minHours, maxHours) {
  let total = 0

  for (const issue of issueList) {
    const updatedAt = parseTimestamp(issue?.updatedAt)
    if (!updatedAt) {
      continue
    }

    const hours = diffHours(updatedAt, new Date())
    if (hours === null) {
      continue
    }

    if (hours >= minHours && hours < maxHours) {
      total += 1
    }
  }

  return total
}

function averageDurationHours(issueList) {
  const values = issueList
    .map((issue) => calculateLifecycleHours(issue))
    .filter((value) => value !== null)

  return average(values)
}

function averageAgeHours(issueList) {
  const values = issueList
    .map((issue) => {
      const updatedAt = parseTimestamp(issue?.updatedAt)
      return updatedAt ? diffHours(updatedAt, new Date()) : null
    })
    .filter((value) => value !== null)

  return average(values)
}

function calculateLifecycleHours(issue) {
  const createdAt = parseTimestamp(issue?.createdAt)
  const updatedAt = parseTimestamp(issue?.updatedAt)

  if (!createdAt || !updatedAt) {
    return null
  }

  return diffHours(createdAt, updatedAt)
}

function parseTimestamp(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return null
  }

  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime()) ? null : parsed
}

function diffHours(startDate, endDate) {
  const startTime = startDate instanceof Date ? startDate.getTime() : Number.NaN
  const endTime = endDate instanceof Date ? endDate.getTime() : Number.NaN

  if (!Number.isFinite(startTime) || !Number.isFinite(endTime) || endTime < startTime) {
    return null
  }

  return (endTime - startTime) / 3600000
}

function average(values) {
  if (!Array.isArray(values) || values.length === 0) {
    return null
  }

  return values.reduce((total, value) => total + value, 0) / values.length
}

function withPercent(items, valueKey = 'value') {
  const maxValue = items.reduce((highest, item) => Math.max(highest, Number(item?.[valueKey] || 0)), 0)

  return items.map((item) => ({
    ...item,
    percentage: maxValue > 0 ? (Number(item?.[valueKey] || 0) / maxValue) * 100 : 0,
  }))
}

function buildBarStyle(percentage) {
  if (!Number.isFinite(percentage) || percentage <= 0) {
    return { width: '0%' }
  }

  return {
    width: `${Math.max(percentage, 8)}%`,
  }
}

function formatDuration(hours) {
  if (!Number.isFinite(hours) || hours === null) {
    return 'sem base'
  }

  if (hours < 1) {
    return `${Math.max(1, Math.round(hours * 60))} min`
  }

  if (hours < 24) {
    return `${hours >= 10 ? Math.round(hours) : hours.toFixed(1)} h`
  }

  const days = hours / 24
  if (days < 30) {
    return `${days >= 10 ? Math.round(days) : days.toFixed(1)} d`
  }

  const months = days / 30
  return `${months.toFixed(1)} mes`
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
    <article class="themed-hero-surface rounded-[28px] border border-white/60 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div class="min-w-0">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">Dashboard</h2>
      </div>
    </article>

    <article class="grid gap-6 rounded-[28px] border border-white/60 bg-white/80 p-5 md:p-6 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div class="grid gap-3 md:grid-cols-[1fr_auto] md:items-center">
        <div class="min-w-0">
          <div
            class="inline-flex w-full max-w-full flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50/80 p-1.5 md:w-auto">
            <button type="button"
              class="app-btn min-w-[120px]"
              :class="activeTab === 'tasks' ? 'app-btn-tab-active' : 'app-btn-secondary'" @click="activeTab = 'tasks'">
              Tarefas
            </button>

            <button type="button"
              class="app-btn min-w-[120px]"
              :class="activeTab === 'finance' ? 'app-btn-tab-active' : 'app-btn-secondary'" @click="activeTab = 'finance'">
              Financeiro
            </button>
          </div>
        </div>

        <div class="flex items-center justify-start md:justify-end">
          <button type="button"
            class="app-btn app-btn-primary app-btn-icon shrink-0"
            :disabled="loading || syncing" title="Atualizar analises" @click="loadDashboardContext(true)">
            <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-2.64-6.36" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 3v6h-6" />
            </svg>
          </button>
        </div>
      </div>

      <article
        v-if="loading"
        class="grid min-h-[220px] place-items-center rounded-[24px] border border-slate-200 bg-slate-50/80 p-5 text-sm text-slate-500"
      >
        Carregando dashboard analitico...
      </article>

      <article
        v-else-if="!workspaceReady"
        class="grid gap-4 rounded-[24px] border border-slate-200 bg-slate-50/80 p-5"
      >
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Perfil necessario</p>
            <h3 class="mt-1 text-2xl font-semibold text-slate-950">Configure o GitHub antes de liberar as analises</h3>
            <p class="mt-3 text-sm leading-7 text-slate-600">
            Salve o token do GitHub e cadastre ao menos um repositorio para liberar as analises do dashboard.
            </p>
          </div>

        <div class="grid gap-3 sm:grid-cols-3">
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repositorios</span>
            <strong class="mt-2 block break-all text-sm font-semibold text-slate-950">{{ repositoryLabel }}</strong>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Token</span>
            <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ profile?.tokenConfigured ? 'Salvo' : 'Ausente' }}</strong>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Workspace</span>
            <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ profile?.workspaceReady ? 'Pronto' : 'Pendente' }}</strong>
          </div>
        </div>
      </article>

      <div v-else-if="activeTab === 'tasks'" class="grid gap-5">
        <article
          v-if="issues.length === 0 && !syncing"
          class="grid gap-4 rounded-[24px] border border-dashed border-slate-300 bg-slate-50/80 p-5"
        >
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Sem base analitica</p>
            <h3 class="mt-1 text-2xl font-semibold text-slate-950">Ainda nao existem issues suficientes para montar os graficos</h3>
            <p class="mt-3 text-sm leading-7 text-slate-600">
              O dashboard usa a base cacheada das issues. Se for sua primeira entrada, atualize para preencher as analises.
            </p>
          </div>
        </article>

        <template v-else>
        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-4">
          <article
            v-for="card in taskOverviewCards"
            :key="card.label"
            class="rounded-[24px] border p-4 shadow-[0_16px_38px_rgba(15,23,42,0.05)]"
            :class="card.cardClass"
          >
            <span class="text-xs font-semibold uppercase tracking-[0.18em]" :class="card.labelClass">{{ card.label }}</span>
            <strong class="mt-2 block break-words text-2xl font-semibold" :class="card.valueClass">{{ card.value }}</strong>
            <p class="mt-2 text-sm text-slate-500">{{ card.note }}</p>
          </article>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1.05fr),minmax(0,0.95fr)]">
          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Status atual</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Abertas x fechadas</h3>
              </div>
              <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                {{ issues.length }} issues analisadas
              </span>
            </div>

            <div class="flex h-4 overflow-hidden rounded-full bg-slate-200/80">
              <span
                v-for="segment in issueStatusSeries"
                :key="segment.label"
                class="h-full"
                :class="segment.barClass"
                :style="{ width: `${segment.share}%` }"
              />
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
              <div
                v-for="segment in issueStatusSeries"
                :key="segment.label"
                class="rounded-2xl border p-4"
                :class="segment.cardClass"
              >
                <span class="text-xs font-semibold uppercase tracking-[0.18em]" :class="segment.labelClass">{{ segment.label }}</span>
                <strong class="mt-2 block text-2xl font-semibold" :class="segment.valueClass">{{ segment.value }}</strong>
                <p class="mt-2 text-sm text-slate-500">{{ segment.share.toFixed(1) }}% do universo atual</p>
              </div>
            </div>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Fechamento</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Finalizadas por periodo</h3>
              <p class="mt-3 text-sm leading-7 text-slate-600">
                Quantas issues fechadas tiveram ultimo movimento de encerramento na semana, no mes e no ano.
              </p>
            </div>

            <div class="grid gap-3">
              <div
                v-for="item in closureWindowSeries"
                :key="item.label"
                class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div class="flex items-center justify-between gap-3 text-sm">
                  <strong class="font-semibold text-slate-900">{{ item.label }}</strong>
                  <span class="font-semibold text-slate-600">{{ item.value }}</span>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                  <span
                    class="block h-full rounded-full bg-gradient-to-r from-cyan-500 to-sky-500"
                    :style="buildBarStyle(item.percentage)"
                  />
                </div>
              </div>
            </div>
          </article>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,0.8fr),minmax(0,1.2fr)]">
          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Tempo medio</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Ciclo das tarefas</h3>
            </div>

            <div class="grid gap-3">
              <article
                v-for="card in cycleTimeCards"
                :key="card.label"
                class="rounded-2xl border p-4"
                :class="card.cardClass"
              >
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ card.label }}</span>
                <strong class="mt-2 block text-3xl font-semibold" :class="card.valueClass">{{ card.value }}</strong>
                <p class="mt-2 text-sm text-slate-500">{{ card.note }}</p>
              </article>
            </div>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Atualizacao</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Tempo sem mexer nas tarefas abertas</h3>
            </div>

            <div class="grid gap-3">
              <div
                v-for="item in updateFreshnessSeries"
                :key="item.label"
                class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div class="flex items-center justify-between gap-3 text-sm">
                  <strong class="font-semibold text-slate-900">{{ item.label }}</strong>
                  <span class="font-semibold text-slate-600">{{ item.value }}</span>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                  <span
                    class="block h-full rounded-full bg-gradient-to-r from-orange-500 to-amber-400"
                    :style="buildBarStyle(item.percentage)"
                  />
                </div>
              </div>
            </div>
          </article>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,0.82fr),minmax(0,1.18fr)]">
          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Tipos abertos</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Tipos de tarefa mais abertas</h3>
            </div>

            <div v-if="mostOpenTypesSeries.length" class="grid gap-3">
              <div
                v-for="item in mostOpenTypesSeries"
                :key="item.label"
                class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div class="flex items-center justify-between gap-3 text-sm">
                  <strong class="font-semibold text-slate-900">{{ item.label }}</strong>
                  <span class="font-semibold text-slate-600">{{ item.value }}</span>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                  <span
                    class="block h-full rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500"
                    :style="buildBarStyle(item.percentage)"
                  />
                </div>
              </div>
            </div>

            <p
              v-else
              class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
            >
              Nenhuma issue aberta o suficiente para montar este ranking agora.
            </p>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Resposta por tipo</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Tempo medio por categoria</h3>
              <p class="mt-3 text-sm leading-7 text-slate-600">
                Leitura comparativa do tempo medio entre criacao e ultimo movimento para cada tipo de tarefa.
              </p>
            </div>

            <div v-if="responseByTypeSeries.length" class="grid gap-3">
              <div
                v-for="item in responseByTypeSeries"
                :key="item.label"
                class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div class="flex items-center justify-between gap-3">
                  <strong class="text-sm font-semibold text-slate-900">{{ item.label }}</strong>
                  <span class="text-sm font-semibold text-slate-600">{{ item.formatted }}</span>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                  <span
                    class="block h-full rounded-full bg-gradient-to-r from-teal-500 to-cyan-500"
                    :style="buildBarStyle(item.percentage)"
                  />
                </div>
              </div>
            </div>

            <p
              v-else
              class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
            >
              Ainda nao existe base temporal suficiente para comparar os tipos de tarefa.
            </p>
          </article>
        </div>

        </template>
      </div>

      <article
        v-else
        class="themed-soft-surface grid gap-4 rounded-[24px] border border-slate-200 p-6"
      >
        <div>
          <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Financeiro</p>
          <h3 class="mt-1 text-3xl font-semibold text-slate-950">Espaco reservado para os proximos indicadores</h3>
          <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">
            Esta aba fica pronta para receber analises financeiras no futuro, sem misturar operacao de tarefas com custo, receita ou margem.
          </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
          <div class="rounded-2xl border border-slate-200 bg-white/85 p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</span>
            <strong class="mt-2 block text-base font-semibold text-slate-950">Planejado</strong>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white/85 p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Escopo futuro</span>
            <strong class="mt-2 block text-base font-semibold text-slate-950">Custos, fluxo e previsoes</strong>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white/85 p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Aba</span>
            <strong class="mt-2 block text-base font-semibold text-slate-950">Financeiro</strong>
          </div>
        </div>
      </article>
    </article>
  </section>
</template>
