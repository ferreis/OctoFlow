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
  background: var(--secondary-soft, color-mix(in srgb, var(--color-secondary) 12%, white));
  border-color: color-mix(in srgb, var(--color-secondary) 32%, var(--app-panel-border, transparent));
  color: var(--color-secondary);
}

.alert-success {
  background: var(--success-bg, color-mix(in srgb, var(--success) 14%, white));
  border-color: color-mix(in srgb, var(--success) 28%, var(--app-panel-border, transparent));
  color: var(--success);
}

.alert-warning {
  background: var(--warning-bg, color-mix(in srgb, var(--warning) 16%, white));
  border-color: color-mix(in srgb, var(--warning) 32%, var(--app-panel-border, transparent));
  color: var(--warning);
}

.alert-error {
  background: var(--danger-bg, color-mix(in srgb, var(--danger) 14%, white));
  border-color: color-mix(in srgb, var(--danger) 28%, var(--app-panel-border, transparent));
  color: var(--danger);
}
</style>
