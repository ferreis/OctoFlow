<script setup>
import {
  computed,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
  reactive,
  ref,
  watch,
} from 'vue'
import { useI18n } from '../../composables/useI18n'
import { useNotification } from '../../composables/useNotification'
import {
  RemoteMarkdownPreview as MarkdownPreview,
  RemoteMultiSelect,
} from '../../federation/remoteComponents'
import {
  createGithubSubIssues,
  fetchGithubIssueDetails,
  fetchLocalTask,
  fetchTaskTemplates,
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
import {
  readDraft,
  removeDraft,
  TASK_DRAFT_MAX_AGE_MS,
  TASK_DRAFT_MAX_ITEMS,
  writeDraft,
} from '../../utils/draftStorage'
import { resolveHistoryBadgeToneClass } from '../../utils/statusTone'
import {
  buildInitialFieldState,
  buildSubmissionFields,
  snapshotFieldValues,
} from '../../utils/issueTemplate'
import TaskModalShell from './TaskModalShell.vue'

const props = defineProps({
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

const emit = defineEmits(['close', 'item-updated', 'item-synced', 'open-github-issue'])
const { notifyUser } = useNotification(props.notify)
const { translate } = useI18n()

const TITLE_MAX_LENGTH = 220
const FIELD_TEXT_MAX_LENGTH = 5000
const FIELD_LINE_MAX_LENGTH = 420
const LABEL_NAME_MAX_LENGTH = 80
const FEEDBACK_MESSAGE_MAX_LENGTH = 260

const isGithubMode = computed(() => props.mode === 'github')
const isLocalMode = computed(() => props.mode === 'local')
const activePanel = ref('view')
const githubViewTab = ref('description')
const localViewTab = ref('description')
const applyingStoredWorkItemDraft = ref(false)
const githubBaselineSnapshot = ref('')
const localBaselineSnapshot = ref('')
const shouldPersistWorkItemDraftOnUnmount = ref(true)
const runtimeError = ref('')
const viewTabBeforeUpdate = ref('')
const panelBeforeUpdate = ref('')
const keepAlivePaused = ref(false)
let componentDisposed = false

const TASK_DRAFT_STORAGE_PREFIX = 'octoflow.tasks.'

function translateWithFallback(messageKey, fallbackMessage, variables = {}) {
  const translatedMessage = translate(messageKey, variables)
  return translatedMessage === messageKey ? fallbackMessage : translatedMessage
}

function translateWorkItem(messageKey, fallbackMessage, variables = {}) {
  return translateWithFallback(`tasks.workItemEditModal.${messageKey}`, fallbackMessage, variables)
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

function sanitizeSingleLineText(rawValue, maxLength = 120) {
  return replaceControlCharactersWithSpaces(rawValue)
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, maxLength)
}

function sanitizeMultiLineText(rawValue, maxLength = FIELD_TEXT_MAX_LENGTH) {
  return replaceControlCharactersWithSpaces(rawValue)
    .replace(/\r\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
    .slice(0, maxLength)
}

function sanitizeIdentifier(rawValue, maxLength = 120) {
  const normalizedIdentifier = sanitizeSingleLineText(rawValue, maxLength)
  if (normalizedIdentifier === '') {
    return ''
  }

  return /^[a-zA-Z0-9_.:-]+$/.test(normalizedIdentifier)
    ? normalizedIdentifier
    : ''
}

function sanitizeLabelName(rawLabelName) {
  return sanitizeSingleLineText(rawLabelName, LABEL_NAME_MAX_LENGTH)
}

function sanitizeLabelNames(rawLabelNames = []) {
  if (!Array.isArray(rawLabelNames)) {
    return []
  }

  return rawLabelNames
    .map((rawLabelName) => sanitizeLabelName(rawLabelName))
    .filter((normalizedLabelName) => normalizedLabelName !== '')
}

function sanitizeFeedbackMessage(rawMessage) {
  return sanitizeSingleLineText(rawMessage, FEEDBACK_MESSAGE_MAX_LENGTH)
}

function sanitizeIssueSubmissionFields(template, rawSubmissionFields = {}) {
  const normalizedSubmissionFields = {}
  const templateFields = Array.isArray(template?.fields) ? template.fields : []

  for (const templateField of templateFields) {
    const templateFieldKey = sanitizeSingleLineText(templateField?.key, 80)
    if (templateFieldKey === '') {
      continue
    }

    const rawFieldValue = rawSubmissionFields[templateFieldKey]
    if (templateField.type === 'list') {
      normalizedSubmissionFields[templateFieldKey] = Array.isArray(rawFieldValue)
        ? rawFieldValue
          .map((listItem) => sanitizeSingleLineText(listItem, FIELD_LINE_MAX_LENGTH))
          .filter((listItem) => listItem !== '')
        : []
      continue
    }

    if (templateField.type === 'textarea') {
      normalizedSubmissionFields[templateFieldKey] = sanitizeMultiLineText(rawFieldValue)
      continue
    }

    normalizedSubmissionFields[templateFieldKey] = sanitizeSingleLineText(rawFieldValue, FIELD_LINE_MAX_LENGTH)
  }

  return normalizedSubmissionFields
}

function ensureComponentIsActive() {
  return !componentDisposed
}

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
const creatingSubIssues = ref(false)
const detailLoading = ref(false)
const error = ref('')
const subIssueError = ref('')
const detailSource = ref('cache')
const selectedTemplateKey = ref('')
const templateFieldValues = reactive({})
const templateDrafts = reactive({})
const templateRenderTimestamp = ref(buildCurrentDateTimeLabel())
const subIssueTemplates = ref([])
const subIssueDrafts = ref([buildEmptySubIssueDraft()])
const form = reactive({
  state: 'OPEN',
  assignCollaboratorId: '',
  selectedLabels: [],
})

const canEdit = computed(() => Boolean(currentIssue.value?.viewerCanUpdate))
const repositoryName = computed(() => (
  sanitizeSingleLineText(currentIssue.value?.repository?.nameWithOwner, 180)
  || translateWorkItem('labels.currentRepository', 'Repositório atual')
))
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
const availableSubIssueTemplates = computed(() => Array.isArray(subIssueTemplates.value) ? subIssueTemplates.value : [])
const historyEntries = computed(() => normalizeHistoryEntries(currentHistory.value, currentIssue.value))
const statusLabel = computed(() => (
  currentIssue.value?.state === 'CLOSED'
    ? translateWorkItem('labels.closed', 'Fechada')
    : translateWorkItem('labels.open', 'Aberta')
))
const currentIssueParent = computed(() => currentIssue.value?.parent || null)
const subIssues = computed(() => Array.isArray(currentIssue.value?.subIssues) ? currentIssue.value.subIssues : [])
const hasSubIssues = computed(() => subIssues.value.length > 0)
const openSubIssuesCount = computed(() => subIssues.value.filter((subIssue) => subIssue?.state !== 'CLOSED').length)
const canCreateSubIssues = computed(() => canEdit.value && !currentIssueParent.value)
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
  .map((selectedLabel) => sanitizeLabelName(selectedLabel?.name))
  .filter((selectedLabelName) => selectedLabelName !== '')
)
const assignablePlaceholderLabel = computed(() => {
  if (detailLoading.value && assignableUsers.value.length === 0) {
    return translateWorkItem('assignee.loading', 'Carregando colaboradores...')
  }

  if (assignableUsers.value.length === 0) {
    return translateWorkItem('assignee.empty', 'Nenhum colaborador encontrado')
  }

  return translateWorkItem('assignee.none', 'Não adicionar colaborador')
})
const isWorkItemBusy = computed(() => (
  saving.value
  || creatingSubIssues.value
  || detailLoading.value
  || localLoading.value
  || localSaving.value
  || localSyncing.value
))
const activeWorkItemId = computed(() => {
  if (isGithubMode.value) {
    return String(currentIssue.value?.id || '').trim()
  }

  if (isLocalMode.value) {
    return String(localCurrentTask.value?.id || '').trim()
  }

  return ''
})
const draftScopeIdentifier = computed(() => resolveDraftScope(props.currentUser))
const workItemDraftScopePrefix = computed(() => `${TASK_DRAFT_STORAGE_PREFIX}${draftScopeIdentifier.value}.`)
const workItemDraftStorageKey = computed(() => {
  const itemId = activeWorkItemId.value
  if (itemId === '') {
    return ''
  }

  return `${workItemDraftScopePrefix.value}work-item.${props.mode}.${itemId}`
})
const hasGithubUnsavedChanges = computed(() => {
  if (!isGithubMode.value || activePanel.value !== 'edit' || githubBaselineSnapshot.value === '') {
    return false
  }

  return buildGithubEditSnapshot() !== githubBaselineSnapshot.value
})
const hasLocalUnsavedChanges = computed(() => {
  if (!isLocalMode.value || activePanel.value !== 'edit' || localBaselineSnapshot.value === '') {
    return false
  }

  return buildLocalEditSnapshot() !== localBaselineSnapshot.value
})
const hasUnsavedWorkItemChanges = computed(() => hasGithubUnsavedChanges.value || hasLocalUnsavedChanges.value)
const workItemModalTitleId = computed(() => (
  isGithubMode.value
    ? 'work-item-edit-modal-github-title'
    : 'work-item-edit-modal-local-title'
))

watch(
  () => [props.mode, props.issue],
  async ([mode, issue]) => {
    if (mode !== 'github') {
      return
    }

    currentIssue.value = normalizeIssuePayload(issue)
    currentHistory.value = buildFallbackHistory(issue)
    syncFormFromIssue(issue)
    resetSubIssueComposer()
    resetTemplateCatalog()
    activePanel.value = 'view'
    githubViewTab.value = 'description'
    error.value = ''
    detailSource.value = 'cache'

    if (issue?.id) {
      await loadSubIssueTemplates()
      await loadLatestIssue(issue.id)
    }

    captureGithubBaselineSnapshot()
    restoreStoredWorkItemDraft()
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
  try {
    const { data } = await fetchGithubIssueDetails(issueId)
    if (!ensureComponentIsActive()) {
      return
    }

    currentIssue.value = normalizeIssuePayload(data?.item || currentIssue.value)
    currentHistory.value = normalizeHistoryEntries(data?.history, currentIssue.value)
    detailSource.value = data?.source || 'github'
    syncFormFromIssue(currentIssue.value)
    syncSubIssueDraftAssignees()

    const detailWarning = String(data?.warning || '').trim()
    if (detailWarning !== '') {
      notifyUser(sanitizeFeedbackMessage(detailWarning), 'warning')
    }

  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateWorkItem(
          'errors.issueDetailsRefreshFailed',
          'Não foi possível atualizar os detalhes da issue no GitHub. Mantendo o cache local.',
        ),
      ),
      'warning'
    )
    currentHistory.value = buildFallbackHistory(currentIssue.value)
  } finally {
    detailLoading.value = false
  }
}

async function loadSubIssueTemplates() {
  if (availableSubIssueTemplates.value.length > 0) {
    return
  }

  try {
    const { data } = await fetchTaskTemplates()
    subIssueTemplates.value = Array.isArray(data?.items) ? data.items : []
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateWorkItem('errors.subIssueTemplatesLoadFailed', 'Não foi possível carregar os templates de criação para sub-issues.'),
      ),
      'warning'
    )
    subIssueTemplates.value = []
  }
}

