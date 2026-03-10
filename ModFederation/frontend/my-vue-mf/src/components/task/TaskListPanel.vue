<script setup>
import { onMounted, ref } from 'vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  endpoint: {
    type: String,
    default: '/tasks',
  },
})

const emit = defineEmits(['select-task', 'open-view', 'open-edit', 'task-deleted', 'task-status'])

const tasks = ref([])
const loading = ref(false)
const error = ref('')
const rowLoadingId = ref(null)

onMounted(() => {
  void loadTasks()
})

async function loadTasks() {
  loading.value = true
  error.value = ''

  try {
    const response = await props.request({
      url: props.endpoint,
      method: 'GET',
    })

    tasks.value = response?.data?.items || []
  } catch (requestError) {
    error.value = extractMessage(requestError, 'Falha ao carregar lista de tarefas.')
  } finally {
    loading.value = false
  }
}

function selectTask(task) {
  emit('select-task', task.id)
  emit('task-status', `Tarefa ${task.id} selecionada.`)
}

function openView(task) {
  emit('select-task', task.id)
  emit('open-view')
}

function openEdit(task) {
  emit('select-task', task.id)
  emit('open-edit')
}

async function removeTask(task) {
  rowLoadingId.value = task.id
  error.value = ''

  try {
    await props.request({
      url: `${props.endpoint}/${task.id}`,
      method: 'DELETE',
    })

    tasks.value = tasks.value.filter((item) => item.id !== task.id)
    emit('task-deleted', task.id)
    emit('task-status', `Tarefa ${task.id} removida.`)
  } catch (requestError) {
    error.value = extractMessage(requestError, 'Falha ao remover tarefa.')
  } finally {
    rowLoadingId.value = null
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
    <header class="task-block-header">
      <h4>Lista de tarefas</h4>
      <button type="button" class="ghost" :disabled="loading" @click="loadTasks">
        {{ loading ? 'Atualizando...' : 'Atualizar' }}
      </button>
    </header>

    <p v-if="error" class="feedback error">{{ error }}</p>
    <p v-if="loading" class="empty">Carregando tarefas...</p>
    <p v-else-if="tasks.length === 0" class="empty">Nenhuma tarefa encontrada.</p>

    <ul v-else class="task-list">
      <li v-for="task in tasks" :key="task.id" class="task-item">
        <div class="task-main">
          <p class="task-title" :class="{ done: task.completed }">{{ task.title }}</p>
          <p class="task-description">{{ task.description || 'Sem descricao' }}</p>
        </div>

        <div class="task-actions">
          <button type="button" class="tag" @click="selectTask(task)">Selecionar</button>
          <button type="button" class="ghost" @click="openView(task)">View</button>
          <button type="button" class="ghost" @click="openEdit(task)">Editar</button>
          <button type="button" class="danger" :disabled="rowLoadingId === task.id" @click="removeTask(task)">
            Excluir
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.task-block {
  border: 1px solid #d1d5db;
  border-radius: 12px;
  padding: 14px;
}

.task-block-header {
  align-items: center;
  display: flex;
  justify-content: space-between;
}

.task-block-header h4 {
  margin: 0;
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

.empty {
  color: #64748b;
  margin-top: 12px;
}

.task-list {
  display: grid;
  gap: 10px;
  list-style: none;
  margin: 12px 0 0;
  padding: 0;
}

.task-item {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  display: flex;
  gap: 10px;
  justify-content: space-between;
  padding: 10px;
}

.task-title {
  color: #0f172a;
  font-weight: 700;
  margin: 0;
}

.task-title.done {
  color: #0f766e;
  text-decoration: line-through;
}

.task-description {
  color: #475569;
  margin: 4px 0 0;
}

.task-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}

button {
  background: linear-gradient(90deg, #0f766e 0%, #0369a1 100%);
  border: 0;
  border-radius: 8px;
  color: #ffffff;
  cursor: pointer;
  font-weight: 700;
  padding: 8px 10px;
}

button.ghost {
  background: #e2e8f0;
  color: #0f172a;
}

button.tag {
  background: #f1f5f9;
  color: #0f172a;
}

button.danger {
  background: #ef4444;
}

button:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

@media (max-width: 700px) {
  .task-item {
    flex-direction: column;
  }

  .task-actions {
    justify-content: flex-start;
  }
}
</style>
