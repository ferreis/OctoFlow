<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
  request: {
    type: Function,
    required: true,
  },
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

const TASK_DRAFT_STORAGE_PREFIX = 'octoflow.tasks.'

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
  const primaryEmail = typeof props.currentUser?.defaultEmail === 'string' ? props.currentUser.defaultEmail.trim() : ''
  const fallbackEmail = typeof props.currentUser?.email === 'string' ? props.currentUser.email.trim() : ''

  return primaryEmail || fallbackEmail || 'usuario autenticado'
})
const repositorySelection = computed(() => splitRepositoryKey(selectedRepositoryKey.value || repository.value?.nameWithOwner || ''))
const localRepositorySelection = computed(() => splitRepositoryKey(selectedRepositoryKey.value))
const previewTitle = computed(() => formatTemplateTitle(selectedTemplate.value, title.value))
const previewBody = computed(() => renderPreview(selectedTemplate.value, buildSubmissionFields(selectedTemplate.value, fieldValues), requesterEmail.value))
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
    return 'Carregando colaboradores...'
  }

  if (assignableUsers.value.length === 0) {
    return 'Nenhum colaborador encontrado'
  }

  return 'Sem atribuição inicial'
})

onMounted(async () => {
  creationMode.value = canUseGithubMode.value ? 'github' : 'local'
  selectedRepositoryKey.value = resolveInitialRepositoryKey()
  await Promise.all([
    loadTemplates(),
    canUseGithubMode.value ? loadWorkspace() : Promise.resolve(),
  ])

  restoreStoredCreateDraft()
})

onBeforeUnmount(() => {
  if (!shouldPersistDraftOnUnmount.value) {
    return
  }

  persistCreateDraft()
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
    const { data } = await fetchTaskTemplates(props.request)
    localTemplates.value = Array.isArray(data?.items) ? data.items : []
    initializeWorkspaceState()
  } catch (error) {
    localTemplates.value = []
    templatesError.value = extractHttpMessage(error, 'Nao foi possivel carregar os templates de tarefa.')
  } finally {
    loadingTemplates.value = false
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

    const { data } = await fetchGithubWorkspace(props.request, params)

    workspace.value = data || null

    const fallbackRepositoryKey = String(data?.repository?.nameWithOwner || '').trim()
    if (selectedRepositoryKey.value === '' && fallbackRepositoryKey !== '') {
      syncingRepositoryKey.value = true
      selectedRepositoryKey.value = fallbackRepositoryKey
      syncingRepositoryKey.value = false
    }

    initializeWorkspaceState()
  } catch (error) {
    workspace.value = null
    workspaceError.value = extractHttpMessage(error, 'Nao foi possivel carregar o workspace GitHub para criar a issue.')
  } finally {
    loadingWorkspace.value = false
  }
}

