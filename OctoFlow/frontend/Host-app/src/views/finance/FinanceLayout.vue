<script setup>
import {
  computed,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  ref,
} from 'vue'
import { storeToRefs } from 'pinia'
import FinanceTabsNavbar from '../../components/finance/FinanceTabsNavbar.vue'
import { useNotification } from '../../composables/useNotification'
import { useScopedI18n } from '../../composables/useScopedI18n'
import { useFinanceStore } from '../../stores/financeStore'

const financeStore = useFinanceStore()
const { loading, error } = storeToRefs(financeStore)
const { notifyUser } = useNotification()
const { translateScoped } = useScopedI18n('financeModule.layout')

const financeLayoutIsActive = ref(false)

const isCatalogLoading = computed(() => loading.value.catalogs === true)

async function ensureCatalogsLoaded(forceReload = false) {
  try {
    await financeStore.loadCatalogs(forceReload)
  } catch {
    notifyUser(translateScoped('messages.catalogLoadFailed', 'Falha ao carregar dados iniciais do módulo financeiro.'), 'error')
  }
}

function retryCatalogsLoad() {
  void ensureCatalogsLoaded(true)
}

onBeforeMount(() => {
  void ensureCatalogsLoaded(false)
})

onMounted(() => {
  financeLayoutIsActive.value = true
})

onActivated(() => {
  financeLayoutIsActive.value = true
  void ensureCatalogsLoaded(false)
})

onDeactivated(() => {
  financeLayoutIsActive.value = false
})

onBeforeUnmount(() => {
  financeLayoutIsActive.value = false
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
  <section class="finance-layout-container">
    <FinanceTabsNavbar />

    <div class="finance-content-stage">

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
    </div>
  </section>
</template>
