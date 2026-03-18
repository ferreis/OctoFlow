<template>
  <div class="loading-state">
    <slot>Carregando...</slot>
  </div>
</template>

<style scoped>
.loading-state {
  background: color-mix(in srgb, var(--surface-muted, #f8fafc) 88%, white);
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 24px;
  color: var(--muted, #475569);
  display: grid;
  min-height: 180px;
  padding: 1.25rem;
  place-items: center;
  text-align: center;
}
</style>
