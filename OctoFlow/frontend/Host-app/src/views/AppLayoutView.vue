<script setup>
import { storeToRefs } from 'pinia'
import {
  onBeforeUnmount,
  onErrorCaptured,
  onMounted,
  watch,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppFooter from '../components/layout/AppFooter.vue'
import MenuSidebar from '../components/layout/MenuSidebar.vue'
import { useI18n } from '../composables/useI18n'
import { navigationItems } from '../constants/navigation'
import { useAppNavigationStore } from '../stores/appNavigationStore'
import { useAppShellStore } from '../stores/appShellStore'
import { useSessionStore } from '../stores/sessionStore'

const appShellStore = useAppShellStore()
const appNavigationStore = useAppNavigationStore()
const sessionStore = useSessionStore()
const appRoute = useRoute()
const appRouter = useRouter()
const { translate } = useI18n()

const { isCompactViewport, effectiveSidebarExpanded } = storeToRefs(appShellStore)
const { activeViewKey: activeView } = storeToRefs(appNavigationStore)
const { isAuthenticated, requiresGooglePasswordSetup, currentUser } = storeToRefs(sessionStore)

let viewportMediaQuery = null
let removeViewportListener = null

onMounted(() => {
  if (typeof window === 'undefined') {
    return
  }

  viewportMediaQuery = window.matchMedia('(max-width: 1180px)')
  appShellStore.setCompactViewport(viewportMediaQuery.matches)

  const handleViewportChange = (event) => {
    appShellStore.setCompactViewport(event.matches)
  }

  if (typeof viewportMediaQuery.addEventListener === 'function') {
    viewportMediaQuery.addEventListener('change', handleViewportChange)
    removeViewportListener = () => viewportMediaQuery?.removeEventListener('change', handleViewportChange)
  } else {
    viewportMediaQuery.addListener(handleViewportChange)
    removeViewportListener = () => viewportMediaQuery?.removeListener(handleViewportChange)
  }
})

onBeforeUnmount(() => {
  removeViewportListener?.()
  removeViewportListener = null
  viewportMediaQuery = null
})

onErrorCaptured((error) => {
  console.error('Layout Error Blocked:', error)
  sessionStore.showNotification(translate('app.globalInteractiveError'), 'error')
  return false
})

watch(
  () => appRoute.fullPath,
  () => {
    appNavigationStore.syncFromRoute(appRoute)
  },
  {
    immediate: true,
  },
)

async function navigateTo(viewKey) {
  if (!isAuthenticated.value) {
    return
  }

  const normalizedViewKey = appNavigationStore.normalizeViewKey(viewKey)

  const screenAuthIsValid = await sessionStore.verifyAuthenticatedSession()
  if (!screenAuthIsValid) {
    await appRouter.replace({ name: 'auth-login' })
    return
  }

  await appNavigationStore.navigateToView(appRouter, normalizedViewKey)
}

function expandSidebar() {
  appShellStore.expandSidebar()
}

function collapseSidebar() {
  appShellStore.collapseSidebar()
}

async function logoutSession() {
  await sessionStore.logout()
  await appRouter.replace({ name: 'auth-login' })
}
</script>

<template>
  <div
    class="app-shell"
    :class="{
      collapsed: isAuthenticated && !effectiveSidebarExpanded && !requiresGooglePasswordSetup,
      compact: isCompactViewport,
      'no-sidebar': !isAuthenticated || requiresGooglePasswordSetup,
    }"
  >
    <MenuSidebar
      v-if="isAuthenticated && !requiresGooglePasswordSetup"
      :items="navigationItems"
      :active-key="activeView"
      :authenticated="isAuthenticated"
      :current-user="currentUser"
      :compact="isCompactViewport"
      :expanded="effectiveSidebarExpanded"
      @navigate="navigateTo"
      @expand="expandSidebar"
      @collapse="collapseSidebar"
      @logout="logoutSession"
    />

    <div class="app-main">
      <main class="page-stage">
        <RouterView />
      </main>

      <AppFooter />
    </div>
  </div>
</template>
