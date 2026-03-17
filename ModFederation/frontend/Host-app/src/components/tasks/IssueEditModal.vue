<script setup>
import { computed, reactive, ref, watch } from 'vue'
import MarkdownPreview from '../shared/MarkdownPreview.vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  issue: {
    type: Object,
    required: true,
  },
  updateTemplates: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'issue-updated'])

const currentIssue = ref(null)
const currentHistory = ref([])
const saving = ref(false)
const detailLoading = ref(false)
const error = ref('')
const detailWarning = ref('')
const detailSource = ref('cache')
const selectedTemplateKey = ref('')
const activePanel = ref('view')
const form = reactive({
  title: '',
  body: '',
  state: 'OPEN',
})

const canEdit = computed(() => Boolean(currentIssue.value?.viewerCanUpdate))
const selectedTemplate = computed(() => props.updateTemplates.find((template) => template.key === selectedTemplateKey.value) || null)
const repositoryName = computed(() => currentIssue.value?.repository?.nameWithOwner || 'Repositorio atual')
const historyEntries = computed(() => normalizeHistoryEntries(currentHistory.value, currentIssue.value))
const statusLabel = computed(() => currentIssue.value?.state === 'CLOSED' ? 'Fechada' : 'Aberta')

watch(
  () => props.issue,
  (issue) => {
    currentIssue.value = normalizeIssuePayload(issue)
    currentHistory.value = buildFallbackHistory(issue)
    syncFormFromIssue(issue)
    selectedTemplateKey.value = props.updateTemplates[0]?.key || ''
    activePanel.value = 'view'
    error.value = ''
    detailWarning.value = ''
    detailSource.value = 'cache'

    if (issue?.id) {
      void loadLatestIssue(issue.id)
    }
  },
  { immediate: true },
)

async function loadLatestIssue(issueId) {
  detailLoading.value = true
  detailWarning.value = ''

  try {
    const { data } = await props.request({
      url: `/github/issues/${encodeURIComponent(issueId)}`,
      method: 'GET',
    })

    currentIssue.value = normalizeIssuePayload(data?.item || currentIssue.value)
    currentHistory.value = normalizeHistoryEntries(data?.history, currentIssue.value)
    detailSource.value = data?.source || 'github'
    detailWarning.value = data?.warning || ''
    syncFormFromIssue(currentIssue.value)
  } catch (requestError) {
    detailWarning.value = extractHttpMessage(requestError, 'Nao foi possivel atualizar os detalhes da issue no GitHub. Mantendo o cache local.')
    currentHistory.value = buildFallbackHistory(currentIssue.value)
  } finally {
    detailLoading.value = false
  }
}

function syncFormFromIssue(issue) {
  form.title = issue?.title || ''
  form.body = issue?.body || ''
  form.state = issue?.state === 'CLOSED' ? 'CLOSED' : 'OPEN'
}

function applyUpdateTemplate() {
  if (!selectedTemplate.value) {
    return
  }

  const block = selectedTemplate.value.build()
  form.body = form.body.trim() === ''
    ? block
    : `${form.body.trim()}\n\n${block}`
}

async function saveIssue() {
  if (!currentIssue.value?.id) {
    error.value = 'Nenhuma issue valida foi selecionada.'
    return
  }

  if (form.title.trim() === '') {
    error.value = 'O titulo da issue e obrigatorio.'
    return
  }

  saving.value = true
  error.value = ''

  try {
    const { data } = await props.request({
      url: `/github/issues/${encodeURIComponent(currentIssue.value.id)}`,
      method: 'PATCH',
      csrfActionId: 'github.issue.update',
      data: {
        title: form.title,
        body: form.body,
        state: form.state,
      },
    })

    currentIssue.value = normalizeIssuePayload(data?.item || currentIssue.value)
    syncFormFromIssue(currentIssue.value)
    emit('issue-updated', currentIssue.value)
    await loadLatestIssue(currentIssue.value.id)
    activePanel.value = 'view'
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel atualizar a issue.')
  } finally {
    saving.value = false
  }
}

function resetForm() {
  syncFormFromIssue(currentIssue.value)
  error.value = ''
}

