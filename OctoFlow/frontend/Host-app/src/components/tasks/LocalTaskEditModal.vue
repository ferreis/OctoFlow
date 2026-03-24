<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useNotification } from '../../composables/useNotification'
import { RemoteMarkdownPreview as MarkdownPreview } from '../../federation/remoteComponents'
import { fetchLocalTask, fetchTaskTemplates, syncLocalTaskToGithub, updateLocalTask } from '../../services/tasks'
import { formatDateTime } from '../../utils/date'
import { splitRepositoryKey } from '../../utils/githubRepository'
import { extractHttpMessage } from '../../utils/httpErrors'
import { resolveHistoryBadgeToneClass } from '../../utils/statusTone'
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
  task: {
    type: Object,
    required: true,
  },
  repositories: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'task-updated', 'task-synced'])

const { notifyUser } = useNotification(props.notify)

const loading = ref(false)
const loadingTemplates = ref(false)
const saving = ref(false)
const syncing = ref(false)
const error = ref('')
const templatesError = ref('')
const currentTask = ref(null)
const syncRepositoryKey = ref('')
const taskTemplates = ref([])
const activePanel = ref('view')
const form = reactive({
  title: '',
  body: '',
  templateKey: '',
  repositoryKey: '',
})

const availableRepositories = computed(() => {
  return (props.repositories || [])
    .map((repository) => String(repository?.nameWithOwner || '').trim())
    .filter(Boolean)
    .sort((left, right) => left.localeCompare(right, 'pt-BR'))
})
const availableTemplates = computed(() => {
  return (Array.isArray(taskTemplates.value) ? taskTemplates.value : [])
    .map((template) => ({
      key: String(template?.key || '').trim(),
      name: String(template?.name || '').trim(),
      description: String(template?.description || '').trim(),
      titlePrefix: String(template?.titlePrefix || '').trim(),
    }))
    .filter((template) => template.key !== '' && template.name !== '')
    .sort((leftTemplate, rightTemplate) => leftTemplate.name.localeCompare(rightTemplate.name, 'pt-BR'))
})
const selectedTemplate = computed(() => {
  const selectedTemplateKey = String(form.templateKey || '').trim()
  if (selectedTemplateKey === '') {
    return null
  }

  return availableTemplates.value.find((template) => template.key === selectedTemplateKey) || null
})

