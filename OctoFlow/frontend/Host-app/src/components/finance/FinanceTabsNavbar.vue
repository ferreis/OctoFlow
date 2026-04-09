<script setup>
import {
  computed,
  nextTick,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onMounted,
  onUnmounted,
  onUpdated,
  ref,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useScopedI18n } from '../../composables/useScopedI18n'
import { FINANCE_ROUTE_NAMES } from '../../router/viewRouting'

const appRouter = useRouter()
const appRoute = useRoute()
const { translateScoped } = useScopedI18n('financeModule.layout')

const activeRouteNameBeforeDomUpdate = ref('')
const shouldFocusCurrentTabAfterDomUpdate = ref(false)
const keyboardNavigationEnabled = ref(true)
const financeNavbarIsActive = ref(false)
const navigationElementRef = ref(null)
const tabButtonByRouteName = new Map()

const tabItems = computed(() => ([
  {
    key: 'accounts',
    routeName: FINANCE_ROUTE_NAMES.accounts,
    label: translateScoped('tabs.accounts', 'Contas'),
  },
  {
    key: 'banks',
    routeName: FINANCE_ROUTE_NAMES.banks,
    label: translateScoped('tabs.banks', 'Bancos'),
  },
  {
    key: 'investments',
    routeName: FINANCE_ROUTE_NAMES.investments,
    label: translateScoped('tabs.investments', 'Investimentos'),
  },
  {
    key: 'settings',
    routeName: FINANCE_ROUTE_NAMES.settings,
    label: translateScoped('tabs.settings', 'Configurações'),
  },
  {
    key: 'reports',
    routeName: FINANCE_ROUTE_NAMES.reports,
    label: translateScoped('tabs.reports', 'Relatórios'),
  },
]))

const currentRouteName = computed(() => String(appRoute.name || '').trim())

function isTabActive(tabItem) {
  return currentRouteName.value === tabItem.routeName
}

function registerTabRef(routeName, element) {
  if (!routeName) {
    return
  }

  if (element) {
    tabButtonByRouteName.set(routeName, element)
    return
  }

  tabButtonByRouteName.delete(routeName)
}

function navigateToTab(tabItem) {
  if (isTabActive(tabItem)) {
    return
  }

  appRouter.push({ name: tabItem.routeName })
}

function focusCurrentTab() {
  const currentTabButton = tabButtonByRouteName.get(currentRouteName.value)
  if (currentTabButton && typeof currentTabButton.focus === 'function') {
    currentTabButton.focus({ preventScroll: true })
  }
}

function getCurrentTabIndex() {
  return tabItems.value.findIndex((tabItem) => tabItem.routeName === currentRouteName.value)
}

function navigateUsingKeyboard(direction) {
  const currentTabIndex = getCurrentTabIndex()
  if (currentTabIndex < 0) {
    return
  }

  const nextIndex = (currentTabIndex + direction + tabItems.value.length) % tabItems.value.length
  const nextTab = tabItems.value[nextIndex]
  if (!nextTab) {
    return
  }

  navigateToTab(nextTab)
}

function handleTabsKeyboardNavigation(keyboardEvent) {
  if (!keyboardNavigationEnabled.value) {
    return
  }

  if (!navigationElementRef.value || !navigationElementRef.value.contains(keyboardEvent.target)) {
    return
  }

  if (keyboardEvent.key === 'ArrowRight') {
    keyboardEvent.preventDefault()
    navigateUsingKeyboard(1)
    return
  }

  if (keyboardEvent.key === 'ArrowLeft') {
    keyboardEvent.preventDefault()
    navigateUsingKeyboard(-1)
  }
}

onBeforeMount(() => {
  if (!Object.values(FINANCE_ROUTE_NAMES).includes(currentRouteName.value)) {
    void appRouter.replace({ name: FINANCE_ROUTE_NAMES.accounts })
  }
})

onMounted(() => {
  financeNavbarIsActive.value = true

  if (typeof window !== 'undefined') {
    window.addEventListener('keydown', handleTabsKeyboardNavigation)
  }

  void nextTick(() => {
    focusCurrentTab()
  })
})

onBeforeUpdate(() => {
  const routeNameBeforeDomUpdate = activeRouteNameBeforeDomUpdate.value
  const nextRouteName = currentRouteName.value

  if (
    routeNameBeforeDomUpdate !== ''
    && routeNameBeforeDomUpdate !== nextRouteName
  ) {
    shouldFocusCurrentTabAfterDomUpdate.value = true
  }

  activeRouteNameBeforeDomUpdate.value = nextRouteName
})

onUpdated(() => {
  if (!shouldFocusCurrentTabAfterDomUpdate.value) {
    return
  }

  shouldFocusCurrentTabAfterDomUpdate.value = false
  void nextTick(() => {
    focusCurrentTab()
  })
})

onActivated(() => {
  financeNavbarIsActive.value = true
  keyboardNavigationEnabled.value = true

  void nextTick(() => {
    focusCurrentTab()
  })
})

onDeactivated(() => {
  financeNavbarIsActive.value = false
  keyboardNavigationEnabled.value = false
})

onBeforeUnmount(() => {
  financeNavbarIsActive.value = false
  keyboardNavigationEnabled.value = false
  shouldFocusCurrentTabAfterDomUpdate.value = false
})

onUnmounted(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('keydown', handleTabsKeyboardNavigation)
  }

  activeRouteNameBeforeDomUpdate.value = ''
  tabButtonByRouteName.clear()
})
</script>

<template>
  <header class="finance-header">
    <div class="finance-header-brand">
      <span class="finance-header-icon" aria-hidden="true">💰</span>
      <div class="finance-header-title-group">
        <h2 class="finance-header-title">
          {{ translateScoped('header.title', 'Financeiro') }}
        </h2>
        <span class="finance-header-badge">
          {{ translateScoped('header.badge', 'Módulo') }}
        </span>
      </div>
    </div>

    <span class="finance-header-divider" aria-hidden="true"></span>

    <nav
      ref="navigationElementRef"
      class="finance-header-nav"
      role="tablist"
      :aria-label="translateScoped('tabs.navigationAriaLabel', 'Navegação do módulo financeiro')"
    >
      <button
        v-for="tabItem in tabItems"
        :key="tabItem.routeName"
        :ref="(element) => registerTabRef(tabItem.routeName, element)"
        type="button"
        role="tab"
        class="finance-header-link"
        :class="{ active: isTabActive(tabItem) }"
        :aria-selected="isTabActive(tabItem)"
        :aria-controls="`finance-route-${tabItem.key}`"
        @click="navigateToTab(tabItem)"
      >
        {{ tabItem.label }}
      </button>
    </nav>
  </header>
</template>
