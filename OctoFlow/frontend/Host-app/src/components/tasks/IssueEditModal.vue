<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useNotification } from '../../composables/useNotification'
import { RemoteMarkdownPreview as MarkdownPreview } from '../../federation/remoteComponents'
import { fetchGithubIssueDetails, updateGithubIssue } from '../../services/tasks'
import { buildCurrentDateTimeLabel, formatDateTime } from '../../utils/date'
import { extractHttpMessage } from '../../utils/httpErrors'
import { resolveHistoryBadgeToneClass } from '../../utils/statusTone'
import {
  buildInitialFieldState,
  buildSubmissionFields,
  snapshotFieldValues,
} from '../../utils/issueTemplate'
import TaskModalShell from './TaskModalShell.vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  notify: {
    type: Function,
    default: null,
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
const detailSource = ref('cache')
const selectedTemplateKey = ref('')
const activePanel = ref('view')
const templateFieldValues = reactive({})
const templateDrafts = reactive({})
const templateRenderTimestamp = ref(buildCurrentDateTimeLabel())
const form = reactive({
  state: 'OPEN',
  assignCollaboratorId: '',
})
const { notifyUser } = useNotification(props.notify)

const canEdit = computed(() => Boolean(currentIssue.value?.viewerCanUpdate))
const repositoryName = computed(() => currentIssue.value?.repository?.nameWithOwner || 'Repositorio atual')
const assignableUsers = computed(() => Array.isArray(currentIssue.value?.repository?.assignableUsers) ? currentIssue.value.repository.assignableUsers : [])
const assignableUserOptions = computed(() => buildAssignableUserOptions(assignableUsers.value, currentIssue.value?.assignees))
const selectedTemplate = computed(() => {
  const template = props.updateTemplates.find((item) => item.key === selectedTemplateKey.value) || null
  if (!template) {
    return null
  }

  return enrichTemplateWithAssignableOptions(template, assignableUserOptions.value)
})
const selectedTemplateAssigneeFieldKey = computed(() => getTemplateAssigneeFieldKey(selectedTemplate.value))
const historyEntries = computed(() => normalizeHistoryEntries(currentHistory.value, currentIssue.value))
const statusLabel = computed(() => currentIssue.value?.state === 'CLOSED' ? 'Fechada' : 'Aberta')
const shouldShowStandaloneCollaboratorSelect = computed(() => selectedTemplateAssigneeFieldKey.value === '')
const finalBody = computed(() => buildFinalBody())
const assignablePlaceholderLabel = computed(() => {
  if (detailLoading.value && assignableUsers.value.length === 0) {
    return 'Carregando colaboradores...'
  }

  if (assignableUsers.value.length === 0) {
    return 'Nenhum colaborador encontrado'
  }

  return 'Nao adicionar colaborador'
})

watch(
  () => props.issue,
  (issue) => {
    currentIssue.value = normalizeIssuePayload(issue)
    currentHistory.value = buildFallbackHistory(issue)
    syncFormFromIssue(issue)
    resetTemplateCatalog()
    activePanel.value = 'view'
    error.value = ''
    detailSource.value = 'cache'

    if (issue?.id) {
      void loadLatestIssue(issue.id)
    }
  },
  { immediate: true },
)

watch(selectedTemplateKey, (newKey, oldKey) => {
  if (oldKey) {
    persistTemplateDraft(oldKey)
  }

  if (newKey) {
    restoreTemplateDraft(newKey)
  }
})

watch(
  () => props.updateTemplates,
  (templates) => {
    if (!Array.isArray(templates) || templates.length === 0) {
      selectedTemplateKey.value = ''
      return
    }

    const selectedTemplateExists = templates.some((template) => template?.key === selectedTemplateKey.value)
    if (!selectedTemplateExists) {
      resetTemplateCatalog()
    }
  },
  { immediate: true },
)

async function loadLatestIssue(issueId) {
  detailLoading.value = true
  notifyUser({
    message: 'Atualizando esta issue direto do GitHub...',
    type: 'info',
    duration: 7000,
  })

  try {
    const { data } = await fetchGithubIssueDetails(props.request, issueId)

    currentIssue.value = normalizeIssuePayload(data?.item || currentIssue.value)
    currentHistory.value = normalizeHistoryEntries(data?.history, currentIssue.value)
    detailSource.value = data?.source || 'github'
    syncFormFromIssue(currentIssue.value)

    const detailWarning = String(data?.warning || '').trim()
    if (detailWarning !== '') {
      notifyUser(detailWarning, 'warning')
    }

    if (detailSource.value === 'github') {
      notifyUser('Detalhes confirmados com o GitHub e salvos no banco local.', 'success')
    }
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(requestError, 'Nao foi possivel atualizar os detalhes da issue no GitHub. Mantendo o cache local.'),
      'warning'
    )
    currentHistory.value = buildFallbackHistory(currentIssue.value)
  } finally {
    detailLoading.value = false
  }
}