function syncFormFromIssue(issue) {
  form.state = issue?.state === 'CLOSED' ? 'CLOSED' : 'OPEN'
  form.assignCollaboratorId = resolvePrimaryAssigneeId(issue)
  form.selectedLabels = mapLabelNamesToSelectedOptions(
    Array.isArray(issue?.labels) ? issue.labels.map((label) => sanitizeLabelName(label?.name)) : [],
    availableLabelOptions.value,
  )
}

function buildEmptySubIssueDraft() {
  return {
    key: `sub-issue-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    title: '',
    body: '',
    assigneeId: '',
    dueDate: '',
    templateKey: '',
    templateFieldValues: {},
  }
}

function resetSubIssueComposer() {
  subIssueDrafts.value = [buildEmptySubIssueDraft()]
  subIssueError.value = ''
}

function addSubIssueDraft() {
  subIssueDrafts.value = [...subIssueDrafts.value, buildEmptySubIssueDraft()]
}

function removeSubIssueDraft(draftKey) {
  const remainingDrafts = subIssueDrafts.value.filter((draft) => draft.key !== draftKey)
  subIssueDrafts.value = remainingDrafts.length > 0 ? remainingDrafts : [buildEmptySubIssueDraft()]
}

function syncSubIssueDraftAssignees() {
  const availableAssigneeIds = new Set(
    assignableUsers.value
      .map((user) => sanitizeIdentifier(user?.id, 120))
      .filter(Boolean),
  )

  subIssueDrafts.value = subIssueDrafts.value.map((draft) => ({
      ...draft,
      assigneeId: availableAssigneeIds.has(sanitizeIdentifier(draft.assigneeId, 120))
        ? sanitizeIdentifier(draft.assigneeId, 120)
        : '',
    }))
}

function findSubIssueTemplate(templateKey) {
  return availableSubIssueTemplates.value.find((template) => template?.key === templateKey) || null
}

function handleSubIssueTemplateChange(draft) {
  const template = findSubIssueTemplate(String(draft?.templateKey || '').trim())
  draft.templateFieldValues = template ? buildInitialFieldState(template) : {}
}

function updateSubIssueTemplateField(draft, fieldKey, value) {
  const normalizedFieldKey = sanitizeSingleLineText(fieldKey, 80)
  if (normalizedFieldKey === '') {
    return
  }

  draft.templateFieldValues = {
    ...(draft.templateFieldValues || {}),
    [normalizedFieldKey]: typeof value === 'string'
      ? sanitizeMultiLineText(value)
      : sanitizeSingleLineText(value, FIELD_LINE_MAX_LENGTH),
  }
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

  const lines = [
    `## ${template.markdownTitle || template.label || translateWorkItem('github.edit.defaultUpdateTitle', 'Atualização')}`,
  ]
  let hasContent = false

  if (timestampLabel !== '') {
    lines.push(`- ${translateWorkItem('github.edit.timestampLabel', 'Data e hora')}: ${timestampLabel}`)
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
    return translateWorkItem('labels.commit', 'Commit')
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
    error.value = translateWorkItem('errors.issueMissing', 'Nenhuma issue válida foi selecionada.')
    return
  }

  const issueTitle = sanitizeSingleLineText(currentIssue.value?.title, TITLE_MAX_LENGTH)
  if (issueTitle === '') {
    error.value = translateWorkItem('errors.issueTitleRequired', 'O título da issue é obrigatório.')
    return
  }

  saving.value = true
  error.value = ''

  try {
    templateRenderTimestamp.value = buildCurrentDateTimeLabel()
    const assigneeId = sanitizeIdentifier(resolveSelectedAssigneeId(), 120)
    const templateKey = sanitizeSingleLineText(selectedTemplate.value?.key, 120)
    const normalizedTemplateFields = sanitizeIssueSubmissionFields(
      selectedTemplate.value,
      buildTemplatePayloadFields(selectedTemplate.value),
    )
    const normalizedLabelIds = selectedIssueLabelIds.value
      .map((labelId) => sanitizeIdentifier(labelId, 80))
      .filter((labelId) => labelId !== '')
    const normalizedNewLabelNames = sanitizeLabelNames(selectedIssueNewLabelNames.value)

    const { data } = await updateGithubIssue(currentIssue.value.id, {
      title: issueTitle,
      state: form.state === 'CLOSED' ? 'CLOSED' : 'OPEN',
      templateKey,
      templateFields: normalizedTemplateFields,
      labelIds: normalizedLabelIds,
      newLabelNames: normalizedNewLabelNames,
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
    clearStoredWorkItemDraft()
  } catch (requestError) {
    error.value = extractHttpMessage(
      requestError,
      translateWorkItem('errors.issueUpdateFailed', 'Não foi possível atualizar a issue.'),
    )
  } finally {
    saving.value = false
  }
}

async function saveSubIssues() {
  if (!currentIssue.value?.id) {
    subIssueError.value = translateWorkItem('errors.issueMissing', 'Nenhuma issue válida foi selecionada.')
    return
  }

  if (!canCreateSubIssues.value) {
    subIssueError.value = currentIssueParent.value
      ? translateWorkItem('errors.subIssueCannotHaveChildren', 'Sub-issues não podem receber novas sub-issues.')
      : translateWorkItem('errors.subIssuePermissionDenied', 'Você não tem permissão para criar sub-issues nesta issue.')
    return
  }

  const filledDrafts = subIssueDrafts.value
    .map((draft) => ({
      ...draft,
      title: sanitizeSingleLineText(draft.title, TITLE_MAX_LENGTH),
      body: sanitizeMultiLineText(draft.body),
      assigneeId: sanitizeIdentifier(draft.assigneeId, 120),
      dueDate: /^\d{4}-\d{2}-\d{2}$/.test(String(draft.dueDate || '').trim())
        ? String(draft.dueDate || '').trim()
        : '',
      templateKey: sanitizeSingleLineText(draft.templateKey, 120),
      templateFieldValues: normalizeDraftFieldValues(draft.templateFieldValues),
    }))
    .filter((draft) => draft.title !== '' || draft.body !== '' || draft.assigneeId !== '' || draft.dueDate !== '')

  if (filledDrafts.length === 0) {
    subIssueError.value = translateWorkItem('errors.subIssueAtLeastOneRequired', 'Preencha pelo menos uma sub-issue antes de salvar.')
    return
  }

  const draftWithoutTitle = filledDrafts.findIndex((draft) => draft.title === '')
  if (draftWithoutTitle >= 0) {
    subIssueError.value = translateWorkItem('errors.subIssueTitleRequiredByIndex', 'Informe o título da sub-issue {index}.', {
      index: draftWithoutTitle + 1,
    })
    return
  }

  creatingSubIssues.value = true
  subIssueError.value = ''

  try {
    const { data } = await createGithubSubIssues(currentIssue.value.id, {
      items: filledDrafts.map((draft) => ({
        title: draft.title,
        body: draft.body,
        dueDate: draft.dueDate || null,
        template: draft.templateKey || null,
        templateFields: draft.templateKey
          ? sanitizeIssueSubmissionFields(
            findSubIssueTemplate(draft.templateKey),
            buildSubmissionFields(findSubIssueTemplate(draft.templateKey), draft.templateFieldValues || {}),
          )
          : {},
        assigneeIds: draft.assigneeId ? [draft.assigneeId] : [],
      })),
    })

    currentIssue.value = normalizeIssuePayload(data?.item || currentIssue.value)
    currentHistory.value = normalizeHistoryEntries(data?.history, currentIssue.value)
    syncFormFromIssue(currentIssue.value)
    syncSubIssueDraftAssignees()
    emit('item-updated', {
      mode: 'github',
      payload: currentIssue.value,
    })
    notifyUser(
      filledDrafts.length === 1
        ? translateWorkItem('success.subIssueCreatedSingle', 'Sub-issue criada com sucesso.')
        : translateWorkItem('success.subIssueCreatedMultiple', 'Sub-issues criadas com sucesso.'),
      'success'
    )
    resetSubIssueComposer()
    activePanel.value = 'subtasks'
    clearStoredWorkItemDraft()
  } catch (requestError) {
    subIssueError.value = extractHttpMessage(
      requestError,
      translateWorkItem('errors.subIssueCreateFailed', 'Não foi possível criar as sub-issues.'),
    )
  } finally {
    creatingSubIssues.value = false
  }
}

function resetIssueForm() {
  syncFormFromIssue(currentIssue.value)
  resetTemplateInputs()
  error.value = ''
  subIssueError.value = ''
}

function normalizeIssuePayload(issue) {
  if (!issue || typeof issue !== 'object') {
    return null
  }

  return {
    ...issue,
    assignees: Array.isArray(issue.assignees) ? issue.assignees : [],
    labels: Array.isArray(issue.labels) ? issue.labels : [],
    parent: issue.parent && typeof issue.parent === 'object' ? issue.parent : null,
    subIssues: Array.isArray(issue.subIssues) ? issue.subIssues : [],
    projects: Array.isArray(issue.projects) ? issue.projects : [],
    hasOpenSubIssues: Boolean(issue.hasOpenSubIssues),
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

  return translateWorkItem('labels.select', 'Selecione')
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
      octoflowIssueId: typeof entry.octoflowIssueId === 'string' && entry.octoflowIssueId.trim() !== ''
        ? entry.octoflowIssueId
        : '',
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
      title: translateWorkItem('history.issueUpdated', 'Issue atualizada'),
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
      title: translateWorkItem('history.issueClosed', 'Issue fechada'),
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
      title: translateWorkItem('history.issueCreated', 'Issue criada'),
      actorLogin: issue.authorLogin || null,
      createdAt: issue.createdAt,
      updatedAt: issue.createdAt,
      body: '',
      url: '',
    })
  }

  return history.sort((left, right) => String(right.createdAt || '').localeCompare(String(left.createdAt || '')))
}

