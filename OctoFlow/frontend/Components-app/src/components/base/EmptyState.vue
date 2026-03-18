<template>
  <div class="empty-state">
    <slot />
  </div>
</template>

<style scoped>
.empty-state {
  background: color-mix(in srgb, var(--surface-muted, #f8fafc) 88%, white);
  border: 1px dashed var(--line, rgba(148, 163, 184, 0.28));
  border-radius: 24px;
  color: var(--muted, #475569);
  padding: 1.25rem;
}
</style>