function syncFormFromIssue(issue) {
  form.state = issue?.state === 'CLOSED' ? 'CLOSED' : 'OPEN'
  form.assignCollaboratorId = resolvePrimaryAssigneeId(issue)
}

function resetTemplateCatalog() {
  selectedTemplateKey.value = ''

  for (const key of Object.keys(templateFieldValues)) {
    delete templateFieldValues[key]
  }

  for (const key of Object.keys(templateDrafts)) {
    delete templateDrafts[key]
  }

  const firstTemplateKey = props.updateTemplates[0]?.key || ''
  if (firstTemplateKey !== '') {
    selectedTemplateKey.value = firstTemplateKey
  }
}

function persistTemplateDraft(templateKey = selectedTemplateKey.value) {
  const template = props.updateTemplates.find((item) => item.key === templateKey)
  if (!template) {
    return
  }

  templateDrafts[templateKey] = {
    fields: snapshotFieldValues(template, templateFieldValues),
  }
}

function restoreTemplateDraft(templateKey) {
  const template = selectedTemplate.value?.key === templateKey
    ? selectedTemplate.value
    : props.updateTemplates.find((item) => item.key === templateKey)
  if (!template) {
    return
  }

  templateRenderTimestamp.value = buildCurrentDateTimeLabel()

  const restoredFields = buildInitialFieldState(template)
  const existingDraft = templateDrafts[templateKey]?.fields || {}

  for (const field of template.fields || []) {
    const fieldKey = typeof field?.key === 'string' ? field.key.trim() : ''
    if (fieldKey === '') {
      continue
    }

    if (typeof existingDraft[fieldKey] === 'string') {
      restoredFields[fieldKey] = existingDraft[fieldKey]
    }
  }

  for (const key of Object.keys(templateFieldValues)) {
    delete templateFieldValues[key]
  }

  applyTemplateAutoValues(template, restoredFields)
  Object.assign(templateFieldValues, restoredFields)
}

function buildTemplateSubmissionFields(template) {
  return buildSubmissionFields(template, templateFieldValues, { resolveSelectToLabel: true })
}

function buildTemplatePayloadFields(template) {
  return buildSubmissionFields(template, templateFieldValues)
}

function renderUpdateTemplate(template, submissionFields, timestampLabel = '') {
  if (!template) {
    return ''
  }

  const lines = [`## ${template.markdownTitle || template.label || 'Atualização'}`]
  let hasContent = false

  if (timestampLabel !== '') {
    lines.push(`- Data e hora: ${timestampLabel}`)
    hasContent = true
  }

  for (const field of template.fields || []) {
    const fieldKey = typeof field?.key === 'string' ? field.key.trim() : ''
    if (fieldKey === '') {
      continue
    }

    const rawValue = submissionFields[fieldKey]

    if (Array.isArray(rawValue)) {
      if (rawValue.length === 0) {
        continue
      }

      hasContent = true
      lines.push(`### ${field.label}`)

      for (const item of rawValue) {
        lines.push(`${field.listStyle === 'checklist' ? '- [ ]' : '-'} ${item}`)
      }

      lines.push('')
      continue
    }

    const normalizedValue = formatTemplateFieldValue(field, rawValue)
    if (normalizedValue === '') {
      continue
    }

    hasContent = true

    if (field.renderAs === 'bullet' || field.renderAs === 'commit') {
      lines.push(`- ${field.label}: ${normalizedValue}`)
      continue
    }

    lines.push(`### ${field.label}`)
    lines.push(normalizedValue)
    lines.push('')
  }

  return hasContent ? lines.join('\n').trim() : ''
}

function formatTemplateFieldValue(field, value) {
  const normalizedValue = String(value || '').trim()
  if (normalizedValue === '') {
    return ''
  }

  if (field?.renderAs === 'commit') {
    return buildCommitReferenceMarkdown(normalizedValue)
  }

  return normalizedValue
}

