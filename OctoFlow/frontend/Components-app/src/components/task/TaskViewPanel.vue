<script setup>
import { ref, watch } from 'vue'
import { extractRequestErrorMessage } from '../../utils/requestErrors'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  endpoint: {
    type: String,
    default: '/tasks',
  },
  taskId: {
    type: Number,
    default: null,
  },
})

const loading = ref(false)
const error = ref('')
const task = ref(null)

watch(
  () => props.taskId,
  async (newTaskId) => {
    if (!newTaskId) {
      task.value = null
      return
    }

    await loadTask(newTaskId)
  },
  { immediate: true },
)

async function loadTask(taskId) {
  loading.value = true
  error.value = ''

  try {
    const response = await props.request({
      url: `${props.endpoint}/${taskId}`,
      method: 'GET',
    })

    task.value = response?.data?.item || null
  } catch (requestError) {
    task.value = null
    error.value = extractRequestErrorMessage(requestError, 'Falha ao carregar detalhes da tarefa.')
  } finally {
    loading.value = false
  }
}

function statusLabel(taskEntry) {
  return taskEntry?.completed ? 'Concluída' : 'Pendente'
}

function statusTone(taskEntry) {
  return taskEntry?.completed ? 'app-status-badge--success' : 'app-status-badge--warning'
}
</script>

<template>
  <section class="task-panel-block">
    <h4 class="task-panel-title">Visualizar tarefa</h4>

    <p v-if="!taskId" class="task-panel-empty">Selecione uma tarefa na lista para visualizar.</p>
    <p v-else-if="loading" class="task-panel-empty">Carregando detalhes...</p>
    <p v-else-if="error" class="app-feedback app-feedback--danger task-panel-feedback">{{ error }}</p>

    <div v-else-if="task" class="task-panel-view-grid">
      <div>
        <p class="task-panel-meta-label">ID</p>
        <p class="task-panel-meta-value">{{ task.id }}</p>
      </div>
      <div>
        <p class="task-panel-meta-label">Título</p>
        <p class="task-panel-meta-value">{{ task.title }}</p>
      </div>
      <div>
        <p class="task-panel-meta-label">Descrição</p>
        <p class="task-panel-meta-value">{{ task.description || 'Sem descrição' }}</p>
      </div>
      <div>
        <p class="task-panel-meta-label">Status</p>
        <span class="app-status-badge" :class="statusTone(task)">{{ statusLabel(task) }}</span>
      </div>
      <div>
        <p class="task-panel-meta-label">Owner</p>
        <p class="task-panel-meta-value">{{ task.owner?.email || '-' }}</p>
      </div>
    </div>

    <button
      v-if="taskId"
      type="button"
      class="task-panel-button task-panel-button--secondary"
      :disabled="loading"
      @click="loadTask(taskId)"
    >
      {{ loading ? 'Atualizando...' : 'Atualizar view' }}
    </button>
  </section>
</template>
