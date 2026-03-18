<script setup>
import { computed, reactive, ref, watch } from 'vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  currentUser: {
    type: Object,
    default: null,
  },
  workspace: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['issue-created'])

const submitting = ref(false)
const submitError = ref('')
const successPayload = ref(null)
const selectedTemplateKey = ref('')
const selectedProjectId = ref('')
const selectedStatusOptionId = ref('')
const title = ref('')
const selectedLabelIds = ref([])
const fieldValues = reactive({})
const templateDrafts = reactive({})

const templates = computed(() => Array.isArray(props.workspace?.templates) ? props.workspace.templates : [])
const repository = computed(() => props.workspace?.repository || null)
const labels = computed(() => Array.isArray(repository.value?.labels) ? repository.value.labels : [])
const projects = computed(() => Array.isArray(props.workspace?.projects) ? props.workspace.projects : [])
const projectsMeta = computed(() => props.workspace?.projectsMeta || { available: true, message: null })
const requesterEmail = computed(() => {
  const primaryEmail = typeof props.currentUser?.defaultEmail === 'string' ? props.currentUser.defaultEmail.trim() : ''
  const fallbackEmail = typeof props.currentUser?.email === 'string' ? props.currentUser.email.trim() : ''

  return primaryEmail || fallbackEmail || 'usuario autenticado'
})
const selectedTemplate = computed(() => templates.value.find((template) => template.key === selectedTemplateKey.value) || null)
const selectedProject = computed(() => projects.value.find((project) => project.id === selectedProjectId.value) || null)
const selectedProjectStatusOptions = computed(() => Array.isArray(selectedProject.value?.statusField?.options) ? selectedProject.value.statusField.options : [])
const previewTitle = computed(() => formatTitle(selectedTemplate.value, title.value))
const previewBody = computed(() => renderPreview(selectedTemplate.value, buildSubmissionFields(selectedTemplate.value)))

watch(
  () => props.workspace,
  () => {
    initializeWorkspaceState()
  },
  { immediate: true },
)

watch(selectedTemplateKey, (newKey, oldKey) => {
  if (oldKey) {
    persistTemplateDraft(oldKey)
  }

  if (newKey) {
    restoreTemplateDraft(newKey)
    successPayload.value = null
    submitError.value = ''
  }
})

watch(selectedProjectId, () => {
  if (!selectedProjectStatusOptions.value.some((option) => option.id === selectedStatusOptionId.value)) {
    selectedStatusOptionId.value = ''
  }
})

