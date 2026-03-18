<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import MarkdownPreview from '../shared/MarkdownPreview.vue'
import MultiSelect from '../shared/MultiSelect.vue'

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
const workspaceError = ref('')
const workspace = ref(null)
const submitting = ref(false)
const submitError = ref('')
const selectedTemplateKey = ref('')
const selectedAssigneeId = ref('')
const selectedRepositoryKey = ref('')
const title = ref('')
const selectedLabels = ref([])
const syncingRepositoryKey = ref(false)
const fieldValues = reactive({})
const templateDrafts = reactive({})

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
const templates = computed(() => Array.isArray(workspace.value?.templates) ? workspace.value.templates : [])
const repository = computed(() => workspace.value?.repository || null)
const labels = computed(() => Array.isArray(repository.value?.labels) ? repository.value.labels : [])
const labelOptions = computed(() => labels.value
  .map((label) => normalizeLabelOption(label))
  .filter((label) => label.id !== '' && label.name !== '')
  .sort((left, right) => left.name.localeCompare(right.name, 'pt-BR', { sensitivity: 'base' }))
)
const selectedLabelIds = computed(() => selectedLabels.value
  .map((label) => String(label?.id || '').trim())
  .filter(Boolean)
)
const newLabelNames = computed(() => selectedLabels.value
  .filter((label) => String(label?.id || '').trim() === '')
  .map((label) => normalizeLabelName(label?.name))
  .filter(Boolean)
)
const assignableUsers = computed(() => Array.isArray(repository.value?.assignableUsers) ? repository.value.assignableUsers : [])
const requesterEmail = computed(() => {
  const primaryEmail = typeof props.currentUser?.defaultEmail === 'string' ? props.currentUser.defaultEmail.trim() : ''
  const fallbackEmail = typeof props.currentUser?.email === 'string' ? props.currentUser.email.trim() : ''

  return primaryEmail || fallbackEmail || 'usuario autenticado'
})
const selectedTemplate = computed(() => templates.value.find((template) => template.key === selectedTemplateKey.value) || null)
const repositorySelection = computed(() => splitRepositoryKey(selectedRepositoryKey.value || repository.value?.nameWithOwner || ''))
const previewTitle = computed(() => formatTitle(selectedTemplate.value, title.value))
const previewBody = computed(() => renderPreview(selectedTemplate.value, buildSubmissionFields(selectedTemplate.value), requesterEmail.value))
const isFeatureRequestTemplate = computed(() => selectedTemplate.value?.key === 'feature-request')
const featureRequestFields = computed(() => {
  const catalog = new Map()

  for (const field of selectedTemplate.value?.fields || []) {
    const fieldKey = typeof field?.key === 'string' ? field.key.trim() : ''
    if (fieldKey !== '') {
      catalog.set(fieldKey, field)
    }
  }

  return {
    description: catalog.get('description') || null,
    businessRule: catalog.get('businessRule') || null,
    acceptanceCriteria: catalog.get('acceptanceCriteria') || null,
  }
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

onMounted(() => {
  selectedRepositoryKey.value = resolveInitialRepositoryKey()
  void loadWorkspace()
})

watch(selectedRepositoryKey, async (newValue, previousValue) => {
  if (syncingRepositoryKey.value || newValue === previousValue) {
    return
  }

  await loadWorkspace()
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

function resolveInitialRepositoryKey() {
  const normalizedInitialKey = String(props.initialRepositoryKey || '').trim()
  if (normalizedInitialKey !== '') {
    return normalizedInitialKey
  }

  const firstRepository = props.repositories[0]
  return String(firstRepository?.nameWithOwner || '').trim()
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

    const { data } = await props.request({
      url: '/github/workspace',
      method: 'GET',
      params,
    })

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

function initializeWorkspaceState() {
  selectedTemplateKey.value = ''
  selectedAssigneeId.value = ''
  title.value = ''
  selectedLabels.value = []

  for (const key of Object.keys(fieldValues)) {
    delete fieldValues[key]
  }

  for (const key of Object.keys(templateDrafts)) {
    delete templateDrafts[key]
  }

  const firstTemplateKey = templates.value[0]?.key || ''
  if (firstTemplateKey !== '') {
    selectedTemplateKey.value = firstTemplateKey
  }
}

function persistTemplateDraft(templateKey = selectedTemplateKey.value) {
  const template = templates.value.find((item) => item.key === templateKey)
  if (!template) {
    return
  }

  templateDrafts[templateKey] = {
    title: title.value,
    labels: selectedLabels.value.map((label) => cloneSelectedLabel(label)),
    fields: snapshotFieldValues(template),
  }
}

function restoreTemplateDraft(templateKey) {
  const template = templates.value.find((item) => item.key === templateKey)
  if (!template) {
    return
  }

  const existingDraft = templateDrafts[templateKey]
  const restoredFields = buildInitialFieldState(template)
  const draftFields = existingDraft?.fields || {}

  for (const field of template.fields || []) {
    const fieldKey = typeof field?.key === 'string' ? field.key : ''
    if (fieldKey === '') {
      continue
    }

    const candidateValue = typeof draftFields[fieldKey] === 'string' ? draftFields[fieldKey] : ''
    restoredFields[fieldKey] = candidateValue
  }

  title.value = typeof existingDraft?.title === 'string' ? existingDraft.title : ''
  selectedLabels.value = Array.isArray(existingDraft?.labels)
    ? sanitizeSelectedLabels(existingDraft.labels)
    : resolveDefaultLabels(template)

  for (const key of Object.keys(fieldValues)) {
    delete fieldValues[key]
  }

  Object.assign(fieldValues, restoredFields)
}

function buildInitialFieldState(template) {
  const state = {}

  for (const field of template?.fields || []) {
    if (typeof field?.key !== 'string' || field.key.trim() === '') {
      continue
    }

    state[field.key] = typeof field.defaultValue === 'string' ? field.defaultValue : ''
  }

  return state
}

function snapshotFieldValues(template) {
  const snapshot = {}

  for (const field of template?.fields || []) {
    if (typeof field?.key !== 'string' || field.key.trim() === '') {
      continue
    }

    snapshot[field.key] = typeof fieldValues[field.key] === 'string' ? fieldValues[field.key] : ''
  }

  return snapshot
}

function resolveDefaultLabels(template) {
  const defaultLabels = Array.isArray(template?.defaultLabels) ? template.defaultLabels : []
  const defaultLabelSet = new Set(defaultLabels.map((labelName) => buildLabelNameKey(labelName)).filter(Boolean))

  return labelOptions.value
    .filter((label) => defaultLabelSet.has(buildLabelNameKey(label.name)))
    .map((label) => cloneSelectedLabel(label))
}

function splitMultilineItems(rawValue) {
  return String(rawValue || '')
    .split(/\r?\n/)
    .map((item) => item.trim())
    .filter(Boolean)
}

function buildSubmissionFields(template) {
  const submission = {}

  for (const field of template?.fields || []) {
    if (typeof field?.key !== 'string' || field.key.trim() === '') {
      continue
    }

    const rawValue = typeof fieldValues[field.key] === 'string' ? fieldValues[field.key] : ''
    if (field.type === 'list') {
      submission[field.key] = splitMultilineItems(rawValue)
      continue
    }

    submission[field.key] = rawValue.trim()
  }

  return submission
}

function renderPreview(template, submissionFields, email) {
  if (!template) {
    return 'Selecione um template para ver o preview.'
  }

  const lines = [
    `> Solicitante: ${email}`,
    '> Data da solicitação: ' + new Date().toLocaleString(),
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

function resolveSelectLabel(field, value) {
  const normalizedValue = String(value || '').trim()
  const option = Array.isArray(field?.options)
    ? field.options.find((candidate) => candidate.value === normalizedValue)
    : null

  return option?.label || normalizedValue
}

function formatTitle(template, rawTitle) {
  const normalizedTitle = String(rawTitle || '').trim()
  if (normalizedTitle === '') {
    return 'Titulo da issue'
  }

  const prefix = typeof template?.titlePrefix === 'string' ? template.titlePrefix.trim() : ''
  if (!prefix) {
    return normalizedTitle
  }

  const prefixPattern = new RegExp(`^\\[${escapeRegExp(prefix)}\\]\\s+`, 'i')
  if (prefixPattern.test(normalizedTitle)) {
    return normalizedTitle
  }

  return `[${prefix}] ${normalizedTitle}`
}

function escapeRegExp(value) {
  return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function resetActiveTemplateInputs() {
  const template = selectedTemplate.value
  if (!template) {
    return
  }

  title.value = ''
  selectedLabels.value = resolveDefaultLabels(template)

  for (const key of Object.keys(fieldValues)) {
    fieldValues[key] = ''
  }

  for (const field of template.fields || []) {
    if (typeof field?.key !== 'string' || field.key.trim() === '') {
      continue
    }

    fieldValues[field.key] = typeof field.defaultValue === 'string' ? field.defaultValue : ''
  }

  templateDrafts[template.key] = {
    title: '',
    labels: selectedLabels.value.map((label) => cloneSelectedLabel(label)),
    fields: snapshotFieldValues(template),
  }
}

async function submitIssue() {
  if (!selectedTemplate.value) {
    submitError.value = 'Selecione um template antes de criar a issue.'
    return
  }

  if (repositorySelection.value.owner === '' || repositorySelection.value.name === '') {
    submitError.value = 'Selecione um repositorio para criar a issue.'
    return
  }

  submitting.value = true
  submitError.value = ''
  persistTemplateDraft()

  try {
    const { data } = await props.request({
      url: '/github/issues',
      method: 'POST',
      csrfActionId: 'github.issue.create',
      data: {
        template: selectedTemplate.value.key,
        title: title.value,
        fields: buildSubmissionFields(selectedTemplate.value),
        labelIds: selectedLabelIds.value,
        newLabelNames: newLabelNames.value,
        assigneeIds: selectedAssigneeId.value ? [selectedAssigneeId.value] : [],
        repositoryOwner: repositorySelection.value.owner,
        repositoryName: repositorySelection.value.name,
      },
    })

    emit('issue-created', data || null)
    emit('close')
  } catch (error) {
    submitError.value = extractHttpMessage(error, 'Nao foi possivel criar a issue no GitHub.')
  } finally {
    submitting.value = false
  }
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
  const responseMessage = error?.response?.data?.message
  if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
    return responseMessage
  }

  if (typeof error?.message === 'string' && error.message.trim() !== '') {
    return error.message
  }

  return fallback
}

function normalizeLabelName(value) {
  return String(value || '')
    .trim()
    .replace(/\s+/g, ' ')
}

function buildLabelNameKey(value) {
  return normalizeLabelName(value).toLocaleLowerCase()
}

function normalizeLabelColor(value) {
  const normalizedValue = String(value || '').trim().replace(/^#/, '')
  return /^[0-9a-fA-F]{6}$/.test(normalizedValue) ? normalizedValue.toUpperCase() : '94A3B8'
}

function normalizeLabelOption(label) {
  return {
    id: String(label?.id || '').trim(),
    name: normalizeLabelName(label?.name),
    color: normalizeLabelColor(label?.color),
    description: typeof label?.description === 'string' && label.description.trim() !== ''
      ? label.description.trim()
      : null,
    isNew: String(label?.id || '').trim() === '',
  }
}

function cloneSelectedLabel(label) {
  const normalizedLabel = normalizeLabelOption(label)

  return {
    ...normalizedLabel,
    isNew: normalizedLabel.id === '',
  }
}

function sanitizeSelectedLabels(rawLabels) {
  if (!Array.isArray(rawLabels)) {
    return []
  }

  const sanitizedLabels = []
  const seenIds = new Set()
  const seenNames = new Set()

  for (const rawLabel of rawLabels) {
    const normalizedLabel = cloneSelectedLabel(rawLabel)
    const labelNameKey = buildLabelNameKey(normalizedLabel.name)

    if (labelNameKey === '' || seenNames.has(labelNameKey)) {
      continue
    }

    if (normalizedLabel.id !== '') {
      if (seenIds.has(normalizedLabel.id)) {
        continue
      }

      seenIds.add(normalizedLabel.id)
      normalizedLabel.isNew = false
    }

    seenNames.add(labelNameKey)
    sanitizedLabels.push(normalizedLabel)
  }

  return sanitizedLabels
}
</script>

<template>
  <div class="fixed inset-0 z-50 bg-slate-950/55 px-4 py-6 backdrop-blur-sm" @click.self="$emit('close')">
    <div class="themed-modal-surface mx-auto flex max-h-full w-full max-w-7xl flex-col overflow-hidden rounded-[32px] border border-white/60 shadow-[0_28px_80px_rgba(15,23,42,0.28)]">
      <header class="flex flex-col gap-4 border-b border-slate-200/80 px-5 py-5 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Nova issue</p>
          <h2 class="mt-1 text-2xl font-semibold text-slate-950 sm:text-3xl">Abrir issue por template</h2>
          <p class="mt-2 text-sm leading-7 text-slate-600">
            Escolha o repositorio, preencha o template e acompanhe o Markdown final antes de enviar.
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="app-btn app-btn-secondary"
            @click="$emit('close')"
          >
            Fechar
          </button>
        </div>
      </header>

      <div class="grid min-h-0 flex-1 gap-5 overflow-y-auto p-5 xl:grid-cols-[minmax(0,1.08fr),minmax(320px,0.92fr)]">
        <article class="grid gap-4">
          <label class="grid gap-2">
            <span class="text-sm font-semibold text-slate-900">Repositorio de destino</span>
            <select
              v-model="selectedRepositoryKey"
              class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
            >
              <option value="">Repositorio padrao do perfil</option>
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
            v-if="workspaceError"
            class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
          >
            {{ workspaceError }}
          </p>

          <article
            v-if="loadingWorkspace"
            class="rounded-[28px] border border-white/60 bg-white/85 p-5 text-sm text-slate-500 shadow-[0_18px_48px_rgba(15,23,42,0.07)]"
          >
            Carregando templates e configuracoes do repositorio...
          </article>

          <template v-else>
            <div class="rounded-[28px] border border-white/60 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)]">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Templates</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Modelos disponiveis</h3>
              </div>

              <div v-if="templates.length" class="mt-4 grid gap-3 md:grid-cols-2">
                <button
                  v-for="template in templates"
                  :key="template.key"
                  type="button"
                  class="grid min-w-0 gap-2 rounded-2xl border p-4 text-left transition"
                  :class="template.key === selectedTemplateKey ? 'border-cyan-300 bg-cyan-50/70 shadow-[0_14px_28px_rgba(14,165,233,0.12)]' : 'border-slate-200 bg-white hover:bg-slate-50'"
                  @click="selectedTemplateKey = template.key"
                >
                  <span class="text-xs font-black uppercase tracking-[0.18em] text-orange-600">[{{ template.titlePrefix || 'issue' }}]</span>
                  <strong class="break-words text-base font-semibold text-slate-950">{{ template.name }}</strong>
                  <small class="break-words text-sm leading-6 text-slate-500">{{ template.description }}</small>
                </button>
              </div>

              <p
                v-else
                class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
              >
                Esse repositorio nao retornou templates disponiveis.
              </p>
            </div>

            <form v-if="selectedTemplate" class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)]" @submit.prevent="submitIssue">
              <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">Titulo</span>
                  <input
                    v-model="title"
                    type="text"
                    placeholder="Ex.: Ajustar fluxo de atendimento no portal"
                    required
                    class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                  >
                </label>

                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">Responsavel</span>
                  <select
                    v-model="selectedAssigneeId"
                    class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
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
              </div>

              <template v-if="isFeatureRequestTemplate">
                <label v-if="featureRequestFields.description" class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">{{ featureRequestFields.description.label }}</span>
                  <textarea
                    v-model="fieldValues[featureRequestFields.description.key]"
                    rows="5"
                    :placeholder="featureRequestFields.description.placeholder || ''"
                    :required="featureRequestFields.description.required"
                    class="min-h-[132px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                  />
                </label>

                <label v-if="featureRequestFields.businessRule" class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">{{ featureRequestFields.businessRule.label }}</span>
                  <textarea
                    v-model="fieldValues[featureRequestFields.businessRule.key]"
                    rows="5"
                    :placeholder="featureRequestFields.businessRule.placeholder || ''"
                    :required="featureRequestFields.businessRule.required"
                    class="min-h-[132px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                  />
                </label>

                <label v-if="featureRequestFields.acceptanceCriteria" class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">{{ featureRequestFields.acceptanceCriteria.label }}</span>
                  <textarea
                    v-model="fieldValues[featureRequestFields.acceptanceCriteria.key]"
                    rows="5"
                    :placeholder="featureRequestFields.acceptanceCriteria.placeholder || ''"
                    :required="featureRequestFields.acceptanceCriteria.required"
                    class="min-h-[132px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                  />
                </label>

                <label class="grid gap-2">
                  <span class="text-sm font-semibold text-slate-900">Tags</span>
                  <MultiSelect
                    v-model="selectedLabels"
                    :options="labelOptions"
                    search-placeholder="Pesquisar ou criar tag"
                    helper-text="Pesquise tags existentes ou crie uma nova no proprio campo."
                    selected-count-suffix="selecionada(s)"
                    create-label-prefix="Criar tag"
                    create-helper-text="A tag sera criada no GitHub ao enviar a issue."
                    existing-option-helper-text="Tag existente no repositorio"
                    empty-options-text="Nenhuma tag cadastrada. Digite para criar a primeira."
                    empty-search-text="Nenhuma tag encontrada para essa busca."
                    empty-idle-text="Digite para pesquisar tags existentes."
                    new-option-badge="Nova"
                  />
                </label>
              </template>

              <div v-else class="grid gap-4 md:grid-cols-2">
                <template v-for="field in selectedTemplate.fields || []" :key="field.key">
                  <label v-if="field.type === 'textarea'" class="grid gap-2 md:col-span-2">
                    <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                    <textarea
                      v-model="fieldValues[field.key]"
                      rows="5"
                      :placeholder="field.placeholder || ''"
                      :required="field.required"
                      class="min-h-[132px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                    />
                  </label>

                  <label v-else-if="field.type === 'list'" class="grid gap-2 md:col-span-2">
                    <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                    <textarea
                      v-model="fieldValues[field.key]"
                      rows="4"
                      :placeholder="field.placeholder || 'Um item por linha.'"
                      :required="field.required"
                      class="min-h-[120px] rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                    />
                    <small class="text-sm text-slate-500">Use uma linha por item. O preview vira lista automaticamente.</small>
                  </label>

                  <label v-else-if="field.type === 'select'" class="grid gap-2">
                    <span class="text-sm font-semibold text-slate-900">{{ field.label }}</span>
                    <select
                      v-model="fieldValues[field.key]"
                      :required="field.required"
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
                      v-model="fieldValues[field.key]"
                      type="text"
                      :placeholder="field.placeholder || ''"
                      :required="field.required"
                      class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300"
                    >
                  </label>
                </template>
              </div>

              <div class="flex flex-wrap gap-2">
                <button
                  type="submit"
                  class="app-btn app-btn-primary"
                  :disabled="submitting"
                >
                  {{ submitting ? 'Criando issue...' : 'Criar issue no GitHub' }}
                </button>
                <button
                  type="button"
                  class="app-btn app-btn-secondary"
                  :disabled="submitting"
                  @click="resetActiveTemplateInputs"
                >
                  Limpar formulario
                </button>
              </div>

              <p
                v-if="submitError"
                class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
              >
                {{ submitError }}
              </p>
            </form>
          </template>
        </article>

        <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)]">
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Preview Markdown</p>
            <h3 class="mt-1 text-2xl font-semibold text-slate-950">Como a issue vai subir</h3>
          </div>

          <div class="rounded-2xl border border-cyan-200 bg-cyan-50/60 px-4 py-3 text-sm font-semibold text-cyan-950">
            {{ previewTitle }}
          </div>

          <div class="min-h-[420px] overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
            <MarkdownPreview :content="previewBody" />
          </div>
        </article>
      </div>
    </div>
  </div>
</template>
