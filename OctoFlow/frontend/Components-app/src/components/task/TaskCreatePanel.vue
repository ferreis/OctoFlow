<script setup>
import { reactive, ref } from 'vue'
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
})

const emit = defineEmits(['task-created', 'task-status'])

const saving = ref(false)
const error = ref('')

const form = reactive({
  title: '',
  description: '',
  completed: false,
})

async function createTask() {
  const title = form.title.trim()
  if (title === '') {
    error.value = 'Informe um título para criar a tarefa.'
    return
  }

  saving.value = true
  error.value = ''

  try {
    const response = await props.request({
      url: props.endpoint,
      method: 'POST',
      csrfActionId: 'task.create.form',
      data: {
        title,
        description: form.description.trim(),
        completed: Boolean(form.completed),
      },
    })

    const createdTask = response?.data?.item || null
    emit('task-created', createdTask)
    emit('task-status', 'Tarefa criada com sucesso.')

    form.title = ''
    form.description = ''
    form.completed = false
  } catch (requestError) {
    error.value = extractRequestErrorMessage(requestError, 'Falha ao criar tarefa.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="task-panel-block">
    <h4 class="task-panel-title">Criar tarefa</h4>

    <form class="task-panel-form" @submit.prevent="createTask">
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
        <span>Já concluída</span>
      </label>

      <button type="submit" :disabled="saving" class="task-panel-button task-panel-button--primary">
        {{ saving ? 'Salvando...' : 'Criar tarefa' }}
      </button>
    </form>

    <p v-if="error" class="app-feedback app-feedback--danger task-panel-feedback">{{ error }}</p>
  </section>
</template>
