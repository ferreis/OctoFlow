<script setup>
import { reactive, ref, watch } from 'vue'
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

const emit = defineEmits(['task-updated', 'task-status'])

const loading = ref(false)
const saving = ref(false)
const error = ref('')

const form = reactive({
  title: '',
  description: '',
  completed: false,
})

watch(
  () => props.taskId,
  async (newTaskId) => {
    if (!newTaskId) {
      resetForm()
      return
    }

    await loadTask(newTaskId)
  },
  { immediate: true },
)

function resetForm() {
  form.title = ''
  form.description = ''
  form.completed = false
}

async function loadTask(taskId) {
  loading.value = true
  error.value = ''

  try {
    const response = await props.request({
      url: `${props.endpoint}/${taskId}`,
      method: 'GET',
    })

    const task = response?.data?.item || null
    form.title = task?.title || ''
    form.description = task?.description || ''
    form.completed = Boolean(task?.completed)
  } catch (requestError) {
    resetForm()
    error.value = extractRequestErrorMessage(requestError, 'Falha ao carregar tarefa para edição.')
  } finally {
    loading.value = false
  }
}

async function saveTask() {
  if (!props.taskId) {
    error.value = 'Selecione uma tarefa para editar.'
    return
  }

  const title = form.title.trim()
  if (title === '') {
    error.value = 'O título não pode ficar vazio.'
    return
  }

  saving.value = true
  error.value = ''

  try {
    const response = await props.request({
      url: `${props.endpoint}/${props.taskId}`,
      method: 'PATCH',
      csrfActionId: `task.update.form.${props.taskId}`,
      data: {
        title,
        description: form.description.trim(),
        completed: Boolean(form.completed),
      },
    })

    emit('task-updated', response?.data?.item || null)
    emit('task-status', 'Tarefa atualizada com sucesso.')
  } catch (requestError) {
    error.value = extractRequestErrorMessage(requestError, 'Falha ao atualizar tarefa.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="task-panel-block">
    <h4 class="task-panel-title">Editar tarefa</h4>

    <p v-if="!taskId" class="task-panel-empty">Selecione uma tarefa na lista para editar.</p>
    <p v-else-if="loading" class="task-panel-empty">Carregando dados para edição...</p>

    <form v-else class="task-panel-form" @submit.prevent="saveTask">
      <label class="task-panel-field">
        <span class="task-panel-field-label">Título</span>
        <input
          v-model="form.title"
          type="text"
          maxlength="120"
          required
          class="app-field-control task-panel-field-control"
        >
      </label>

      <label class="task-panel-field">
        <span class="task-panel-field-label">Descrição</span>
        <textarea
          v-model="form.description"
          rows="3"
          class="app-field-control task-panel-field-area"
        />
      </label>

      <label class="task-panel-checkbox">
        <input v-model="form.completed" type="checkbox">
        <span>Concluída</span>
      </label>

      <button type="submit" :disabled="saving" class="task-panel-button task-panel-button--primary">
        {{ saving ? 'Salvando...' : 'Salvar alterações' }}
      </button>
    </form>

    <p v-if="error" class="app-feedback app-feedback--danger task-panel-feedback">{{ error }}</p>
  </section>
</template>
