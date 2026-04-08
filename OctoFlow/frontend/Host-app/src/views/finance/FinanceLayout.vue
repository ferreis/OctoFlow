<script setup>
import {
  computed,
  nextTick,
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
} from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import { useNotification } from '../../composables/useNotification'
import { useScopedI18n } from '../../composables/useScopedI18n'
import { FINANCE_ROUTE_NAMES } from '../../router/viewRouting'
import { useFinanceStore } from '../../stores/financeStore'

const router = useRouter()
const route = useRoute()
const financeStore = useFinanceStore()
const { loading, error } = storeToRefs(financeStore)
const { notifyUser } = useNotification()
const { translateScoped } = useScopedI18n('financeModule.layout')

const activeRouteNameBeforeDomUpdate = ref('')
const shouldFocusCurrentTabAfterDomUpdate = ref(false)
const keyboardNavigationEnabled = ref(true)
const financeLayoutIsActive = ref(false)
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

const currentRouteName = computed(() => String(route.name || '').trim())

const isCatalogLoading = computed(() => loading.value.catalogs === true)

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

async function ensureCatalogsLoaded(forceReload = false) {
  try {
    await financeStore.loadCatalogs(forceReload)
  } catch {
    notifyUser(translateScoped('messages.catalogLoadFailed', 'Falha ao carregar dados iniciais do módulo financeiro.'), 'error')
  }
}

function navigateToTab(tabItem) {
  if (isTabActive(tabItem)) {
    return
  }

  router.push({ name: tabItem.routeName })
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

function retryCatalogsLoad() {
  void ensureCatalogsLoaded(true)
}

onBeforeMount(() => {
  if (!Object.values(FINANCE_ROUTE_NAMES).includes(currentRouteName.value)) {
    void router.replace({ name: FINANCE_ROUTE_NAMES.accounts })
  }

  void ensureCatalogsLoaded(false)
})

onMounted(() => {
  financeLayoutIsActive.value = true

  if (typeof window !== 'undefined') {
    window.addEventListener('keydown', handleTabsKeyboardNavigation)
  }

  void nextTick(() => {
    focusCurrentTab()
  })
})

onBeforeUpdate(() => {
  const currentRouteNameBeforeDomUpdate = activeRouteNameBeforeDomUpdate.value
  const nextRouteName = currentRouteName.value

  if (
    currentRouteNameBeforeDomUpdate !== ''
    && currentRouteNameBeforeDomUpdate !== nextRouteName
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
  financeLayoutIsActive.value = true
  keyboardNavigationEnabled.value = true

  void nextTick(() => {
    focusCurrentTab()
  })

  void ensureCatalogsLoaded(false)
})

onDeactivated(() => {
  financeLayoutIsActive.value = false
  keyboardNavigationEnabled.value = false
})

onBeforeUnmount(() => {
  financeLayoutIsActive.value = false
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

onErrorCaptured((error) => {
  if (!financeLayoutIsActive.value) {
    return false
  }

  console.error('[FinanceLayout] child render error:', error)
  notifyUser(translateScoped('messages.childRenderError', 'Erro inesperado ao renderizar a tela financeira.'), 'error')
  return false
})
</script>

<template>
  <section class="finance-screen">
    <nav
      ref="navigationElementRef"
      class="finance-subtabs"
      :aria-label="translateScoped('tabs.navigationAriaLabel', 'Navegação do módulo financeiro')"
    >
      <button
        v-for="tabItem in tabItems"
        :key="tabItem.routeName"
        :ref="(element) => registerTabRef(tabItem.routeName, element)"
        type="button"
        class="finance-subtab-button"
        :class="{ active: isTabActive(tabItem) }"
        @click="navigateToTab(tabItem)"
      >
        {{ tabItem.label }}
      </button>
    </nav>

    <article v-if="error" class="finance-panel finance-state-alert">
      <header>
        <h3>{{ translateScoped('messages.catalogLoadFailedTitle', 'Falha ao carregar catálogo financeiro') }}</h3>
      </header>
      <p>{{ error }}</p>
      <div class="finance-form-actions">
        <button
          type="button"
          class="finance-inline-action"
          :disabled="isCatalogLoading"
          @click="retryCatalogsLoad"
        >
          {{ isCatalogLoading
            ? translateScoped('buttons.loading', 'Atualizando...')
            : translateScoped('buttons.retry', 'Tentar novamente') }}
        </button>
      </div>
    </article>

    <transition name="finance-view" mode="out-in">
      <router-view />
    </transition>
  </section>
</template>
