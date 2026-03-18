<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  currentUser: {
    type: Object,
    default: null,
  },
})

const profile = ref(null)
const profileLoading = ref(false)
const profileError = ref('')
const profileSuccess = ref('')
const savingProfile = ref(false)
const editingProfile = ref(false)

const workspace = ref(null)
const workspaceLoading = ref(false)
const submitting = ref(false)
const loadError = ref('')
const submitError = ref('')
const successPayload = ref(null)
const selectedTemplateKey = ref('')
const selectedProjectId = ref('')
const selectedStatusOptionId = ref('')
const title = ref('')
const selectedLabelIds = ref([])
const fieldValues = reactive({})
const templateDrafts = reactive({})
const profileForm = reactive({
  repositoryOwner: '',
  repositoryName: '',
  token: '',
  clearToken: false,
})

const templates = computed(() => Array.isArray(workspace.value?.templates) ? workspace.value.templates : [])
const repository = computed(() => workspace.value?.repository || null)
const labels = computed(() => Array.isArray(repository.value?.labels) ? repository.value.labels : [])
const projects = computed(() => Array.isArray(workspace.value?.projects) ? workspace.value.projects : [])
const projectsMeta = computed(() => workspace.value?.projectsMeta || { available: true, message: null })
const requesterEmail = computed(() => typeof props.currentUser?.email === 'string' && props.currentUser.email.trim() !== '' ? props.currentUser.email.trim() : 'usuario autenticado')
const selectedTemplate = computed(() => templates.value.find((template) => template.key === selectedTemplateKey.value) || null)
const selectedProject = computed(() => projects.value.find((project) => project.id === selectedProjectId.value) || null)
const selectedProjectStatusOptions = computed(() => Array.isArray(selectedProject.value?.statusField?.options) ? selectedProject.value.statusField.options : [])
const previewTitle = computed(() => formatTitle(selectedTemplate.value, title.value))
const previewBody = computed(() => renderPreview(selectedTemplate.value, buildSubmissionFields(selectedTemplate.value)))
const tokenConfigured = computed(() => Boolean(profile.value?.tokenConfigured))
const workspaceReady = computed(() => Boolean(profile.value?.workspaceReady))

onMounted(async () => {
  await loadProfile()

  if (workspaceReady.value) {
    await loadWorkspace()
  }
})

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

async function loadProfile() {
  profileLoading.value = true
  profileError.value = ''

  try {
    const { data } = await props.request({
      url: '/github/profile',
      method: 'GET',
    })

    profile.value = data?.profile || null
    syncProfileForm()
    editingProfile.value = !workspaceReady.value

    if (!workspaceReady.value) {
      resetWorkspaceData()
    }
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel carregar a configuração GitHub do seu perfil.')
  } finally {
    profileLoading.value = false
  }
}

function syncProfileForm() {
  profileForm.repositoryOwner = typeof profile.value?.repositoryOwner === 'string' ? profile.value.repositoryOwner : ''
  profileForm.repositoryName = typeof profile.value?.repositoryName === 'string' ? profile.value.repositoryName : ''
  profileForm.token = ''
  profileForm.clearToken = false
}

async function saveProfile() {
  savingProfile.value = true
  profileError.value = ''
  profileSuccess.value = ''

  try {
    const { data } = await props.request({
      url: '/github/profile',
      method: 'PATCH',
      csrfActionId: 'github.profile.update',
      data: {
        repositoryOwner: profileForm.repositoryOwner,
        repositoryName: profileForm.repositoryName,
        token: profileForm.token,
        clearToken: profileForm.clearToken,
      },
    })

    profile.value = data?.profile || null
    syncProfileForm()
    profileSuccess.value = 'Configuração do perfil GitHub salva com sucesso.'
    editingProfile.value = !workspaceReady.value

    if (workspaceReady.value) {
      await loadWorkspace()
    } else {
      resetWorkspaceData()
    }
  } catch (error) {
    profileError.value = extractHttpMessage(error, 'Nao foi possivel salvar a configuração GitHub do perfil.')
  } finally {
    savingProfile.value = false
  }
}

