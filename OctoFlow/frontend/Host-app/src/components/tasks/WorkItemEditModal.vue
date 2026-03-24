<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useNotification } from '../../composables/useNotification'
import {
  RemoteMarkdownPreview as MarkdownPreview,
  RemoteMultiSelect,
} from '../../federation/remoteComponents'
import {
  fetchGithubIssueDetails,
  fetchLocalTask,
  syncLocalTaskToGithub,
  updateGithubIssue,
  updateLocalTask,
} from '../../services/tasks'
import { buildCurrentDateTimeLabel, formatDateTime } from '../../utils/date'
import { splitRepositoryKey } from '../../utils/githubRepository'
import { extractHttpMessage } from '../../utils/httpErrors'
import {
  buildLabelNamesFromSelection,
  buildMergedProjectLabelOptions,
  mapLabelNamesToSelectedOptions,
} from '../../utils/projectLabels'
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
  mode: {
    type: String,
    required: true,
    validator: (value) => value === 'github' || value === 'local',
  },
  issue: {
    type: Object,
    default: null,
  },
  task: {
    type: Object,
    default: null,
  },
  currentUser: {
    type: Object,
    default: null,
  },
  updateTemplates: {
    type: Array,
    default: () => [],
  },
  repositories: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'item-updated', 'item-synced'])
const { notifyUser } = useNotification(props.notify)

const isGithubMode = computed(() => props.mode === 'github')
const isLocalMode = computed(() => props.mode === 'local')
const activePanel = ref('view')
const githubViewTab = ref('description')
const localViewTab = ref('description')

watch(
  () => props.mode,
  () => {
    activePanel.value = 'view'
    githubViewTab.value = 'description'
    localViewTab.value = 'description'
  },
)

const currentIssue = ref(null)
const currentHistory = ref([])
const saving = ref(false)
const detailLoading = ref(false)
const error = ref('')
const detailSource = ref('cache')
const selectedTemplateKey = ref('')
const templateFieldValues = reactive({})
const templateDrafts = reactive({})
const templateRenderTimestamp = ref(buildCurrentDateTimeLabel())
const form = reactive({
  state: 'OPEN',
  assignCollaboratorId: '',
  selectedLabels: [],
})

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
const availableLabelOptions = computed(() => buildMergedProjectLabelOptions(
  Array.isArray(currentIssue.value?.labels) ? currentIssue.value.labels : [],
))
const selectedLabelsModel = computed({
  get() {
    return form.selectedLabels
  },
  set(nextSelectedLabels) {
    form.selectedLabels = mapLabelNamesToSelectedOptions(
      buildLabelNamesFromSelection(nextSelectedLabels),
      availableLabelOptions.value,
    )
  },
})
const selectedIssueLabelIds = computed(() => form.selectedLabels
  .map((selectedLabel) => String(selectedLabel?.id || '').trim())
  .filter((selectedLabelId) => selectedLabelId !== '')
)
const selectedIssueNewLabelNames = computed(() => form.selectedLabels
  .filter((selectedLabel) => String(selectedLabel?.id || '').trim() === '')
  .map((selectedLabel) => String(selectedLabel?.name || '').trim())
  .filter((selectedLabelName) => selectedLabelName !== '')
)
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
  () => [props.mode, props.issue],
  ([mode, issue]) => {
    if (mode !== 'github') {
      return
    }

    currentIssue.value = normalizeIssuePayload(issue)
    currentHistory.value = buildFallbackHistory(issue)
    syncFormFromIssue(issue)
    resetTemplateCatalog()
    activePanel.value = 'view'
    githubViewTab.value = 'description'
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
  () => [props.mode, props.updateTemplates],
  ([mode, templates]) => {
    if (mode !== 'github') {
      return
    }

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
  form.selectedLabels = mapLabelNamesToSelectedOptions(
    Array.isArray(issue?.labels) ? issue.labels.map((label) => String(label?.name || '').trim()) : [],
    availableLabelOptions.value,
  )
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

  const repositoryKey = String(
    currentIssue.value?.repository?.nameWithOwner
      || localCurrentTask.value?.repositoryKey
      || '',
  ).trim()
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
      labelIds: selectedIssueLabelIds.value,
      newLabelNames: selectedIssueNewLabelNames.value,
      assigneeIds: assigneeId ? [assigneeId] : [],
    })

    currentIssue.value = normalizeIssuePayload(data?.item || currentIssue.value)
    syncFormFromIssue(currentIssue.value)
    emit('item-updated', {
      mode: 'github',
      payload: currentIssue.value,
    })
    await loadLatestIssue(currentIssue.value.id)
    activePanel.value = 'view'
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel atualizar a issue.')
  } finally {
    saving.value = false
  }
}

