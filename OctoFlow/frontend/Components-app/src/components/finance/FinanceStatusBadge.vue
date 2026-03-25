<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: {
    type: String,
    default: '',
  },
  label: {
    type: String,
    default: '',
  },
})

const normalizedStatus = computed(() => String(props.status || '').toUpperCase())

const badgeClass = computed(() => {
  if (['PAID', 'RECEIVED'].includes(normalizedStatus.value)) {
    return 'finance-status-badge-positive'
  }

  if (normalizedStatus.value === 'PARTIAL') {
    return 'finance-status-badge-warning'
  }

  if (['OVERDUE', 'CANCELED'].includes(normalizedStatus.value)) {
    return 'finance-status-badge-negative'
  }

  if (normalizedStatus.value === 'NEGOTIATED') {
    return 'finance-status-badge-neutral'
  }

  return 'finance-status-badge-default'
})

const displayLabel = computed(() => {
  const normalizedLabel = String(props.label || '').trim()
  if (normalizedLabel) {
    return normalizedLabel
  }

  return normalizedStatus.value || 'N/A'
})
</script>

<template>
  <span class="finance-status-badge" :class="badgeClass">{{ displayLabel }}</span>
</template>

<style scoped>
.finance-status-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 0.25rem 0.5rem;
  border: 1px solid var(--line, #cbd5e1);
  font-size: 0.73rem;
  font-weight: 700;
  line-height: 1;
}

.finance-status-badge-positive {
  border-color: color-mix(in srgb, var(--success, #22c55e) 40%, var(--line, #cbd5e1));
  color: var(--success, #166534);
  background: var(--success-bg, color-mix(in srgb, var(--success, #22c55e) 16%, transparent));
}

.finance-status-badge-warning {
  border-color: color-mix(in srgb, var(--warning, #f59e0b) 40%, var(--line, #cbd5e1));
  color: var(--warning, #92400e);
  background: var(--warning-bg, color-mix(in srgb, var(--warning, #f59e0b) 16%, transparent));
}

.finance-status-badge-negative {
  border-color: color-mix(in srgb, var(--danger, #ef4444) 40%, var(--line, #cbd5e1));
  color: var(--danger, #be123c);
  background: var(--danger-bg, color-mix(in srgb, var(--danger, #ef4444) 16%, transparent));
}

.finance-status-badge-neutral {
  border-color: var(--line, #cbd5e1);
  color: var(--ink, #334155);
  background: var(--surface, #f8fafc);
}

.finance-status-badge-default {
  border-color: color-mix(in srgb, var(--accent, #1d4ed8) 36%, var(--line, #cbd5e1));
  color: var(--accent, #1d4ed8);
  background: color-mix(in srgb, var(--accent, #1d4ed8) 14%, transparent);
}
</style>