function normalizeIssuePayload(issue) {
  if (!issue || typeof issue !== 'object') {
    return null
  }

  return {
    ...issue,
    assignees: Array.isArray(issue.assignees) ? issue.assignees : [],
    labels: Array.isArray(issue.labels) ? issue.labels : [],
    repository: issue.repository || {},
    closedAt: typeof issue.closedAt === 'string' && issue.closedAt.trim() !== ''
      ? issue.closedAt
      : null,
  }
}

function normalizeHistoryEntries(history, fallbackIssue = null) {
  if (!Array.isArray(history) || history.length === 0) {
    return buildFallbackHistory(fallbackIssue)
  }

  return history
    .filter((entry) => entry && typeof entry === 'object')
    .map((entry) => ({
      id: entry.id || `${entry.kind || 'entry'}-${entry.createdAt || Math.random()}`,
      kind: normalizeHistoryKind(entry.kind),
      title: entry.title || buildHistoryTitle(entry.kind),
      actorLogin: entry.actorLogin || null,
      createdAt: entry.createdAt || '',
      updatedAt: entry.updatedAt || entry.createdAt || '',
      body: typeof entry.body === 'string' && entry.body.trim() !== '' ? entry.body : '',
      url: typeof entry.url === 'string' && entry.url.trim() !== '' ? entry.url : '',
    }))
    .sort((left, right) => String(right.createdAt || '').localeCompare(String(left.createdAt || '')))
}

function buildFallbackHistory(issue) {
  if (!issue || typeof issue !== 'object') {
    return []
  }

  const history = []
  const issueId = String(issue.id || 'issue')

  if (typeof issue.updatedAt === 'string' && issue.updatedAt !== '' && issue.updatedAt !== issue.createdAt) {
    history.push({
      id: `${issueId}-updated`,
      kind: 'updated',
      title: 'Issue atualizada',
      actorLogin: null,
      createdAt: issue.updatedAt,
      updatedAt: issue.updatedAt,
      body: '',
      url: '',
    })
  }

  if (issue.state === 'CLOSED' && typeof issue.closedAt === 'string' && issue.closedAt !== '') {
    history.push({
      id: `${issueId}-closed`,
      kind: 'closed',
      title: 'Issue fechada',
      actorLogin: null,
      createdAt: issue.closedAt,
      updatedAt: issue.closedAt,
      body: '',
      url: '',
    })
  }

  if (typeof issue.createdAt === 'string' && issue.createdAt !== '') {
    history.push({
      id: `${issueId}-created`,
      kind: 'created',
      title: 'Issue criada',
      actorLogin: issue.authorLogin || null,
      createdAt: issue.createdAt,
      updatedAt: issue.createdAt,
      body: '',
      url: '',
    })
  }

  return history.sort((left, right) => String(right.createdAt || '').localeCompare(String(left.createdAt || '')))
}

function normalizeHistoryKind(kind) {
  const normalizedKind = String(kind || '').trim().toLowerCase()

  if (['comment', 'closed', 'updated', 'created'].includes(normalizedKind)) {
    return normalizedKind
  }

  return 'comment'
}

function buildHistoryTitle(kind) {
  switch (normalizeHistoryKind(kind)) {
    case 'created':
      return 'Issue criada'
    case 'updated':
      return 'Issue atualizada'
    case 'closed':
      return 'Issue fechada'
    default:
      return 'Comentario'
  }
}

function historyBadgeClass(kind) {
  switch (normalizeHistoryKind(kind)) {
    case 'created':
      return 'border-cyan-200 bg-cyan-50 text-cyan-800'
    case 'updated':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    case 'closed':
      return 'border-rose-200 bg-rose-50 text-rose-800'
    default:
      return 'border-emerald-200 bg-emerald-50 text-emerald-800'
  }
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
  const responseMessage = error?.response?.data?.message
  if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
    return responseMessage
  }

  if (typeof error?.message === 'string' && error.message.trim() !== '') {
    return error.message
  }

  return fallback
}
</script>

