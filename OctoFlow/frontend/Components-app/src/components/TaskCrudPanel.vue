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
  <article class="task-shell">
    <header class="task-shell-header">
      <p class="task-shell-kicker">Componente remoto de task</p>
      <h3 class="task-shell-title">Acoes sob demanda</h3>
      <p class="task-shell-copy">
        Lista, create, view e edit sao montados apenas quando voce clica na ação correspondente.
      </p>
      <p class="task-shell-copy">
        Ao fechar ou trocar de ação, o componente atual e destruido.
      </p>
    </header>

    <div class="task-shell-toolbar">
      <button type="button" class="task-panel-button task-panel-button--primary" @click="openAction('list')">Lista</button>
      <button type="button" class="task-panel-button task-panel-button--primary" @click="openAction('create')">Create</button>
      <button type="button" class="task-panel-button task-panel-button--primary" @click="openAction('view')">View</button>
      <button type="button" class="task-panel-button task-panel-button--primary" @click="openAction('edit')">Edit</button>
      <button type="button" class="task-panel-button task-panel-button--secondary" @click="closeAction">Fechar componente</button>
    </div>

    <p class="task-shell-selected">
      <strong>Tarefa selecionada:</strong>
      <span>{{ selectedTaskId || 'nenhuma' }}</span>
    </p>

    <p v-if="panelError" class="app-feedback app-feedback--danger task-panel-feedback">{{ panelError }}</p>
    <p v-if="panelStatus" class="app-feedback app-feedback--success task-panel-feedback">{{ panelStatus }}</p>

    <div class="task-shell-mount">
      <p v-if="!currentComponent" class="task-panel-empty">
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
          <p class="task-panel-empty">Carregando componente de task...</p>
        </template>
      </Suspense>
    </div>
  </article>
</template>