async function openGithubIssueInOctoFlow(issueId) {
  const normalizedIssueId = String(issueId || '').trim()
  if (normalizedIssueId === '') {
    return
  }

  currentIssue.value = {
    ...(currentIssue.value || {}),
    id: normalizedIssueId,
  }
  currentHistory.value = []
  activePanel.value = 'view'
  githubViewTab.value = 'description'
  error.value = ''
  subIssueError.value = ''
  await loadLatestIssue(normalizedIssueId)

  if (currentIssue.value?.id) {
    emit('open-github-issue', currentIssue.value)
  }
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
      return translateWorkItem('history.issueCreated', 'Issue criada')
    case 'updated':
      return translateWorkItem('history.issueUpdated', 'Issue atualizada')
    case 'closed':
      return translateWorkItem('history.issueClosed', 'Issue fechada')
    default:
      return translateWorkItem('history.comment', 'Comentário')
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
  state: 'OPEN',
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
    name: sanitizeLabelName(labelName),
  })),
))
const localSyncStatusLabel = computed(() => {
  if (localCurrentTask.value?.syncState === 'FAILED') {
    return translateWorkItem('local.status.syncFailed', 'Falhou ao sincronizar')
  }

  if (localCurrentTask.value?.state === 'CLOSED') {
    return translateWorkItem('local.status.closed', 'Fechada')
  }

  return translateWorkItem('local.status.open', 'Aberta')
})
const localSuggestedRepositoryLabel = computed(() => {
  const repositoryKey = String(localCurrentTask.value?.repositoryKey || '').trim()

  if (localCurrentTask.value?.syncState === 'PENDING') {
    return repositoryKey !== ''
      ? translateWorkItem('local.repository.pendingWithTarget', '{repository} - Pendente de sincronização', {
        repository: repositoryKey,
      })
      : translateWorkItem('local.repository.pending', 'Pendente de sincronização')
  }

  return repositoryKey || translateWorkItem('local.repository.defineLater', 'Definir depois')
})
const localAuthorLabel = computed(() => {
  const authorName = sanitizeSingleLineText(
    props.currentUser?.name
      || props.currentUser?.login
      || props.currentUser?.email
      || props.currentUser?.defaultEmail
      || '',
    160,
  )

  return authorName !== '' ? authorName : translateWorkItem('local.author.default', 'Usuário local')
})
const localResponsiblesLabel = computed(() => {
  const githubIssueNumber = Number(localCurrentTask.value?.githubIssueNumber || 0)
  if (Number.isInteger(githubIssueNumber) && githubIssueNumber > 0) {
    return translateWorkItem('local.responsibles.githubIssue', 'Issue #{number} (GitHub)', {
      number: githubIssueNumber,
    })
  }

  return translateWorkItem('local.responsibles.none', 'Sem responsável')
})
const localPreviewTitle = computed(() => (
  sanitizeSingleLineText(localForm.title, TITLE_MAX_LENGTH)
  || translateWorkItem('local.preview.defaultTitle', 'Título da tarefa local')
))
const localPreviewBody = computed(() => {
  if (activePanel.value !== 'edit') {
    return sanitizeMultiLineText(localCurrentTask.value?.body, FIELD_TEXT_MAX_LENGTH)
      || translateWorkItem('local.preview.emptyBody', 'Sem conteúdo para visualizar.')
  }

  const finalBody = buildLocalFinalBody()
  return finalBody !== ''
    ? finalBody
    : translateWorkItem('local.preview.emptyBody', 'Sem conteúdo para visualizar.')
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
      title: translateWorkItem('local.history.taskCreated', 'Tarefa local criada'),
      createdAt: normalizedCreatedAt,
      description: task.templateKey
        ? translateWorkItem('local.history.initialTemplate', 'Template inicial: {templateKey}', {
          templateKey: sanitizeSingleLineText(task.templateKey, 120),
        })
        : translateWorkItem('local.history.createdWithoutTemplate', 'Criada sem template definido.'),
      url: '',
    })
  }

  if (normalizedUpdatedAt !== '' && normalizedUpdatedAt !== normalizedCreatedAt) {
    historyEntries.push({
      id: `local-task-updated-${task.id || 'item'}`,
      kind: 'updated',
      title: translateWorkItem('local.history.lastLocalEdit', 'Última edição local'),
      createdAt: normalizedUpdatedAt,
      description: translateWorkItem('local.history.localUpdateDescription', 'Conteúdo, template, tags ou repositório sugerido foram atualizados.'),
      url: '',
    })
  }

  if (task.syncState === 'FAILED') {
    historyEntries.push({
      id: `local-task-failed-${task.id || 'item'}`,
      kind: 'failed',
      title: translateWorkItem('local.history.syncFailed', 'Falha de sincronização'),
      createdAt: normalizedUpdatedAt || normalizedCreatedAt,
      description: sanitizeSingleLineText(
        task.syncError,
        FEEDBACK_MESSAGE_MAX_LENGTH,
      ) || translateWorkItem('local.history.syncFailedDescription', 'O envio ao GitHub falhou.'),
      url: '',
    })
  } else if (task.syncState === 'PENDING') {
    historyEntries.push({
      id: `local-task-pending-${task.id || 'item'}`,
      kind: 'pending',
      title: translateWorkItem('local.history.pendingSync', 'Pendente de sincronização'),
      createdAt: normalizedUpdatedAt || normalizedCreatedAt,
      description: normalizedRepositoryKey !== ''
        ? translateWorkItem('local.history.pendingTarget', 'Repositório alvo: {repository}', {
          repository: normalizedRepositoryKey,
        })
        : translateWorkItem('local.history.pendingWithoutRepository', 'Sem repositório definido para sincronizar.'),
      url: '',
    })
  }

  if (normalizedSyncedAt !== '') {
    historyEntries.push({
      id: `local-task-synced-${task.id || 'item'}`,
      kind: 'synced',
      title: translateWorkItem('local.history.synced', 'Sincronizada com GitHub'),
      createdAt: normalizedSyncedAt,
      description: task.githubIssueNumber
        ? translateWorkItem('local.history.syncedWithIssue', 'Issue #{number} criada no GitHub.', {
          number: task.githubIssueNumber,
        })
        : translateWorkItem('local.history.syncedSuccess', 'Enviada ao GitHub com sucesso.'),
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
  async ([mode, task]) => {
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
      await loadLocalTask(task.id)
    }

    captureLocalBaselineSnapshot()
    restoreStoredWorkItemDraft()
  },
  { immediate: true },
)

watch(
  [
    () => props.mode,
    activePanel,
    githubViewTab,
    selectedTemplateKey,
    () => form.state,
    () => form.assignCollaboratorId,
    () => JSON.stringify(form.selectedLabels || []),
    () => JSON.stringify(templateFieldValues || {}),
    () => JSON.stringify(subIssueDrafts.value || []),
  ],
  () => {
    if (!isGithubMode.value) {
      return
    }

    persistWorkItemDraft()
  },
)

watch(
  [
    () => props.mode,
    activePanel,
    localViewTab,
    localSelectedTemplateKey,
    () => localForm.title,
    () => localForm.body,
    () => localForm.templateKey,
    () => localForm.repositoryKey,
    () => localSyncRepositoryKey.value,
    () => JSON.stringify(localForm.selectedLabels || []),
    () => JSON.stringify(localTemplateFieldValues || {}),
  ],
  () => {
    if (!isLocalMode.value) {
      return
    }

    persistWorkItemDraft()
  },
)

onBeforeMount(() => {
  componentDisposed = false
  runtimeError.value = ''
  keepAlivePaused.value = false
})

onMounted(() => {
  keepAlivePaused.value = false
})

onBeforeUpdate(() => {
  panelBeforeUpdate.value = activePanel.value
  viewTabBeforeUpdate.value = isGithubMode.value ? githubViewTab.value : localViewTab.value
})

onUpdated(() => {
  if (panelBeforeUpdate.value !== activePanel.value) {
    error.value = ''
    subIssueError.value = ''
    localError.value = ''
  }

  if (viewTabBeforeUpdate.value !== (isGithubMode.value ? githubViewTab.value : localViewTab.value)) {
    error.value = ''
    localError.value = ''
  }
})

onActivated(() => {
  keepAlivePaused.value = false
})

onDeactivated(() => {
  keepAlivePaused.value = true
  persistWorkItemDraft()
})

onBeforeUnmount(() => {
  componentDisposed = true

  if (!shouldPersistWorkItemDraftOnUnmount.value) {
    return
  }

  persistWorkItemDraft()
})

onUnmounted(() => {
  runtimeError.value = ''
})

onErrorCaptured((capturedError) => {
  runtimeError.value = sanitizeFeedbackMessage(capturedError?.message)

  const fallbackMessage = translateWorkItem('errors.unexpected', 'Erro inesperado ao editar a tarefa.')
  const resolvedErrorMessage = runtimeError.value !== '' ? runtimeError.value : fallbackMessage

  if (isGithubMode.value) {
    error.value = resolvedErrorMessage
  } else {
    localError.value = resolvedErrorMessage
  }

  return false
})

async function loadLocalTask(taskId) {
  localLoading.value = true
  localError.value = ''

  try {
    const { data } = await fetchLocalTask(taskId)
    if (!ensureComponentIsActive()) {
      return
    }

    localCurrentTask.value = data?.item || localCurrentTask.value
    syncLocalForm(localCurrentTask.value)
  } catch (requestError) {
    localError.value = extractHttpMessage(
      requestError,
      translateWorkItem('errors.localLoadFailed', 'Não foi possível carregar os detalhes da tarefa local.'),
    )
  } finally {
    if (ensureComponentIsActive()) {
      localLoading.value = false
    }
  }
}

function syncLocalForm(task) {
  localForm.title = sanitizeSingleLineText(task?.title, TITLE_MAX_LENGTH)
  localForm.body = sanitizeMultiLineText(task?.body)
  localForm.state = task?.state === 'CLOSED' ? 'CLOSED' : 'OPEN'
  localForm.templateKey = sanitizeSingleLineText(task?.templateKey, 120)
  localForm.repositoryKey = sanitizeSingleLineText(task?.repositoryKey, 180)
  localForm.selectedLabels = mapLabelNamesToSelectedOptions(sanitizeLabelNames(task?.labelNames), localAvailableLabelOptions.value)
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

  const normalizedLocalTitle = sanitizeSingleLineText(localForm.title, TITLE_MAX_LENGTH)
  if (normalizedLocalTitle === '') {
    localError.value = translateWorkItem('errors.localTitleRequired', 'O título da tarefa local é obrigatório.')
    return
  }

  localSaving.value = true
  localError.value = ''

  try {
    localTemplateRenderTimestamp.value = buildCurrentDateTimeLabel()
    const repository = splitRepositoryKey(localForm.repositoryKey)
    const nextBody = buildLocalFinalBody(localTemplateRenderTimestamp.value)
    const sanitizedRepositoryOwner = sanitizeIdentifier(repository.owner, 120)
    const sanitizedRepositoryName = sanitizeIdentifier(repository.name, 120)
    const { data } = await updateLocalTask(localCurrentTask.value.id, {
      title: normalizedLocalTitle,
      body: nextBody !== ''
        ? sanitizeMultiLineText(nextBody)
        : sanitizeMultiLineText(localCurrentTask.value?.body),
      state: localForm.state === 'CLOSED' ? 'CLOSED' : 'OPEN',
      templateKey: sanitizeSingleLineText(localForm.templateKey, 120) || null,
      labelNames: sanitizeLabelNames(buildLabelNamesFromSelection(localForm.selectedLabels)),
      repositoryOwner: sanitizedRepositoryOwner || null,
      repositoryName: sanitizedRepositoryName || null,
    })

    if (!ensureComponentIsActive()) {
      return
    }

    localCurrentTask.value = data?.item || localCurrentTask.value
    syncLocalForm(localCurrentTask.value)
    resetLocalTemplateInputs()
    activePanel.value = 'view'
    notifyUser(translateWorkItem('success.localUpdated', 'Tarefa local atualizada com sucesso.'), 'success')
    emit('item-updated', {
      mode: 'local',
      payload: data || null,
    })
    clearStoredWorkItemDraft()
  } catch (requestError) {
    localError.value = extractHttpMessage(
      requestError,
      translateWorkItem('errors.localUpdateFailed', 'Não foi possível atualizar a tarefa local.'),
    )
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
    localError.value = translateWorkItem('errors.syncRepositoryRequired', 'Selecione o repositório GitHub antes de sincronizar a tarefa local.')
    return
  }

  localSyncing.value = true
  localError.value = ''

  try {
    const { data } = await syncLocalTaskToGithub(localCurrentTask.value.id, {
      repositoryOwner: sanitizeIdentifier(selectedRepository.owner, 120),
      repositoryName: sanitizeIdentifier(selectedRepository.name, 120),
    })

    if (!ensureComponentIsActive()) {
      return
    }

    shouldPersistWorkItemDraftOnUnmount.value = false
    clearStoredWorkItemDraft()
    notifyUser(translateWorkItem('success.localSynced', 'Tarefa local enviada ao GitHub com sucesso.'), 'success')
    emit('item-synced', data || null)
    emit('close')
  } catch (requestError) {
    localError.value = extractHttpMessage(
      requestError,
      translateWorkItem('errors.localSyncFailed', 'Não foi possível sincronizar a tarefa local com o GitHub.'),
    )
  } finally {
    localSyncing.value = false
  }
}

function localHistoryBadgeClass(kind) {
  return resolveHistoryBadgeToneClass(kind)
}

function requestClose() {
  if (isWorkItemBusy.value) {
    notifyUser(
      translateWorkItem('errors.waitOperationToClose', 'Aguarde a operação atual finalizar antes de fechar.'),
      'warning',
    )
    return
  }

  persistWorkItemDraft()

  if (!hasUnsavedWorkItemChanges.value) {
    emit('close')
    return
  }

  emit('close')
}

function handleModalRuntimeError(runtimePayload) {
  const runtimeMessage = sanitizeFeedbackMessage(runtimePayload?.message)
  const fallbackMessage = translateWorkItem('errors.unexpected', 'Erro inesperado ao editar a tarefa.')
  const resolvedErrorMessage = runtimeMessage !== '' ? runtimeMessage : fallbackMessage

  if (isGithubMode.value) {
    error.value = resolvedErrorMessage
    return
  }

  localError.value = resolvedErrorMessage
}

function resolveDraftScope(currentUser) {
  const userId = sanitizeIdentifier(currentUser?.id, 120)
  if (userId !== '') {
    return userId
  }

  const normalizedEmail = sanitizeSingleLineText(currentUser?.defaultEmail || currentUser?.email, 160).toLowerCase()
  if (normalizedEmail !== '') {
    return normalizedEmail
  }

  return translateWorkItem('labels.guest', 'guest')
}

function buildGithubEditSnapshot() {
  return JSON.stringify({
    activePanel: activePanel.value,
    viewTab: githubViewTab.value,
    selectedTemplateKey: String(selectedTemplateKey.value || '').trim(),
    state: String(form.state || '').trim(),
    assignCollaboratorId: String(form.assignCollaboratorId || '').trim(),
    selectedLabelNames: buildLabelNamesFromSelection(form.selectedLabels).sort((leftName, rightName) => leftName.localeCompare(rightName, 'pt-BR')),
    templateFieldValues: normalizeDraftFieldValues(templateFieldValues),
    subIssueDrafts: normalizeSubIssueDrafts(subIssueDrafts.value),
  })
}

function buildLocalEditSnapshot() {
  return JSON.stringify({
    activePanel: activePanel.value,
    viewTab: localViewTab.value,
    selectedTemplateKey: String(localSelectedTemplateKey.value || '').trim(),
    title: String(localForm.title || ''),
    body: String(localForm.body || ''),
    state: String(localForm.state || 'OPEN'),
    templateKey: String(localForm.templateKey || ''),
    repositoryKey: String(localForm.repositoryKey || ''),
    syncRepositoryKey: String(localSyncRepositoryKey.value || ''),
    selectedLabelNames: buildLabelNamesFromSelection(localForm.selectedLabels).sort((leftName, rightName) => leftName.localeCompare(rightName, 'pt-BR')),
    templateFieldValues: normalizeDraftFieldValues(localTemplateFieldValues),
  })
}

function captureGithubBaselineSnapshot() {
  githubBaselineSnapshot.value = buildGithubEditSnapshot()
}

function captureLocalBaselineSnapshot() {
  localBaselineSnapshot.value = buildLocalEditSnapshot()
}

function readStoredWorkItemDraft() {
  if (workItemDraftStorageKey.value === '') {
    return null
  }

  return readDraft(workItemDraftStorageKey.value, {
    scopePrefix: workItemDraftScopePrefix.value,
    maxAgeMs: TASK_DRAFT_MAX_AGE_MS,
    maxDraftItems: TASK_DRAFT_MAX_ITEMS,
  })
}

function persistWorkItemDraft() {
  if (workItemDraftStorageKey.value === '' || applyingStoredWorkItemDraft.value) {
    return
  }

  const baseDraftPayload = {
    version: 1,
    savedAt: new Date().toISOString(),
    mode: props.mode,
    itemId: activeWorkItemId.value,
  }

  let draftPayload = null

  if (isGithubMode.value) {
    draftPayload = {
      ...baseDraftPayload,
      activePanel: activePanel.value,
      viewTab: githubViewTab.value,
      selectedTemplateKey: String(selectedTemplateKey.value || '').trim(),
      formState: String(form.state || '').trim(),
      assignCollaboratorId: String(form.assignCollaboratorId || '').trim(),
      selectedLabelNames: buildLabelNamesFromSelection(form.selectedLabels),
      templateFieldValues: normalizeDraftFieldValues(templateFieldValues),
      subIssueDrafts: normalizeSubIssueDrafts(subIssueDrafts.value),
    }
  } else if (isLocalMode.value) {
    draftPayload = {
      ...baseDraftPayload,
      activePanel: activePanel.value,
      viewTab: localViewTab.value,
      localSelectedTemplateKey: String(localSelectedTemplateKey.value || '').trim(),
      localForm: {
        title: String(localForm.title || ''),
        body: String(localForm.body || ''),
        state: String(localForm.state || 'OPEN'),
        templateKey: String(localForm.templateKey || ''),
        repositoryKey: String(localForm.repositoryKey || ''),
        selectedLabelNames: buildLabelNamesFromSelection(localForm.selectedLabels),
      },
      localSyncRepositoryKey: String(localSyncRepositoryKey.value || '').trim(),
      localTemplateFieldValues: normalizeDraftFieldValues(localTemplateFieldValues),
    }
  }

  if (!draftPayload) {
    return
  }

  writeDraft(workItemDraftStorageKey.value, draftPayload, {
    scopePrefix: workItemDraftScopePrefix.value,
    maxAgeMs: TASK_DRAFT_MAX_AGE_MS,
    maxDraftItems: TASK_DRAFT_MAX_ITEMS,
  })
}

function clearStoredWorkItemDraft() {
  if (workItemDraftStorageKey.value === '') {
    return
  }

  removeDraft(workItemDraftStorageKey.value)
}

function restoreStoredWorkItemDraft() {
  const storedDraft = readStoredWorkItemDraft()
  if (!storedDraft) {
    return
  }

  const storedMode = String(storedDraft.mode || '').trim()
  const storedItemId = String(storedDraft.itemId || '').trim()
  if (storedMode !== props.mode || storedItemId === '' || storedItemId !== activeWorkItemId.value) {
    return
  }

  applyingStoredWorkItemDraft.value = true

  try {
    const restoredPanel = ['view', 'edit', 'subtasks'].includes(String(storedDraft.activePanel || '').trim())
      ? storedDraft.activePanel
      : 'view'
    activePanel.value = restoredPanel

    if (isGithubMode.value) {
      githubViewTab.value = ['description', 'history'].includes(String(storedDraft.viewTab || '').trim())
        ? storedDraft.viewTab
        : 'description'
      form.state = storedDraft.formState === 'CLOSED' ? 'CLOSED' : 'OPEN'
      form.assignCollaboratorId = String(storedDraft.assignCollaboratorId || '').trim()
      form.selectedLabels = mapLabelNamesToSelectedOptions(storedDraft.selectedLabelNames, availableLabelOptions.value)

      const restoredTemplateKey = String(storedDraft.selectedTemplateKey || '').trim()
      if (restoredTemplateKey !== '' && props.updateTemplates.some((template) => template?.key === restoredTemplateKey)) {
        selectedTemplateKey.value = restoredTemplateKey
      }

      applyDraftFieldValues(templateFieldValues, storedDraft.templateFieldValues)
      applySubIssueDrafts(storedDraft.subIssueDrafts)
      return
    }

    localViewTab.value = storedDraft.viewTab === 'history' ? 'history' : 'description'
    const restoredLocalForm = storedDraft.localForm && typeof storedDraft.localForm === 'object'
      ? storedDraft.localForm
      : {}

    localForm.title = String(restoredLocalForm.title || '')
    localForm.body = String(restoredLocalForm.body || '')
    localForm.state = restoredLocalForm.state === 'CLOSED' ? 'CLOSED' : 'OPEN'
    localForm.templateKey = String(restoredLocalForm.templateKey || '')
    localForm.repositoryKey = String(restoredLocalForm.repositoryKey || '')
    localForm.selectedLabels = mapLabelNamesToSelectedOptions(
      restoredLocalForm.selectedLabelNames,
      localAvailableLabelOptions.value,
    )

    localSyncRepositoryKey.value = String(storedDraft.localSyncRepositoryKey || '').trim()

    const restoredLocalTemplateKey = String(storedDraft.localSelectedTemplateKey || '').trim()
    if (restoredLocalTemplateKey !== '' && props.updateTemplates.some((template) => template?.key === restoredLocalTemplateKey)) {
      localSelectedTemplateKey.value = restoredLocalTemplateKey
    }

    applyDraftFieldValues(localTemplateFieldValues, storedDraft.localTemplateFieldValues)
  } finally {
    applyingStoredWorkItemDraft.value = false
  }
}

function normalizeDraftFieldValues(rawFieldValues) {
  const normalizedFieldValues = {}
  const sourceFieldValues = rawFieldValues && typeof rawFieldValues === 'object' ? rawFieldValues : {}

  for (const fieldKey of Object.keys(sourceFieldValues)) {
    const normalizedFieldKey = sanitizeSingleLineText(fieldKey, 80)
    if (normalizedFieldKey === '') {
      continue
    }

    const rawFieldValue = sourceFieldValues[fieldKey]
    if (Array.isArray(rawFieldValue)) {
      normalizedFieldValues[normalizedFieldKey] = rawFieldValue
        .map((fieldItem) => sanitizeSingleLineText(fieldItem, FIELD_LINE_MAX_LENGTH))
        .filter((fieldItem) => fieldItem !== '')
      continue
    }

    normalizedFieldValues[normalizedFieldKey] = typeof rawFieldValue === 'string'
      ? sanitizeMultiLineText(rawFieldValue)
      : sanitizeSingleLineText(String(rawFieldValue ?? ''), FIELD_LINE_MAX_LENGTH)
  }

  return normalizedFieldValues
}

function normalizeSubIssueDrafts(rawDrafts) {
  if (!Array.isArray(rawDrafts)) {
    return []
  }

  return rawDrafts
    .filter((draft) => draft && typeof draft === 'object')
    .map((draft, index) => ({
      key: sanitizeSingleLineText(draft.key, 80) || `stored-sub-issue-${index}`,
      title: sanitizeSingleLineText(draft.title, TITLE_MAX_LENGTH),
      body: sanitizeMultiLineText(draft.body),
      assigneeId: sanitizeIdentifier(draft.assigneeId, 120),
      dueDate: /^\d{4}-\d{2}-\d{2}$/.test(String(draft.dueDate || '').trim())
        ? String(draft.dueDate || '').trim()
        : '',
      templateKey: sanitizeSingleLineText(draft.templateKey, 120),
      templateFieldValues: normalizeDraftFieldValues(draft.templateFieldValues),
    }))
}

function applySubIssueDrafts(rawDrafts) {
  const normalizedDrafts = normalizeSubIssueDrafts(rawDrafts)
  subIssueDrafts.value = normalizedDrafts.length > 0 ? normalizedDrafts : [buildEmptySubIssueDraft()]
  syncSubIssueDraftAssignees()
}

function subIssueStatusLabel(state) {
  return String(state || '').trim().toUpperCase() === 'CLOSED'
    ? translateWorkItem('labels.closed', 'Fechada')
    : translateWorkItem('labels.open', 'Aberta')
}

function subIssueStatusClass(state) {
  return String(state || '').trim().toUpperCase() === 'CLOSED'
    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
    : 'border-amber-200 bg-amber-50 text-amber-700'
}

function applyDraftFieldValues(targetReactiveMap, rawFieldValues) {
  for (const fieldKey of Object.keys(targetReactiveMap)) {
    delete targetReactiveMap[fieldKey]
  }

  if (!rawFieldValues || typeof rawFieldValues !== 'object') {
    return
  }

  for (const fieldKey of Object.keys(rawFieldValues)) {
    const rawFieldValue = rawFieldValues[fieldKey]
    targetReactiveMap[fieldKey] = typeof rawFieldValue === 'string'
      ? sanitizeMultiLineText(rawFieldValue)
      : Array.isArray(rawFieldValue)
        ? rawFieldValue
          .map((fieldItem) => sanitizeSingleLineText(fieldItem, FIELD_LINE_MAX_LENGTH))
          .filter((fieldItem) => fieldItem !== '')
          .join('\n')
        : sanitizeSingleLineText(String(rawFieldValue ?? ''), FIELD_LINE_MAX_LENGTH)
  }
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
      title: normalizedTitle !== '' ? normalizedTitle : translateWorkItem('history.recordedUpdate', 'Atualização registrada'),
      createdAt: normalizedCreatedAt !== '' ? normalizedCreatedAt : String(localCurrentTask.value?.updatedAt || ''),
      description: normalizedDescription !== '' ? normalizedDescription : translateWorkItem('history.noExtraDetails', 'Sem detalhes adicionais.'),
      url: normalizedUrl,
    })
  }

  return normalizedEntries
}
</script>

