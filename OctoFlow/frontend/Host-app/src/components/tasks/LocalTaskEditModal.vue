<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useNotification } from '../../composables/useNotification'
import { fetchLocalTask, syncLocalTaskToGithub, updateLocalTask } from '../../services/tasks'
import { formatDateTime } from '../../utils/date'
import { splitRepositoryKey } from '../../utils/githubRepository'
import { extractHttpMessage } from '../../utils/httpErrors'

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
const saving = ref(false)
const syncing = ref(false)
const error = ref('')
const currentTask = ref(null)
const syncRepositoryKey = ref('')
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

const syncStatusLabel = computed(() => {
  if (currentTask.value?.syncState === 'FAILED') {
    return 'Falhou ao sincronizar'
  }

  if (currentTask.value?.syncState === 'PENDING') {
    return 'Pendente de sincronização'
  }

  return 'Sincronizada'
})

watch(
  () => props.task,
  (task) => {
    currentTask.value = task || null
    syncForm(task)

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
</script>

<template>
  <div class="fixed inset-0 z-50 bg-slate-950/55 px-4 py-6 backdrop-blur-sm" @click.self="$emit('close')">
    <div class="themed-modal-surface mx-auto flex max-h-full w-full max-w-5xl flex-col overflow-hidden rounded-[32px] border border-white/60 shadow-[0_28px_80px_rgba(15,23,42,0.28)]">
      <header class="flex flex-col gap-4 border-b border-slate-200/80 px-5 py-5 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Tarefa local</p>
          <h2 class="mt-1 text-2xl font-semibold text-slate-950 sm:text-3xl">
            {{ currentTask?.title || 'Editar tarefa local' }}
          </h2>
          <p class="mt-2 text-sm leading-7 text-slate-600">
            Salve ajustes locais normalmente. Para publicar no GitHub, escolha explicitamente o repositorio antes de sincronizar.
          </p>
        </div>

        <button type="button" class="app-btn app-btn-secondary" @click="$emit('close')">
          Fechar
        </button>
      </header>

      <div class="grid min-h-0 flex-1 gap-5 overflow-y-auto p-5 xl:grid-cols-[minmax(0,1.05fr),minmax(320px,0.95fr)]">
        <article class="app-panel-standard grid gap-4 rounded-[28px] p-5">
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
</template>
