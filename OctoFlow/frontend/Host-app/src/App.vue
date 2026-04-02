<script setup>
import { storeToRefs } from 'pinia'
import { onMounted } from 'vue'
import AppNotification from './components/layout/AppNotification.vue'
import { useSessionStore } from './stores/sessionStore'

const sessionStore = useSessionStore()
const { notification } = storeToRefs(sessionStore)

onMounted(async () => {
  await sessionStore.ensureInitialized()
})
</script>

<template>
  <AppNotification :notification="notification" @close="sessionStore.clearNotification" />
  <RouterView />
</template>