function resetIssueForm() {
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

const localLoading = ref(false)
const localSaving = ref(false)
const localSyncing = ref(false)
const localError = ref('')
const localCurrentTask = ref(null)
const localSyncRepositoryKey = ref('')
const localSelectedTemplateKey = ref('')
const localTemplateFieldValues = reactive({})
const localTemplateDrafts = reactive({})
const localTemplateRenderTimestamp = ref(buildCurrentDateTimeLabel())
const localForm = reactive({
  title: '',
  body: '',
  templateKey: '',
  repositoryKey: '',
  selectedLabels: [],
})

const localAvailableRepositories = computed(() => {
  return (props.repositories || [])
    .map((repository) => String(repository?.nameWithOwner || '').trim())
    .filter(Boolean)
    .sort((left, right) => left.localeCompare(right, 'pt-BR'))
})
const localSelectedUpdateTemplate = computed(() => {
  return props.updateTemplates.find((template) => template.key === localSelectedTemplateKey.value) || null
})
const localAvailableLabelOptions = computed(() => buildMergedProjectLabelOptions(
  (localCurrentTask.value?.labelNames || []).map((labelName) => ({
    id: '',
    name: labelName,
  })),
))
const localSyncStatusLabel = computed(() => {
  if (localCurrentTask.value?.syncState === 'FAILED') {
    return 'Falhou ao sincronizar'
  }

  if (localCurrentTask.value?.state === 'CLOSED') {
    return 'Fechada'
  }

  return 'Aberta'
})
const localSuggestedRepositoryLabel = computed(() => {
  const repositoryKey = String(localCurrentTask.value?.repositoryKey || '').trim()

  if (localCurrentTask.value?.syncState === 'PENDING') {
    return repositoryKey !== ''
      ? `${repositoryKey} - Pendente de sincronização`
      : 'Pendente de sincronização'
  }

  return repositoryKey || 'Definir depois'
})
const localAuthorLabel = computed(() => {
  const authorName = String(
    props.currentUser?.name
      || props.currentUser?.login
      || props.currentUser?.email
      || props.currentUser?.defaultEmail
      || '',
  ).trim()

  return authorName !== '' ? authorName : 'Usuario local'
})
const localResponsiblesLabel = computed(() => {
  const githubIssueNumber = Number(localCurrentTask.value?.githubIssueNumber || 0)
  if (Number.isInteger(githubIssueNumber) && githubIssueNumber > 0) {
    return `Issue #${githubIssueNumber} (GitHub)`
  }

  return 'Sem responsavel'
})
const localPreviewTitle = computed(() => String(localForm.title || '').trim() || 'Titulo da tarefa local')
const localPreviewBody = computed(() => {
  if (activePanel.value !== 'edit') {
    return String(localCurrentTask.value?.body || '').trim() || 'Sem conteudo para visualizar.'
  }

  const finalBody = buildLocalFinalBody()
  return finalBody !== '' ? finalBody : 'Sem conteudo para visualizar.'
})
const localPreviewLabelNames = computed(() => buildLabelNamesFromSelection(localForm.selectedLabels))
const localSelectedLabelsModel = computed({
  get() {
    return localForm.selectedLabels
  },
  set(nextSelectedLabels) {
    localForm.selectedLabels = mapLabelNamesToSelectedOptions(
      buildLabelNamesFromSelection(nextSelectedLabels),
      localAvailableLabelOptions.value,
    )
  },
})
const localTaskHistoryEntries = computed(() => {
  const task = localCurrentTask.value
  if (!task || typeof task !== 'object') {
    return []
  }

  const historyEntries = normalizePersistedHistoryEntries(task.historyEntries, task.id)
  const normalizedCreatedAt = String(task.createdAt || '').trim()
  const normalizedUpdatedAt = String(task.updatedAt || '').trim()
  const normalizedSyncedAt = String(task.syncedAt || '').trim()
  const normalizedRepositoryKey = String(task.repositoryKey || '').trim()

  if (normalizedCreatedAt !== '') {
    historyEntries.push({
      id: `local-task-created-${task.id || 'item'}`,
      kind: 'created',
      title: 'Tarefa local criada',
      createdAt: normalizedCreatedAt,
      description: task.templateKey ? `Template inicial: ${task.templateKey}` : 'Criada sem template definido.',
      url: '',
    })
  }

  if (normalizedUpdatedAt !== '' && normalizedUpdatedAt !== normalizedCreatedAt) {
    historyEntries.push({
      id: `local-task-updated-${task.id || 'item'}`,
      kind: 'updated',
      title: 'Ultima edicao local',
      createdAt: normalizedUpdatedAt,
      description: 'Conteudo, template, tags ou repositorio sugerido foram atualizados.',
      url: '',
    })
  }

  if (task.syncState === 'FAILED') {
    historyEntries.push({
      id: `local-task-failed-${task.id || 'item'}`,
      kind: 'failed',
      title: 'Falha de sincronizacao',
      createdAt: normalizedUpdatedAt || normalizedCreatedAt,
      description: String(task.syncError || 'O envio ao GitHub falhou.'),
      url: '',
    })
  } else if (task.syncState === 'PENDING') {
    historyEntries.push({
      id: `local-task-pending-${task.id || 'item'}`,
      kind: 'pending',
      title: 'Pendente de sincronizacao',
      createdAt: normalizedUpdatedAt || normalizedCreatedAt,
      description: normalizedRepositoryKey !== ''
        ? `Repositorio alvo: ${normalizedRepositoryKey}`
        : 'Sem repositorio definido para sincronizar.',
      url: '',
    })
  }

  if (normalizedSyncedAt !== '') {
    historyEntries.push({
      id: `local-task-synced-${task.id || 'item'}`,
      kind: 'synced',
      title: 'Sincronizada com GitHub',
      createdAt: normalizedSyncedAt,
      description: task.githubIssueNumber
        ? `Issue #${task.githubIssueNumber} criada no GitHub.`
        : 'Enviada ao GitHub com sucesso.',
      url: String(task.githubIssueUrl || '').trim(),
    })
  }

  return historyEntries.sort((leftEntry, rightEntry) => {
    return String(rightEntry.createdAt || '').localeCompare(String(leftEntry.createdAt || ''))
  })
})

watch(localSelectedTemplateKey, (newTemplateKey, oldTemplateKey) => {
  if (oldTemplateKey) {
    persistLocalTemplateDraft(oldTemplateKey)
  }

  if (newTemplateKey) {
    restoreLocalTemplateDraft(newTemplateKey)
  }
})

watch(
  () => [props.mode, props.updateTemplates],
  ([mode, templates]) => {
    if (mode !== 'local') {
      return
    }

    if (!Array.isArray(templates) || templates.length === 0) {
      localSelectedTemplateKey.value = ''
      return
    }

    const selectedTemplateExists = templates.some((template) => template?.key === localSelectedTemplateKey.value)
    if (!selectedTemplateExists) {
      resetLocalTemplateCatalog()
    }
  },
  { immediate: true },
)

watch(
  () => [props.mode, props.task],
  ([mode, task]) => {
    if (mode !== 'local') {
      return
    }

    localCurrentTask.value = task || null
    syncLocalForm(task)
    resetLocalTemplateCatalog()
    activePanel.value = 'view'
    localViewTab.value = 'description'
    localError.value = ''

    if (task?.id) {
      void loadLocalTask(task.id)
    }
  },
  { immediate: true },
)

async function loadLocalTask(taskId) {
  localLoading.value = true
  localError.value = ''

  try {
    const { data } = await fetchLocalTask(props.request, taskId)
    localCurrentTask.value = data?.item || localCurrentTask.value
    syncLocalForm(localCurrentTask.value)
  } catch (requestError) {
    localError.value = extractHttpMessage(requestError, 'Nao foi possivel carregar os detalhes da tarefa local.')
  } finally {
    localLoading.value = false
  }
}

function syncLocalForm(task) {
  localForm.title = String(task?.title || '')
  localForm.body = String(task?.body || '')
  localForm.templateKey = String(task?.templateKey || '')
  localForm.repositoryKey = String(task?.repositoryKey || '')
  localForm.selectedLabels = mapLabelNamesToSelectedOptions(task?.labelNames, localAvailableLabelOptions.value)
  localSyncRepositoryKey.value = ''
}

function resetLocalTemplateCatalog() {
  localSelectedTemplateKey.value = ''

  for (const templateFieldKey of Object.keys(localTemplateFieldValues)) {
    delete localTemplateFieldValues[templateFieldKey]
  }

  for (const draftTemplateKey of Object.keys(localTemplateDrafts)) {
    delete localTemplateDrafts[draftTemplateKey]
  }

  const firstTemplateKey = props.updateTemplates[0]?.key || ''
  if (firstTemplateKey !== '') {
    localSelectedTemplateKey.value = firstTemplateKey
  }
}

function persistLocalTemplateDraft(templateKey = localSelectedTemplateKey.value) {
  const template = props.updateTemplates.find((catalogTemplate) => catalogTemplate.key === templateKey)
  if (!template) {
    return
  }

  localTemplateDrafts[templateKey] = {
    fields: snapshotFieldValues(template, localTemplateFieldValues),
  }
}

function restoreLocalTemplateDraft(templateKey) {
  const template = localSelectedUpdateTemplate.value?.key === templateKey
    ? localSelectedUpdateTemplate.value
    : props.updateTemplates.find((catalogTemplate) => catalogTemplate.key === templateKey)
  if (!template) {
    return
  }

  localTemplateRenderTimestamp.value = buildCurrentDateTimeLabel()

  const restoredFields = buildInitialFieldState(template)
  const existingDraft = localTemplateDrafts[templateKey]?.fields || {}

  for (const field of template.fields || []) {
    const fieldKey = typeof field?.key === 'string' ? field.key.trim() : ''
    if (fieldKey === '') {
      continue
    }

    if (typeof existingDraft[fieldKey] === 'string') {
      restoredFields[fieldKey] = existingDraft[fieldKey]
    }
  }

  for (const fieldKey of Object.keys(localTemplateFieldValues)) {
    delete localTemplateFieldValues[fieldKey]
  }

  Object.assign(localTemplateFieldValues, restoredFields)
}

function resetLocalTemplateInputs() {
  if (!localSelectedUpdateTemplate.value) {
    return
  }

  localTemplateRenderTimestamp.value = buildCurrentDateTimeLabel()

  for (const fieldKey of Object.keys(localTemplateFieldValues)) {
    delete localTemplateFieldValues[fieldKey]
  }

  const initialFieldValues = buildInitialFieldState(localSelectedUpdateTemplate.value)
  Object.assign(localTemplateFieldValues, initialFieldValues)
  localTemplateDrafts[localSelectedUpdateTemplate.value.key] = {
    fields: snapshotFieldValues(localSelectedUpdateTemplate.value, localTemplateFieldValues),
  }
}

function buildLocalFinalBody(timestampLabel = localTemplateRenderTimestamp.value) {
  const sections = []
  const currentBody = String(localCurrentTask.value?.body || '').trim()
  const updateBlock = renderUpdateTemplate(
    localSelectedUpdateTemplate.value,
    buildSubmissionFields(localSelectedUpdateTemplate.value, localTemplateFieldValues, { resolveSelectToLabel: true }),
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

async function saveLocalTask() {
  if (!localCurrentTask.value?.id) {
    return
  }

  if (String(localForm.title || '').trim() === '') {
    localError.value = 'O titulo da tarefa local e obrigatorio.'
    return
  }

  localSaving.value = true
  localError.value = ''

  try {
    localTemplateRenderTimestamp.value = buildCurrentDateTimeLabel()
    const repository = splitRepositoryKey(localForm.repositoryKey)
    const nextBody = buildLocalFinalBody(localTemplateRenderTimestamp.value)
    const { data } = await updateLocalTask(props.request, localCurrentTask.value.id, {
      title: localForm.title,
      body: nextBody !== '' ? nextBody : String(localCurrentTask.value?.body || '').trim(),
      templateKey: localForm.templateKey || null,
      labelNames: buildLabelNamesFromSelection(localForm.selectedLabels),
      repositoryOwner: repository.owner || null,
      repositoryName: repository.name || null,
    })

    localCurrentTask.value = data?.item || localCurrentTask.value
    syncLocalForm(localCurrentTask.value)
    resetLocalTemplateInputs()
    activePanel.value = 'view'
    notifyUser('Tarefa local atualizada com sucesso.', 'success')
    emit('item-updated', {
      mode: 'local',
      payload: data || null,
    })
  } catch (requestError) {
    localError.value = extractHttpMessage(requestError, 'Nao foi possivel atualizar a tarefa local.')
  } finally {
    localSaving.value = false
  }
}

async function syncLocalTask() {
  if (!localCurrentTask.value?.id) {
    return
  }

  const selectedRepository = splitRepositoryKey(localSyncRepositoryKey.value)
  if (selectedRepository.owner === '' || selectedRepository.name === '') {
    localError.value = 'Selecione o repositorio GitHub antes de sincronizar a tarefa local.'
    return
  }

  localSyncing.value = true
  localError.value = ''

  try {
    const { data } = await syncLocalTaskToGithub(props.request, localCurrentTask.value.id, {
      repositoryOwner: selectedRepository.owner,
      repositoryName: selectedRepository.name,
    })

    notifyUser('Tarefa local enviada ao GitHub com sucesso.', 'success')
    emit('item-synced', data || null)
    emit('close')
  } catch (requestError) {
    localError.value = extractHttpMessage(requestError, 'Nao foi possivel sincronizar a tarefa local com o GitHub.')
  } finally {
    localSyncing.value = false
  }
}

function localHistoryBadgeClass(kind) {
  return resolveHistoryBadgeToneClass(kind)
}

function normalizePersistedHistoryEntries(rawHistoryEntries, taskId) {
  if (!Array.isArray(rawHistoryEntries) || rawHistoryEntries.length === 0) {
    return []
  }

  const normalizedTaskId = String(taskId || 'item').trim() || 'item'
  const normalizedEntries = []

  for (const [entryIndex, rawHistoryEntry] of rawHistoryEntries.entries()) {
    if (!rawHistoryEntry || typeof rawHistoryEntry !== 'object') {
      continue
    }

    const normalizedKind = String(rawHistoryEntry.kind || '').trim().toLowerCase() || 'updated'
    const normalizedCreatedAt = String(rawHistoryEntry.createdAt || '').trim()
    const normalizedTitle = String(rawHistoryEntry.title || '').trim()
    const normalizedDescription = String(rawHistoryEntry.description || '').trim()
    const normalizedId = String(rawHistoryEntry.id || '').trim()
    const normalizedUrl = String(rawHistoryEntry.url || '').trim()

    normalizedEntries.push({
      id: normalizedId !== '' ? normalizedId : `local-task-history-${normalizedTaskId}-${normalizedKind}-${entryIndex}`,
      kind: normalizedKind,
      title: normalizedTitle !== '' ? normalizedTitle : 'Atualizacao registrada',
      createdAt: normalizedCreatedAt !== '' ? normalizedCreatedAt : String(localCurrentTask.value?.updatedAt || ''),
      description: normalizedDescription !== '' ? normalizedDescription : 'Sem detalhes adicionais.',
      url: normalizedUrl,
    })
  }

  return normalizedEntries
}
</script>

<template>
  <TaskModalShell @close="$emit('close')">
    <template v-if="isGithubMode">
      <header class="flex flex-col gap-3 border-b border-slate-200/80 px-5 py-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Visualização</p>
            <h2 class="mt-1 break-words text-xl font-semibold text-slate-950 sm:text-2xl">
              #{{ currentIssue?.number }} {{ currentIssue?.title }}
            </h2>
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

        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Status</span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ statusLabel }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Repositorio</span>
            <strong class="mt-0.5 block break-all text-sm font-semibold text-slate-950">{{ repositoryName }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Autor</span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ currentIssue?.authorLogin || 'desconhecido' }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Atualizada</span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentIssue?.updatedAt) }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Criada em</span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentIssue?.createdAt) }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Responsaveis</span>
            <strong class="mt-0.5 block break-words text-sm font-semibold text-slate-950">
              {{ currentIssue?.assignees?.length ? currentIssue.assignees.map((assignee) => assignee.name || assignee.login).join(', ') : 'Sem responsavel' }}
            </strong>
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
        <div v-if="activePanel === 'view'" class="grid gap-4">
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="app-btn"
              :class="githubViewTab === 'description' ? 'app-btn-tab-active' : 'app-btn-secondary'"
              @click="githubViewTab = 'description'"
            >
              Descrição
            </button>
            <button
              type="button"
              class="app-btn"
              :class="githubViewTab === 'history' ? 'app-btn-tab-active' : 'app-btn-secondary'"
              @click="githubViewTab = 'history'"
            >
              Histórico
            </button>
          </div>

          <article v-if="githubViewTab === 'description'" class="grid gap-4">
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

          <article v-else class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft">
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

              <div v-if="props.updateTemplates.length" class="grid gap-2">
                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">Template selecionado</span>
                  <select v-model="selectedTemplateKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                    <option value="">Selecionar template</option>
                    <option v-for="template in props.updateTemplates" :key="template.key" :value="template.key">
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

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Tags</span>
                <RemoteMultiSelect
                  v-model="selectedLabelsModel"
                  :options="availableLabelOptions"
                  option-label-key="name"
                  option-value-key="id"
                  option-color-key="color"
                  option-description-key="description"
                  search-placeholder="Pesquisar tags padrao"
                  helper-text="Use a busca para filtrar tags. Tambem e possivel criar novas tags."
                  selected-count-suffix="tag(s) selecionada(s)"
                  create-label-prefix="Criar tag"
                  create-helper-text="A nova tag sera criada ao salvar."
                  existing-option-helper-text="Tag existente"
                  empty-idle-text="Digite para buscar tags ou criar uma nova."
                  empty-search-text="Nenhuma tag encontrada para essa busca."
                  empty-create-text="Pressione Enter para criar essa tag."
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
                  @click="resetIssueForm"
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
    </template>

    <template v-else-if="isLocalMode">
      <header class="flex flex-col gap-3 border-b border-slate-200/80 px-5 py-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Tarefa local</p>
            <h2 class="mt-1 text-xl font-semibold text-slate-950 sm:text-2xl">
              {{ localCurrentTask?.title || 'Editar tarefa local' }}
            </h2>
          </div>

          <button type="button" class="app-btn app-btn-secondary" @click="$emit('close')">
            Fechar
          </button>
        </div>

        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Status</span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ localSyncStatusLabel }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Repositorio</span>
            <strong class="mt-0.5 block break-all text-sm font-semibold text-slate-950">{{ localCurrentTask?.repositoryKey || 'Definir depois' }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Autor</span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ localAuthorLabel }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Atualizada</span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ formatDateTime(localCurrentTask?.updatedAt) }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Criada em</span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ formatDateTime(localCurrentTask?.createdAt) }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Responsaveis</span>
            <strong class="mt-0.5 block break-words text-sm font-semibold text-slate-950">{{ localResponsiblesLabel }}</strong>
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
        <div v-if="activePanel === 'view'" class="mb-4 flex flex-wrap gap-2">
          <button
            type="button"
            class="app-btn"
            :class="localViewTab === 'description' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="localViewTab = 'description'"
          >
            Descrição
          </button>
          <button
            type="button"
            class="app-btn"
            :class="localViewTab === 'history' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="localViewTab = 'history'"
          >
            Histórico
          </button>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1.05fr),minmax(320px,0.95fr)]">
          <article v-if="activePanel === 'view' && localViewTab === 'description'" class="app-panel-standard grid gap-4 rounded-[28px] p-5">
            <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
              <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Tags</span>
              <div v-if="localPreviewLabelNames.length > 0" class="mt-2 flex flex-wrap gap-2">
                <span
                  v-for="labelName in localPreviewLabelNames"
                  :key="`local-task-preview-label-${labelName}`"
                  class="app-chip rounded-full px-3 py-1 text-xs font-semibold"
                >
                  {{ labelName }}
                </span>
              </div>
              <strong v-else class="mt-2 block text-sm font-semibold text-slate-950">Sem tags definidas</strong>
            </div>

            <article class="rounded-[24px] border border-slate-200/80 bg-white/80 p-5">
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Resumo</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Informações da tarefa</h3>
              <div class="mt-4 grid gap-3">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                  <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Titulo</span>
                  <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ localPreviewTitle }}</strong>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                  <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Conteudo</span>
                  <p class="mt-2 line-clamp-5 text-sm leading-6 text-slate-700">{{ localCurrentTask?.body || 'Sem conteudo para visualizar.' }}</p>
                </div>
              </div>
            </article>

            <p v-if="localCurrentTask?.syncError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
              {{ localCurrentTask.syncError }}
            </p>

            <p v-if="localError" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
              {{ localError }}
            </p>
          </article>

          <article
            v-else-if="activePanel === 'view' && localViewTab === 'history'"
            class="app-panel-standard grid gap-4 rounded-[28px] p-5"
          >
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Historico</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Linha do tempo da tarefa local</h3>
            </div>

            <div v-if="localTaskHistoryEntries.length > 0" class="grid gap-3">
              <article
                v-for="historyEntry in localTaskHistoryEntries"
                :key="historyEntry.id"
                class="rounded-2xl border border-slate-200 bg-white px-4 py-4"
              >
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span
                    class="app-status-badge app-status-badge--compact"
                    :class="localHistoryBadgeClass(historyEntry.kind)"
                  >
                    {{ historyEntry.title }}
                  </span>

                  <div class="flex items-center gap-3">
                    <span class="text-xs font-medium text-slate-500">{{ formatDateTime(historyEntry.createdAt) }}</span>
                    <a
                      v-if="historyEntry.url"
                      class="text-xs font-semibold text-cyan-700 underline"
                      :href="historyEntry.url"
                      target="_blank"
                      rel="noreferrer noopener"
                    >
                      Abrir
                    </a>
                  </div>
                </div>

                <p class="mt-3 text-sm leading-6 text-slate-600">{{ historyEntry.description }}</p>
              </article>
            </div>

            <p
              v-else
              class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
            >
              Ainda nao existe historico suficiente para esta tarefa local.
            </p>
          </article>

          <article v-else-if="activePanel === 'edit'" class="app-panel-standard grid gap-4 rounded-[28px] p-5">
            <div class="grid gap-3 md:grid-cols-3">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status local</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ localSyncStatusLabel }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Criada em</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(localCurrentTask?.createdAt) }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Atualizada em</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(localCurrentTask?.updatedAt) }}</strong>
              </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Template</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ localCurrentTask?.templateKey || 'personalizado' }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repositorio sugerido</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ localSuggestedRepositoryLabel }}</strong>
              </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
              <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Tags</span>
              <div v-if="localPreviewLabelNames.length > 0" class="mt-2 flex flex-wrap gap-2">
                <span
                  v-for="labelName in localPreviewLabelNames"
                  :key="`local-task-current-label-${labelName}`"
                  class="app-chip rounded-full px-3 py-1 text-xs font-semibold"
                >
                  {{ labelName }}
                </span>
              </div>
              <strong v-else class="mt-2 block text-sm font-semibold text-slate-950">Sem tags definidas</strong>
            </div>

            <div v-if="localLoading" class="app-empty-panel rounded-2xl px-4 py-6 text-sm text-slate-500">
              Carregando detalhes da tarefa local...
            </div>

            <template v-else>
              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Titulo</span>
                <input v-model="localForm.title" type="text" class="app-field-control h-11 px-3 text-sm text-slate-900">
              </label>

              <div v-if="props.updateTemplates.length" class="grid gap-2">
                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">Template da atualização</span>
                  <select v-model="localSelectedTemplateKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                    <option value="">Selecionar template</option>
                    <option v-for="template in props.updateTemplates" :key="template.key" :value="template.key">
                      {{ template.label }}
                    </option>
                  </select>
                </label>

                <p
                  v-if="localSelectedUpdateTemplate"
                  class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-600"
                >
                  {{ localSelectedUpdateTemplate.description }}
                </p>
              </div>
              <p
                v-else
                class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
              >
                Nenhum template de atualização foi configurado.
              </p>

              <div
                v-if="localSelectedUpdateTemplate"
                class="grid gap-4 rounded-[24px] border border-slate-200/80 bg-white/80 p-5"
              >
                <div>
                  <p class="text-sm font-semibold text-slate-900">Campos da atualização</p>
                  <p class="text-sm text-slate-500">{{ localSelectedUpdateTemplate.description }}</p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                  <template v-for="field in localSelectedUpdateTemplate.fields || []" :key="field.key">
                    <label v-if="field.type === 'textarea'" class="grid gap-2 md:col-span-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <textarea
                        v-model="localTemplateFieldValues[field.key]"
                        rows="5"
                        :placeholder="field.placeholder || ''"
                        class="min-h-[132px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      />
                    </label>

                    <label v-else-if="field.type === 'list'" class="grid gap-2 md:col-span-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <textarea
                        v-model="localTemplateFieldValues[field.key]"
                        rows="4"
                        :placeholder="field.placeholder || 'Um item por linha.'"
                        class="min-h-[120px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      />
                      <small class="text-sm text-slate-500">Use uma linha por item. O preview vira lista automaticamente.</small>
                    </label>

                    <label v-else-if="field.type === 'select'" class="grid gap-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <select
                        v-model="localTemplateFieldValues[field.key]"
                        class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      >
                        <option value="">Selecione</option>
                        <option v-for="option in field.options || []" :key="option.value" :value="option.value">
                          {{ option.label }}
                        </option>
                      </select>
                    </label>

                    <label v-else class="grid gap-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <input
                        v-model="localTemplateFieldValues[field.key]"
                        type="text"
                        :placeholder="field.placeholder || ''"
                        class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      >
                    </label>
                  </template>
                </div>
              </div>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Tags</span>
                <RemoteMultiSelect
                  v-model="localSelectedLabelsModel"
                  :options="localAvailableLabelOptions"
                  option-label-key="name"
                  option-value-key="id"
                  option-color-key="color"
                  option-description-key="description"
                  search-placeholder="Pesquisar tags padrao"
                  helper-text="Use a busca para filtrar tags. Tambem e possivel criar novas tags."
                  selected-count-suffix="tag(s) selecionada(s)"
                  create-label-prefix="Criar tag"
                  create-helper-text="A nova tag sera criada ao salvar."
                  existing-option-helper-text="Tag existente"
                  empty-idle-text="Digite para buscar tags ou criar uma nova."
                  empty-search-text="Nenhuma tag encontrada para essa busca."
                  empty-create-text="Pressione Enter para criar essa tag."
                />
              </label>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Repositorio sugerido para depois</span>
                <select v-model="localForm.repositoryKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="">Definir depois</option>
                  <option v-for="repositoryKey in localAvailableRepositories" :key="repositoryKey" :value="repositoryKey">
                    {{ repositoryKey }}
                  </option>
                </select>
              </label>

              <p v-if="localCurrentTask?.syncError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                {{ localCurrentTask.syncError }}
              </p>

              <p v-if="localError" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                {{ localError }}
              </p>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="app-btn app-btn-primary" :disabled="localSaving || localSyncing" @click="saveLocalTask">
                  {{ localSaving ? 'Salvando...' : 'Salvar alteracoes' }}
                </button>
                <button type="button" class="app-btn app-btn-secondary" :disabled="localSaving || localSyncing" @click="resetLocalTemplateInputs">
                  Limpar formulario
                </button>
              </div>
            </template>
          </article>

          <div v-if="activePanel === 'edit' || localViewTab === 'description'" class="grid gap-4">
            <article v-if="activePanel === 'edit'" class="app-panel-standard grid gap-4 rounded-[28px] p-5">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Conteudo formatado</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Preview da tarefa local</h3>
              </div>

              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm font-semibold text-slate-900">
                {{ localPreviewTitle }}
              </div>

              <div class="min-h-[360px] overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
                <MarkdownPreview :content="localPreviewBody" />
              </div>
            </article>

            <article class="app-panel-standard grid gap-4 rounded-[28px] p-5">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Historico</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Linha do tempo da tarefa local</h3>
              </div>

              <div v-if="localTaskHistoryEntries.length > 0" class="grid gap-3">
                <article
                  v-for="historyEntry in localTaskHistoryEntries"
                  :key="historyEntry.id"
                  class="rounded-2xl border border-slate-200 bg-white px-4 py-4"
                >
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <span
                      class="app-status-badge app-status-badge--compact"
                      :class="localHistoryBadgeClass(historyEntry.kind)"
                    >
                      {{ historyEntry.title }}
                    </span>

                    <div class="flex items-center gap-3">
                      <span class="text-xs font-medium text-slate-500">{{ formatDateTime(historyEntry.createdAt) }}</span>
                      <a
                        v-if="historyEntry.url"
                        class="text-xs font-semibold text-cyan-700 underline"
                        :href="historyEntry.url"
                        target="_blank"
                        rel="noreferrer noopener"
                      >
                        Abrir
                      </a>
                    </div>
                  </div>

                  <p class="mt-3 text-sm leading-6 text-slate-600">{{ historyEntry.description }}</p>
                </article>
              </div>

              <p
                v-else
                class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
              >
                Ainda nao existe historico suficiente para esta tarefa local.
              </p>
            </article>

            <aside class="app-panel-standard grid gap-4 rounded-[28px] p-5">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Sincronizacao</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Enviar ao GitHub</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">
                  A sincronizacao manual sempre pede o repositorio de destino. Nada e publicado automaticamente.
                </p>
              </div>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Repositorio para publicar agora</span>
                <select v-model="localSyncRepositoryKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="">Selecionar repositorio</option>
                  <option v-for="repositoryKey in localAvailableRepositories" :key="repositoryKey" :value="repositoryKey">
                    {{ repositoryKey }}
                  </option>
                </select>
              </label>

              <div v-if="localAvailableRepositories.length === 0" class="app-empty-panel rounded-2xl px-4 py-6 text-sm text-slate-500">
                Nenhum repositorio GitHub disponivel para sincronizacao no momento.
              </div>

              <button
                type="button"
                class="app-btn app-btn-secondary"
                :disabled="localSyncing || localSaving || localAvailableRepositories.length === 0"
                @click="syncLocalTask"
              >
                {{ localSyncing ? 'Enviando ao GitHub...' : 'Enviar ao GitHub' }}
              </button>
            </aside>
          </div>
        </div>
      </div>
    </template>
  </TaskModalShell>
</template>
