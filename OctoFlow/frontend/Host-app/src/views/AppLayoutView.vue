<script setup>
import { storeToRefs } from 'pinia'
import {
  computed,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
  ref,
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

const pageStageElement = ref(null)
const shouldFocusMainStage = ref(false)
const lastRenderedRoutePath = ref('')
const navigationRequestInProgress = ref(false)

const { isCompactViewport, effectiveSidebarExpanded } = storeToRefs(appShellStore)
const { activeViewKey: activeView } = storeToRefs(appNavigationStore)
const { isAuthenticated, requiresGooglePasswordSetup, currentUser } = storeToRefs(sessionStore)
const isFinanceModule = computed(() => appRoute.path.includes('/finance'))

let viewportMediaQuery = null
let removeViewportListener = null
let removeKeyboardShortcutListener = null

function detachViewportListener() {
  removeViewportListener?.()
  removeViewportListener = null
  viewportMediaQuery = null
}

function attachViewportListener() {
  if (typeof window === 'undefined') {
    return
  }

  detachViewportListener()

  viewportMediaQuery = window.matchMedia('(max-width: 1180px)')
  appShellStore.setCompactViewport(viewportMediaQuery.matches)

  const handleViewportChange = (viewportEvent) => {
    appShellStore.setCompactViewport(viewportEvent.matches)
  }

  if (typeof viewportMediaQuery.addEventListener === 'function') {
    viewportMediaQuery.addEventListener('change', handleViewportChange)
    removeViewportListener = () => viewportMediaQuery?.removeEventListener('change', handleViewportChange)
    return
  }

  viewportMediaQuery.addListener(handleViewportChange)
  removeViewportListener = () => viewportMediaQuery?.removeListener(handleViewportChange)
}

function collapseSidebarOnEscape(keyboardEvent) {
  if (keyboardEvent.key !== 'Escape') {
    return
  }

  if (!effectiveSidebarExpanded.value) {
    return
  }

  collapseSidebar()
}

function detachKeyboardShortcutListener() {
  removeKeyboardShortcutListener?.()
  removeKeyboardShortcutListener = null
}

function attachKeyboardShortcutListener() {
  if (typeof window === 'undefined' || removeKeyboardShortcutListener) {
    return
  }

  const keyboardShortcutHandler = (keyboardEvent) => collapseSidebarOnEscape(keyboardEvent)
  window.addEventListener('keydown', keyboardShortcutHandler)
  removeKeyboardShortcutListener = () => {
    window.removeEventListener('keydown', keyboardShortcutHandler)
  }
}

function syncNavigationFromCurrentRoute() {
  appNavigationStore.syncFromRoute(appRoute)
}

onBeforeMount(() => {
  syncNavigationFromCurrentRoute()
  shouldFocusMainStage.value = true
})

onMounted(() => {
  attachViewportListener()
  attachKeyboardShortcutListener()
})

onBeforeUpdate(() => {
  const currentRoutePath = String(appRoute.fullPath || '')
  if (currentRoutePath !== lastRenderedRoutePath.value) {
    shouldFocusMainStage.value = true
  }
})

onUpdated(() => {
  lastRenderedRoutePath.value = String(appRoute.fullPath || '')

  if (!shouldFocusMainStage.value) {
    return
  }

  const pageStageValue = pageStageElement.value
  if (pageStageValue instanceof HTMLElement) {
    pageStageValue.focus({ preventScroll: true })
  }

  shouldFocusMainStage.value = false
})

onActivated(() => {
  attachViewportListener()
  attachKeyboardShortcutListener()
  syncNavigationFromCurrentRoute()
})

onDeactivated(() => {
  detachViewportListener()
  detachKeyboardShortcutListener()
})

onBeforeUnmount(() => {
  detachViewportListener()
  detachKeyboardShortcutListener()
})

onUnmounted(() => {
  lastRenderedRoutePath.value = ''
  shouldFocusMainStage.value = false
})

onErrorCaptured((capturedError) => {
  console.error('Layout Error Blocked:', capturedError)
  sessionStore.showNotification(translate('app.globalInteractiveError'), 'error')
  return false
})

watch(
  () => appRoute.fullPath,
  (nextFullPath, previousFullPath) => {
    if (nextFullPath === previousFullPath) {
      return
    }

    syncNavigationFromCurrentRoute()
    shouldFocusMainStage.value = true
  },
)

async function navigateTo(nextViewKey) {
  if (!isAuthenticated.value || navigationRequestInProgress.value) {
    return
  }

  navigationRequestInProgress.value = true

  try {
    const normalizedViewKey = appNavigationStore.normalizeViewKey(nextViewKey)
    const authenticatedSessionIsValid = await sessionStore.verifyAuthenticatedSession()

    if (!authenticatedSessionIsValid) {
      await appRouter.replace({ name: 'auth-login' })
      return
    }

    await appNavigationStore.navigateToView(appRouter, normalizedViewKey)
  } finally {
    navigationRequestInProgress.value = false
  }
}

function expandSidebar() {
  appShellStore.expandSidebar()
}

function collapseSidebar() {
  appShellStore.collapseSidebar()
}

async function logoutSession() {
  if (navigationRequestInProgress.value) {
    return
  }

  navigationRequestInProgress.value = true

  try {
    await sessionStore.logout()
    await appRouter.replace({ name: 'auth-login' })
  } finally {
    navigationRequestInProgress.value = false
  }
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

    <div class="app-main" :class="{ 'edge-to-edge': isFinanceModule }">
      <main ref="pageStageElement" class="page-stage" tabindex="-1">
        <RouterView />
      </main>

      <AppFooter />
    </div>
  </div>
</template>
