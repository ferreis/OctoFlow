<script setup>
import { computed } from 'vue'

const props = defineProps({
  label: {
    type: String,
    default: '',
  },
  value: {
    type: [String, Number],
    default: '-',
  },
  caption: {
    type: String,
    default: '',
  },
  tone: {
    type: String,
    default: 'neutral',
  },
})

const toneClass = computed(() => {
  if (props.tone === 'positive') {
    return 'finance-kpi-card-positive'
  }

  if (props.tone === 'negative') {
    return 'finance-kpi-card-negative'
  }

  if (props.tone === 'warning') {
    return 'finance-kpi-card-warning'
  }

  return 'finance-kpi-card-neutral'
})
</script>

<template>
  <article class="finance-kpi-card" :class="toneClass">
    <p class="finance-kpi-card-label">{{ label }}</p>
    <p class="finance-kpi-card-value">{{ value }}</p>
    <p v-if="caption" class="finance-kpi-card-caption">{{ caption }}</p>
  </article>
</template>

<style scoped>
.finance-kpi-card {
  border-radius: 14px;
  border: 1px solid var(--line, #d1d5db);
  background: var(--surface-strong, #ffffff);
  padding: 14px;
  display: grid;
  gap: 8px;
}

.finance-kpi-card-label {
  margin: 0;
  font-size: 0.74rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted, #6b7280);
  font-weight: 700;
}

.finance-kpi-card-value {
  margin: 0;
  font-size: 1.3rem;
  font-weight: 700;
  color: var(--ink, #111827);
  line-height: 1.1;
}

.finance-kpi-card-caption {
  margin: 0;
  color: var(--muted, #6b7280);
  font-size: 0.82rem;
}

.finance-kpi-card-positive {
  border-color: color-mix(in srgb, var(--success, #22c55e) 40%, var(--line, #d1d5db));
  background: var(--success-bg, color-mix(in srgb, var(--success, #22c55e) 18%, transparent));
}

.finance-kpi-card-negative {
  border-color: color-mix(in srgb, var(--danger, #ef4444) 40%, var(--line, #d1d5db));
  background: var(--danger-bg, color-mix(in srgb, var(--danger, #ef4444) 16%, transparent));
}

.finance-kpi-card-warning {
  border-color: color-mix(in srgb, var(--warning, #f59e0b) 40%, var(--line, #d1d5db));
  background: var(--warning-bg, color-mix(in srgb, var(--warning, #f59e0b) 16%, transparent));
}

.finance-kpi-card-neutral {
  border-color: var(--line, #d1d5db);
  background: var(--surface-strong, #ffffff);
}
</style>
