<script setup>
import { onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useFinanceStore } from '../../stores/financeStore'

const router = useRouter()
const route = useRoute()
const financeStore = useFinanceStore()

const tabItems = [
  { key: 'finance-accounts', label: 'Contas', routeName: 'finance-accounts' },
  { key: 'finance-banks', label: 'Bancos', routeName: 'finance-banks' },
  { key: 'finance-investments', label: 'Investimentos', routeName: 'finance-investments' },
  { key: 'finance-settings', label: 'Configurações', routeName: 'finance-settings' },
  { key: 'finance-reports', label: 'Relatórios', routeName: 'finance-reports' },
]

function isTabActive(tabItem) {
  return route.name === tabItem.routeName
}

function navigateToTab(tabItem) {
  if (route.name !== tabItem.routeName) {
    router.push({ name: tabItem.routeName })
  }
}

onMounted(() => {
  financeStore.loadCatalogs()
})
</script>

<template>
  <section class="finance-screen">
    <nav class="finance-subtabs" aria-label="Navegação do módulo financeiro">
      <button
        v-for="tabItem in tabItems"
        :key="tabItem.key"
        type="button"
        class="finance-subtab-button"
        :class="{ active: isTabActive(tabItem) }"
        @click="navigateToTab(tabItem)"
      >
        {{ tabItem.label }}
      </button>
    </nav>

    <transition name="finance-view" mode="out-in">
      <router-view />
    </transition>
  </section>
</template>

<style scoped>
.finance-view-enter-active,
.finance-view-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.finance-view-enter-from {
  opacity: 0;
  transform: translateY(6px);
}

.finance-view-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
