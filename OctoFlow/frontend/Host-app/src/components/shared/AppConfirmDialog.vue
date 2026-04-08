<script setup>
import {
  computed,
  nextTick,
  onActivated,
  onBeforeUnmount,
  onDeactivated,
  onMounted,
  ref,
  watch,
} from 'vue'
import { useI18n } from '../../composables/useI18n'

const ALLOWED_CONFIRM_TONES = new Set(['primary', 'danger'])

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: '',
  },
  message: {
    type: String,
    default: '',
  },
  confirmLabel: {
    type: String,
    default: '',
  },
  cancelLabel: {
    type: String,
    default: '',
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
const { translate } = useI18n()
const confirmButtonRef = ref(null)
const componentIdSuffix = Math.random().toString(36).slice(2, 10)

const dialogTitleId = computed(() => `app-confirm-dialog-title-${componentIdSuffix}`)
const dialogDescriptionId = computed(() => `app-confirm-dialog-description-${componentIdSuffix}`)

const resolvedConfirmTone = computed(() => {
  const normalizedTone = String(props.confirmTone || '').trim().toLowerCase()
  return ALLOWED_CONFIRM_TONES.has(normalizedTone) ? normalizedTone : 'danger'
})

const resolvedTitle = computed(() => {
  const normalizedTitle = String(props.title || '').trim()
  return normalizedTitle !== '' ? normalizedTitle : translate('shared.confirmDialog.titleDefault')
})

const resolvedMessage = computed(() => String(props.message || '').trim())
const resolvedDescriptionId = computed(() => (
  resolvedMessage.value !== '' ? dialogDescriptionId.value : undefined
))

const resolvedConfirmLabel = computed(() => {
  const normalizedLabel = String(props.confirmLabel || '').trim()
  return normalizedLabel !== '' ? normalizedLabel : translate('shared.confirmDialog.confirmDefault')
})

const resolvedCancelLabel = computed(() => {
  const normalizedLabel = String(props.cancelLabel || '').trim()
  return normalizedLabel !== '' ? normalizedLabel : translate('shared.confirmDialog.cancelDefault')
})

const confirmButtonClass = computed(() => (
  resolvedConfirmTone.value === 'primary'
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

async function focusConfirmButton() {
  if (!props.isOpen || props.processing) {
    return
  }

  await nextTick()
  confirmButtonRef.value?.focus()
}

function handleEscapeKeydown(event) {
  if (event?.key !== 'Escape' || !props.isOpen || props.processing) {
    return
  }

  event.preventDefault()
  closeDialog()
}

function registerEscapeListener() {
  if (typeof window === 'undefined') {
    return
  }

  window.addEventListener('keydown', handleEscapeKeydown)
}

function unregisterEscapeListener() {
  if (typeof window === 'undefined') {
    return
  }

  window.removeEventListener('keydown', handleEscapeKeydown)
}

onMounted(() => {
  registerEscapeListener()
})

onActivated(() => {
  registerEscapeListener()
})

onDeactivated(() => {
  unregisterEscapeListener()
})

onBeforeUnmount(() => {
  unregisterEscapeListener()
})

watch(
  () => [props.isOpen, props.processing],
  ([dialogIsOpen, dialogIsProcessing]) => {
    if (!dialogIsOpen || dialogIsProcessing) {
      return
    }

    void focusConfirmButton()
  },
  {
    immediate: true,
  },
)
</script>

<template>
  <div
    v-if="isOpen"
    class="app-modal-overlay flex items-center justify-center"
    role="dialog"
    :aria-labelledby="dialogTitleId"
    :aria-describedby="resolvedDescriptionId"
    aria-modal="true"
    @click.self="closeDialog"
  >
    <div class="app-modal-frame w-full max-w-lg p-6 md:p-7">
      <header class="grid gap-2">
        <h3 :id="dialogTitleId" class="text-base font-semibold text-slate-950 md:text-lg">{{ resolvedTitle }}</h3>
        <p v-if="resolvedMessage !== ''" :id="dialogDescriptionId" class="text-sm text-slate-600">{{ resolvedMessage }}</p>
      </header>

      <footer class="mt-6 flex flex-wrap justify-end gap-3">
        <button
          type="button"
          class="button-secondary"
          :disabled="processing"
          @click="closeDialog"
        >
          {{ resolvedCancelLabel }}
        </button>
        <button
          ref="confirmButtonRef"
          type="button"
          :class="confirmButtonClass"
          :disabled="processing"
          @click="confirmDialogAction"
        >
          {{ processing ? translate('shared.confirmDialog.processing') : resolvedConfirmLabel }}
        </button>
      </footer>
    </div>
  </div>
</template>
