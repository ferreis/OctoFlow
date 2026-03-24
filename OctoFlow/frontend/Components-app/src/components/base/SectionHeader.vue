<script setup>
defineProps({
  kicker: {
    type: String,
    default: '',
  },
  title: {
    type: String,
    default: '',
  },
  description: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <div class="section-header">
    <p v-if="kicker" class="section-kicker">{{ kicker }}</p>
    <h3 v-if="title" class="section-title">{{ title }}</h3>
    <p v-if="description" class="section-description">{{ description }}</p>
    <slot />
  </div>
</template>

<style scoped>
.section-kicker {
  color: var(--color-secondary);
  font-size: 0.72rem;
  font-weight: 900;
  letter-spacing: 0.22em;
  margin: 0;
  text-transform: uppercase;
}

.section-title {
  color: var(--ink);
  font-size: clamp(1.45rem, 2vw, 1.9rem);
  font-weight: 700;
  margin: 0.35rem 0 0;
}

.section-description {
  color: var(--muted);
  line-height: 1.75;
  margin: 0.75rem 0 0;
}
</style>