<template>
  <div class="fixed inset-0 z-50 bg-slate-950/55 px-4 py-6 backdrop-blur-sm" @click.self="$emit('close')">
    <div class="themed-modal-surface mx-auto flex max-h-full w-full max-w-7xl flex-col overflow-hidden rounded-[32px] border border-white/60 shadow-[0_28px_80px_rgba(15,23,42,0.28)]">
      <header class="flex flex-col gap-4 border-b border-slate-200/80 px-5 py-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Show/View</p>
            <h2 class="mt-1 break-words text-2xl font-semibold text-slate-950 sm:text-3xl">
              #{{ currentIssue?.number }} {{ currentIssue?.title }}
            </h2>
            <p class="mt-2 text-sm leading-7 text-slate-600">
              Visualizacao detalhada da issue com historico renderizado e acao de atualizacao.
            </p>
          </div>

          <div class="flex flex-wrap gap-2">
            <a
              v-if="currentIssue?.url"
              class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
              :href="currentIssue.url"
              target="_blank"
              rel="noreferrer noopener"
            >
              Abrir no GitHub
            </a>
            <button
              v-if="canEdit"
              type="button"
              class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/15 transition hover:brightness-105"
              @click="activePanel = 'edit'"
            >
              Atualizar issue
            </button>
            <button
              type="button"
              class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="$emit('close')"
            >
              Fechar
            </button>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition"
            :class="activePanel === 'view' ? 'border-cyan-300 bg-cyan-50 text-cyan-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
            @click="activePanel = 'view'"
          >
            Show/View
          </button>
          <button
            v-if="canEdit"
            type="button"
            class="inline-flex items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition"
            :class="activePanel === 'edit' ? 'border-cyan-300 bg-cyan-50 text-cyan-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
            @click="activePanel = 'edit'"
          >
            Atualizar issue
          </button>
        </div>
      </header>

      <div class="min-h-0 flex-1 overflow-y-auto p-5">
        <div
          v-if="detailLoading"
          class="mb-4 rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm font-medium text-cyan-800"
        >
          Atualizando esta issue direto do GitHub...
        </div>

        <div
          v-else-if="detailSource === 'github'"
          class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
        >
          Detalhes confirmados com o GitHub e salvos no banco local.
        </div>

        <div
          v-if="detailWarning"
          class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800"
        >
          {{ detailWarning }}
        </div>

        <div
          v-if="activePanel === 'view'"
          class="grid gap-5 xl:grid-cols-[minmax(0,1.05fr),minmax(320px,0.95fr)]"
        >
          <article class="grid gap-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div class="rounded-2xl border border-slate-200 bg-white/90 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ statusLabel }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-white/90 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repositorio</span>
                <strong class="mt-2 block break-all text-sm font-semibold text-slate-950">{{ repositoryName }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-white/90 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Autor</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ currentIssue?.authorLogin || 'desconhecido' }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-white/90 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Atualizada</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDate(currentIssue?.updatedAt) }}</strong>
              </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
              <div class="rounded-2xl border border-slate-200 bg-white/90 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Criada em</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDate(currentIssue?.createdAt) }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-white/90 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Responsaveis</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">
                  {{ currentIssue?.assignees?.length ? currentIssue.assignees.map((assignee) => assignee.name || assignee.login).join(', ') : 'Sem responsavel' }}
                </strong>
              </div>
            </div>

            <div v-if="currentIssue?.labels?.length" class="flex flex-wrap gap-2">
              <span
                v-for="label in currentIssue.labels"
                :key="label.id"
                class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600"
              >
                {{ label.name }}
              </span>
            </div>

            <div class="rounded-[28px] border border-white/60 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)]">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Descricao</p>
                  <h3 class="mt-1 text-2xl font-semibold text-slate-950">Conteudo formatado</h3>
                </div>

                <button
                  v-if="canEdit"
                  type="button"
                  class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                  @click="activePanel = 'edit'"
                >
                  Atualizar issue
                </button>
              </div>

              <div class="mt-4 overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
                <MarkdownPreview
                  :content="currentIssue?.body || ''"
                  empty-label="Nenhuma descricao em Markdown foi informada para esta issue."
                />
              </div>
            </div>

            <p
              v-if="!canEdit"
              class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
            >
              Sua conta consegue visualizar esta issue, mas nao tem permissao para atualiza-la.
            </p>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)]">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Historico</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Linha do tempo da issue</h3>
            </div>

            <div v-if="historyEntries.length > 0" class="grid gap-3">
              <article
                v-for="entry in historyEntries"
                :key="entry.id"
                class="rounded-2xl border border-slate-200 bg-white px-4 py-4"
              >
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <span
                      class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.14em]"
                      :class="historyBadgeClass(entry.kind)"
                    >
                      {{ entry.title }}
                    </span>
                    <strong class="truncate text-sm font-semibold text-slate-900">
                      {{ entry.actorLogin || 'Sistema' }}
                    </strong>
                  </div>

                  <div class="flex items-center gap-3">
                    <span class="text-xs font-medium text-slate-500">{{ formatDate(entry.createdAt) }}</span>
                    <a
                      v-if="entry.url"
                      class="text-xs font-semibold text-cyan-700 underline"
                      :href="entry.url"
                      target="_blank"
                      rel="noreferrer noopener"
                    >
                      Abrir
                    </a>
                  </div>
                </div>

                <div
                  v-if="entry.body"
                  class="mt-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3"
                >
                  <MarkdownPreview :content="entry.body" />
                </div>
              </article>
            </div>

            <p
              v-else
              class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
            >
              Nenhum historico adicional foi encontrado para esta issue.
            </p>
          </article>
        </div>

        <div
          v-else
          class="grid gap-5 xl:grid-cols-[minmax(0,1.05fr),minmax(320px,0.95fr)]"
        >
          <article class="grid gap-4">
            <div class="grid gap-3 rounded-[28px] border border-white/60 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)]">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-slate-900">Templates de atualizacao</p>
                  <p class="text-sm text-slate-500">Blocos padrao para andamento, bloqueio, repasse ou resolucao.</p>
                </div>

                <div class="flex flex-wrap gap-2">
                  <select
                    v-model="selectedTemplateKey"
                    class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                  >
                    <option v-for="template in updateTemplates" :key="template.key" :value="template.key">
                      {{ template.label }}
                    </option>
                  </select>
                  <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    :disabled="!canEdit"
                    @click="applyUpdateTemplate"
                  >
                    Aplicar template
                  </button>
                </div>
              </div>

              <p class="text-sm text-slate-500">{{ selectedTemplate?.description }}</p>
            </div>

            <form class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)]" @submit.prevent="saveIssue">
              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Titulo</span>
                <input
                  v-model="form.title"
                  type="text"
                  :disabled="!canEdit || saving"
                  class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
                  required
                >
              </label>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Descricao</span>
                <textarea
                  v-model="form.body"
                  rows="15"
                  :disabled="!canEdit || saving"
                  class="min-h-[300px] rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm leading-6 text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
                />
              </label>

              <label class="grid gap-2 sm:max-w-xs">
                <span class="text-sm font-semibold text-slate-900">Status</span>
                <select
                  v-model="form.state"
                  :disabled="!canEdit || saving"
                  class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
                >
                  <option value="OPEN">Aberta</option>
                  <option value="CLOSED">Fechada</option>
                </select>
              </label>

              <p
                v-if="error"
                class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
              >
                {{ error }}
              </p>

              <div class="flex flex-wrap gap-2">
                <button
                  type="submit"
                  :disabled="!canEdit || saving"
                  class="inline-flex max-w-full items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/15 transition hover:brightness-105 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {{ saving ? 'Salvando...' : 'Salvar issue' }}
                </button>
                <button
                  type="button"
                  :disabled="saving"
                  class="inline-flex max-w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                  @click="resetForm"
                >
                  Desfazer alteracoes
                </button>
                <button
                  type="button"
                  class="inline-flex max-w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                  @click="activePanel = 'view'"
                >
                  Voltar para Show/View
                </button>
              </div>
            </form>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)]">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Preview Markdown</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Visualizacao renderizada</h3>
            </div>

            <div class="min-h-[540px] overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
              <MarkdownPreview
                :content="form.body"
                empty-label="Nenhuma descricao em Markdown foi informada para esta issue."
              />
            </div>
          </article>
        </div>
      </div>
    </div>
  </div>
</template>