function renderPreview(template, submissionFields, email) {
  if (!template) {
    return 'Selecione um template para ver o preview.'
  }

  const lines = [
    `> Solicitante: ${email}`,
    '> Data da solicitação: ' + formatDateTime(new Date()),
    '> Origem: OctoFlow',
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

  return lines.join('\n').trim() || 'Preencha os campos para gerar o preview.'
}

async function submitIssue() {
  if (!selectedTemplate.value) {
    submitError.value = isLocalMode.value
      ? 'Selecione um template antes de criar a tarefa local.'
      : 'Selecione um template antes de criar a issue.'
    return
  }

  if (!isLocalMode.value && (repositorySelection.value.owner === '' || repositorySelection.value.name === '')) {
    submitError.value = 'Selecione um repositorio para criar a issue.'
    return
  }

  submitting.value = true
  submitError.value = ''
  persistTemplateDraft()

  try {
    let data

    if (isLocalMode.value) {
      const response = await createLocalTask(props.request, {
        templateKey: selectedTemplate.value.key,
        title: previewTitle.value,
        body: previewBody.value,
        labelNames: buildLabelNamesFromSelection(selectedLabels.value),
        repositoryOwner: localRepositorySelection.value.owner || null,
        repositoryName: localRepositorySelection.value.name || null,
      })

      data = {
        ...(response.data || {}),
        mode: 'local',
      }
    } else {
      const response = await createGithubIssue(props.request, {
        template: selectedTemplate.value.key,
        title: title.value,
        fields: buildSubmissionFields(selectedTemplate.value, fieldValues),
        labelIds: selectedLabelIds.value,
        newLabelNames: newLabelNames.value,
        assigneeIds: selectedAssigneeId.value ? [selectedAssigneeId.value] : [],
        repositoryOwner: repositorySelection.value.owner,
        repositoryName: repositorySelection.value.name,
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
        ? 'Nao foi possivel criar a tarefa local.'
        : 'Nao foi possivel criar a issue no GitHub.',
    )
  } finally {
    submitting.value = false
  }
}

function requestClose() {
  if (createModalBusy.value) {
    submitError.value = 'Aguarde a operacao atual finalizar antes de fechar.'
    return
  }

  persistCreateDraft()

  if (!hasUnsavedCreateInput.value) {
    emit('close')
    return
  }

  emit('close')
}

function resolveDraftScope(currentUser) {
  const userId = String(currentUser?.id || '').trim()
  if (userId !== '') {
    return userId
  }

  const normalizedEmail = String(currentUser?.defaultEmail || currentUser?.email || '').trim().toLowerCase()
  if (normalizedEmail !== '') {
    return normalizedEmail
  }

  return 'guest'
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
    selectedRepositoryKey: String(selectedRepositoryKey.value || '').trim(),
    selectedTemplateKey: String(selectedTemplateKey.value || '').trim(),
    selectedAssigneeId: String(selectedAssigneeId.value || '').trim(),
    templatePickerMode: templatePickerMode.value === 'cards' ? 'cards' : 'select',
    title: String(title.value || ''),
    selectedLabelNames: buildLabelNamesFromSelection(selectedLabels.value),
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

    const draftRepositoryKey = String(storedDraft.selectedRepositoryKey || '').trim()
    if (draftRepositoryKey !== '') {
      selectedRepositoryKey.value = draftRepositoryKey
    }

    templatePickerMode.value = storedDraft.templatePickerMode === 'cards' ? 'cards' : 'select'

    const draftTemplateKey = String(storedDraft.selectedTemplateKey || '').trim()
    if (draftTemplateKey !== '' && templates.value.some((template) => template?.key === draftTemplateKey)) {
      selectedTemplateKey.value = draftTemplateKey
    }

    title.value = typeof storedDraft.title === 'string' ? storedDraft.title : ''
    selectedAssigneeId.value = typeof storedDraft.selectedAssigneeId === 'string'
      ? storedDraft.selectedAssigneeId
      : ''

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
    if (Array.isArray(rawFieldValue)) {
      normalizedFieldValues[fieldKey] = rawFieldValue.map((fieldItem) => String(fieldItem || ''))
      continue
    }

    normalizedFieldValues[fieldKey] = typeof rawFieldValue === 'string'
      ? rawFieldValue
      : String(rawFieldValue ?? '')
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
  <TaskModalShell @close="requestClose">
      <header class="flex flex-col gap-4 border-b border-slate-200/80 px-5 py-5 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Nova tarefa</p>
          <h2 class="mt-1 text-2xl font-semibold text-slate-950 sm:text-3xl">Criar tarefa por template</h2>
          <p class="mt-2 text-sm leading-7 text-slate-600">
            Escolha se a tarefa nasce no GitHub ou localmente, preencha o template e acompanhe o Markdown final antes de enviar.
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="app-btn app-btn-secondary"
            :disabled="createModalBusy"
            @click="requestClose"
          >
            Fechar
          </button>
        </div>
      </header>

      <div class="grid min-h-0 flex-1 gap-5 overflow-y-auto p-5 xl:grid-cols-[minmax(0,1.08fr),minmax(320px,0.92fr)]">
        <article class="grid gap-4">
          <div class="app-panel-standard grid gap-3 rounded-[28px] p-5">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Destino</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Onde a tarefa nasce</h3>
              <p class="mt-2 text-sm leading-6 text-slate-500">
                Tarefas locais ficam pendentes no sistema e podem ser enviadas ao GitHub depois.
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
                <strong class="text-base font-semibold text-slate-950">Criar no GitHub</strong>
                <small class="text-sm leading-6 text-slate-500">
                  {{ canUseGithubMode
                    ? 'Usa repositório, labels e colaboradores do GitHub imediatamente.'
                    : 'Indisponível até existir pelo menos um repositório GitHub configurado.' }}
                </small>
              </button>

              <button
                type="button"
                class="app-choice-card grid gap-2 p-4 text-left"
                :class="{ 'is-active': isLocalMode }"
                @click="creationMode = 'local'"
              >
                <strong class="text-base font-semibold text-slate-950">Criar localmente</strong>
                <small class="text-sm leading-6 text-slate-500">
                  A tarefa fica no sistema e entra na fila de sincronização quando o GitHub estiver disponível.
                </small>
              </button>
            </div>
          </div>

          <label
            v-if="!isLocalMode || availableRepositories.length > 0"
            class="grid gap-2"
          >
            <span class="text-sm font-semibold text-slate-900">
              {{ isLocalMode ? 'Repositório para sincronização futura (opcional)' : 'Repositório de destino' }}
            </span>
            <select
              v-model="selectedRepositoryKey"
              class="app-field-control h-11 px-3 text-sm text-slate-900"
            >
              <option value="">
                {{ isLocalMode ? 'Definir depois' : 'Repositório padrão do perfil' }}
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
            {{ isLocalMode ? 'Carregando templates do sistema...' : 'Carregando templates e configuracoes do repositorio...' }}
          </article>

          <template v-else>
            <div class="app-panel-standard rounded-[28px] p-5">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Templates</p>
                  <h3 class="mt-1 text-2xl font-semibold text-slate-950">
                    {{ isLocalMode ? 'Modelos para tarefa local' : 'Modelos disponiveis' }}
                  </h3>
                </div>

                <div class="inline-flex rounded-2xl border border-slate-200 bg-slate-50/80 p-1">
                  <button
                    type="button"
                    class="app-btn app-btn-sm"
                    :class="templatePickerMode === 'select' ? 'app-btn-tab-active' : 'app-btn-secondary'"
                    @click="templatePickerMode = 'select'"
                  >
                    Select
                  </button>
                  <button
                    type="button"
                    class="app-btn app-btn-sm"
                    :class="templatePickerMode === 'cards' ? 'app-btn-tab-active' : 'app-btn-secondary'"
                    @click="templatePickerMode = 'cards'"
                  >
                    Cards
                  </button>
                </div>
              </div>

              <div v-if="templates.length && templatePickerMode === 'select'" class="mt-4 grid gap-2">
                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">Template selecionado</span>
                  <select v-model="selectedTemplateKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                    <option value="">Selecionar template</option>
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
                {{ isLocalMode ? 'Nenhum template local foi carregado.' : 'Esse repositorio nao retornou templates disponiveis.' }}
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
                :submit-label="isLocalMode ? 'Criar tarefa local' : 'Criar issue no GitHub'"
                :submitting-label="isLocalMode ? 'Criando tarefa local...' : 'Criando issue...'"
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
          kicker="Preview Markdown"
          :heading="isLocalMode ? 'Como a tarefa sera salva localmente' : 'Como a issue vai subir'"
        />
      </div>
  </TaskModalShell>
</template>