function initializeWorkspaceState() {
  selectedTemplateKey.value = ''
  selectedProjectId.value = ''
  selectedStatusOptionId.value = ''
  title.value = ''
  selectedLabelIds.value = []
  submitError.value = ''
  successPayload.value = null

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
    labelIds: [...selectedLabelIds.value],
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
  selectedLabelIds.value = Array.isArray(existingDraft?.labelIds)
    ? existingDraft.labelIds.filter((labelId) => typeof labelId === 'string' && labelId.trim() !== '')
    : resolveDefaultLabelIds(template)

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

function resolveDefaultLabelIds(template) {
  const defaultLabels = Array.isArray(template?.defaultLabels) ? template.defaultLabels : []
  const defaultLabelSet = new Set(defaultLabels.map((labelName) => String(labelName).trim()).filter(Boolean))

  return labels.value
    .filter((label) => defaultLabelSet.has(label.name))
    .map((label) => label.id)
}

function toggleLabel(labelId) {
  const normalizedLabelId = String(labelId || '').trim()
  if (normalizedLabelId === '') {
    return
  }

  if (selectedLabelIds.value.includes(normalizedLabelId)) {
    selectedLabelIds.value = selectedLabelIds.value.filter((currentId) => currentId !== normalizedLabelId)
    return
  }

  selectedLabelIds.value = [...selectedLabelIds.value, normalizedLabelId]
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

function renderPreview(template, submissionFields) {
  if (!template) {
    return 'Selecione um template para ver o preview.'
  }

  const lines = [
    `> Template: ${template.name || 'Issue'}`,
    `> Solicitante: ${requesterEmail.value}`,
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
  selectedLabelIds.value = resolveDefaultLabelIds(template)

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
    labelIds: [...selectedLabelIds.value],
    fields: snapshotFieldValues(template),
  }
}

async function submitIssue() {
  if (!selectedTemplate.value) {
    submitError.value = 'Selecione um template antes de criar a issue.'
    return
  }

  submitting.value = true
  submitError.value = ''
  successPayload.value = null
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
        projectId: selectedProjectId.value || null,
        statusOptionId: selectedStatusOptionId.value || null,
      },
    })

    successPayload.value = data || null
    resetActiveTemplateInputs()
    emit('issue-created', data || null)
  } catch (error) {
    submitError.value = extractHttpMessage(error, 'Nao foi possivel criar a issue no GitHub.')
  } finally {
    submitting.value = false
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

function labelChipStyle(label) {
  const color = typeof label?.color === 'string' && label.color.trim() !== '' ? `#${label.color.trim()}` : '#0f766e'

  return {
    borderColor: color,
    background: `${color}18`,
    color,
  }
}
</script>

<template>
  <section class="grid gap-5 xl:grid-cols-[minmax(0,1.08fr),minmax(320px,0.92fr)]">
    <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div>
        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Templates</p>
        <h3 class="mt-1 text-2xl font-semibold text-slate-950">Abrir novo chamado</h3>
        <p class="mt-3 text-sm leading-7 text-slate-600">
          Este painel remoto cuida apenas da criação da issue. O host decide quando ele deve ser montado.
        </p>
      </div>

      <div v-if="templates.length" class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
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
        class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
      >
        O backend nao retornou templates disponiveis para este workspace.
      </p>

      <form v-if="selectedTemplate" class="grid gap-4" @submit.prevent="submitIssue">
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

        <div class="grid gap-4 md:grid-cols-2">
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

        <div class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
          <div>
            <span class="text-sm font-semibold text-slate-900">Labels</span>
            <div v-if="labels.length" class="mt-3 flex flex-wrap gap-2">
              <button
                v-for="label in labels"
                :key="label.id"
                type="button"
                class="inline-flex max-w-full items-center justify-center rounded-full border px-3 py-1 text-xs font-semibold transition"
                :class="selectedLabelIds.includes(label.id) ? 'shadow-[0_10px_20px_rgba(15,23,42,0.08)]' : ''"
                :style="labelChipStyle(label)"
                @click="toggleLabel(label.id)"
              >
                {{ label.name }}
              </button>
            </div>
            <p v-else class="mt-3 text-sm text-slate-500">Esse repositorio ainda nao expoe labels via GraphQL.</p>
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <label class="grid gap-2">
              <span class="text-sm font-semibold text-slate-900">Project</span>
              <select
                v-model="selectedProjectId"
                :disabled="projects.length === 0"
                class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
              >
                <option value="">Sem project</option>
                <option v-for="project in projects" :key="project.id" :value="project.id">
                  {{ project.title }}
                </option>
              </select>
              <small v-if="!projectsMeta.available && projectsMeta.message" class="text-sm text-slate-500">
                {{ projectsMeta.message }}
              </small>
            </label>

            <label class="grid gap-2">
              <span class="text-sm font-semibold text-slate-900">Status do project</span>
              <select
                v-model="selectedStatusOptionId"
                :disabled="!selectedProject || selectedProjectStatusOptions.length === 0"
                class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-cyan-300 disabled:bg-slate-100"
              >
                <option value="">Sem status inicial</option>
                <option v-for="statusOption in selectedProjectStatusOptions" :key="statusOption.id" :value="statusOption.id">
                  {{ statusOption.name }}
                </option>
              </select>
              <small v-if="selectedProject && selectedProjectStatusOptions.length === 0" class="text-sm text-slate-500">
                O project selecionado nao expoe um campo Status.
              </small>
            </label>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="submit"
            class="inline-flex max-w-full items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/15 transition hover:brightness-105"
            :disabled="submitting"
          >
            {{ submitting ? 'Criando issue...' : 'Criar issue no GitHub' }}
          </button>
          <button
            type="button"
            class="inline-flex max-w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
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

        <div
          v-if="successPayload?.issue"
          class="grid gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
        >
          <p>
            Issue <strong>#{{ successPayload.issue.number }}</strong> criada com sucesso.
            <a :href="successPayload.issue.url" target="_blank" rel="noreferrer noopener" class="font-semibold underline">
              Abrir no GitHub
            </a>
          </p>
          <p v-if="successPayload.project?.attached">A issue tambem foi adicionada ao project selecionado.</p>
          <p v-if="successPayload.project?.statusUpdated">O status inicial do project foi sincronizado no mesmo fluxo.</p>
          <p v-if="successPayload.project?.message">{{ successPayload.project.message }}</p>
        </div>
      </form>
    </article>

    <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
      <div>
        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Preview</p>
        <h3 class="mt-1 text-2xl font-semibold text-slate-950">Como a issue vai subir</h3>
      </div>

      <div class="rounded-2xl border border-cyan-200 bg-cyan-50/60 px-4 py-3 text-sm font-semibold text-cyan-950">
        {{ previewTitle }}
      </div>

      <pre class="min-h-[360px] overflow-auto rounded-[22px] bg-slate-950 p-4 font-mono text-sm leading-7 text-slate-100 whitespace-pre-wrap">{{ previewBody }}</pre>
    </article>
  </section>
</template>
