<script setup>
import { storeToRefs } from 'pinia'
import {
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  ref,
} from 'vue'
import AppNotification from './components/layout/AppNotification.vue'
import { useI18n } from './composables/useI18n'
import { useSessionStore } from './stores/sessionStore'

const sessionStore = useSessionStore()
const { translate } = useI18n()
const { notification } = storeToRefs(sessionStore)

const appInitializationInProgress = ref(false)
const appInitializationFinished = ref(false)
let appWasUnmounted = false

async function ensureApplicationInitialization() {
  if (appInitializationInProgress.value || appInitializationFinished.value || appWasUnmounted) {
    return
  }

  appInitializationInProgress.value = true

  try {
    await sessionStore.ensureInitialized()

    if (!appWasUnmounted) {
      appInitializationFinished.value = true
    }
  } finally {
    if (!appWasUnmounted) {
      appInitializationInProgress.value = false
    }
  }
}

onBeforeMount(() => {
  appWasUnmounted = false
})

onMounted(async () => {
  await ensureApplicationInitialization()
})

onActivated(async () => {
  if (!appInitializationFinished.value) {
    await ensureApplicationInitialization()
  }
})

onDeactivated(() => {
  appInitializationInProgress.value = false
})

onBeforeUnmount(() => {
  appWasUnmounted = true
})

onUnmounted(() => {
  appInitializationInProgress.value = false
})

onErrorCaptured((capturedError, _componentInstance, errorInfo) => {
  console.error('App Global Error (Frontend Protegido):', capturedError, errorInfo)

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
