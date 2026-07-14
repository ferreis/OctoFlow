<script setup>
import {
  computed,
  nextTick,
  onMounted,
  ref,
  watch,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useScopedI18n } from '../../composables/useScopedI18n'
import { FINANCE_ROUTE_NAMES } from '../../router/viewRouting'

const appRouter = useRouter()
const appRoute = useRoute()
const { translateScoped } = useScopedI18n('financeModule.layout')

const navigationElementRef = ref(null)
const tabButtonByRouteName = new Map()

const tabItems = computed(() => ([
  {
    key: 'accounts',
    routeName: FINANCE_ROUTE_NAMES.accounts,
    icon: 'accounts',
    label: translateScoped('tabs.accounts', 'Contas'),
  },
  {
    key: 'banks',
    routeName: FINANCE_ROUTE_NAMES.banks,
    icon: 'banks',
    label: translateScoped('tabs.banks', 'Bancos'),
  },
  {
    key: 'investments',
    routeName: FINANCE_ROUTE_NAMES.investments,
    icon: 'investments',
    label: translateScoped('tabs.investments', 'Investimentos'),
  },
  {
    key: 'settings',
    routeName: FINANCE_ROUTE_NAMES.settings,
    icon: 'settings',
    label: translateScoped('tabs.settings', 'Configurações'),
  },
  {
    key: 'reports',
    routeName: FINANCE_ROUTE_NAMES.reports,
    icon: 'reports',
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

  void appRouter.push({ name: tabItem.routeName })
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

onMounted(() => {
  void nextTick(() => {
    focusCurrentTab()
  })
})

watch(currentRouteName, (nextRouteName) => {
  if (!Object.values(FINANCE_ROUTE_NAMES).includes(nextRouteName)) {
    void appRouter.replace({ name: FINANCE_ROUTE_NAMES.accounts })
    return
  }

  void nextTick(() => {
    focusCurrentTab()
  })
}, { immediate: true })
</script>

<template>
  <header class="finance-header">
    <div class="finance-header-brand">
      <span class="finance-header-icon" aria-hidden="true">💰</span>
      <div class="finance-header-title-group">
        <h2 class="finance-header-title">
          {{ translateScoped('header.title', 'Financeiro') }}
        </h2>
      </div>
    </div>

    <span class="finance-header-divider" aria-hidden="true"></span>

    <nav
      ref="navigationElementRef"
      class="finance-header-nav"
      role="tablist"
      :aria-label="translateScoped('tabs.navigationAriaLabel', 'Navegação do módulo financeiro')"
      @keydown="handleTabsKeyboardNavigation"
    >
      <button
        v-for="tabItem in tabItems"
        :key="tabItem.routeName"
        :ref="(element) => registerTabRef(tabItem.routeName, element)"
        type="button"
        role="tab"
        class="finance-header-link"
        :class="{ active: isTabActive(tabItem) }"
        :title="tabItem.label"
        :aria-label="tabItem.label"
        :aria-selected="isTabActive(tabItem)"
        :aria-controls="`finance-route-${tabItem.key}`"
        @click="navigateToTab(tabItem)"
      >
        <svg v-if="tabItem.icon === 'accounts'" viewBox="0 0 24 24" class="finance-header-link-icon" aria-hidden="true"><path d="M4 19h16M6 16v-5M12 16V6M18 16v-8" /></svg>
        <svg v-else-if="tabItem.icon === 'banks'" viewBox="0 0 24 24" class="finance-header-link-icon" aria-hidden="true"><path d="M4 20V8l8-4 8 4v12M8 20v-5h8v5M9 10h.01M15 10h.01" /></svg>
        <svg v-else-if="tabItem.icon === 'investments'" viewBox="0 0 24 24" class="finance-header-link-icon" aria-hidden="true"><path d="M4 19h16M6 16l4-5 3 3 5-7M16 7h2v2" /></svg>
        <svg v-else-if="tabItem.icon === 'settings'" viewBox="0 0 24 24" class="finance-header-link-icon" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.12 2.12-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56v.08h-3v-.08A1.7 1.7 0 0 0 10.68 18.66a1.7 1.7 0 0 0-1.88.34l-.06.06-2.12-2.12.06-.06A1.7 1.7 0 0 0 7.02 15a1.7 1.7 0 0 0-1.56-1.03h-.08v-3h.08A1.7 1.7 0 0 0 7.02 9.94a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.12-2.12.06.06a1.7 1.7 0 0 0 1.88.34 1.7 1.7 0 0 0 1.03-1.56v-.08h3v.08a1.7 1.7 0 0 0 1.03 1.56 1.7 1.7 0 0 0 1.88-.34l.06-.06L19.8 8l-.06.06a1.7 1.7 0 0 0-.34 1.88 1.7 1.7 0 0 0 1.56 1.03h.08v3h-.08A1.7 1.7 0 0 0 19.4 15Z" /></svg>
        <svg v-else viewBox="0 0 24 24" class="finance-header-link-icon" aria-hidden="true"><path d="M5 20V10M10 20V4M15 20v-7M20 20V7M3 20h18" /></svg>
        <span class="finance-header-link-label">{{ tabItem.label }}</span>
      </button>
    </nav>
  </header>
</template>
