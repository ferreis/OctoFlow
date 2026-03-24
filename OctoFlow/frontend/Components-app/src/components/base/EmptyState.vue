<template>
  <div class="empty-state">
    <slot />
  </div>
</template>

<style scoped>
.empty-state {
  background: color-mix(in srgb, var(--surface-muted) 88%, white);
  border: 1px dashed var(--line);
  border-radius: 24px;
  color: var(--muted);
  padding: 1.25rem;
}
</style>
