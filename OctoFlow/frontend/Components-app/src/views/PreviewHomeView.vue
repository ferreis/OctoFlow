<script setup>
import { storeToRefs } from 'pinia'
import {
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
} from 'vue'
import { useRouter } from 'vue-router'
import { useRemotePreviewStore } from '../stores/remotePreviewStore'

const remotePreviewStore = useRemotePreviewStore()

// Adicionando vue-router conforme padrão apontado
const router = useRouter()

const { title, description, details } = storeToRefs(remotePreviewStore)

onBeforeMount(() => {})
onMounted(() => {})
onBeforeUpdate(() => {})
onUpdated(() => {})

onBeforeUnmount(() => {
  // Limpeza explícita para evitar memory leaks antes da destruição
  if (remotePreviewStore && typeof remotePreviewStore.$reset === 'function') {
    remotePreviewStore.$reset()
  }
})
onUnmounted(() => {})

onActivated(() => {})
onDeactivated(() => {})

onErrorCaptured((error, instance, info) => {
  console.error('PreviewHomeView Error Blocked:', error, info)
  return false
})
</script>

<template>
  <main class="remote-shell">
    <h1>{{ title }}</h1>
    <p>{{ description }}</p>
    <p>{{ details }}</p>
  </main>
</template>

