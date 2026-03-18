<script setup>
import { ref, watch } from 'vue'

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
    error.value = extractMessage(requestError, 'Falha ao carregar detalhes da tarefa.')
  } finally {
    loading.value = false
  }
}

function extractMessage(error, fallback) {
  if (error && typeof error === 'object') {
    const responseMessage = error.response?.data?.message
    if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
      return responseMessage
    }

    const message = error.message
    if (typeof message === 'string' && message.trim() !== '') {
      return message
    }
  }

  return fallback
}
</script>

<template>
  <section class="task-block">
    <h4>Visualizar tarefa</h4>

    <p v-if="!taskId" class="empty">Selecione uma tarefa na lista para visualizar.</p>
    <p v-else-if="loading" class="empty">Carregando detalhes...</p>
    <p v-else-if="error" class="feedback error">{{ error }}</p>

    <div v-else-if="task" class="task-view-grid">
      <div>
        <p class="label">ID</p>
        <p class="value">{{ task.id }}</p>
      </div>
      <div>
        <p class="label">Titulo</p>
        <p class="value">{{ task.title }}</p>
      </div>
      <div>
        <p class="label">Descriçao</p>
        <p class="value">{{ task.description || 'Sem Descriçao' }}</p>
      </div>
      <div>
        <p class="label">Status</p>
        <p class="value">{{ task.completed ? 'Concluida' : 'Pendente' }}</p>
      </div>
      <div>
        <p class="label">Owner</p>
        <p class="value">{{ task.owner?.email || '-' }}</p>
      </div>
    </div>

    <button v-if="taskId" type="button" class="ghost" :disabled="loading" @click="loadTask(taskId)">
      {{ loading ? 'Atualizando...' : 'Atualizar view' }}
    </button>
  </section>
</template>

<style scoped>
.task-block {
  border: 1px solid #d1d5db;
  border-radius: 12px;
  padding: 14px;
}

.task-block h4 {
  margin: 0 0 10px;
}

.empty {
  color: #64748b;
  margin: 8px 0 0;
}

.feedback {
  border-radius: 8px;
  font-weight: 600;
  margin-top: 10px;
  padding: 8px 10px;
}

.feedback.error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
}

.task-view-grid {
  display: grid;
  gap: 10px;
  margin-top: 8px;
}

.label {
  color: #64748b;
  font-size: 0.8rem;
  font-weight: 700;
  margin: 0;
  text-transform: uppercase;
}

.value {
  color: #0f172a;
  font-weight: 600;
  margin: 3px 0 0;
}

button {
  background: #e2e8f0;
  border: 0;
  border-radius: 8px;
  color: #0f172a;
  cursor: pointer;
  font-weight: 700;
  margin-top: 12px;
  padding: 8px 10px;
}

button:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}
</style>
