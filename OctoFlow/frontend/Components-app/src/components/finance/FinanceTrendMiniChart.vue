<script setup>
import { computed } from 'vue'

const props = defineProps({
  points: {
    type: Array,
    default: () => [],
  },
  strokeColor: {
    type: String,
    default: '#2563eb',
  },
  height: {
    type: Number,
    default: 72,
  },
})

const normalizedPoints = computed(() => {
  return props.points
    .map((point) => {
      if (typeof point === 'number') {
        return Number(point)
      }

      if (typeof point?.value === 'number') {
        return Number(point.value)
      }

      if (typeof point?.realizedNetBrl === 'number') {
        return Number(point.realizedNetBrl)
      }

      if (typeof point?.expectedNetBrl === 'number') {
        return Number(point.expectedNetBrl)
      }

      return null
    })
    .filter((point) => Number.isFinite(point))
})

const pathData = computed(() => {
  const points = normalizedPoints.value
  if (points.length < 2) {
    return ''
  }

  const width = 240
  const height = Math.max(24, props.height)

  const minimumValue = Math.min(...points)
  const maximumValue = Math.max(...points)
  const range = maximumValue - minimumValue || 1

  return points
    .map((pointValue, index) => {
      const xPosition = (index / (points.length - 1)) * width
      const normalizedValue = (pointValue - minimumValue) / range
      const yPosition = height - normalizedValue * height

      return `${index === 0 ? 'M' : 'L'} ${xPosition.toFixed(2)} ${yPosition.toFixed(2)}`
    })
    .join(' ')
})
</script>

<template>
  <div class="finance-trend-chart">
    <svg viewBox="0 0 240 72" preserveAspectRatio="none">
      <path v-if="pathData" :d="pathData" fill="none" :stroke="strokeColor" stroke-width="2.4" stroke-linecap="round"
        stroke-linejoin="round" />
    </svg>
  </div>
</template>

<style scoped>
.finance-trend-chart {
  width: 100%;
  height: 72px;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--accent, #2563eb) 24%, var(--line, #dbeafe));
  background: linear-gradient(180deg,
      color-mix(in srgb, var(--accent, #2563eb) 12%, transparent) 0%,
      var(--surface-strong, #ffffff) 100%);
  padding: 6px;
}

.finance-trend-chart svg {
  width: 100%;
  height: 100%;
}
</style>
