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
  ref,
  watch,
} from 'vue'
import { useI18n } from '../../composables/useI18n'
import {
  RemoteIssueTemplateForm,
  RemoteIssueTemplatePreview,
} from '../../federation/remoteComponents'
import { useIssueCreateComposer } from '../../composables/useIssueCreateComposer'
import { fetchGithubWorkspace } from '../../services/githubWorkspace'
import { createGithubIssue, createLocalTask, fetchTaskTemplates } from '../../services/tasks'
import { splitRepositoryKey } from '../../utils/githubRepository'
import { extractHttpMessage } from '../../utils/httpErrors'
import { formatDateTime } from '../../utils/date'
import {
  buildSubmissionFields,
  formatTemplateTitle,
  resolveSelectLabel,
} from '../../utils/issueTemplate'
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
import TaskModalShell from './TaskModalShell.vue'

const props = defineProps({
  currentUser: {
    type: Object,
    default: null,
  },
  repositories: {
    type: Array,
    default: () => [],
  },
  initialRepositoryKey: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['close', 'issue-created'])
const { translate } = useI18n()

const CREATE_TITLE_MAX_LENGTH = 220
const CREATE_FIELD_TEXT_MAX_LENGTH = 5000
const CREATE_FIELD_LINE_MAX_LENGTH = 420
const CREATE_LABEL_MAX_LENGTH = 80

const loadingWorkspace = ref(false)
const loadingTemplates = ref(false)
const workspaceError = ref('')
const templatesError = ref('')
const workspace = ref(null)
const localTemplates = ref([])
const submitting = ref(false)
const submitError = ref('')
const selectedAssigneeId = ref('')
const selectedRepositoryKey = ref('')
const syncingRepositoryKey = ref(false)
const creationMode = ref('github')
const templatePickerMode = ref('select')
const applyingStoredDraft = ref(false)
const shouldPersistDraftOnUnmount = ref(true)
const modalWasDeactivated = ref(false)
const templateCountBeforeUpdate = ref(0)
const runtimeError = ref('')
let componentDisposed = false

const TASK_DRAFT_STORAGE_PREFIX = 'octoflow.tasks.'

function translateWithFallback(messageKey, fallbackMessage, variables = {}) {
  const translatedMessage = translate(messageKey, variables)
  return translatedMessage === messageKey ? fallbackMessage : translatedMessage
}

function translateIssue(messageKey, fallbackMessage, variables = {}) {
  return translateWithFallback(`tasks.issueCreateModal.${messageKey}`, fallbackMessage, variables)
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

function sanitizeMultiLineText(rawValue, maxLength = CREATE_FIELD_TEXT_MAX_LENGTH) {
  return replaceControlCharactersWithSpaces(rawValue)
    .replace(/\r\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
    .slice(0, maxLength)
}

function sanitizeIdentifier(rawValue, maxLength = 120) {
  const normalizedIdentifier = sanitizeSingleLineText(rawValue, maxLength)
  return /^[a-zA-Z0-9_.:-]+$/.test(normalizedIdentifier)
    ? normalizedIdentifier
    : ''
}

function sanitizeLabelNames(rawLabelNames = []) {
  if (!Array.isArray(rawLabelNames)) {
    return []
  }

  return rawLabelNames
    .map((rawLabelName) => sanitizeSingleLineText(rawLabelName, CREATE_LABEL_MAX_LENGTH))
    .filter((labelName) => labelName !== '')
}

function sanitizeSubmissionFieldsForTemplate(template, rawSubmissionFields = {}) {
  const normalizedSubmissionFields = {}
  const templateFields = Array.isArray(template?.fields) ? template.fields : []

  for (const templateField of templateFields) {
    const templateFieldKey = sanitizeSingleLineText(templateField?.key, 80)
    if (templateFieldKey === '') {
      continue
    }

    const rawFieldValue = rawSubmissionFields[templateFieldKey]

    if (templateField.type === 'list') {
      const normalizedListItems = Array.isArray(rawFieldValue)
        ? rawFieldValue
          .map((listItem) => sanitizeSingleLineText(listItem, CREATE_FIELD_LINE_MAX_LENGTH))
          .filter((listItem) => listItem !== '')
        : []

      normalizedSubmissionFields[templateFieldKey] = normalizedListItems
      continue
    }

    if (templateField.type === 'textarea') {
      normalizedSubmissionFields[templateFieldKey] = sanitizeMultiLineText(rawFieldValue)
      continue
    }

    normalizedSubmissionFields[templateFieldKey] = sanitizeSingleLineText(rawFieldValue, CREATE_FIELD_LINE_MAX_LENGTH)
  }

  return normalizedSubmissionFields
}

function ensureComponentIsActive() {
  return !componentDisposed
}

const availableRepositories = computed(() => {
  const catalog = new Map()

  for (const repository of props.repositories || []) {
    const key = String(repository?.nameWithOwner || '').trim()
    if (key !== '' && !catalog.has(key)) {
      catalog.set(key, repository)
    }
  }

  const workspaceRepository = workspace.value?.repository
  const workspaceKey = String(workspaceRepository?.nameWithOwner || '').trim()
  if (workspaceKey !== '' && !catalog.has(workspaceKey)) {
    catalog.set(workspaceKey, workspaceRepository)
  }

  return Array.from(catalog.values())
})
const canUseGithubMode = computed(() => availableRepositories.value.length > 0)
const isLocalMode = computed(() => creationMode.value === 'local')
const templates = computed(() => {
  if (isLocalMode.value) {
    return Array.isArray(localTemplates.value) ? localTemplates.value : []
  }

  return Array.isArray(workspace.value?.templates) ? workspace.value.templates : []
})
const repository = computed(() => workspace.value?.repository || null)
const labels = computed(() => Array.isArray(repository.value?.labels) ? repository.value.labels : [])
const labelOptions = computed(() => buildMergedProjectLabelOptions(labels.value))
const {
  fieldValues,
  selectedLabels,
  selectedTemplate,
  selectedTemplateKey,
  title,
  initializeWorkspaceState,
  persistTemplateDraft,
  resetActiveTemplateInputs,
  resolveNewLabelNames,
  resolveSelectedLabelIds,
  restoreTemplateDraft,
  sanitizeSelectedLabels,
  syncFieldValues,
} = useIssueCreateComposer(templates, labelOptions)
const selectedLabelIds = computed(() => resolveSelectedLabelIds())
const newLabelNames = computed(() => resolveNewLabelNames())
const assignableUsers = computed(() => Array.isArray(repository.value?.assignableUsers) ? repository.value.assignableUsers : [])
const requesterEmail = computed(() => {
  const primaryEmail = sanitizeSingleLineText(props.currentUser?.defaultEmail, 160)
  const fallbackEmail = sanitizeSingleLineText(props.currentUser?.email, 160)

  return primaryEmail || fallbackEmail || translateIssue('labels.authenticatedUser', 'usuário autenticado')
})
const repositorySelection = computed(() => splitRepositoryKey(selectedRepositoryKey.value || repository.value?.nameWithOwner || ''))
const localRepositorySelection = computed(() => splitRepositoryKey(selectedRepositoryKey.value))
const previewTitle = computed(() => formatTemplateTitle(selectedTemplate.value, title.value))
const previewSubmissionFields = computed(() => {
  const rawSubmissionFields = buildSubmissionFields(selectedTemplate.value, fieldValues)
  return sanitizeSubmissionFieldsForTemplate(selectedTemplate.value, rawSubmissionFields)
})
const previewBody = computed(() => renderPreview(selectedTemplate.value, previewSubmissionFields.value, requesterEmail.value))
const activeError = computed(() => isLocalMode.value ? templatesError.value : workspaceError.value)
const createModalBusy = computed(() => submitting.value || loadingWorkspace.value || loadingTemplates.value)
const draftScopeIdentifier = computed(() => resolveDraftScope(props.currentUser))
const createDraftScopePrefix = computed(() => `${TASK_DRAFT_STORAGE_PREFIX}${draftScopeIdentifier.value}.`)
const createDraftStorageKey = computed(() => `${createDraftScopePrefix.value}create`)
const hasUnsavedCreateInput = computed(() => {
  const normalizedTitle = String(title.value || '').trim()
  const selectedLabelNames = buildLabelNamesFromSelection(selectedLabels.value)
  const hasFieldContent = hasFilledDraftFieldValues(fieldValues)
  const hasAssignee = String(selectedAssigneeId.value || '').trim() !== ''

  return normalizedTitle !== '' || selectedLabelNames.length > 0 || hasFieldContent || hasAssignee
})
const assignablePlaceholderLabel = computed(() => {
  if (loadingWorkspace.value && assignableUsers.value.length === 0) {
    return translateIssue('assignee.loading', 'Carregando colaboradores...')
  }

  if (assignableUsers.value.length === 0) {
    return translateIssue('assignee.empty', 'Nenhum colaborador encontrado')
  }

  return translateIssue('assignee.none', 'Sem atribuição inicial')
})

onBeforeMount(() => {
  componentDisposed = false
  runtimeError.value = ''
  modalWasDeactivated.value = false
})

onMounted(async () => {
  creationMode.value = canUseGithubMode.value ? 'github' : 'local'
  selectedRepositoryKey.value = resolveInitialRepositoryKey()
  await Promise.all([
    loadTemplates(),
    canUseGithubMode.value ? loadWorkspace() : Promise.resolve(),
  ])

  if (!ensureComponentIsActive()) {
    return
  }

  restoreStoredCreateDraft()
})

onBeforeUpdate(() => {
  templateCountBeforeUpdate.value = templates.value.length
})

onUpdated(() => {
  if (templateCountBeforeUpdate.value === templates.value.length) {
    return
  }

  if (
    selectedTemplateKey.value !== ''
    && !templates.value.some((template) => template?.key === selectedTemplateKey.value)
  ) {
    selectedTemplateKey.value = ''
  }
})

onActivated(async () => {
  if (!modalWasDeactivated.value) {
    return
  }

  modalWasDeactivated.value = false
  if (templates.value.length > 0) {
    return
  }

  await Promise.all([
    loadTemplates(),
    !isLocalMode.value && canUseGithubMode.value ? loadWorkspace() : Promise.resolve(),
  ])
})

onDeactivated(() => {
  modalWasDeactivated.value = true
  persistCreateDraft()
})

onBeforeUnmount(() => {
  componentDisposed = true

  if (!shouldPersistDraftOnUnmount.value) {
    return
  }

  persistCreateDraft()
})

onUnmounted(() => {
  runtimeError.value = ''
})

onErrorCaptured((capturedError) => {
  runtimeError.value = sanitizeSingleLineText(capturedError?.message, 220)
  submitError.value = runtimeError.value !== ''
    ? runtimeError.value
    : translateIssue('errors.unexpected', 'Erro inesperado na criação da tarefa.')

  return false
})

watch(selectedRepositoryKey, async (newValue, previousValue) => {
  if (isLocalMode.value || syncingRepositoryKey.value || newValue === previousValue) {
    return
  }

  await loadWorkspace()
})

watch(canUseGithubMode, (nextValue) => {
  if (!nextValue && creationMode.value === 'github') {
    creationMode.value = 'local'
  }
})

watch(creationMode, async (nextMode, previousMode) => {
  submitError.value = ''

  if (nextMode === previousMode) {
    return
  }

  if (nextMode === 'github' && canUseGithubMode.value && workspace.value === null) {
    await loadWorkspace()
    return
  }

  initializeWorkspaceState()
})

watch(selectedTemplateKey, (newKey, oldKey) => {
  if (oldKey) {
    persistTemplateDraft(oldKey)
  }

  if (newKey) {
    restoreTemplateDraft(newKey)
    submitError.value = ''
  }
})

watch(assignableUsers, (users) => {
  if (!users.some((user) => user?.id === selectedAssigneeId.value)) {
    selectedAssigneeId.value = ''
  }
})

watch(
  [
    creationMode,
    selectedRepositoryKey,
    selectedTemplateKey,
    selectedAssigneeId,
    title,
    templatePickerMode,
    () => JSON.stringify(selectedLabels.value || []),
    () => JSON.stringify(fieldValues || {}),
  ],
  () => {
    persistCreateDraft()
  },
)

function resolveInitialRepositoryKey() {
  const normalizedInitialKey = String(props.initialRepositoryKey || '').trim()
  if (normalizedInitialKey !== '') {
    return normalizedInitialKey
  }

  const firstRepository = props.repositories[0]
  return String(firstRepository?.nameWithOwner || '').trim()
}

async function loadTemplates() {
  loadingTemplates.value = true
  templatesError.value = ''

  try {
    const { data } = await fetchTaskTemplates()
    if (!ensureComponentIsActive()) {
      return
    }

    localTemplates.value = Array.isArray(data?.items) ? data.items : []
    initializeWorkspaceState()
  } catch (error) {
    if (!ensureComponentIsActive()) {
      return
    }

    localTemplates.value = []
    templatesError.value = extractHttpMessage(
      error,
      translateIssue('errors.loadTemplatesFailed', 'Não foi possível carregar os templates de tarefa.'),
    )
  } finally {
    if (ensureComponentIsActive()) {
      loadingTemplates.value = false
    }
  }
}

async function loadWorkspace() {
  loadingWorkspace.value = true
  workspaceError.value = ''
  submitError.value = ''

  try {
    const params = {}
    const selectedRepository = splitRepositoryKey(selectedRepositoryKey.value)
    if (selectedRepository.owner !== '' && selectedRepository.name !== '') {
      params.repositoryOwner = selectedRepository.owner
      params.repositoryName = selectedRepository.name
    }

    const { data } = await fetchGithubWorkspace(params)
    if (!ensureComponentIsActive()) {
      return
    }

    workspace.value = data || null

    const fallbackRepositoryKey = String(data?.repository?.nameWithOwner || '').trim()
    if (selectedRepositoryKey.value === '' && fallbackRepositoryKey !== '') {
      syncingRepositoryKey.value = true
      selectedRepositoryKey.value = fallbackRepositoryKey
      syncingRepositoryKey.value = false
    }

    initializeWorkspaceState()
  } catch (error) {
    if (!ensureComponentIsActive()) {
      return
    }

    workspace.value = null
    workspaceError.value = extractHttpMessage(
      error,
      translateIssue('errors.loadWorkspaceFailed', 'Não foi possível carregar o workspace GitHub para criar a issue.'),
    )
  } finally {
    if (ensureComponentIsActive()) {
      loadingWorkspace.value = false
    }
  }
}

function renderPreview(template, submissionFields, email) {
  if (!template) {
    return translateIssue('preview.selectTemplate', 'Selecione um template para ver o preview.')
  }

  const lines = [
    `> ${translateIssue('preview.requester', 'Solicitante')}: ${email}`,
    `> ${translateIssue('preview.requestDate', 'Data da solicitação')}: ${formatDateTime(new Date())}`,
    `> ${translateIssue('preview.source', 'Origem')}: OctoFlow`,
    '',
  ]

  for (const field of template.fields || []) {
    const rawValue = submissionFields[field.key]
    const normalizedValue = field.type === 'list'
      ? (Array.isArray(rawValue) ? rawValue.filter(Boolean) : [])
      : String(rawValue || '').trim()

    const isEmptyList = Array.isArray(normalizedValue) && normalizedValue.length === 0
    const isEmptyString = typeof normalizedValue === 'string' && normalizedValue === ''
    if (isEmptyList || isEmptyString) {
      continue
    }

    lines.push(`## ${field.label}`)

    if (Array.isArray(normalizedValue)) {
      for (const item of normalizedValue) {
        lines.push(`${field.style === 'checklist' ? '- [ ]' : '-'} ${item}`)
      }
    } else if (field.type === 'select') {
      lines.push(resolveSelectLabel(field, normalizedValue))
    } else {
      lines.push(normalizedValue)
    }

    lines.push('')
  }

  return lines.join('\n').trim() || translateIssue('preview.fillFields', 'Preencha os campos para gerar o preview.')
}

async function submitIssue() {
  if (!selectedTemplate.value) {
    submitError.value = isLocalMode.value
      ? translateIssue('errors.selectTemplateForLocal', 'Selecione um template antes de criar a tarefa local.')
      : translateIssue('errors.selectTemplateForGithub', 'Selecione um template antes de criar a issue.')
    return
  }

  if (!isLocalMode.value && (repositorySelection.value.owner === '' || repositorySelection.value.name === '')) {
    submitError.value = translateIssue('errors.selectRepository', 'Selecione um repositório para criar a issue.')
    return
  }

  submitting.value = true
  submitError.value = ''
  persistTemplateDraft()

  try {
    let data
    const normalizedAssigneeId = sanitizeIdentifier(selectedAssigneeId.value, 120)
    const normalizedLabelIds = selectedLabelIds.value
      .map((labelId) => sanitizeIdentifier(labelId, 80))
      .filter((labelId) => labelId !== '')
    const normalizedNewLabelNames = sanitizeLabelNames(newLabelNames.value)

    if (isLocalMode.value) {
      const normalizedPreviewTitle = sanitizeSingleLineText(previewTitle.value, CREATE_TITLE_MAX_LENGTH)
      const normalizedPreviewBody = sanitizeMultiLineText(previewBody.value)

      const response = await createLocalTask({
        templateKey: sanitizeSingleLineText(selectedTemplate.value.key, 80),
        title: normalizedPreviewTitle,
        body: normalizedPreviewBody,
        labelNames: sanitizeLabelNames(buildLabelNamesFromSelection(selectedLabels.value)),
        repositoryOwner: sanitizeIdentifier(localRepositorySelection.value.owner, 120) || null,
        repositoryName: sanitizeIdentifier(localRepositorySelection.value.name, 120) || null,
      })

      data = {
        ...(response.data || {}),
        mode: 'local',
      }
    } else {
      const rawSubmissionFields = buildSubmissionFields(selectedTemplate.value, fieldValues)
      const normalizedSubmissionFields = sanitizeSubmissionFieldsForTemplate(selectedTemplate.value, rawSubmissionFields)
      const normalizedIssueTitle = sanitizeSingleLineText(title.value, CREATE_TITLE_MAX_LENGTH)

      const response = await createGithubIssue({
        template: sanitizeSingleLineText(selectedTemplate.value.key, 80),
        title: normalizedIssueTitle,
        fields: normalizedSubmissionFields,
        labelIds: normalizedLabelIds,
        newLabelNames: normalizedNewLabelNames,
        assigneeIds: normalizedAssigneeId !== '' ? [normalizedAssigneeId] : [],
        repositoryOwner: sanitizeIdentifier(repositorySelection.value.owner, 120),
        repositoryName: sanitizeIdentifier(repositorySelection.value.name, 120),
      })

      data = {
        ...(response.data || {}),
        mode: 'github',
      }
    }

    shouldPersistDraftOnUnmount.value = false
    clearCreateDraft()
    emit('issue-created', data || null)
    emit('close')
  } catch (error) {
    submitError.value = extractHttpMessage(
      error,
      isLocalMode.value
        ? translateIssue('errors.createLocalFailed', 'Não foi possível criar a tarefa local.')
        : translateIssue('errors.createGithubFailed', 'Não foi possível criar a issue no GitHub.'),
    )
  } finally {
    submitting.value = false
  }
}

function requestClose() {
  if (createModalBusy.value) {
    submitError.value = translateIssue('errors.waitOperationToClose', 'Aguarde a operação atual finalizar antes de fechar.')
    return
  }

  persistCreateDraft()

  if (!hasUnsavedCreateInput.value) {
    emit('close')
    return
  }

  emit('close')
}

function handleModalRuntimeError(runtimePayload) {
  const runtimeMessage = sanitizeSingleLineText(runtimePayload?.message, 220)
  submitError.value = runtimeMessage !== ''
    ? runtimeMessage
    : translateIssue('errors.unexpected', 'Erro inesperado na criação da tarefa.')
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

  return translateIssue('labels.guest', 'guest')
}

function readStoredCreateDraft() {
  return readDraft(createDraftStorageKey.value, {
    scopePrefix: createDraftScopePrefix.value,
    maxAgeMs: TASK_DRAFT_MAX_AGE_MS,
    maxDraftItems: TASK_DRAFT_MAX_ITEMS,
  })
}

function persistCreateDraft() {
  if (applyingStoredDraft.value) {
    return
  }

  const draftPayload = {
    version: 1,
    createdAt: new Date().toISOString(),
    creationMode: creationMode.value === 'local' ? 'local' : 'github',
    selectedRepositoryKey: sanitizeSingleLineText(selectedRepositoryKey.value, 180),
    selectedTemplateKey: sanitizeSingleLineText(selectedTemplateKey.value, 120),
    selectedAssigneeId: sanitizeIdentifier(selectedAssigneeId.value, 120),
    templatePickerMode: templatePickerMode.value === 'cards' ? 'cards' : 'select',
    title: sanitizeSingleLineText(title.value, CREATE_TITLE_MAX_LENGTH),
    selectedLabelNames: sanitizeLabelNames(buildLabelNamesFromSelection(selectedLabels.value)),
    fieldValues: normalizeDraftFieldValues(fieldValues),
  }

  writeDraft(createDraftStorageKey.value, draftPayload, {
    scopePrefix: createDraftScopePrefix.value,
    maxAgeMs: TASK_DRAFT_MAX_AGE_MS,
    maxDraftItems: TASK_DRAFT_MAX_ITEMS,
  })
}

function clearCreateDraft() {
  removeDraft(createDraftStorageKey.value)
}

function restoreStoredCreateDraft() {
  const storedDraft = readStoredCreateDraft()
  if (!storedDraft) {
    return
  }

  applyingStoredDraft.value = true

  try {
    const draftCreationMode = storedDraft.creationMode === 'local' ? 'local' : 'github'
    creationMode.value = draftCreationMode === 'github' && !canUseGithubMode.value ? 'local' : draftCreationMode

    const draftRepositoryKey = sanitizeSingleLineText(storedDraft.selectedRepositoryKey, 180)
    if (draftRepositoryKey !== '') {
      selectedRepositoryKey.value = draftRepositoryKey
    }

    templatePickerMode.value = storedDraft.templatePickerMode === 'cards' ? 'cards' : 'select'

    const draftTemplateKey = sanitizeSingleLineText(storedDraft.selectedTemplateKey, 120)
    if (draftTemplateKey !== '' && templates.value.some((template) => template?.key === draftTemplateKey)) {
      selectedTemplateKey.value = draftTemplateKey
    }

    title.value = sanitizeSingleLineText(storedDraft.title, CREATE_TITLE_MAX_LENGTH)
    selectedAssigneeId.value = sanitizeIdentifier(storedDraft.selectedAssigneeId, 120)

    if (Array.isArray(storedDraft.selectedLabelNames)) {
      selectedLabels.value = mapLabelNamesToSelectedOptions(storedDraft.selectedLabelNames, labelOptions.value)
    }

    if (storedDraft.fieldValues && typeof storedDraft.fieldValues === 'object') {
      syncFieldValues(storedDraft.fieldValues)
    }
  } finally {
    applyingStoredDraft.value = false
  }
}

function normalizeDraftFieldValues(rawFieldValues) {
  const normalizedFieldValues = {}
  const sourceFieldValues = rawFieldValues && typeof rawFieldValues === 'object' ? rawFieldValues : {}

  for (const fieldKey of Object.keys(sourceFieldValues)) {
    const rawFieldValue = sourceFieldValues[fieldKey]
    const normalizedFieldKey = sanitizeSingleLineText(fieldKey, 80)
    if (normalizedFieldKey === '') {
      continue
    }

    if (Array.isArray(rawFieldValue)) {
      normalizedFieldValues[normalizedFieldKey] = rawFieldValue
        .map((fieldItem) => sanitizeSingleLineText(fieldItem, CREATE_FIELD_LINE_MAX_LENGTH))
        .filter((fieldItem) => fieldItem !== '')
      continue
    }

    normalizedFieldValues[normalizedFieldKey] = typeof rawFieldValue === 'string'
      ? sanitizeMultiLineText(rawFieldValue)
      : sanitizeSingleLineText(String(rawFieldValue ?? ''), CREATE_FIELD_LINE_MAX_LENGTH)
  }

  return normalizedFieldValues
}

function hasFilledDraftFieldValues(rawFieldValues) {
  const sourceFieldValues = rawFieldValues && typeof rawFieldValues === 'object' ? rawFieldValues : {}

  return Object.values(sourceFieldValues).some((rawFieldValue) => {
    if (Array.isArray(rawFieldValue)) {
      return rawFieldValue.some((fieldItem) => String(fieldItem || '').trim() !== '')
    }

    return String(rawFieldValue || '').trim() !== ''
  })
}

</script>

<template>
  <TaskModalShell
    dialog-title-id="issue-create-modal-title"
    dialog-description-id="issue-create-modal-description"
    @close="requestClose"
    @runtime-error="handleModalRuntimeError"
  >
    <header class="flex flex-col gap-4 border-b border-slate-200/80 px-5 py-5 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0">
        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
          {{ translateIssue('header.kicker', 'Nova tarefa') }}
        </p>
        <h2 id="issue-create-modal-title" class="mt-1 text-2xl font-semibold text-slate-950 sm:text-3xl">
          {{ translateIssue('header.title', 'Criar tarefa por template') }}
        </h2>
        <p id="issue-create-modal-description" class="mt-2 text-sm leading-7 text-slate-600">
          {{ translateIssue('header.description', 'Escolha se a tarefa nasce no GitHub ou localmente, preencha o template e acompanhe o Markdown final antes de enviar.') }}
        </p>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="app-btn app-btn-secondary"
          :disabled="createModalBusy"
          @click="requestClose"
        >
          {{ translateIssue('actions.close', 'Fechar') }}
        </button>
      </div>
    </header>

    <div class="grid min-h-0 flex-1 gap-5 overflow-y-auto p-5 xl:grid-cols-[minmax(0,1.08fr),minmax(320px,0.92fr)]">
      <article class="grid gap-4">
        <div class="app-panel-standard grid gap-3 rounded-[28px] p-5">
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
              {{ translateIssue('destination.kicker', 'Destino') }}
            </p>
            <h3 class="mt-1 text-2xl font-semibold text-slate-950">
              {{ translateIssue('destination.title', 'Onde a tarefa nasce') }}
            </h3>
            <p class="mt-2 text-sm leading-6 text-slate-500">
              {{ translateIssue('destination.description', 'Tarefas locais ficam pendentes no sistema e podem ser enviadas ao GitHub depois.') }}
            </p>
          </div>

          <div class="grid gap-3 md:grid-cols-2">
            <button
              type="button"
              class="app-choice-card grid gap-2 p-4 text-left"
              :class="{ 'is-active': !isLocalMode }"
              :disabled="!canUseGithubMode"
              @click="creationMode = 'github'"
            >
              <strong class="text-base font-semibold text-slate-950">
                {{ translateIssue('destination.github.title', 'Criar no GitHub') }}
              </strong>
              <small class="text-sm leading-6 text-slate-500">
                {{ canUseGithubMode
                  ? translateIssue('destination.github.availableDescription', 'Usa repositório, labels e colaboradores do GitHub imediatamente.')
                  : translateIssue('destination.github.unavailableDescription', 'Indisponível até existir pelo menos um repositório GitHub configurado.') }}
              </small>
            </button>

            <button
              type="button"
              class="app-choice-card grid gap-2 p-4 text-left"
              :class="{ 'is-active': isLocalMode }"
              @click="creationMode = 'local'"
            >
              <strong class="text-base font-semibold text-slate-950">
                {{ translateIssue('destination.local.title', 'Criar localmente') }}
              </strong>
              <small class="text-sm leading-6 text-slate-500">
                {{ translateIssue('destination.local.description', 'A tarefa fica no sistema e entra na fila de sincronização quando o GitHub estiver disponível.') }}
              </small>
            </button>
          </div>
        </div>

        <label
          v-if="!isLocalMode || availableRepositories.length > 0"
          class="grid gap-2"
        >
          <span class="text-sm font-semibold text-slate-900">
            {{ isLocalMode
              ? translateIssue('repository.localLabel', 'Repositório para sincronização futura (opcional)')
              : translateIssue('repository.githubLabel', 'Repositório de destino') }}
          </span>
          <select
            v-model="selectedRepositoryKey"
            class="app-field-control h-11 px-3 text-sm text-slate-900"
          >
            <option value="">
              {{ isLocalMode
                ? translateIssue('repository.localPlaceholder', 'Definir depois')
                : translateIssue('repository.githubPlaceholder', 'Repositório padrão do perfil') }}
            </option>
            <option
              v-for="availableRepository in availableRepositories"
              :key="availableRepository.nameWithOwner"
              :value="availableRepository.nameWithOwner"
            >
              {{ availableRepository.nameWithOwner }}
            </option>
          </select>
        </label>

        <p
          v-if="activeError"
          class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
        >
          {{ activeError }}
        </p>

        <article
          v-if="loadingTemplates || (!isLocalMode && loadingWorkspace)"
          class="app-panel-standard rounded-[28px] p-5 text-sm text-slate-500"
        >
          {{ isLocalMode
            ? translateIssue('loading.localTemplates', 'Carregando templates do sistema...')
            : translateIssue('loading.githubTemplates', 'Carregando templates e configurações do repositório...') }}
        </article>

        <template v-else>
          <div class="app-panel-standard rounded-[28px] p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">
                  {{ translateIssue('templates.kicker', 'Templates') }}
                </p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                  {{ isLocalMode
                    ? translateIssue('templates.localTitle', 'Modelos para tarefa local')
                    : translateIssue('templates.githubTitle', 'Modelos disponíveis') }}
                </h3>
              </div>

              <div class="inline-flex rounded-2xl border border-slate-200 bg-slate-50/80 p-1">
                <button
                  type="button"
                  class="app-btn app-btn-sm"
                  :class="templatePickerMode === 'select' ? 'app-btn-tab-active' : 'app-btn-secondary'"
                  @click="templatePickerMode = 'select'"
                >
                  {{ translateIssue('templates.mode.select', 'Select') }}
                </button>
                <button
                  type="button"
                  class="app-btn app-btn-sm"
                  :class="templatePickerMode === 'cards' ? 'app-btn-tab-active' : 'app-btn-secondary'"
                  @click="templatePickerMode = 'cards'"
                >
                  {{ translateIssue('templates.mode.cards', 'Cards') }}
                </button>
              </div>
            </div>

            <div v-if="templates.length && templatePickerMode === 'select'" class="mt-4 grid gap-2">
              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">
                  {{ translateIssue('templates.selectedLabel', 'Template selecionado') }}
                </span>
                <select v-model="selectedTemplateKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="">
                    {{ translateIssue('templates.selectPlaceholder', 'Selecionar template') }}
                  </option>
                  <option v-for="template in templates" :key="template.key" :value="template.key">
                    {{ template.name }}
                  </option>
                </select>
              </label>

              <p v-if="selectedTemplate" class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-600">
                {{ selectedTemplate.description }}
              </p>
            </div>

            <div v-else-if="templates.length" class="mt-4 grid gap-3 md:grid-cols-2">
              <button
                v-for="template in templates"
                :key="template.key"
                type="button"
                class="app-choice-card grid min-w-0 gap-2 p-4 text-left"
                :class="{ 'is-active': template.key === selectedTemplateKey }"
                @click="selectedTemplateKey = template.key"
              >
                <span class="text-xs font-black uppercase tracking-[0.18em] text-orange-600">[{{ template.titlePrefix || 'issue' }}]</span>
                <strong class="break-words text-base font-semibold text-slate-950">{{ template.name }}</strong>
                <small class="break-words text-sm leading-6 text-slate-500">{{ template.description }}</small>
              </button>
            </div>

            <p
              v-else
              class="app-empty-panel mt-4 rounded-2xl px-4 py-6 text-sm text-slate-500"
            >
              {{ isLocalMode
                ? translateIssue('templates.emptyLocal', 'Nenhum template local foi carregado.')
                : translateIssue('templates.emptyGithub', 'Esse repositório não retornou templates disponíveis.') }}
            </p>
          </div>

          <article
            v-if="selectedTemplate"
            class="app-panel-standard rounded-[28px] p-5"
          >
            <RemoteIssueTemplateForm
              :template="selectedTemplate"
              :title="title"
              :assignee-id="selectedAssigneeId"
              :assignee-options="assignableUsers"
              :assignee-placeholder="assignablePlaceholderLabel"
              :field-values="fieldValues"
              :selected-labels="selectedLabels"
              :label-options="labelOptions"
              :show-assignee-field="!isLocalMode"
              :show-label-field="true"
              :submit-error="submitError"
              :submitting="submitting"
              :submit-label="isLocalMode
                ? translateIssue('actions.submitLocal', 'Criar tarefa local')
                : translateIssue('actions.submitGithub', 'Criar issue no GitHub')"
              :submitting-label="isLocalMode
                ? translateIssue('actions.submittingLocal', 'Criando tarefa local...')
                : translateIssue('actions.submittingGithub', 'Criando issue...')"
              @update:title="title = $event"
              @update:assignee-id="selectedAssigneeId = $event"
              @update:selected-labels="selectedLabels = sanitizeSelectedLabels($event)"
              @update:field-values="syncFieldValues"
              @submit="submitIssue"
              @reset="resetActiveTemplateInputs"
            />
          </article>
        </template>
      </article>

      <RemoteIssueTemplatePreview
        :title="previewTitle"
        :body="previewBody"
        :kicker="translateIssue('preview.kicker', 'Preview Markdown')"
        :heading="isLocalMode
          ? translateIssue('preview.localHeading', 'Como a tarefa será salva localmente')
          : translateIssue('preview.githubHeading', 'Como a issue vai subir')"
      />
    </div>
  </TaskModalShell>
</template>
