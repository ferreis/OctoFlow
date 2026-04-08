<script setup>
import { storeToRefs } from 'pinia'
import { onErrorCaptured, onMounted } from 'vue'
import AppNotification from './components/layout/AppNotification.vue'
import { useI18n } from './composables/useI18n'
import { useSessionStore } from './stores/sessionStore'

const sessionStore = useSessionStore()
const { translate } = useI18n()
const { notification } = storeToRefs(sessionStore)

onMounted(async () => {
  await sessionStore.ensureInitialized()
})

onErrorCaptured((error, instance, info) => {
  console.error('App Global Error (Frontend Protegido):', error, info)

  if (sessionStore && typeof sessionStore.showNotification === 'function') {
    sessionStore.showNotification(translate('app.globalInteractiveError'), 'error')
  }

  return false
})
</script>

<template>
  <AppNotification :notification="notification" @close="sessionStore.clearNotification" />
  <RouterView />
</template>
