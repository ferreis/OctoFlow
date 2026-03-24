<script setup>
const { maxWidthClass } = defineProps({
  maxWidthClass: {
    type: String,
    default: 'max-w-7xl',
  },
})

const emit = defineEmits(['close'])

function handleBackdropClick() {
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
