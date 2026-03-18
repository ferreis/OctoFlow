<template>
  <article class="base-card">
    <slot />
  </article>
</template>

<style scoped>
.base-card {
  background: var(--app-panel-bg, var(--surface-strong, rgba(255, 255, 255, 0.92)));
  border: 1px solid var(--app-panel-border, var(--line, rgba(148, 163, 184, 0.22)));
  border-radius: 28px;
  box-shadow: var(--app-panel-shadow, 0 18px 48px rgba(15, 23, 42, 0.07));
  color: var(--ink, #0f172a);
  padding: 1.25rem;
}
</style>
