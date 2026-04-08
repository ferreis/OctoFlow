<script setup>
import { storeToRefs } from 'pinia'
import {
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
} from 'vue'
import AppNotification from './components/layout/AppNotification.vue'
import { useSessionStore } from './stores/sessionStore'

const sessionStore = useSessionStore()
const { notification } = storeToRefs(sessionStore)

// Vue Lifecycle completo (padronizado)
onBeforeMount(() => {
  // lógica antes de renderizar (sem DOM ainda)
})

onMounted(async () => {
  // DOM pronto: API, refs, libs externas
  await sessionStore.ensureInitialized()
})

onBeforeUpdate(() => {
  // antes de atualizar DOM (estado mudou)
})

onUpdated(() => {
  // depois que DOM atualizou
})

onBeforeUnmount(() => {
  // preparar limpeza (parar processos)
})

onUnmounted(() => {
  // limpar tudo (eventos, timers, sockets)
})

onActivated(() => {
  // componente reativado (keep-alive)
})

onDeactivated(() => {
  // componente pausado (keep-alive)
})

// capturar erro de filhos
onErrorCaptured((error, instance, info) => {
  console.error('App Global Error (Frontend Protegido):', error, info)

  if (sessionStore && typeof sessionStore.showNotification === 'function') {
    sessionStore.showNotification('Ocorreu um erro interativo no painel. Tente novamente.', 'error')
  }

  // Previne a propagação global para não travar a UI silenciosamente
  return false
})
</script>

<template>
  <AppNotification :notification="notification" @close="sessionStore.clearNotification" />
  <RouterView />
</template>
