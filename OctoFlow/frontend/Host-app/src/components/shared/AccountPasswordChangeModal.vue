<script setup>
const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
  step: {
    type: String,
    default: 'code',
  },
  processing: {
    type: Boolean,
    default: false,
  },
  email: {
    type: String,
    default: '',
  },
  codeExpiresAtLabel: {
    type: String,
    default: '',
  },
  code: {
    type: String,
    default: '',
  },
  password: {
    type: String,
    default: '',
  },
  confirmPassword: {
    type: String,
    default: '',
  },
})

const emit = defineEmits([
  'close',
  'resend-code',
  'verify-code',
  'submit-password',
  'update:code',
  'update:password',
  'update:confirmPassword',
])

function closeDialog() {
  if (props.processing) {
    return
  }

  emit('close')
}

function updateCode(event) {
  emit('update:code', String(event?.target?.value || ''))
}

function updatePassword(event) {
  emit('update:password', String(event?.target?.value || ''))
}

function updateConfirmPassword(event) {
  emit('update:confirmPassword', String(event?.target?.value || ''))
}

function requestCodeValidation() {
  if (props.processing) {
    return
  }

  emit('verify-code')
}

function requestPasswordUpdate() {
  if (props.processing) {
    return
  }

  emit('submit-password')
}

function requestCodeResend() {
  if (props.processing) {
    return
  }

  emit('resend-code')
}
</script>

<template>
  <div
    v-if="isOpen"
    class="app-modal-overlay flex items-center justify-center"
    role="dialog"
    aria-modal="true"
    @click.self="closeDialog"
  >
    <div class="app-modal-frame w-full max-w-lg p-6 md:p-7">
      <header class="grid gap-2">
        <h3 class="text-base font-semibold text-slate-950 md:text-lg">
          {{ step === 'password' ? 'Definir nova senha' : 'Validar código de segurança' }}
        </h3>
        <p class="text-sm text-slate-600">
          {{ step === 'password'
            ? 'Código validado. Agora informe sua nova senha.'
            : `Enviamos um código para o e-mail principal: ${email || 'não informado'}.` }}
        </p>
        <p v-if="step === 'code' && codeExpiresAtLabel" class="text-sm text-slate-500">
          Código expira em: {{ codeExpiresAtLabel }}
        </p>
      </header>

      <form v-if="step === 'code'" class="mt-5 grid gap-4" @submit.prevent="requestCodeValidation">
        <label class="field">
          <span>Código</span>
          <input
            :value="code"
            type="text"
            autocomplete="one-time-code"
            minlength="6"
            maxlength="16"
            required
            @input="updateCode"
          >
        </label>

        <footer class="mt-1 flex flex-wrap justify-end gap-3">
          <button type="button" class="button-secondary" :disabled="processing" @click="closeDialog">
            Cancelar
          </button>
          <button type="button" class="button-secondary" :disabled="processing" @click="requestCodeResend">
            Reenviar código
          </button>
          <button type="submit" class="button-primary" :disabled="processing">
            {{ processing ? 'Validando...' : 'Validar código' }}
          </button>
        </footer>
      </form>

      <form v-else class="mt-5 grid gap-4" @submit.prevent="requestPasswordUpdate">
        <label class="field">
          <span>Nova senha</span>
          <input
            :value="password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            @input="updatePassword"
          >
        </label>

        <label class="field">
          <span>Repetir senha</span>
          <input
            :value="confirmPassword"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            @input="updateConfirmPassword"
          >
        </label>

        <footer class="mt-1 flex flex-wrap justify-end gap-3">
          <button type="button" class="button-secondary" :disabled="processing" @click="closeDialog">
            Cancelar
          </button>
          <button type="submit" class="button-primary" :disabled="processing">
            {{ processing ? 'Salvando...' : 'Atualizar senha' }}
          </button>
        </footer>
      </form>
    </div>
  </div>
</template>
