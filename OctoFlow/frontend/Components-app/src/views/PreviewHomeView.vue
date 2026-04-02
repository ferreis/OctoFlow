<script setup>
import { storeToRefs } from 'pinia'
import { useRemotePreviewStore } from '../stores/remotePreviewStore'

const remotePreviewStore = useRemotePreviewStore()
const { title, description, details } = storeToRefs(remotePreviewStore)
</script>

<template>
  <main class="remote-shell">
    <h1>{{ title }}</h1>
    <p>{{ description }}</p>
    <p>{{ details }}</p>
  </main>
</template>

<style scoped>
.remote-shell {
  align-items: center;
  color: var(--ink);
  display: grid;
  gap: 8px;
  margin: 0 auto;
  max-width: 760px;
  min-height: 100vh;
  padding: 24px;
  place-content: center;
  text-align: center;
}

.remote-shell h1 {
  margin: 0;
}

.remote-shell p {
  color: var(--muted);
  margin: 0;
}
</style>
