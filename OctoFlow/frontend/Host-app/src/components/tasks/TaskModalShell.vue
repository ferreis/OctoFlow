<script setup>
import {
  computed,
  nextTick,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
  ref,
  watch,
} from 'vue'
import { useI18n } from '../../composables/useI18n'

const props = defineProps({
  maxWidthClass: {
    type: String,
    default: 'max-w-7xl',
  },
  closeOnBackdrop: {
    type: Boolean,
    default: false,
  },
  closeOnEscape: {
    type: Boolean,
    default: true,
  },
  trapFocus: {
    type: Boolean,
    default: true,
  },
  isOpen: {
    type: Boolean,
    default: true,
  },
  dialogTitleId: {
    type: String,
    default: '',
  },
  dialogDescriptionId: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['close', 'runtime-error'])
const { translate } = useI18n()
const modalFrameRef = ref(null)
const lastFocusedElementBeforeOpen = ref(null)
const lastFocusedElementDuringUpdate = ref(null)
const hasWindowListeners = ref(false)
const componentIdSuffix = Math.random().toString(36).slice(2, 10)

const resolvedAriaLabelledby = computed(() => {
  const normalizedDialogTitleId = String(props.dialogTitleId || '').trim()
  return normalizedDialogTitleId !== '' ? normalizedDialogTitleId : `task-modal-shell-title-${componentIdSuffix}`
})

const resolvedAriaDescribedby = computed(() => {
  const normalizedDialogDescriptionId = String(props.dialogDescriptionId || '').trim()
  return normalizedDialogDescriptionId !== '' ? normalizedDialogDescriptionId : undefined
})

const isModalOpen = computed(() => props.isOpen)

function captureFocusedElementBeforeOpen() {
  if (typeof document === 'undefined') {
    return
  }

  const currentFocusedElement = document.activeElement
  if (currentFocusedElement instanceof HTMLElement) {
    lastFocusedElementBeforeOpen.value = currentFocusedElement
  }
}

function restoreFocusToPreviousElement() {
  const focusTarget = lastFocusedElementBeforeOpen.value
  if (!(focusTarget instanceof HTMLElement)) {
    return
  }

  if (!focusTarget.isConnected) {
    return
  }

  focusTarget.focus()
}

function resolveFocusableElements() {
  const modalFrameElement = modalFrameRef.value
  if (!(modalFrameElement instanceof HTMLElement)) {
    return []
  }

  return Array.from(
    modalFrameElement.querySelectorAll(
      'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ),
  ).filter((focusableElement) => (
    focusableElement instanceof HTMLElement
    && focusableElement.offsetParent !== null
  ))
}

async function focusFirstModalElement() {
  if (!isModalOpen.value) {
    return
  }

  await nextTick()
  const focusableElements = resolveFocusableElements()

  if (focusableElements.length > 0) {
    focusableElements[0].focus()
    return
  }

  modalFrameRef.value?.focus()
}

function requestClose() {
  emit('close')
}

function handleBackdropClick() {
  if (!props.closeOnBackdrop || !isModalOpen.value) {
    return
  }

  requestClose()
}

function handleTabKeyForFocusTrap(event) {
  if (!props.trapFocus || event?.key !== 'Tab') {
    return
  }

  const focusableElements = resolveFocusableElements()
  if (focusableElements.length === 0) {
    event.preventDefault()
    modalFrameRef.value?.focus()
    return
  }

  const firstFocusableElement = focusableElements[0]
  const lastFocusableElement = focusableElements[focusableElements.length - 1]
  const activeElement = document.activeElement

  if (event.shiftKey && activeElement === firstFocusableElement) {
    event.preventDefault()
    lastFocusableElement.focus()
    return
  }

  if (!event.shiftKey && activeElement === lastFocusableElement) {
    event.preventDefault()
    firstFocusableElement.focus()
  }
}

function handleWindowKeydown(event) {
  if (!isModalOpen.value) {
    return
  }

  if (event?.key === 'Escape' && props.closeOnEscape) {
    event.preventDefault()
    requestClose()
    return
  }

  handleTabKeyForFocusTrap(event)
}

function registerWindowListeners() {
  if (typeof window === 'undefined' || hasWindowListeners.value) {
    return
  }

  window.addEventListener('keydown', handleWindowKeydown)
  hasWindowListeners.value = true
}

function unregisterWindowListeners() {
  if (typeof window === 'undefined' || !hasWindowListeners.value) {
    return
  }

  window.removeEventListener('keydown', handleWindowKeydown)
  hasWindowListeners.value = false
}

onBeforeMount(() => {
  if (!isModalOpen.value) {
    return
  }

  captureFocusedElementBeforeOpen()
})

onMounted(() => {
  registerWindowListeners()
  void focusFirstModalElement()
})

onBeforeUpdate(() => {
  if (typeof document === 'undefined') {
    return
  }

  const activeElement = document.activeElement
  if (activeElement instanceof HTMLElement) {
    lastFocusedElementDuringUpdate.value = activeElement
  }
})

onUpdated(() => {
  if (!isModalOpen.value || typeof document === 'undefined') {
    return
  }

  const activeElement = document.activeElement
  if (activeElement !== document.body) {
    return
  }

  if (lastFocusedElementDuringUpdate.value instanceof HTMLElement) {
    lastFocusedElementDuringUpdate.value.focus()
    return
  }

  void focusFirstModalElement()
})

onActivated(() => {
  registerWindowListeners()
})

onDeactivated(() => {
  unregisterWindowListeners()
})

onBeforeUnmount(() => {
  unregisterWindowListeners()
  restoreFocusToPreviousElement()
})

onUnmounted(() => {
  lastFocusedElementBeforeOpen.value = null
  lastFocusedElementDuringUpdate.value = null
})

onErrorCaptured((capturedError) => {
  const errorMessage = capturedError instanceof Error
    ? capturedError.message
    : translate('tasks.shared.modalUnexpectedError')

  emit('runtime-error', {
    message: String(errorMessage || 'Erro inesperado no modal de tarefa.'),
    source: 'TaskModalShell',
  })

  return false
})

watch(
  () => isModalOpen.value,
  (isDialogOpen) => {
    if (!isDialogOpen) {
      unregisterWindowListeners()
      restoreFocusToPreviousElement()
      return
    }

    captureFocusedElementBeforeOpen()
    registerWindowListeners()
    void focusFirstModalElement()
  },
  {
    immediate: true,
  },
)
</script>

<template>
  <div
    v-if="isOpen"
    class="app-modal-overlay"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="resolvedAriaLabelledby"
    :aria-describedby="resolvedAriaDescribedby"
    @click.self="handleBackdropClick"
  >
    <div
      ref="modalFrameRef"
      class="app-modal-frame mx-auto flex max-h-full w-full flex-col overflow-hidden"
      :class="maxWidthClass"
      tabindex="-1"
    >
      <slot />
    </div>
  </div>
</template>
