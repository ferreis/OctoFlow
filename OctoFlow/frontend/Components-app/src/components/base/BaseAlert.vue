<script setup>
import { computed } from 'vue'

const props = defineProps({
  type: {
    type: String,
    default: 'info',
  },
})

const alertClass = computed(() => {
  switch (props.type) {
    case 'error':
      return 'alert-error'
    case 'success':
      return 'alert-success'
    case 'warning':
      return 'alert-warning'
    default:
      return 'alert-info'
  }
})
</script>

<template>
  <div class="base-alert" :class="alertClass">
    <slot />
  </div>
</template>

<style scoped>
.base-alert {
  border-radius: 18px;
  border: 1px solid transparent;
  font-size: 0.95rem;
  font-weight: 600;
  padding: 0.85rem 1rem;
}

.alert-info {
  background: color-mix(in srgb, var(--color-secondary, #06b6d4) 12%, white);
  border-color: color-mix(in srgb, var(--color-secondary, #06b6d4) 32%, white);
  color: color-mix(in srgb, var(--color-secondary, #06b6d4) 68%, black);
}

.alert-success {
  background: color-mix(in srgb, var(--success, #22c55e) 14%, white);
  border-color: color-mix(in srgb, var(--success, #22c55e) 28%, white);
  color: color-mix(in srgb, var(--success, #22c55e) 72%, black);
}

.alert-warning {
  background: color-mix(in srgb, var(--warning, #f59e0b) 16%, white);
  border-color: color-mix(in srgb, var(--warning, #f59e0b) 32%, white);
  color: color-mix(in srgb, var(--warning, #f59e0b) 74%, black);
}

.alert-error {
  background: color-mix(in srgb, var(--danger, #ef4444) 14%, white);
  border-color: color-mix(in srgb, var(--danger, #ef4444) 28%, white);
  color: color-mix(in srgb, var(--danger, #ef4444) 72%, black);
}
</style>
