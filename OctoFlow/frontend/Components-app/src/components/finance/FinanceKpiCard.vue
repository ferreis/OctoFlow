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
  border: 1px solid var(--app-border-color, #d1d5db);
  background: var(--app-card-bg, #ffffff);
  padding: 14px;
  display: grid;
  gap: 8px;
}

.finance-kpi-card-label {
  margin: 0;
  font-size: 0.74rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--app-text-muted, #6b7280);
  font-weight: 700;
}

.finance-kpi-card-value {
  margin: 0;
  font-size: 1.3rem;
  font-weight: 700;
  color: var(--app-text-color, #111827);
  line-height: 1.1;
}

.finance-kpi-card-caption {
  margin: 0;
  color: var(--app-text-muted, #6b7280);
  font-size: 0.82rem;
}

.finance-kpi-card-positive {
  border-color: #86efac;
  background: #f0fdf4;
}

.finance-kpi-card-negative {
  border-color: #fda4af;
  background: #fff1f2;
}

.finance-kpi-card-warning {
  border-color: #fcd34d;
  background: #fffbeb;
}

.finance-kpi-card-neutral {
  border-color: var(--app-border-color, #d1d5db);
  background: var(--app-card-bg, #ffffff);
}
</style>
