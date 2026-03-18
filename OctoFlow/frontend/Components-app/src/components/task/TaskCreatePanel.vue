<script setup>
import { reactive, ref } from 'vue'

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
    error.value = 'Informe um titulo para criar a tarefa.'
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
    error.value = extractMessage(requestError, 'Falha ao criar tarefa.')
  } finally {
    saving.value = false
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
    <h4>Criar tarefa</h4>

    <form class="task-form" @submit.prevent="createTask">
      <label class="field">
        <span>Titulo</span>
        <input v-model="form.title" type="text" maxlength="120" required>
      </label>

      <label class="field">
        <span>Descriçao</span>
        <textarea v-model="form.description" rows="3"></textarea>
      </label>

      <label class="checkbox-field">
        <input v-model="form.completed" type="checkbox">
        <span>Ja concluir</span>
      </label>

      <button type="submit" :disabled="saving">
        {{ saving ? 'Salvando...' : 'Criar tarefa' }}
      </button>
    </form>

    <p v-if="error" class="feedback error">{{ error }}</p>
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

.task-form {
  display: grid;
  gap: 10px;
}

.field {
  display: grid;
  gap: 6px;
}

.field span {
  color: #0f172a;
  font-weight: 600;
}

.field input,
.field textarea {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  color: #111827;
  padding: 8px 10px;
}

.checkbox-field {
  align-items: center;
  color: #334155;
  display: flex;
  font-weight: 600;
  gap: 8px;
}

button {
  background: linear-gradient(90deg, #0f766e 0%, #0369a1 100%);
  border: 0;
  border-radius: 8px;
  color: #ffffff;
  cursor: pointer;
  font-weight: 700;
  padding: 10px;
}

button:disabled {
  cursor: not-allowed;
  opacity: 0.7;
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
</style>
