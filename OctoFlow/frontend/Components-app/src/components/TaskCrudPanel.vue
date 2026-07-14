<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'

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

const TaskListPanel = defineAsyncComponent(() => import('./task/TaskListPanel.vue'))
const TaskCreatePanel = defineAsyncComponent(() => import('./task/TaskCreatePanel.vue'))
const TaskViewPanel = defineAsyncComponent(() => import('./task/TaskViewPanel.vue'))
const TaskEditPanel = defineAsyncComponent(() => import('./task/TaskEditPanel.vue'))

const activeMode = ref('')
const selectedTaskId = ref(null)
const listRefreshKey = ref(0)
const feedbackMessage = ref('')
const feedbackTone = ref('success')

const detailsComponent = computed(() => {
  if (activeMode.value === 'create') {
    return TaskCreatePanel
  }

  if (activeMode.value === 'view') {
    return TaskViewPanel
  }

  if (activeMode.value === 'edit') {
    return TaskEditPanel
  }

  return null
})

const detailsTitle = computed(() => {
  const titleByMode = {
    create: 'Nova tarefa',
    view: 'Detalhes da tarefa',
    edit: 'Editar tarefa',
  }

  return titleByMode[activeMode.value] || ''
})

const detailsProps = computed(() => ({
  request: props.request,
  endpoint: props.endpoint,
  ...(activeMode.value === 'view' || activeMode.value === 'edit'
    ? { taskId: selectedTaskId.value }
    : {}),
}))

function selectTask(taskId) {
  selectedTaskId.value = taskId
  feedbackMessage.value = ''
}

function openCreate() {
  activeMode.value = 'create'
  feedbackMessage.value = ''
}

function openTaskDetails(mode) {
  if (!selectedTaskId.value) {
    feedbackTone.value = 'danger'
    feedbackMessage.value = 'Selecione uma tarefa na lista para continuar.'
    return
  }

  activeMode.value = mode
  feedbackMessage.value = ''
}

function closeDetails() {
  activeMode.value = ''
}

function refreshTaskList() {
  listRefreshKey.value += 1
}

function handleTaskCreated(task) {
  selectedTaskId.value = task?.id || null
  activeMode.value = task?.id ? 'view' : ''
  feedbackTone.value = 'success'
  feedbackMessage.value = 'Tarefa criada com sucesso.'
  refreshTaskList()
}

function handleTaskUpdated(task) {
  selectedTaskId.value = task?.id || selectedTaskId.value
  activeMode.value = 'view'
  feedbackTone.value = 'success'
  feedbackMessage.value = 'Alterações salvas.'
  refreshTaskList()
}

function handleTaskDeleted(taskId) {
  if (selectedTaskId.value === taskId) {
    selectedTaskId.value = null
  }

  activeMode.value = ''
  feedbackTone.value = 'success'
  feedbackMessage.value = 'Tarefa removida.'
  refreshTaskList()
}

function handleTaskStatus(message) {
  if (typeof message !== 'string' || message.trim() === '') {
    return
  }

  feedbackTone.value = 'success'
  feedbackMessage.value = message
  refreshTaskList()
}
</script>

<template>
  <section class="task-workspace" aria-label="Gerenciamento de tarefas">
    <header class="task-workspace-header">
      <div>
        <p class="task-workspace-eyebrow">Organização</p>
        <h2 class="task-workspace-title">Tarefas</h2>
        <p class="task-workspace-description">Selecione uma tarefa para consultar ou editar. Crie novas tarefas sem perder o contexto da lista.</p>
      </div>

      <button type="button" class="task-panel-button task-panel-button--primary" @click="openCreate">
        Nova tarefa
      </button>
    </header>

    <p
      v-if="feedbackMessage"
      class="app-feedback task-panel-feedback"
      :class="feedbackTone === 'danger' ? 'app-feedback--danger' : 'app-feedback--success'"
      role="status"
    >
      {{ feedbackMessage }}
    </p>

    <div class="task-workspace-grid" :class="{ 'has-details': detailsComponent }">
      <div class="task-workspace-list">
        <Suspense>
          <template #default>
            <TaskListPanel
              :key="listRefreshKey"
              :request="request"
              :endpoint="endpoint"
              :selected-task-id="selectedTaskId"
              @select-task="selectTask"
              @open-view="openTaskDetails('view')"
              @open-edit="openTaskDetails('edit')"
              @task-deleted="handleTaskDeleted"
              @task-status="handleTaskStatus"
            />
          </template>
          <template #fallback>
            <p class="task-panel-empty">Carregando tarefas...</p>
          </template>
        </Suspense>
      </div>

      <aside v-if="detailsComponent" class="task-workspace-details" aria-live="polite">
        <header class="task-workspace-details-header">
          <h3>{{ detailsTitle }}</h3>
          <button type="button" class="task-workspace-close" aria-label="Fechar painel" @click="closeDetails">×</button>
        </header>

        <Suspense>
          <template #default>
            <component
              :is="detailsComponent"
              v-bind="detailsProps"
              @task-created="handleTaskCreated"
              @task-updated="handleTaskUpdated"
              @task-status="handleTaskStatus"
            />
          </template>
          <template #fallback>
            <p class="task-panel-empty">Carregando painel...</p>
          </template>
        </Suspense>
      </aside>
    </div>
  </section>
</template>
