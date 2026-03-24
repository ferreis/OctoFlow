<template>
  <div class="loading-state">
    <slot>Carregando...</slot>
  </div>
</template>

<style scoped>
.loading-state {
  background: color-mix(in srgb, var(--surface-muted) 88%, white);
  border: 1px solid var(--line);
  border-radius: 24px;
  color: var(--muted);
  display: grid;
  min-height: 180px;
  padding: 1.25rem;
  place-items: center;
  text-align: center;
}
</style>
