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

const activeAction = ref(null)
const selectedTaskId = ref(null)
const mountKey = ref(0)
const panelError = ref('')
const panelStatus = ref('')

const actionComponents = {
  list: defineAsyncComponent(() => import('./task/TaskListPanel.vue')),
  create: defineAsyncComponent(() => import('./task/TaskCreatePanel.vue')),
  view: defineAsyncComponent(() => import('./task/TaskViewPanel.vue')),
  edit: defineAsyncComponent(() => import('./task/TaskEditPanel.vue')),
}

const currentComponent = computed(() => {
  if (!activeAction.value) {
    return null
  }

  return actionComponents[activeAction.value] || null
})

const currentComponentProps = computed(() => {
  const baseProps = {
    request: props.request,
    endpoint: props.endpoint,
  }

  if (activeAction.value === 'list') {
    return {
      ...baseProps,
      selectedTaskId: selectedTaskId.value,
    }
  }

  if (activeAction.value === 'view' || activeAction.value === 'edit') {
    return {
      ...baseProps,
      taskId: selectedTaskId.value,
    }
  }

  return baseProps
})

function openAction(action) {
  if ((action === 'view' || action === 'edit') && !selectedTaskId.value) {
    panelError.value = 'Selecione uma tarefa na lista antes de abrir View ou Editar.'
    return
  }

  panelError.value = ''
  panelStatus.value = ''
  activeAction.value = action
  mountKey.value += 1
}

function closeAction() {
  if (!activeAction.value) {
    return
  }

  activeAction.value = null
  mountKey.value += 1
  panelStatus.value = 'Componente encerrado e destruido. Clique em uma ação para montar novamente.'
}

function onTaskSelected(taskId) {
  selectedTaskId.value = taskId
  panelError.value = ''
}

function onTaskDeleted(taskId) {
  if (selectedTaskId.value === taskId) {
    selectedTaskId.value = null
  }
}

function onTaskCreated(task) {
  if (task?.id) {
    selectedTaskId.value = task.id
  }
}

function onTaskUpdated(task) {
  if (task?.id) {
    selectedTaskId.value = task.id
  }
}

function onStatus(message) {
  if (typeof message === 'string' && message.trim() !== '') {
    panelStatus.value = message
  }
}
</script>

<template>
  <article class="task-panel">
    <header class="task-header">
      <p class="task-kicker">Componente Remoto de Task</p>
      <h3>Acoes sob demanda</h3>
      <p>Os componentes de lista, create, view e edit sao montados apenas quando voce clica no botao correspondente.</p>
      <p>Ao fechar ou trocar de ação, o componente atual e destruido.</p>
    </header>

    <div class="toolbar">
      <button type="button" @click="openAction('list')">Lista</button>
      <button type="button" @click="openAction('create')">Create</button>
      <button type="button" @click="openAction('view')">View</button>
      <button type="button" @click="openAction('edit')">Edit</button>
      <button type="button" class="ghost" @click="closeAction">Fechar componente</button>
    </div>

    <p class="selected-task">
      <strong>Tarefa selecionada:</strong>
      <span>{{ selectedTaskId || 'nenhuma' }}</span>
    </p>

    <p v-if="panelError" class="feedback error">{{ panelError }}</p>
    <p v-if="panelStatus" class="feedback success">{{ panelStatus }}</p>

    <div class="mount-zone">
      <p v-if="!currentComponent" class="empty-state">
        Nenhum componente ativo. Clique em um botao para montar um componente de task.
      </p>

      <Suspense v-else>
        <template #default>
          <component
            :is="currentComponent"
            :key="`${activeAction}-${mountKey}`"
            v-bind="currentComponentProps"
            @select-task="onTaskSelected"
            @open-view="openAction('view')"
            @open-edit="openAction('edit')"
            @task-created="onTaskCreated"
            @task-updated="onTaskUpdated"
            @task-deleted="onTaskDeleted"
            @task-status="onStatus"
          />
        </template>
        <template #fallback>
          <p class="empty-state">Carregando componente de task...</p>
        </template>
      </Suspense>
    </div>
  </article>
</template>

<style scoped>
.task-panel {
  background: linear-gradient(165deg, #f8fafc 0%, #ecfeff 55%, #f5f3ff 100%);
  border: 1px solid #cbd5e1;
  border-radius: 18px;
  box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
  margin-top: 18px;
  padding: 20px;
}

.task-header {
  margin-bottom: 16px;
}

.task-kicker {
  color: #0f766e;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  margin: 0 0 6px;
  text-transform: uppercase;
}

.task-header h3 {
  margin: 0;
}

.task-header p {
  color: #475569;
  margin: 6px 0 0;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

button {
  background: linear-gradient(90deg, #0f766e 0%, #0369a1 100%);
  border: 0;
  border-radius: 10px;
  color: #ffffff;
  cursor: pointer;
  font-weight: 700;
  padding: 9px 12px;
}

button.ghost {
  background: #e2e8f0;
  color: #0f172a;
}

.selected-task {
  color: #334155;
  margin: 12px 0 0;
}

.feedback {
  border-radius: 10px;
  font-weight: 600;
  margin: 10px 0 0;
  padding: 9px 12px;
}

.feedback.error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
}

.feedback.success {
  background: #ecfeff;
  border: 1px solid #bae6fd;
  color: #075985;
}

.mount-zone {
  margin-top: 14px;
}

.empty-state {
  color: #64748b;
}

@media (max-width: 680px) {
  .toolbar {
    flex-direction: column;
  }
}
</style>