function buildCommitReferenceMarkdown(value) {
  const commitUrl = resolveCommitUrl(value)
  if (commitUrl === '') {
    return value
  }

  return `[${extractCommitLabel(value, commitUrl)}](${commitUrl})`
}

function resolveCommitUrl(value) {
  const normalizedValue = String(value || '').trim()
  if (normalizedValue === '') {
    return ''
  }

  if (/^https?:\/\//i.test(normalizedValue)) {
    return normalizedValue
  }

  const crossRepositoryCommit = normalizedValue.match(/^([\w.-]+\/[\w.-]+)@([a-f0-9]{7,40})$/i)
  if (crossRepositoryCommit) {
    const [, repositoryKey, sha] = crossRepositoryCommit
    return `https://github.com/${repositoryKey}/commit/${sha}`
  }

  if (!/^[a-f0-9]{7,40}$/i.test(normalizedValue)) {
    return ''
  }

  const repositoryKey = String(currentIssue.value?.repository?.nameWithOwner || '').trim()
  if (repositoryKey === '') {
    return ''
  }

  return `https://github.com/${repositoryKey}/commit/${normalizedValue}`
}

function extractCommitLabel(rawValue, commitUrl) {
  const normalizedValue = String(rawValue || '').trim()
  if (normalizedValue === '') {
    return 'Commit'
  }

  if (!/^https?:\/\//i.test(normalizedValue)) {
    const crossRepositoryCommit = normalizedValue.match(/^([\w.-]+\/[\w.-]+)@([a-f0-9]{7,40})$/i)
    if (crossRepositoryCommit) {
      return crossRepositoryCommit[2]
    }

    return normalizedValue
  }

  try {
    const parsedUrl = new URL(commitUrl)
    const lastPathSegment = parsedUrl.pathname.split('/').filter(Boolean).pop() || ''
    return lastPathSegment || normalizedValue
  } catch {
    return normalizedValue
  }
}

function resetTemplateInputs() {
  if (!selectedTemplate.value) {
    return
  }

  templateRenderTimestamp.value = buildCurrentDateTimeLabel()

  for (const key of Object.keys(templateFieldValues)) {
    delete templateFieldValues[key]
  }

  const initialFieldValues = buildInitialFieldState(selectedTemplate.value)
  applyTemplateAutoValues(selectedTemplate.value, initialFieldValues)

  Object.assign(templateFieldValues, initialFieldValues)
  templateDrafts[selectedTemplate.value.key] = {
    fields: snapshotFieldValues(selectedTemplate.value, templateFieldValues),
  }
}

function buildFinalBody(timestampLabel = templateRenderTimestamp.value) {
  const sections = []
  const currentBody = String(currentIssue.value?.body || '').trim()
  const updateBlock = renderUpdateTemplate(
    selectedTemplate.value,
    buildTemplateSubmissionFields(selectedTemplate.value),
    timestampLabel,
  ).trim()

  if (currentBody !== '') {
    sections.push(currentBody)
  }

  if (updateBlock !== '') {
    sections.push(updateBlock)
  }

  return sections.join('\n\n').trim()
}