async function loadWorkspace() {
  workspaceLoading.value = true
  loadError.value = ''

  try {
    const { data } = await props.request({
      url: '/github/workspace',
      method: 'GET',
    })

    workspace.value = data || null

    if (templates.value.length === 0) {
      loadError.value = 'O backend nao retornou templates do GitHub.'
      return
    }

    const firstTemplateKey = templates.value[0]?.key || ''
    selectedTemplateKey.value = selectedTemplateKey.value && templates.value.some((template) => template.key === selectedTemplateKey.value)
      ? selectedTemplateKey.value
      : firstTemplateKey
  } catch (error) {
    loadError.value = extractHttpMessage(error, 'Nao foi possivel carregar o workspace do GitHub.')
  } finally {
    workspaceLoading.value = false
  }
}

function resetWorkspaceData() {
  workspace.value = null
  loadError.value = ''
  selectedTemplateKey.value = ''
  selectedProjectId.value = ''
  selectedStatusOptionId.value = ''
  title.value = ''
  selectedLabelIds.value = []
  successPayload.value = null
  submitError.value = ''

  for (const key of Object.keys(fieldValues)) {
    delete fieldValues[key]
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
    '> Origem:' ,
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

function projectStatusStyle(option) {
  const palette = {
    BLUE: '#2563eb',
    GRAY: '#475569',
    GREEN: '#15803d',
    ORANGE: '#ea580c',
    PINK: '#db2777',
    PURPLE: '#7c3aed',
    RED: '#dc2626',
    YELLOW: '#ca8a04',
  }

  const normalizedColor = typeof option?.color === 'string' ? option.color.toUpperCase() : ''
  const tone = palette[normalizedColor] || '#0f766e'

  return {
    borderColor: tone,
    background: `${tone}18`,
    color: tone,
  }
}
</script>

<template>
  <section class="workspace-shell text-slate-900">
    <header class="workspace-header">
      <div>
        <p class="kicker">OctoFlow</p>
        <h3>Workspace por usuario com configuração salva no banco</h3>
        <p>
          Cada usuario salva seu proprio owner, repositorio e token do GitHub no perfil.
          O token fica criptografado no backend e nunca volta em texto puro para o frontend.
        </p>
      </div>

      <div class="repo-pill">
        <span>Perfil autenticado</span>
        <strong>{{ requesterEmail }}</strong>
      </div>
    </header>

    <article class="surface profile-card">
      <div class="section-heading">
        <div>
          <p class="section-kicker">Perfil</p>
          <h4>Configuração GitHub do usuario</h4>
        </div>

        <button
          v-if="workspaceReady && !editingProfile"
          type="button"
          class="ghost small"
          @click="editingProfile = true"
        >
          Editar configuração
        </button>
      </div>

      <div v-if="profileLoading" class="loading-state inline-loader">
        <p>Carregando configuração do perfil...</p>
      </div>

      <template v-else>
        <div v-if="profileError" class="notice error">
          <p>{{ profileError }}</p>
        </div>

        <div v-if="profileSuccess" class="notice success">
          <p>{{ profileSuccess }}</p>
        </div>

        <div class="profile-grid">
          <div class="profile-summary">
            <div class="profile-status">
              <span class="profile-label">Repositorio</span>
              <strong>{{ profile?.repositoryOwner || 'nao configurado' }}/{{ profile?.repositoryName || 'nao configurado' }}</strong>
            </div>

            <div class="status-row">
              <span class="summary-pill" :class="{ active: tokenConfigured }">
                {{ tokenConfigured ? 'Token salvo' : 'Token ausente' }}
              </span>
              <span class="summary-pill" :class="{ active: workspaceReady }">
                {{ workspaceReady ? 'Workspace pronto' : 'Workspace incompleto' }}
              </span>
            </div>

            <p class="muted-copy">
              Quando o token ja estiver salvo, voce pode trocar apenas owner/repositorio sem precisar reenviar o token.
            </p>
          </div>

          <form v-if="editingProfile || !workspaceReady" class="profile-form" @submit.prevent="saveProfile">
            <label class="field">
              <span>Repository owner</span>
              <input v-model="profileForm.repositoryOwner" type="text" placeholder="sua-org-ou-usuario" required>
            </label>

            <label class="field">
              <span>Repository name</span>
              <input v-model="profileForm.repositoryName" type="text" placeholder="nome-do-repositorio" required>
            </label>

            <label class="field field-wide">
              <span>GitHub token</span>
              <input
                v-model="profileForm.token"
                type="password"
                :placeholder="tokenConfigured ? 'Deixe vazio para manter o token atual' : 'ghp_xxxxxxxxxxxxxxxxxxxx'"
              >
              <small>O token fica criptografado no banco. Nunca exibimos o valor salvo de volta.</small>
            </label>

            <label class="checkbox-row field-wide">
              <input v-model="profileForm.clearToken" type="checkbox">
              <span>Remover token salvo ao atualizar o perfil</span>
            </label>

            <div class="form-actions field-wide">
              <button type="submit" class="primary" :disabled="savingProfile">
                {{ savingProfile ? 'Salvando perfil...' : 'Salvar configuração' }}
              </button>
              <button
                v-if="workspaceReady"
                type="button"
                class="ghost"
                :disabled="savingProfile"
                @click="editingProfile = false; syncProfileForm()"
              >
                Cancelar
              </button>
            </div>
          </form>
        </div>
      </template>
    </article>

    <div v-if="!workspaceReady" class="surface notice empty-state">
      <p>
        Complete a configuração do seu perfil GitHub para liberar labels, templates, criação de issues e sincronização com Projects.
      </p>
    </div>

    <div v-else-if="workspaceLoading" class="surface loading-state">
      <p>Carregando configuração do repositorio, labels e Projects do GitHub...</p>
    </div>

    <div v-else-if="loadError" class="surface notice error">
      <p>{{ loadError }}</p>
    </div>

    <div v-else class="workspace-grid">
      <section class="workspace-main">
        <article class="surface summary-card">
          <div class="summary-copy">
            <h4>{{ repository?.nameWithOwner }}</h4>
            <p>{{ repository?.description || 'Sem Descriçao cadastrada no GitHub.' }}</p>
            <a v-if="repository?.url" :href="repository.url" target="_blank" rel="noreferrer noopener">Abrir repositorio</a>
          </div>

          <div class="summary-stats">
            <div class="stat">
              <span>Templates</span>
              <strong>{{ templates.length }}</strong>
            </div>
            <div class="stat">
              <span>Labels</span>
              <strong>{{ labels.length }}</strong>
            </div>
            <div class="stat">
              <span>Projects</span>
              <strong>{{ projects.length }}</strong>
            </div>
          </div>
        </article>

        <article class="surface">
          <div class="section-heading">
            <div>
              <p class="section-kicker">1. Template</p>
              <h4>Escolha o modelo da issue</h4>
            </div>
          </div>

          <div class="template-grid">
            <button
              v-for="template in templates"
              :key="template.key"
              type="button"
              class="template-card min-w-0"
              :class="{ active: template.key === selectedTemplateKey }"
              @click="selectedTemplateKey = template.key"
            >
              <span class="template-prefix">[{{ template.titlePrefix || 'issue' }}]</span>
              <strong>{{ template.name }}</strong>
              <small>{{ template.description }}</small>
            </button>
          </div>
        </article>

        <article class="surface">
          <div class="section-heading">
            <div>
              <p class="section-kicker">2. Conteudo</p>
              <h4>Preencha o briefing da issue</h4>
            </div>
          </div>

          <form class="issue-form" @submit.prevent="submitIssue">
            <label class="field field-wide">
              <span>Titulo</span>
              <input
                v-model="title"
                type="text"
                placeholder="Ex.: Integrar criação de issues com Projects do GitHub"
                required
              >
            </label>

            <template v-for="field in selectedTemplate?.fields || []" :key="field.key">
              <label v-if="field.type === 'textarea'" class="field field-wide">
                <span>{{ field.label }}</span>
                <textarea
                  v-model="fieldValues[field.key]"
                  rows="5"
                  :placeholder="field.placeholder || ''"
                  :required="field.required"
                />
              </label>

              <label v-else-if="field.type === 'list'" class="field field-wide">
                <span>{{ field.label }}</span>
                <textarea
                  v-model="fieldValues[field.key]"
                  rows="4"
                  :placeholder="field.placeholder || 'Um item por linha.'"
                  :required="field.required"
                />
                <small>Use uma linha por item. O preview vira lista automaticamente.</small>
              </label>

              <label v-else-if="field.type === 'select'" class="field">
                <span>{{ field.label }}</span>
                <select v-model="fieldValues[field.key]" :required="field.required">
                  <option value="">Selecione</option>
                  <option v-for="option in field.options || []" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>

              <label v-else class="field">
                <span>{{ field.label }}</span>
                <input
                  v-model="fieldValues[field.key]"
                  type="text"
                  :placeholder="field.placeholder || ''"
                  :required="field.required"
                >
              </label>
            </template>

            <div class="field field-wide">
              <span>Labels</span>
              <div v-if="labels.length" class="label-grid">
                <button
                  v-for="label in labels"
                  :key="label.id"
                  type="button"
                  class="label-chip inline-flex max-w-full items-center justify-center"
                  :class="{ active: selectedLabelIds.includes(label.id) }"
                  :style="labelChipStyle(label)"
                  @click="toggleLabel(label.id)"
                >
                  {{ label.name }}
                </button>
              </div>
              <p v-else class="muted-copy">Esse repositorio ainda nao expoe labels via GraphQL.</p>
            </div>

            <div class="field">
              <span>Project</span>
              <select v-model="selectedProjectId" :disabled="projects.length === 0">
                <option value="">Sem project</option>
                <option v-for="project in projects" :key="project.id" :value="project.id">
                  {{ project.title }}
                </option>
              </select>
              <small v-if="!projectsMeta.available && projectsMeta.message">{{ projectsMeta.message }}</small>
            </div>

            <div class="field">
              <span>Status do Project</span>
              <select v-model="selectedStatusOptionId" :disabled="!selectedProject || selectedProjectStatusOptions.length === 0">
                <option value="">Sem status inicial</option>
                <option v-for="statusOption in selectedProjectStatusOptions" :key="statusOption.id" :value="statusOption.id">
                  {{ statusOption.name }}
                </option>
              </select>
              <small v-if="selectedProject && selectedProjectStatusOptions.length === 0">
                O project selecionado nao expoe um campo Status.
              </small>
            </div>

            <div class="form-actions">
              <button
                type="submit"
                class="primary inline-flex max-w-full items-center justify-center"
                :disabled="submitting"
              >
                {{ submitting ? 'Criando issue...' : 'Criar issue no GitHub' }}
              </button>
              <button
                type="button"
                class="ghost inline-flex max-w-full items-center justify-center"
                :disabled="submitting"
                @click="resetActiveTemplateInputs"
              >
                Limpar formulario
              </button>
            </div>
          </form>

          <div v-if="submitError" class="notice error">
            <p>{{ submitError }}</p>
          </div>

          <div v-if="successPayload?.issue" class="notice success">
            <p>
              Issue <strong>#{{ successPayload.issue.number }}</strong> criada com sucesso.
              <a :href="successPayload.issue.url" target="_blank" rel="noreferrer noopener">Abrir no GitHub</a>
            </p>
            <p v-if="successPayload.project?.attached">
              A issue tambem foi adicionada ao project selecionado.
            </p>
            <p v-if="successPayload.project?.statusUpdated">
              O status inicial do project foi sincronizado no mesmo fluxo.
            </p>
            <p v-if="successPayload.project?.message" class="warning-copy">
              {{ successPayload.project.message }}
            </p>
          </div>
        </article>
      </section>

      <aside class="workspace-side">
        <article class="surface preview-card">
          <div class="section-heading">
            <div>
              <p class="section-kicker">3. Preview</p>
              <h4>Como a issue vai subir para o GitHub</h4>
            </div>
          </div>

          <div class="preview-title">{{ previewTitle }}</div>
          <pre class="preview-body">{{ previewBody }}</pre>
        </article>

        <article class="surface project-card">
          <div class="section-heading">
            <div>
              <p class="section-kicker">Projects</p>
              <h4>Estado atual do owner</h4>
            </div>
          </div>

          <div v-if="projects.length" class="project-list">
            <article v-for="project in projects" :key="project.id" class="project-item">
              <div class="project-topline">
                <strong>{{ project.title }}</strong>
                <a v-if="project.url" :href="project.url" target="_blank" rel="noreferrer noopener">Abrir</a>
              </div>
              <p>{{ project.shortDescription || 'Sem Descriçao curta.' }}</p>

              <div v-if="project.statusField?.options?.length" class="status-list">
                <span
                  v-for="statusOption in project.statusField.options"
                  :key="statusOption.id"
                  class="status-pill"
                  :style="projectStatusStyle(statusOption)"
                >
                  {{ statusOption.name }}
                </span>
              </div>
            </article>
          </div>

          <p v-else class="muted-copy">
            Nenhum project disponivel para esse owner ou o token nao possui o escopo necessario.
          </p>
        </article>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.workspace-shell {
  display: grid;
  gap: 18px;
  min-width: 0;
}

.workspace-header {
  align-items: end;
  display: flex;
  gap: 18px;
  justify-content: space-between;
}

.workspace-header h3 {
  font-size: clamp(1.35rem, 2.2vw, 1.8rem);
  margin: 0;
}

.workspace-header p {
  color: #475569;
  margin: 8px 0 0;
  max-width: 720px;
}

.kicker,
.section-kicker {
  color: #b45309;
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  margin: 0 0 6px;
  text-transform: uppercase;
}

.repo-pill {
  background: linear-gradient(135deg, rgba(15, 118, 110, 0.12), rgba(234, 88, 12, 0.12));
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 16px;
  display: grid;
  gap: 4px;
  min-width: 210px;
  padding: 14px 16px;
}

.repo-pill span {
  color: #64748b;
  font-size: 0.8rem;
}

.repo-pill strong {
  font-size: 1rem;
}

.workspace-grid {
  display: grid;
  gap: 18px;
  grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.95fr);
}

.workspace-main,
.workspace-side {
  display: grid;
  gap: 18px;
  min-width: 0;
}

.surface {
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(240, 249, 255, 0.96)),
    radial-gradient(circle at top right, rgba(249, 115, 22, 0.14), transparent 30%);
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-radius: 24px;
  box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
  padding: 22px;
}

