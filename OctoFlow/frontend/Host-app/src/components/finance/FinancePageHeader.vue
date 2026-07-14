<script setup>
defineProps({
  eyebrow: {
    type: String,
    default: 'Financeiro',
  },
  title: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <header class="finance-page-header">
    <div class="finance-page-header-copy">
      <p class="finance-page-header-eyebrow">{{ eyebrow }}</p>
      <h1>{{ title }}</h1>
      <p v-if="description" class="finance-page-header-description">{{ description }}</p>
    </div>

    <div v-if="$slots.actions" class="finance-page-header-actions">
      <slot name="actions" />
    </div>
  </header>
</template>
