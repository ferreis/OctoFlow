<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: {
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
</script>

<template>
  <span class="finance-status-badge" :class="badgeClass">{{ normalizedStatus || 'N/A' }}</span>
</template>

<style scoped>
.finance-status-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 0.25rem 0.5rem;
  border: 1px solid #cbd5e1;
  font-size: 0.73rem;
  font-weight: 700;
  line-height: 1;
}

.finance-status-badge-positive {
  border-color: #86efac;
  color: #166534;
  background: #f0fdf4;
}

.finance-status-badge-warning {
  border-color: #fcd34d;
  color: #92400e;
  background: #fffbeb;
}

.finance-status-badge-negative {
  border-color: #fda4af;
  color: #be123c;
  background: #fff1f2;
}

.finance-status-badge-neutral {
  border-color: #cbd5e1;
  color: #334155;
  background: #f8fafc;
}

.finance-status-badge-default {
  border-color: #bfdbfe;
  color: #1d4ed8;
  background: #eff6ff;
}
</style>