async function saveIssue() {
  if (!currentIssue.value?.id) {
    error.value = 'Nenhuma issue valida foi selecionada.'
    return
  }

  const issueTitle = String(currentIssue.value?.title || '').trim()
  if (issueTitle === '') {
    error.value = 'O titulo da issue e obrigatorio.'
    return
  }

  saving.value = true
  error.value = ''

  try {
    templateRenderTimestamp.value = buildCurrentDateTimeLabel()
    const assigneeId = resolveSelectedAssigneeId()
    const { data } = await updateGithubIssue(props.request, currentIssue.value.id, {
      title: issueTitle,
      state: form.state,
      templateKey: selectedTemplate.value?.key || '',
      templateFields: buildTemplatePayloadFields(selectedTemplate.value),
      assigneeIds: assigneeId ? [assigneeId] : [],
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
  resetTemplateInputs()
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

function enrichTemplateWithAssignableOptions(template, collaboratorOptions) {
  return {
    ...template,
    fields: Array.isArray(template?.fields)
      ? template.fields.map((field) => {
        if (!isTemplateAssigneeField(field)) {
          return field
        }

        return {
          ...field,
          type: 'select',
          options: collaboratorOptions,
        }
      })
      : [],
  }
}

function buildAssignableUserOptions(assignableCollaborators, currentAssignees) {
  const normalizedOptions = []
  const seenOptionValues = new Set()
  const collaborators = [
    ...(Array.isArray(currentAssignees) ? currentAssignees : []),
    ...(Array.isArray(assignableCollaborators) ? assignableCollaborators : []),
  ]

  for (const collaborator of collaborators) {
    const collaboratorValue = String(collaborator?.id || '').trim()
    if (collaboratorValue === '' || seenOptionValues.has(collaboratorValue)) {
      continue
    }

    seenOptionValues.add(collaboratorValue)
    normalizedOptions.push({
      value: collaboratorValue,
      label: collaborator?.name
        ? `${collaborator.name} (${collaborator.login})`
        : collaborator?.login || collaboratorValue,
    })
  }

  return normalizedOptions
}

function isTemplateAssigneeField(field) {
  const fieldKey = String(field?.key || '').trim()
  return fieldKey === 'owner' || fieldKey === 'nextOwner'
}

function getTemplateAssigneeFieldKey(template) {
  if (!Array.isArray(template?.fields)) {
    return ''
  }

  const assigneeField = template.fields.find((field) => isTemplateAssigneeField(field))
  return String(assigneeField?.key || '').trim()
}

function resolvePrimaryAssigneeId(issue) {
  const primaryAssignee = Array.isArray(issue?.assignees) ? issue.assignees[0] : null
  return String(primaryAssignee?.id || '').trim()
}

function applyTemplateAutoValues(template, fieldValues) {
  if (!fieldValues || typeof fieldValues !== 'object') {
    return
  }

  const assigneeFieldKey = getTemplateAssigneeFieldKey(template)
  if (assigneeFieldKey === '') {
    return
  }

  const currentValue = String(fieldValues[assigneeFieldKey] || '').trim()
  if (currentValue !== '') {
    return
  }

  fieldValues[assigneeFieldKey] = String(form.assignCollaboratorId || resolvePrimaryAssigneeId(currentIssue.value) || '').trim()
}

function resolveSelectedAssigneeId() {
  const assigneeFieldKey = selectedTemplateAssigneeFieldKey.value
  if (assigneeFieldKey !== '') {
    return String(templateFieldValues[assigneeFieldKey] || '').trim()
  }

  return String(form.assignCollaboratorId || '').trim()
}

function resolveTemplateSelectPlaceholder(field) {
  if (isTemplateAssigneeField(field)) {
    return assignablePlaceholderLabel.value
  }

  return 'Selecione'
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
  return resolveHistoryBadgeToneClass(normalizeHistoryKind(kind))
}

</script>

<template>
  <TaskModalShell @close="$emit('close')">
      <header class="flex flex-col gap-4 border-b border-slate-200/80 px-5 py-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Visualização</p>
            <h2 class="mt-1 break-words text-2xl font-semibold text-slate-950 sm:text-3xl">
              #{{ currentIssue?.number }} {{ currentIssue?.title }}
            </h2>
            <p class="mt-2 text-sm leading-7 text-slate-600">
              Visualização detalhada da issue com historico renderizado e modelos de atualização.
            </p>
          </div>

          <div class="flex flex-wrap gap-2">
            <a
              v-if="currentIssue?.url"
              class="app-btn app-btn-secondary"
              :href="currentIssue.url"
              target="_blank"
              rel="noreferrer noopener"
            >
              Abrir no GitHub
            </a>
            <button
              type="button"
              class="app-btn app-btn-secondary"
              @click="$emit('close')"
            >
              Fechar
            </button>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="app-btn"
            :class="activePanel === 'view' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="activePanel = 'view'"
          >
            Visualização
          </button>
          <button
            v-if="canEdit"
            type="button"
            class="app-btn"
            :class="activePanel === 'edit' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="activePanel = 'edit'"
          >
            Atualização
          </button>
        </div>
      </header>

      <div class="min-h-0 flex-1 overflow-y-auto p-5">
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
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentIssue?.updatedAt) }}</strong>
              </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
              <div class="rounded-2xl border border-slate-200 bg-white/90 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Criada em</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentIssue?.createdAt) }}</strong>
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

            <div class="rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Descriçao</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Conteudo formatado</h3>
              </div>

              <div class="mt-4 overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
                <MarkdownPreview
                  :content="currentIssue?.body || ''"
                  empty-label="Nenhuma Descriçao em Markdown foi informada para esta issue."
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

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft">
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
                      class="app-status-badge app-status-badge--compact"
                      :class="historyBadgeClass(entry.kind)"
                    >
                      {{ entry.title }}
                    </span>
                    <strong class="truncate text-sm font-semibold text-slate-900">
                      {{ entry.actorLogin || 'Sistema' }}
                    </strong>
                  </div>

                  <div class="flex items-center gap-3">
                    <span class="text-xs font-medium text-slate-500">{{ formatDateTime(entry.createdAt) }}</span>
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
          class="grid gap-5 xl:grid-cols-[minmax(0,1.08fr),minmax(320px,0.92fr)]"
        >
          <article class="grid gap-4">
            <form class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft" @submit.prevent="saveIssue">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Modelos de atualização</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Escolha o template da atualização</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">
                  O sistema carrega o template selecionado e monta a atualização no preview ao lado.
                </p>
              </div>

              <div v-if="updateTemplates.length" class="grid gap-2">
                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">Template selecionado</span>
                  <select v-model="selectedTemplateKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                    <option value="">Selecionar template</option>
                    <option v-for="template in updateTemplates" :key="template.key" :value="template.key">
                      {{ template.label }}
                    </option>
                  </select>
                </label>

                <p v-if="selectedTemplate" class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-600">
                  {{ selectedTemplate.description }}
                </p>
              </div>
              <p
                v-else
                class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
              >
                Nenhum template de atualização foi configurado.
              </p>

              <div
                v-if="selectedTemplate"
                class="grid gap-4 rounded-[24px] border border-slate-200/80 bg-white/80 p-5"
              >
                <div>
                  <p class="text-sm font-semibold text-slate-900">Campos do template</p>
                  <p class="text-sm text-slate-500">{{ selectedTemplate.description }}</p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                  <template v-for="field in selectedTemplate.fields || []" :key="field.key">
                    <label v-if="field.type === 'textarea'" class="grid gap-2 md:col-span-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <textarea
                        v-model="templateFieldValues[field.key]"
                        rows="5"
                        :placeholder="field.placeholder || ''"
                        class="min-h-[132px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      />
                    </label>

                    <label v-else-if="field.type === 'list'" class="grid gap-2 md:col-span-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <textarea
                        v-model="templateFieldValues[field.key]"
                        rows="4"
                        :placeholder="field.placeholder || 'Um item por linha.'"
                        class="min-h-[120px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      />
                      <small class="text-sm text-slate-500">Use uma linha por item. O preview vira lista automaticamente.</small>
                    </label>

                    <label v-else-if="field.type === 'select'" class="grid gap-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <select
                        v-model="templateFieldValues[field.key]"
                        class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      >
                        <option value="">{{ resolveTemplateSelectPlaceholder(field) }}</option>
                        <option v-for="option in field.options || []" :key="option.value" :value="option.value">
                          {{ option.label }}
                        </option>
                      </select>
                    </label>

                    <label v-else class="grid gap-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <input
                        v-model="templateFieldValues[field.key]"
                        type="text"
                        :placeholder="field.placeholder || ''"
                        class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      >
                    </label>
                  </template>
                </div>
              </div>

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

              <label v-if="shouldShowStandaloneCollaboratorSelect" class="grid gap-2 sm:max-w-lg">
                <span class="text-sm font-semibold text-slate-900">Atribuir colaborador</span>
                <select
                  v-model="form.assignCollaboratorId"
                  :disabled="!canEdit || saving"
                  class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
                >
                  <option value="">{{ assignablePlaceholderLabel }}</option>
                  <option
                    v-for="assignableUser in assignableUsers"
                    :key="assignableUser.id || assignableUser.login"
                    :value="assignableUser.id"
                  >
                    {{ assignableUser.name ? `${assignableUser.name} (${assignableUser.login})` : assignableUser.login }}
                  </option>
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
                  class="app-btn app-btn-primary"
                >
                  {{ saving ? 'Salvando...' : 'Salvar issue' }}
                </button>
                <button
                  type="button"
                  :disabled="saving"
                  class="app-btn app-btn-secondary"
                  @click="resetForm"
                >
                  Limpar formulario
                </button>
              </div>
            </form>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Preview Markdown</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Resultado final da atualização</h3>
              <p class="mt-2 text-sm text-slate-500">
                O preview abaixo considera a Descriçao atual da issue e o modelo de atualização preenchido.
              </p>
            </div>

            <div class="min-h-[540px] overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
              <MarkdownPreview
                :content="finalBody"
                empty-label="Preencha os campos do modelo para gerar a atualização."
              />
            </div>
          </article>
        </div>
      </div>
  </TaskModalShell>
</template>