<template>
  <TaskModalShell
    :dialog-title-id="workItemModalTitleId"
    @close="requestClose"
    @runtime-error="handleModalRuntimeError"
  >
    <template v-if="isGithubMode">
      <header class="flex flex-col gap-3 border-b border-slate-200/80 px-5 py-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
              {{ translateWorkItem('github.header.kicker', 'Visualização') }}
            </p>
            <h2 id="work-item-edit-modal-github-title" class="mt-1 break-words text-xl font-semibold text-slate-950 sm:text-2xl">
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
              {{ translateWorkItem('github.actions.openGithub', 'Abrir no GitHub') }}
            </a>
            <button
              type="button"
              class="app-btn app-btn-secondary"
              :disabled="isWorkItemBusy"
              @click="requestClose"
            >
              {{ translateWorkItem('github.actions.close', 'Fechar') }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.status', 'Status') }}
            </span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ statusLabel }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.repository', 'Repositório') }}
            </span>
            <strong class="mt-0.5 block break-all text-sm font-semibold text-slate-950">{{ repositoryName }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.author', 'Autor') }}
            </span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">
              {{ currentIssue?.authorLogin || translateWorkItem('github.meta.unknownAuthor', 'desconhecido') }}
            </strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.updatedAt', 'Atualizada') }}
            </span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentIssue?.updatedAt) }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.createdAt', 'Criada em') }}
            </span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentIssue?.createdAt) }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-white/90 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.assignees', 'Responsáveis') }}
            </span>
            <strong class="mt-0.5 block break-words text-sm font-semibold text-slate-950">
              {{ currentIssue?.assignees?.length
                ? currentIssue.assignees.map((assignee) => assignee.name || assignee.login).join(', ')
                : translateWorkItem('local.responsibles.none', 'Sem responsável') }}
            </strong>
          </div>
        </div>

        <div v-if="currentIssueParent || hasSubIssues" class="grid gap-2 md:grid-cols-2">
          <div
            v-if="currentIssueParent"
            class="rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm text-cyan-800"
          >
            {{ translateWorkItem('github.parentIssue.prefix', 'Esta issue é uma sub-issue de') }}
            <button
              type="button"
              class="font-bold underline text-cyan-800"
              @click="openGithubIssueInOctoFlow(currentIssueParent.id)"
            >
              #{{ currentIssueParent.number }} {{ currentIssueParent.title }}
            </button>{{ '.' }}
          </div>

          <div
            v-if="hasSubIssues"
            class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
          >
            {{ translateWorkItem('github.subIssues.summary', '{total} sub-issue(s) vinculada(s), sendo {open} ainda aberta(s).', {
              total: subIssues.length,
              open: openSubIssuesCount,
            }) }}
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="app-btn"
            :class="activePanel === 'view' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="activePanel = 'view'"
          >
            {{ translateWorkItem('github.tabs.view', 'Visualização') }}
          </button>
          <button
            v-if="canEdit"
            type="button"
            class="app-btn"
            :class="activePanel === 'edit' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="activePanel = 'edit'"
          >
            {{ translateWorkItem('github.tabs.edit', 'Atualização') }}
          </button>
          <button
            type="button"
            class="app-btn"
            :class="activePanel === 'subtasks' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="activePanel = 'subtasks'"
          >
            {{ translateWorkItem('github.tabs.subIssues', 'Subissues') }}
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
              {{ translateWorkItem('github.viewTabs.description', 'Descrição') }}
            </button>
            <button
              type="button"
              class="app-btn"
              :class="githubViewTab === 'history' ? 'app-btn-tab-active' : 'app-btn-secondary'"
              @click="githubViewTab = 'history'"
            >
              {{ translateWorkItem('github.viewTabs.history', 'Histórico') }}
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
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                  {{ translateWorkItem('github.description.kicker', 'Descrição') }}
                </p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                  {{ translateWorkItem('github.description.title', 'Conteúdo formatado') }}
                </h3>
              </div>

              <div class="mt-4 overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
                <MarkdownPreview
                  :content="currentIssue?.body || ''"
                  :empty-label="translateWorkItem('github.description.emptyMarkdown', 'Nenhuma descrição em Markdown foi informada para esta issue.')"
                />
              </div>
            </div>

            <p
              v-if="!canEdit"
              class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
            >
              {{ translateWorkItem('github.errors.readOnly', 'Sua conta consegue visualizar esta issue, mas não tem permissão para atualizá-la.') }}
            </p>
          </article>

          <article v-else-if="githubViewTab === 'history'" class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                {{ translateWorkItem('github.history.kicker', 'Histórico') }}
              </p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                {{ translateWorkItem('github.history.title', 'Linha do tempo da issue') }}
              </h3>
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
                      {{ entry.actorLogin || translateWorkItem('github.history.systemActor', 'Sistema') }}
                    </strong>
                  </div>

                  <div class="flex items-center gap-3">
                    <span class="text-xs font-medium text-slate-500">{{ formatDateTime(entry.createdAt) }}</span>
                    <button
                      v-if="entry.octoflowIssueId"
                      type="button"
                      class="text-xs font-semibold text-cyan-700 underline"
                      @click="openGithubIssueInOctoFlow(entry.octoflowIssueId)"
                    >
                      {{ translateWorkItem('github.actions.openOctoFlow', 'Abrir no OctoFlow') }}
                    </button>
                    <a
                      v-else-if="entry.url"
                      class="text-xs font-semibold text-cyan-700 underline"
                      :href="entry.url"
                      target="_blank"
                      rel="noreferrer noopener"
                    >
                      {{ translateWorkItem('github.actions.open', 'Abrir') }}
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
              {{ translateWorkItem('github.history.empty', 'Nenhum histórico adicional foi encontrado para esta issue.') }}
            </p>
          </article>

        </div>

        <div
          v-else-if="activePanel === 'edit'"
          class="grid gap-5 xl:grid-cols-[minmax(0,1.08fr),minmax(320px,0.92fr)]"
        >
          <article class="grid gap-4">
            <form class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft" @submit.prevent="saveIssue">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                  {{ translateWorkItem('github.edit.kicker', 'Modelos de atualização') }}
                </p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                  {{ translateWorkItem('github.edit.title', 'Escolha o template da atualização') }}
                </h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">
                  {{ translateWorkItem('github.edit.description', 'O sistema carrega o template selecionado e monta a atualização no preview ao lado.') }}
                </p>
              </div>

              <div v-if="props.updateTemplates.length" class="grid gap-2">
                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">
                    {{ translateWorkItem('github.edit.selectedTemplate', 'Template selecionado') }}
                  </span>
                  <select v-model="selectedTemplateKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                    <option value="">{{ translateWorkItem('github.edit.selectTemplate', 'Selecionar template') }}</option>
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
                {{ translateWorkItem('github.edit.emptyTemplates', 'Nenhum template de atualização foi configurado.') }}
              </p>

              <div
                v-if="selectedTemplate"
                class="grid gap-4 rounded-[24px] border border-slate-200/80 bg-white/80 p-5"
              >
                <div>
                  <p class="text-sm font-semibold text-slate-900">
                    {{ translateWorkItem('github.edit.templateFields', 'Campos do template') }}
                  </p>
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
                        :placeholder="field.placeholder || translateWorkItem('github.edit.listPlaceholder', 'Um item por linha.')"
                        class="min-h-[120px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      />
                      <small class="text-sm text-slate-500">
                        {{ translateWorkItem('github.edit.listHelper', 'Use uma linha por item. O preview vira lista automaticamente.') }}
                      </small>
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
                <span class="text-sm font-semibold text-slate-900">{{ translateWorkItem('github.edit.labels', 'Tags') }}</span>
                <RemoteMultiSelect
                  v-model="selectedLabelsModel"
                  :options="availableLabelOptions"
                  option-label-key="name"
                  option-value-key="id"
                  option-color-key="color"
                  option-description-key="description"
                  :search-placeholder="translateWorkItem('github.edit.labelsSearchPlaceholder', 'Pesquisar tags padrão')"
                  :helper-text="translateWorkItem('github.edit.labelsHelper', 'Use a busca para filtrar tags. Também é possível criar novas tags.')"
                  :selected-count-suffix="translateWorkItem('github.edit.labelsSelectedSuffix', 'tag(s) selecionada(s)')"
                  :create-label-prefix="translateWorkItem('github.edit.createLabelPrefix', 'Criar tag')"
                  :create-helper-text="translateWorkItem('github.edit.createLabelHelper', 'A nova tag será criada ao salvar.')"
                  :existing-option-helper-text="translateWorkItem('github.edit.existingLabelHelper', 'Tag existente')"
                  :empty-idle-text="translateWorkItem('github.edit.emptyLabelIdle', 'Digite para buscar tags ou criar uma nova.')"
                  :empty-search-text="translateWorkItem('github.edit.emptyLabelSearch', 'Nenhuma tag encontrada para essa busca.')"
                  :empty-create-text="translateWorkItem('github.edit.emptyLabelCreate', 'Pressione Enter para criar essa tag.')"
                />
              </label>

              <label class="grid gap-2 sm:max-w-xs">
                <span class="text-sm font-semibold text-slate-900">{{ translateWorkItem('github.meta.status', 'Status') }}</span>
                <select
                  v-model="form.state"
                  :disabled="!canEdit || saving"
                  class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
                >
                  <option value="OPEN">{{ translateWorkItem('labels.open', 'Aberta') }}</option>
                  <option value="CLOSED">{{ translateWorkItem('labels.closed', 'Fechada') }}</option>
                </select>
              </label>

              <label v-if="shouldShowStandaloneCollaboratorSelect" class="grid gap-2 sm:max-w-lg">
                <span class="text-sm font-semibold text-slate-900">
                  {{ translateWorkItem('github.edit.assignCollaborator', 'Atribuir colaborador') }}
                </span>
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
                  {{ saving
                    ? translateWorkItem('github.actions.saving', 'Salvando...')
                    : translateWorkItem('github.actions.saveIssue', 'Salvar issue') }}
                </button>
                <button
                  type="button"
                  :disabled="saving"
                  class="app-btn app-btn-secondary"
                  @click="resetIssueForm"
                >
                  {{ translateWorkItem('github.actions.clearForm', 'Limpar formulário') }}
                </button>
              </div>
            </form>

          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                {{ translateWorkItem('github.preview.kicker', 'Preview Markdown') }}
              </p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                {{ translateWorkItem('github.preview.title', 'Resultado final da atualização') }}
              </h3>
              <p class="mt-2 text-sm text-slate-500">
                {{ translateWorkItem('github.preview.description', 'O preview abaixo considera a descrição atual da issue e o modelo de atualização preenchido.') }}
              </p>
            </div>

            <div class="min-h-[540px] overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
              <MarkdownPreview
                :content="finalBody"
                :empty-label="translateWorkItem('github.preview.empty', 'Preencha os campos do modelo para gerar a atualização.')"
              />
            </div>
          </article>
        </div>

        <div v-else class="grid gap-5">
          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 app-depth-soft">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                  {{ translateWorkItem('github.subIssues.kicker', 'Subissues') }}
                </p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                  {{ translateWorkItem('github.subIssues.title', 'Decomposição da issue principal') }}
                </h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">
                  {{ translateWorkItem('github.subIssues.description', 'As sub-issues herdam o mesmo repositório e os mesmos projetos da issue pai.') }}
                </p>
              </div>

              <button
                type="button"
                class="app-btn app-btn-secondary"
                :disabled="creatingSubIssues || !canCreateSubIssues"
                @click="addSubIssueDraft"
              >
                {{ translateWorkItem('github.subIssues.actions.new', 'Nova sub-issue') }}
              </button>
            </div>

            <div v-if="hasSubIssues" class="grid gap-3">
              <article
                v-for="subIssue in subIssues"
                :key="subIssue.id"
                class="rounded-2xl border border-slate-200 bg-white px-4 py-4"
              >
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <span
                        class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="subIssueStatusClass(subIssue.state)"
                      >
                        {{ subIssueStatusLabel(subIssue.state) }}
                      </span>
                      <strong class="break-words text-sm font-semibold text-slate-900">
                        #{{ subIssue.number }} {{ subIssue.title }}
                      </strong>
                    </div>

                    <p class="mt-2 text-sm text-slate-500">
                      {{ translateWorkItem('github.subIssues.responsible', 'Responsável') }}:
                      {{ subIssue.assignees?.length
                        ? subIssue.assignees.map((assignee) => assignee.name || assignee.login).join(', ')
                        : translateWorkItem('local.responsibles.none', 'Sem responsável') }}
                    </p>
                  </div>

                  <div class="flex items-center gap-3">
                    <button
                      type="button"
                      class="text-sm font-semibold text-cyan-700 underline"
                      @click="openGithubIssueInOctoFlow(subIssue.id)"
                    >
                      {{ translateWorkItem('github.actions.openOctoFlow', 'Abrir no OctoFlow') }}
                    </button>
                    <a
                      v-if="subIssue.url"
                      class="text-sm font-semibold text-cyan-700 underline"
                      :href="subIssue.url"
                      target="_blank"
                      rel="noreferrer noopener"
                    >
                      {{ translateWorkItem('github.actions.openGithubShort', 'GitHub') }}
                    </a>
                  </div>
                </div>
              </article>
            </div>

            <p
              v-else
              class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
            >
              {{ translateWorkItem('github.subIssues.empty', 'Nenhuma sub-issue foi criada para esta issue ainda.') }}
            </p>

            <p
              v-if="!canCreateSubIssues"
              class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800"
            >
              {{ currentIssueParent
                ? translateWorkItem('errors.subIssueCannotHaveChildren', 'Esta issue já é uma sub-issue e, por regra, não pode receber filhos.')
                : translateWorkItem('errors.subIssuePermissionDenied', 'Sua conta não possui permissão para criar sub-issues nesta issue.') }}
            </p>

            <div class="grid gap-4">
              <article
                v-for="(draft, index) in subIssueDrafts"
                :key="draft.key"
                class="grid gap-4 rounded-[24px] border border-slate-200/80 bg-white/80 p-5"
              >
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p class="text-sm font-semibold text-slate-900">
                      {{ translateWorkItem('github.subIssues.cardTitle', 'Sub-issue {index}', { index: index + 1 }) }}
                    </p>
                    <p class="text-sm text-slate-500">
                      {{ translateWorkItem('github.subIssues.cardDescription', 'A issue será criada no mesmo repositório da tarefa principal.') }}
                    </p>
                  </div>

                  <button
                    type="button"
                    class="app-btn app-btn-secondary"
                    :disabled="creatingSubIssues"
                    @click="removeSubIssueDraft(draft.key)"
                  >
                    {{ translateWorkItem('github.actions.remove', 'Remover') }}
                  </button>
                </div>

                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">{{ translateWorkItem('github.subIssues.titleLabel', 'Título') }}</span>
                  <input
                    v-model="draft.title"
                    type="text"
                    :placeholder="translateWorkItem('github.subIssues.titlePlaceholder', 'Ex.: Ajustar validação de CPF no cadastro')"
                    class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                  >
                </label>

                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">
                    {{ translateWorkItem('github.subIssues.templateLabel', 'Template de criação') }}
                  </span>
                  <select
                    :value="draft.templateKey"
                    :disabled="creatingSubIssues"
                    class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                    @change="draft.templateKey = $event.target.value; handleSubIssueTemplateChange(draft)"
                  >
                    <option value="">{{ translateWorkItem('github.subIssues.createWithoutTemplate', 'Criar sem template') }}</option>
                    <option
                      v-for="template in availableSubIssueTemplates"
                      :key="`${draft.key}-${template.key}`"
                      :value="template.key"
                    >
                      {{ template.name }}
                    </option>
                  </select>
                </label>

                <div
                  v-if="findSubIssueTemplate(draft.templateKey)"
                  class="grid gap-4 rounded-[24px] border border-slate-200/80 bg-white/80 p-5"
                >
                  <div>
                    <p class="text-sm font-semibold text-slate-900">
                      {{ translateWorkItem('github.edit.templateFields', 'Campos do template') }}
                    </p>
                    <p class="text-sm text-slate-500">{{ findSubIssueTemplate(draft.templateKey)?.description }}</p>
                  </div>

                  <div class="grid gap-4 md:grid-cols-2">
                    <template
                      v-for="field in findSubIssueTemplate(draft.templateKey)?.fields || []"
                      :key="`${draft.key}-${field.key}`"
                    >
                      <label v-if="field.type === 'textarea'" class="grid gap-2 md:col-span-2">
                        <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                        <textarea
                          :value="draft.templateFieldValues?.[field.key] || ''"
                          rows="5"
                          :placeholder="field.placeholder || ''"
                          class="min-h-[132px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                          @input="updateSubIssueTemplateField(draft, field.key, $event.target.value)"
                        />
                      </label>

                      <label v-else-if="field.type === 'list'" class="grid gap-2 md:col-span-2">
                        <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                        <textarea
                          :value="draft.templateFieldValues?.[field.key] || ''"
                          rows="4"
                          :placeholder="field.placeholder || translateWorkItem('github.edit.listPlaceholder', 'Um item por linha.')"
                          class="min-h-[120px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                          @input="updateSubIssueTemplateField(draft, field.key, $event.target.value)"
                        />
                      </label>

                      <label v-else-if="field.type === 'select'" class="grid gap-2">
                        <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                        <select
                          :value="draft.templateFieldValues?.[field.key] || ''"
                          class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                          @change="updateSubIssueTemplateField(draft, field.key, $event.target.value)"
                        >
                          <option value="">{{ translateWorkItem('labels.select', 'Selecione') }}</option>
                          <option v-for="option in field.options || []" :key="option.value" :value="option.value">
                            {{ option.label }}
                          </option>
                        </select>
                      </label>

                      <label v-else class="grid gap-2">
                        <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                        <input
                          :value="draft.templateFieldValues?.[field.key] || ''"
                          type="text"
                          :placeholder="field.placeholder || ''"
                          class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                          @input="updateSubIssueTemplateField(draft, field.key, $event.target.value)"
                        >
                      </label>
                    </template>
                  </div>
                </div>

                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">{{ translateWorkItem('github.subIssues.descriptionLabel', 'Descrição') }}</span>
                  <textarea
                    v-model="draft.body"
                    rows="4"
                    :placeholder="translateWorkItem('github.subIssues.descriptionPlaceholder', 'Descreva o escopo específico desta sub-issue.')"
                    class="min-h-[120px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                  />
                </label>

                <div class="grid gap-4 md:grid-cols-2">
                  <label class="grid gap-2">
                    <span class="text-sm font-semibold text-slate-900">
                      {{ translateWorkItem('github.subIssues.assigneeLabel', 'Responsável') }}
                    </span>
                    <select
                      v-model="draft.assigneeId"
                      :disabled="creatingSubIssues"
                      class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                    >
                      <option value="">{{ assignablePlaceholderLabel }}</option>
                      <option
                        v-for="assignableUser in assignableUsers"
                        :key="`${draft.key}-${assignableUser.id || assignableUser.login}`"
                        :value="assignableUser.id"
                      >
                        {{ assignableUser.name ? `${assignableUser.name} (${assignableUser.login})` : assignableUser.login }}
                      </option>
                    </select>
                  </label>

                  <label class="grid gap-2">
                    <span class="text-sm font-semibold text-slate-900">
                      {{ translateWorkItem('github.subIssues.dueDateLabel', 'Data de entrega') }}
                    </span>
                    <input
                      v-model="draft.dueDate"
                      type="date"
                      :disabled="creatingSubIssues"
                      class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                    >
                  </label>
                </div>
              </article>
            </div>

            <p class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-600">
              {{ translateWorkItem('github.subIssues.dueDateHint', 'Se a issue principal tiver um \"Prazo desejado\", nenhuma sub-issue pode ultrapassar essa data.') }}
            </p>

            <p
              v-if="subIssueError"
              class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
            >
              {{ subIssueError }}
            </p>

            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                :disabled="creatingSubIssues || !canCreateSubIssues"
                class="app-btn app-btn-primary"
                @click="saveSubIssues"
              >
                {{ creatingSubIssues
                  ? translateWorkItem('github.subIssues.actions.creating', 'Criando...')
                  : translateWorkItem('github.subIssues.actions.create', 'Criar sub-issues') }}
              </button>
              <button
                type="button"
                :disabled="creatingSubIssues"
                class="app-btn app-btn-secondary"
                @click="resetSubIssueComposer"
              >
                {{ translateWorkItem('github.subIssues.actions.clear', 'Limpar subtarefas') }}
              </button>
            </div>
          </article>
        </div>
      </div>
    </template>

    <template v-else-if="isLocalMode">
      <header class="flex flex-col gap-3 border-b border-slate-200/80 px-5 py-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
              {{ translateWorkItem('local.header.kicker', 'Tarefa local') }}
            </p>
            <h2 id="work-item-edit-modal-local-title" class="mt-1 text-xl font-semibold text-slate-950 sm:text-2xl">
              {{ localCurrentTask?.title || translateWorkItem('local.header.title', 'Editar tarefa local') }}
            </h2>
          </div>

          <button type="button" class="app-btn app-btn-secondary" :disabled="isWorkItemBusy" @click="requestClose">
            {{ translateWorkItem('github.actions.close', 'Fechar') }}
          </button>
        </div>

        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.status', 'Status') }}
            </span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ localSyncStatusLabel }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.repository', 'Repositório') }}
            </span>
            <strong class="mt-0.5 block break-all text-sm font-semibold text-slate-950">
              {{ localCurrentTask?.repositoryKey || translateWorkItem('local.repository.defineLater', 'Definir depois') }}
            </strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.author', 'Autor') }}
            </span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ localAuthorLabel }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.updatedAt', 'Atualizada') }}
            </span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ formatDateTime(localCurrentTask?.updatedAt) }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.createdAt', 'Criada em') }}
            </span>
            <strong class="mt-0.5 block text-sm font-semibold text-slate-950">{{ formatDateTime(localCurrentTask?.createdAt) }}</strong>
          </div>
          <div class="flex min-h-[68px] flex-col items-center justify-center rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-1.5 text-center">
            <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
              {{ translateWorkItem('github.meta.assignees', 'Responsáveis') }}
            </span>
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
            {{ translateWorkItem('github.tabs.view', 'Visualização') }}
          </button>
          <button
            type="button"
            class="app-btn"
            :class="activePanel === 'edit' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="activePanel = 'edit'"
          >
            {{ translateWorkItem('github.tabs.edit', 'Atualização') }}
          </button>
          <button
            type="button"
            class="app-btn"
            :class="activePanel === 'subtasks' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="activePanel = 'subtasks'"
          >
            {{ translateWorkItem('local.tabs.subTasks', 'SubTarefas') }}
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
            {{ translateWorkItem('github.viewTabs.description', 'Descrição') }}
          </button>
          <button
            type="button"
            class="app-btn"
            :class="localViewTab === 'history' ? 'app-btn-tab-active' : 'app-btn-secondary'"
            @click="localViewTab = 'history'"
          >
            {{ translateWorkItem('github.viewTabs.history', 'Histórico') }}
          </button>
        </div>

        <div v-if="activePanel !== 'subtasks'" class="grid gap-5 xl:grid-cols-[minmax(0,1.05fr),minmax(320px,0.95fr)]">
          <article v-if="activePanel === 'view' && localViewTab === 'description'" class="app-panel-standard grid gap-4 rounded-[28px] p-5">
            <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
              <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                {{ translateWorkItem('github.edit.labels', 'Tags') }}
              </span>
              <div v-if="localPreviewLabelNames.length > 0" class="mt-2 flex flex-wrap gap-2">
                <span
                  v-for="labelName in localPreviewLabelNames"
                  :key="`local-task-preview-label-${labelName}`"
                  class="app-chip rounded-full px-3 py-1 text-xs font-semibold"
                >
                  {{ labelName }}
                </span>
              </div>
              <strong v-else class="mt-2 block text-sm font-semibold text-slate-950">
                {{ translateWorkItem('local.labels.empty', 'Sem tags definidas') }}
              </strong>
            </div>

            <article class="rounded-[24px] border border-slate-200/80 bg-white/80 p-5">
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                {{ translateWorkItem('local.summary.kicker', 'Resumo') }}
              </p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                {{ translateWorkItem('local.summary.title', 'Informações da tarefa') }}
              </h3>
              <div class="mt-4 grid gap-3">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                  <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ translateWorkItem('local.summary.titleLabel', 'Título') }}
                  </span>
                  <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ localPreviewTitle }}</strong>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                  <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                    {{ translateWorkItem('local.summary.contentLabel', 'Conteúdo') }}
                  </span>
                  <p class="mt-2 line-clamp-5 text-sm leading-6 text-slate-700">
                    {{ localCurrentTask?.body || translateWorkItem('local.preview.emptyBody', 'Sem conteúdo para visualizar.') }}
                  </p>
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
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                {{ translateWorkItem('github.history.kicker', 'Histórico') }}
              </p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                {{ translateWorkItem('local.history.title', 'Linha do tempo da tarefa local') }}
              </h3>
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
                      {{ translateWorkItem('github.actions.open', 'Abrir') }}
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
              {{ translateWorkItem('local.history.empty', 'Ainda não existe histórico suficiente para esta tarefa local.') }}
            </p>
          </article>

          <article v-else-if="activePanel === 'edit'" class="app-panel-standard grid gap-4 rounded-[28px] p-5">
            <div class="grid gap-3 md:grid-cols-3">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                  {{ translateWorkItem('local.status.label', 'Status local') }}
                </span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ localSyncStatusLabel }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                  {{ translateWorkItem('github.meta.createdAt', 'Criada em') }}
                </span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(localCurrentTask?.createdAt) }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                  {{ translateWorkItem('local.meta.updatedAt', 'Atualizada em') }}
                </span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(localCurrentTask?.updatedAt) }}</strong>
              </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                  {{ translateWorkItem('local.meta.template', 'Template') }}
                </span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">
                  {{ localCurrentTask?.templateKey || translateWorkItem('local.template.custom', 'personalizado') }}
                </strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                  {{ translateWorkItem('local.meta.suggestedRepository', 'Repositório sugerido') }}
                </span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ localSuggestedRepositoryLabel }}</strong>
              </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
              <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                {{ translateWorkItem('github.edit.labels', 'Tags') }}
              </span>
              <div v-if="localPreviewLabelNames.length > 0" class="mt-2 flex flex-wrap gap-2">
                <span
                  v-for="labelName in localPreviewLabelNames"
                  :key="`local-task-current-label-${labelName}`"
                  class="app-chip rounded-full px-3 py-1 text-xs font-semibold"
                >
                  {{ labelName }}
                </span>
              </div>
              <strong v-else class="mt-2 block text-sm font-semibold text-slate-950">
                {{ translateWorkItem('local.labels.empty', 'Sem tags definidas') }}
              </strong>
            </div>

            <div v-if="localLoading" class="app-empty-panel rounded-2xl px-4 py-6 text-sm text-slate-500">
              {{ translateWorkItem('local.loadingDetails', 'Carregando detalhes da tarefa local...') }}
            </div>

            <template v-else>
              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">
                  {{ translateWorkItem('local.summary.titleLabel', 'Título') }}
                </span>
                <input v-model="localForm.title" type="text" class="app-field-control h-11 px-3 text-sm text-slate-900">
              </label>

              <div v-if="props.updateTemplates.length" class="grid gap-2">
                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">
                    {{ translateWorkItem('local.edit.templateLabel', 'Template da atualização') }}
                  </span>
                  <select v-model="localSelectedTemplateKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                    <option value="">{{ translateWorkItem('github.edit.selectTemplate', 'Selecionar template') }}</option>
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
                {{ translateWorkItem('github.edit.emptyTemplates', 'Nenhum template de atualização foi configurado.') }}
              </p>

              <div
                v-if="localSelectedUpdateTemplate"
                class="grid gap-4 rounded-[24px] border border-slate-200/80 bg-white/80 p-5"
              >
                <div>
                  <p class="text-sm font-semibold text-slate-900">
                    {{ translateWorkItem('local.edit.updateFields', 'Campos da atualização') }}
                  </p>
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
                        :placeholder="field.placeholder || translateWorkItem('github.edit.listPlaceholder', 'Um item por linha.')"
                        class="min-h-[120px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      />
                      <small class="text-sm text-slate-500">
                        {{ translateWorkItem('github.edit.listHelper', 'Use uma linha por item. O preview vira lista automaticamente.') }}
                      </small>
                    </label>

                    <label v-else-if="field.type === 'select'" class="grid gap-2">
                      <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                      <select
                        v-model="localTemplateFieldValues[field.key]"
                        class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                      >
                        <option value="">{{ translateWorkItem('labels.select', 'Selecione') }}</option>
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
                <span class="text-sm font-semibold text-slate-900">{{ translateWorkItem('github.edit.labels', 'Tags') }}</span>
                <RemoteMultiSelect
                  v-model="localSelectedLabelsModel"
                  :options="localAvailableLabelOptions"
                  option-label-key="name"
                  option-value-key="id"
                  option-color-key="color"
                  option-description-key="description"
                  :search-placeholder="translateWorkItem('github.edit.labelsSearchPlaceholder', 'Pesquisar tags padrão')"
                  :helper-text="translateWorkItem('github.edit.labelsHelper', 'Use a busca para filtrar tags. Também é possível criar novas tags.')"
                  :selected-count-suffix="translateWorkItem('github.edit.labelsSelectedSuffix', 'tag(s) selecionada(s)')"
                  :create-label-prefix="translateWorkItem('github.edit.createLabelPrefix', 'Criar tag')"
                  :create-helper-text="translateWorkItem('github.edit.createLabelHelper', 'A nova tag será criada ao salvar.')"
                  :existing-option-helper-text="translateWorkItem('github.edit.existingLabelHelper', 'Tag existente')"
                  :empty-idle-text="translateWorkItem('github.edit.emptyLabelIdle', 'Digite para buscar tags ou criar uma nova.')"
                  :empty-search-text="translateWorkItem('github.edit.emptyLabelSearch', 'Nenhuma tag encontrada para essa busca.')"
                  :empty-create-text="translateWorkItem('github.edit.emptyLabelCreate', 'Pressione Enter para criar essa tag.')"
                />
              </label>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">
                  {{ translateWorkItem('local.meta.suggestedRepositoryAfter', 'Repositório sugerido para depois') }}
                </span>
                <select v-model="localForm.repositoryKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="">{{ translateWorkItem('local.repository.defineLater', 'Definir depois') }}</option>
                  <option v-for="repositoryKey in localAvailableRepositories" :key="repositoryKey" :value="repositoryKey">
                    {{ repositoryKey }}
                  </option>
                </select>
              </label>

              <label class="grid gap-2 sm:max-w-xs">
                <span class="text-sm font-semibold text-slate-900">{{ translateWorkItem('github.meta.status', 'Status') }}</span>
                <select v-model="localForm.state" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="OPEN">{{ translateWorkItem('labels.open', 'Aberta') }}</option>
                  <option value="CLOSED">{{ translateWorkItem('labels.closed', 'Fechada') }}</option>
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
                  {{ localSaving
                    ? translateWorkItem('github.actions.saving', 'Salvando...')
                    : translateWorkItem('local.actions.saveChanges', 'Salvar alterações') }}
                </button>
                <button type="button" class="app-btn app-btn-secondary" :disabled="localSaving || localSyncing" @click="resetLocalTemplateInputs">
                  {{ translateWorkItem('github.actions.clearForm', 'Limpar formulário') }}
                </button>
              </div>
            </template>
          </article>

          <div v-if="activePanel === 'edit' || localViewTab === 'description'" class="grid gap-4">
            <article v-if="activePanel === 'edit'" class="app-panel-standard grid gap-4 rounded-[28px] p-5">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                  {{ translateWorkItem('local.preview.kicker', 'Conteúdo formatado') }}
                </p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                  {{ translateWorkItem('local.preview.title', 'Preview da tarefa local') }}
                </h3>
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
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                  {{ translateWorkItem('github.history.kicker', 'Histórico') }}
                </p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                  {{ translateWorkItem('local.history.title', 'Linha do tempo da tarefa local') }}
                </h3>
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
                        {{ translateWorkItem('github.actions.open', 'Abrir') }}
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
                {{ translateWorkItem('local.history.empty', 'Ainda não existe histórico suficiente para esta tarefa local.') }}
              </p>
            </article>

            <aside class="app-panel-standard grid gap-4 rounded-[28px] p-5">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                  {{ translateWorkItem('local.sync.kicker', 'Sincronização') }}
                </p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                  {{ translateWorkItem('local.sync.title', 'Enviar ao GitHub') }}
                </h3>
                <p class="mt-2 text-sm leading-6 text-slate-500">
                  {{ translateWorkItem('local.sync.description', 'A sincronização manual sempre pede o repositório de destino. Nada é publicado automaticamente.') }}
                </p>
              </div>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">
                  {{ translateWorkItem('local.sync.repositoryLabel', 'Repositório para publicar agora') }}
                </span>
                <select v-model="localSyncRepositoryKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="">{{ translateWorkItem('local.sync.repositoryPlaceholder', 'Selecionar repositório') }}</option>
                  <option v-for="repositoryKey in localAvailableRepositories" :key="repositoryKey" :value="repositoryKey">
                    {{ repositoryKey }}
                  </option>
                </select>
              </label>

              <div v-if="localAvailableRepositories.length === 0" class="app-empty-panel rounded-2xl px-4 py-6 text-sm text-slate-500">
                {{ translateWorkItem('local.sync.emptyRepositories', 'Nenhum repositório GitHub disponível para sincronização no momento.') }}
              </div>

              <button
                type="button"
                class="app-btn app-btn-secondary"
                :disabled="localSyncing || localSaving || localAvailableRepositories.length === 0"
                @click="syncLocalTask"
              >
                {{ localSyncing
                  ? translateWorkItem('local.sync.sending', 'Enviando ao GitHub...')
                  : translateWorkItem('local.sync.sendButton', 'Enviar ao GitHub') }}
              </button>
            </aside>
          </div>

        </div>

        <article
          v-else
          class="app-panel-standard grid gap-4 rounded-[28px] p-5"
        >
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
              {{ translateWorkItem('local.subTasks.kicker', 'SubTarefas') }}
            </p>
            <h3 class="mt-1 text-2xl font-semibold text-slate-950">
              {{ translateWorkItem('local.subTasks.title', 'Organização por subtarefas') }}
            </h3>
            <p class="mt-2 text-sm leading-6 text-slate-500">
              {{ translateWorkItem('local.subTasks.description', 'O fluxo de subtarefas para tarefas locais ainda não foi implementado. Esta aba foi preparada para manter a navegação consistente com as issues do GitHub.') }}
            </p>
          </div>

          <div class="rounded-[24px] border border-dashed border-slate-300 bg-slate-50/80 px-5 py-8 text-sm text-slate-600">
            {{ translateWorkItem('local.subTasks.futureInfo', 'Quando esse módulo evoluir, as subtarefas locais poderão ser exibidas e gerenciadas aqui.') }}
          </div>
        </article>
      </div>
    </template>
  </TaskModalShell>
</template>
