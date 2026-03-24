<script setup>
import { computed, onMounted, ref } from 'vue'
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
  selectedTaskId: {
    type: Number,
    default: null,
  },
})

const emit = defineEmits(['select-task', 'open-view', 'open-edit', 'task-deleted', 'task-status'])

const tasks = ref([])
const loading = ref(false)
const error = ref('')
const rowLoadingId = ref(null)
const statusFilter = ref('all')

const filteredTasks = computed(() => {
  if (statusFilter.value === 'completed') {
    return tasks.value.filter((task) => task.completed)
  }

  if (statusFilter.value === 'pending') {
    return tasks.value.filter((task) => !task.completed)
  }

  return tasks.value
})

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
    error.value = extractRequestErrorMessage(requestError, 'Falha ao carregar lista de tarefas.')
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
      csrfActionId: `task.delete.row.${task.id}`,
    })

    tasks.value = tasks.value.filter((item) => item.id !== task.id)
    emit('task-deleted', task.id)
    emit('task-status', `Tarefa ${task.id} removida.`)
  } catch (requestError) {
    error.value = extractRequestErrorMessage(requestError, 'Falha ao remover tarefa.')
  } finally {
    rowLoadingId.value = null
  }
}

async function toggleStatus(task) {
  rowLoadingId.value = task.id
  error.value = ''

  try {
    const response = await props.request({
      url: `${props.endpoint}/${task.id}`,
      method: 'PATCH',
      csrfActionId: `task.toggle-status.row.${task.id}`,
      data: {
        completed: !task.completed,
      },
    })

    const updatedTask = response?.data?.item || {
      ...task,
      completed: !task.completed,
    }

    tasks.value = tasks.value.map((item) => (item.id === task.id ? updatedTask : item))
    emit('task-status', `Status da tarefa ${task.id} atualizado para ${updatedTask.completed ? 'Concluida' : 'Pendente'}.`)
  } catch (requestError) {
    error.value = extractRequestErrorMessage(requestError, 'Falha ao atualizar status da tarefa.')
  } finally {
    rowLoadingId.value = null
  }
}

function statusLabel(task) {
  return task.completed ? 'Concluida' : 'Pendente'
}

function statusTone(task) {
  return task.completed ? 'app-status-badge--success' : 'app-status-badge--info'
}
</script>

<template>
  <section class="task-panel-block">
    <header class="task-shell-toolbar">
      <h4 class="task-panel-title">Lista de tarefas</h4>
      <button type="button" class="task-panel-button task-panel-button--secondary" :disabled="loading" @click="loadTasks">
        {{ loading ? 'Atualizando...' : 'Atualizar' }}
      </button>
    </header>

    <label class="task-panel-filter">
      <span class="task-panel-filter-label">Filtro de status</span>
      <select v-model="statusFilter" :disabled="loading" class="app-field-control task-panel-field-control">
        <option value="all">Todos</option>
        <option value="pending">Pendentes</option>
        <option value="completed">Concluidas</option>
      </select>
    </label>

    <p v-if="error" class="app-feedback app-feedback--danger task-panel-feedback">{{ error }}</p>
    <p v-if="loading" class="task-panel-empty">Carregando tarefas...</p>
    <p v-else-if="filteredTasks.length === 0" class="task-panel-empty">
      {{ tasks.length === 0 ? 'Nenhuma tarefa encontrada.' : 'Nenhuma tarefa para o status selecionado.' }}
    </p>

    <ul v-else class="task-panel-list">
      <li
        v-for="task in filteredTasks"
        :key="task.id"
        class="task-panel-item"
        :class="{ 'is-selected': task.id === selectedTaskId }"
        tabindex="0"
        @click="selectTask(task)"
        @keydown.enter.prevent="selectTask(task)"
        @keydown.space.prevent="selectTask(task)"
      >
        <div class="task-panel-item-main">
          <div class="task-panel-title-row">
            <p class="task-panel-item-title" :class="{ 'is-done': task.completed }">{{ task.title }}</p>
            <span class="app-status-badge app-status-badge--compact" :class="statusTone(task)">
              {{ statusLabel(task) }}
            </span>
          </div>
          <p class="task-panel-item-description">{{ task.description || 'Sem descricao' }}</p>
        </div>

        <div class="task-panel-actions">
          <button type="button" class="task-panel-button task-panel-button--secondary" @click.stop="openView(task)">View</button>
          <button type="button" class="task-panel-button task-panel-button--secondary" @click.stop="openEdit(task)">Editar</button>
          <button type="button" class="task-panel-button task-panel-button--chip" :disabled="rowLoadingId === task.id" @click.stop="toggleStatus(task)">
            Status
          </button>
          <button type="button" class="task-panel-button task-panel-button--danger" :disabled="rowLoadingId === task.id" @click.stop="removeTask(task)">
            Excluir
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>
