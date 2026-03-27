<script setup>
import { computed } from 'vue'

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: 'Confirmar ação',
  },
  message: {
    type: String,
    default: '',
  },
  confirmLabel: {
    type: String,
    default: 'Confirmar',
  },
  cancelLabel: {
    type: String,
    default: 'Cancelar',
  },
  confirmTone: {
    type: String,
    default: 'danger',
  },
  processing: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['confirm', 'cancel'])

const confirmButtonClass = computed(() => (
  props.confirmTone === 'primary'
    ? 'button-primary'
    : 'button-danger'
))

function closeDialog() {
  if (props.processing) {
    return
  }

  emit('cancel')
}

function confirmDialogAction() {
  if (props.processing) {
    return
  }

  emit('confirm')
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
        <h3 class="text-base font-semibold text-slate-950 md:text-lg">{{ title }}</h3>
        <p class="text-sm text-slate-600">{{ message }}</p>
      </header>

      <footer class="mt-6 flex flex-wrap justify-end gap-3">
        <button
          type="button"
          class="button-secondary"
          :disabled="processing"
          @click="closeDialog"
        >
          {{ cancelLabel }}
        </button>
        <button
          type="button"
          :class="confirmButtonClass"
          :disabled="processing"
          @click="confirmDialogAction"
        >
          {{ processing ? 'Processando...' : confirmLabel }}
        </button>
      </footer>
    </div>
  </div>
</template>

