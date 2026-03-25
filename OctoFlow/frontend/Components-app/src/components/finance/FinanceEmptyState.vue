<script setup>
const props = defineProps({
  title: {
    type: String,
    default: 'Sem dados',
  },
  description: {
    type: String,
    default: 'Nenhum registro encontrado para os filtros atuais.',
  },
  actionLabel: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['action'])
</script>

<template>
  <section class="finance-empty-state">
    <p class="finance-empty-state-title">{{ title }}</p>
    <p class="finance-empty-state-description">{{ description }}</p>
    <button
      v-if="actionLabel"
      type="button"
      class="finance-empty-state-action"
      @click="$emit('action')"
    >
      {{ actionLabel }}
    </button>
  </section>
</template>

<style scoped>
.finance-empty-state {
  border-radius: 14px;
  border: 1px dashed #cbd5e1;
  background: #f8fafc;
  padding: 18px;
  display: grid;
  gap: 10px;
  text-align: center;
}

.finance-empty-state-title {
  margin: 0;
  color: #0f172a;
  font-size: 1rem;
  font-weight: 700;
}

.finance-empty-state-description {
  margin: 0;
  color: #475569;
  font-size: 0.88rem;
}

.finance-empty-state-action {
  justify-self: center;
  border-radius: 10px;
  border: 1px solid #1d4ed8;
  background: #1d4ed8;
  color: #ffffff;
  padding: 0.48rem 0.85rem;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
}
</style>