.section-heading {
  align-items: start;
  display: flex;
  gap: 12px;
  justify-content: space-between;
  margin-bottom: 16px;
}

.section-heading h4 {
  margin: 0;
}

.profile-card,
.profile-grid,
.profile-summary,
.profile-form {
  display: grid;
  gap: 16px;
}

.profile-grid {
  grid-template-columns: minmax(260px, 0.8fr) minmax(0, 1.2fr);
}

.profile-status {
  display: grid;
  gap: 6px;
}

.profile-label {
  color: #64748b;
  font-size: 0.82rem;
}

.status-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.summary-pill {
  background: rgba(148, 163, 184, 0.12);
  border: 1px solid rgba(148, 163, 184, 0.28);
  border-radius: 999px;
  color: #475569;
  display: inline-flex;
  font-size: 0.82rem;
  font-weight: 700;
  padding: 8px 12px;
}

.summary-pill.active {
  background: rgba(15, 118, 110, 0.12);
  border-color: rgba(15, 118, 110, 0.28);
  color: #0f766e;
}

.summary-card {
  align-items: start;
  display: grid;
  gap: 18px;
  grid-template-columns: minmax(0, 1.2fr) minmax(220px, 0.8fr);
}

.summary-copy h4 {
  font-size: 1.15rem;
  margin: 0;
}