const syncStatusLabel = computed(() => {
  if (currentTask.value?.syncState === 'FAILED') {
    return 'Falhou ao sincronizar'
  }

  if (currentTask.value?.state === 'CLOSED') {
    return 'Fechada'
  }

  return 'Aberta'
})
const suggestedRepositoryLabel = computed(() => {
  const repositoryKey = String(currentTask.value?.repositoryKey || '').trim()

  if (currentTask.value?.syncState === 'PENDING') {
    return repositoryKey !== ''
      ? `${repositoryKey} - Pendente de sincronização`
      : 'Pendente de sincronização'
  }

  return repositoryKey || 'Definir depois'
})
const previewTitle = computed(() => String(form.title || '').trim() || 'Titulo da tarefa local')
const previewBody = computed(() => String(form.body || '').trim() || 'Sem conteudo para visualizar.')
const localTaskHistoryEntries = computed(() => {
  const task = currentTask.value
  if (!task || typeof task !== 'object') {
    return []
  }

  const historyEntries = []
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
      description: 'Titulo, conteudo, template ou repositorio sugerido foram atualizados.',
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

onMounted(() => {
  void loadTemplates()
})

watch(
  () => props.task,
  (task) => {
    currentTask.value = task || null
    syncForm(task)
    activePanel.value = 'view'

    if (task?.id) {
      void loadTask(task.id)
    }
  },
  { immediate: true },
)

async function loadTask(taskId) {
  loading.value = true
  error.value = ''

  try {
    const { data } = await fetchLocalTask(props.request, taskId)
    currentTask.value = data?.item || currentTask.value
    syncForm(currentTask.value)
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel carregar os detalhes da tarefa local.')
  } finally {
    loading.value = false
  }
}

async function loadTemplates() {
  loadingTemplates.value = true
  templatesError.value = ''

  try {
    const { data } = await fetchTaskTemplates(props.request)
    taskTemplates.value = Array.isArray(data?.items) ? data.items : []
  } catch (requestError) {
    taskTemplates.value = []
    templatesError.value = extractHttpMessage(requestError, 'Nao foi possivel carregar os templates de tarefa.')
  } finally {
    loadingTemplates.value = false
  }
}

function syncForm(task) {
  form.title = String(task?.title || '')
  form.body = String(task?.body || '')
  form.templateKey = String(task?.templateKey || '')
  form.repositoryKey = String(task?.repositoryKey || '')
  syncRepositoryKey.value = ''
}

async function saveTask() {
  if (!currentTask.value?.id) {
    return
  }

  saving.value = true
  error.value = ''

  try {
    const repository = splitRepositoryKey(form.repositoryKey)
    const { data } = await updateLocalTask(props.request, currentTask.value.id, {
      title: form.title,
      body: form.body,
      templateKey: form.templateKey || null,
      repositoryOwner: repository.owner || null,
      repositoryName: repository.name || null,
    })

    currentTask.value = data?.item || currentTask.value
    syncForm(currentTask.value)
    notifyUser('Tarefa local atualizada com sucesso.', 'success')
    emit('task-updated', data || null)
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel atualizar a tarefa local.')
  } finally {
    saving.value = false
  }
}

async function syncTask() {
  if (!currentTask.value?.id) {
    return
  }

  const selectedRepository = splitRepositoryKey(syncRepositoryKey.value)
  if (selectedRepository.owner === '' || selectedRepository.name === '') {
    error.value = 'Selecione o repositorio GitHub antes de sincronizar a tarefa local.'
    return
  }

  syncing.value = true
  error.value = ''

  try {
    const { data } = await syncLocalTaskToGithub(props.request, currentTask.value.id, {
      repositoryOwner: selectedRepository.owner,
      repositoryName: selectedRepository.name,
    })

    notifyUser('Tarefa local enviada ao GitHub com sucesso.', 'success')
    emit('task-synced', data || null)
    emit('close')
  } catch (requestError) {
    error.value = extractHttpMessage(requestError, 'Nao foi possivel sincronizar a tarefa local com o GitHub.')
  } finally {
    syncing.value = false
  }
}

function historyBadgeClass(kind) {
  return resolveHistoryBadgeToneClass(kind)
}
</script>

<template>
  <TaskModalShell @close="$emit('close')">
      <header class="flex flex-col gap-4 border-b border-slate-200/80 px-5 py-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Tarefa local</p>
            <h2 class="mt-1 text-2xl font-semibold text-slate-950 sm:text-3xl">
              {{ currentTask?.title || 'Editar tarefa local' }}
            </h2>
            <p class="mt-2 text-sm leading-7 text-slate-600">
              Visualize detalhes e histórico da tarefa ou entre na aba de atualização para editar conteúdo, template e sincronização.
            </p>
          </div>

          <button type="button" class="app-btn app-btn-secondary" @click="$emit('close')">
            Fechar
          </button>
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
        <div class="grid gap-5 xl:grid-cols-[minmax(0,1.05fr),minmax(320px,0.95fr)]">
          <article v-if="activePanel === 'view'" class="app-panel-standard grid gap-4 rounded-[28px] p-5">
            <div class="grid gap-3 md:grid-cols-3">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status local</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ syncStatusLabel }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Criada em</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentTask?.createdAt) }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Atualizada em</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentTask?.updatedAt) }}</strong>
              </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Template</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ currentTask?.templateKey || 'personalizado' }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repositorio sugerido</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ suggestedRepositoryLabel }}</strong>
              </div>
            </div>

            <article class="rounded-[24px] border border-slate-200/80 bg-white/80 p-5">
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Resumo</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Informações da tarefa</h3>
              <div class="mt-4 grid gap-3">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                  <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Titulo</span>
                  <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ previewTitle }}</strong>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                  <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Conteudo</span>
                  <p class="mt-2 line-clamp-5 text-sm leading-6 text-slate-700">{{ form.body || 'Sem conteudo para visualizar.' }}</p>
                </div>
              </div>
            </article>

            <p v-if="currentTask?.syncError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
              {{ currentTask.syncError }}
            </p>

            <p v-if="error" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
              {{ error }}
            </p>
          </article>

          <article v-else class="app-panel-standard grid gap-4 rounded-[28px] p-5">
            <div class="grid gap-3 md:grid-cols-3">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status local</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ syncStatusLabel }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Criada em</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentTask?.createdAt) }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Atualizada em</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ formatDateTime(currentTask?.updatedAt) }}</strong>
              </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Template</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ currentTask?.templateKey || 'personalizado' }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repositorio sugerido</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ suggestedRepositoryLabel }}</strong>
              </div>
            </div>

            <div v-if="loading" class="app-empty-panel rounded-2xl px-4 py-6 text-sm text-slate-500">
              Carregando detalhes da tarefa local...
            </div>

            <template v-else>
              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Titulo</span>
                <input v-model="form.title" type="text" class="app-field-control h-11 px-3 text-sm text-slate-900">
              </label>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Conteudo</span>
                <textarea v-model="form.body" class="app-field-control min-h-[220px] px-3 py-3 text-sm leading-6 text-slate-900" />
              </label>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Template</span>
                <select v-model="form.templateKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="">Personalizado (sem template)</option>
                  <option v-for="template in availableTemplates" :key="template.key" :value="template.key">
                    {{ template.name }}
                  </option>
                </select>
              </label>

              <div v-if="loadingTemplates" class="app-empty-panel rounded-2xl px-4 py-3 text-sm text-slate-500">
                Carregando templates disponiveis...
              </div>

              <p v-if="selectedTemplate" class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-600">
                {{ selectedTemplate.description || `Template [${selectedTemplate.titlePrefix || 'issue'}] selecionado.` }}
              </p>

              <p v-if="templatesError" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                {{ templatesError }}
              </p>

              <label class="grid gap-2">
                <span class="text-sm font-semibold text-slate-900">Repositorio sugerido para depois</span>
                <select v-model="form.repositoryKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="">Definir depois</option>
                  <option v-for="repositoryKey in availableRepositories" :key="repositoryKey" :value="repositoryKey">
                    {{ repositoryKey }}
                  </option>
                </select>
              </label>

              <p v-if="currentTask?.syncError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                {{ currentTask.syncError }}
              </p>

              <p v-if="error" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                {{ error }}
              </p>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="app-btn app-btn-primary" :disabled="saving || syncing" @click="saveTask">
                  {{ saving ? 'Salvando...' : 'Salvar alteracoes' }}
                </button>
              </div>
            </template>
          </article>

          <div class="grid gap-4">
            <article class="app-panel-standard grid gap-4 rounded-[28px] p-5">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Conteudo formatado</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Preview da tarefa local</h3>
              </div>

              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm font-semibold text-slate-900">
                {{ previewTitle }}
              </div>

              <div class="min-h-[360px] overflow-auto rounded-[24px] border border-slate-200 bg-white px-5 py-4">
                <MarkdownPreview :content="previewBody" />
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
                      :class="historyBadgeClass(historyEntry.kind)"
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
                <select v-model="syncRepositoryKey" class="app-field-control h-11 appearance-none px-3 text-sm text-slate-900">
                  <option value="">Selecionar repositorio</option>
                  <option v-for="repositoryKey in availableRepositories" :key="repositoryKey" :value="repositoryKey">
                    {{ repositoryKey }}
                  </option>
                </select>
              </label>

              <div v-if="availableRepositories.length === 0" class="app-empty-panel rounded-2xl px-4 py-6 text-sm text-slate-500">
                Nenhum repositorio GitHub disponivel para sincronizacao no momento.
              </div>

              <button
                type="button"
                class="app-btn app-btn-secondary"
                :disabled="syncing || saving || availableRepositories.length === 0"
                @click="syncTask"
              >
                {{ syncing ? 'Enviando ao GitHub...' : 'Enviar ao GitHub' }}
              </button>
            </aside>
          </div>
        </div>
      </div>
  </TaskModalShell>
</template>
