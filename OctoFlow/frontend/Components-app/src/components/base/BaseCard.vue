<template>
  <article class="base-card">
    <slot />
  </article>
</template>

<style scoped>
.base-card {
  background: var(--app-panel-bg);
  border: 1px solid var(--app-panel-border);
  border-radius: 28px;
  box-shadow: var(--app-panel-shadow);
  color: var(--ink);
  padding: 1.25rem;
}
</style>