.summary-copy p {
  color: #475569;
  margin: 8px 0 12px;
}

.summary-copy a,
.project-topline a,
.notice a {
  color: #0f766e;
  font-weight: 700;
  text-decoration: none;
}

.summary-stats {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.stat {
  background: rgba(255, 255, 255, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.25);
  border-radius: 18px;
  display: grid;
  gap: 4px;
  padding: 16px 14px;
}

.stat span {
  color: #64748b;
  font-size: 0.82rem;
}

.stat strong {
  font-size: 1.4rem;
}

.template-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}

.template-card {
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.85), rgba(226, 232, 240, 0.85));
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 18px;
  color: #0f172a;
  cursor: pointer;
  display: grid;
  gap: 8px;
  min-width: 0;
  min-height: 132px;
  padding: 18px;
  text-align: left;
  transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.template-card:hover,
.template-card.active {
  border-color: rgba(15, 118, 110, 0.7);
  box-shadow: 0 14px 28px rgba(15, 118, 110, 0.14);
  transform: translateY(-2px);
}

.template-prefix {
  color: #b45309;
  font-size: 0.82rem;
  font-weight: 800;
}

.template-card strong {
  font-size: 1rem;
}

.template-card small {
  color: #475569;
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.issue-form,
.profile-form {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field {
  display: grid;
  gap: 8px;
}

.field-wide {
  grid-column: 1 / -1;
}

.field span {
  color: #0f172a;
  font-weight: 700;
}

.field input,
.field textarea,
.field select {
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(148, 163, 184, 0.5);
  border-radius: 14px;
  color: #0f172a;
  font: inherit;
  padding: 12px 14px;
  width: 100%;
}

.field textarea {
  min-height: 118px;
  resize: vertical;
}

.field small,
.muted-copy {
  color: #64748b;
  line-height: 1.45;
  margin: 0;
}

.checkbox-row {
  align-items: center;
  display: flex;
  gap: 10px;
}

.checkbox-row input {
  width: auto;
}

.label-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.label-chip,
.status-pill,
.small {
  border: 1px solid transparent;
  border-radius: 999px;
  display: inline-flex;
  flex: 0 0 auto;
  font-size: 0.84rem;
  font-weight: 700;
  max-width: 100%;
  padding: 8px 12px;
}

.label-chip {
  cursor: pointer;
  transition: transform 160ms ease, box-shadow 160ms ease;
}

.label-chip.active {
  box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
  transform: translateY(-1px);
}

.form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  grid-column: 1 / -1;
  margin-top: 6px;
}

.primary,
.ghost {
  border: 0;
  border-radius: 14px;
  cursor: pointer;
  display: inline-flex;
  flex: 0 0 auto;
  font: inherit;
  font-weight: 800;
  justify-content: center;
  max-width: 100%;
  padding: 12px 16px;
}

.primary {
  background: linear-gradient(90deg, #0f766e, #0f766e 45%, #ea580c);
  color: #fff;
}

.ghost {
  background: #e2e8f0;
  color: #0f172a;
}

.small {
  padding: 10px 14px;
}

.primary:disabled,
.ghost:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.preview-title {
  background: rgba(15, 118, 110, 0.09);
  border: 1px solid rgba(15, 118, 110, 0.2);
  border-radius: 16px;
  font-size: 1rem;
  font-weight: 800;
  margin-bottom: 14px;
  padding: 14px 16px;
}

.preview-body {
  background: #0f172a;
  border-radius: 18px;
  color: #e2e8f0;
  font-family: 'IBM Plex Mono', 'SFMono-Regular', monospace;
  font-size: 0.9rem;
  line-height: 1.55;
  margin: 0;
  min-height: 340px;
  overflow: auto;
  padding: 18px;
  white-space: pre-wrap;
}

.project-list {
  display: grid;
  gap: 12px;
}

.project-item {
  background: rgba(255, 255, 255, 0.76);
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 18px;
  display: grid;
  gap: 10px;
  padding: 16px;
}

.project-topline {
  align-items: start;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
}

.project-item p,
.notice p {
  color: #475569;
  margin: 0;
}

.status-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.notice {
  display: grid;
  gap: 8px;
}

.notice.success {
  background: rgba(15, 118, 110, 0.1);
  border: 1px solid rgba(15, 118, 110, 0.24);
}

.notice.error {
  background: rgba(220, 38, 38, 0.08);
  border: 1px solid rgba(220, 38, 38, 0.2);
}

.warning-copy {
  color: #b45309;
  font-weight: 700;
}

.loading-state {
  align-items: center;
  display: flex;
  justify-content: center;
  min-height: 180px;
}

.inline-loader {
  min-height: 80px;
}

.empty-state {
  text-align: center;
}

@media (max-width: 1120px) {
  .workspace-grid,
  .profile-grid {
    grid-template-columns: 1fr;
  }

  .summary-card {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .workspace-header {
    align-items: start;
    flex-direction: column;
  }

  .summary-stats,
  .issue-form,
  .profile-form {
    grid-template-columns: 1fr;
  }

  .form-actions {
    align-items: flex-start;
    flex-direction: row;
  }
}
</style>
