<script setup>
const { maxWidthClass, closeOnBackdrop } = defineProps({
  maxWidthClass: {
    type: String,
    default: 'max-w-7xl',
  },
  closeOnBackdrop: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close'])

function handleBackdropClick() {
  if (!closeOnBackdrop) {
    return
  }

  emit('close')
}
</script>

<template>
  <div class="app-modal-overlay" @click.self="handleBackdropClick">
    <div class="app-modal-frame mx-auto flex max-h-full w-full flex-col overflow-hidden" :class="maxWidthClass">
      <slot />
    </div>
  </div>
</template>
