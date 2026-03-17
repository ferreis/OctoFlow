<script setup>
import { computed, onBeforeUnmount, watch } from 'vue'

const props = defineProps({
  notification: {
    type: Object,
    default: null,
  },
  duration: {
    type: Number,
    default: 5000,
  },
})

const emit = defineEmits(['close'])

let closeTimer = null

const tone = computed(() => {
  const type = String(props.notification?.type || 'info').toLowerCase()

  if (type === 'success') {
    return {
      frame: 'border-emerald-200 bg-emerald-50/95 text-emerald-950 shadow-[0_22px_50px_rgba(22,101,52,0.18)]',
      badge: 'bg-emerald-100 text-emerald-700',
      title: 'Sucesso',
    }
  }

  if (type === 'error') {
    return {
      frame: 'border-rose-200 bg-rose-50/95 text-rose-950 shadow-[0_22px_50px_rgba(185,28,28,0.16)]',
      badge: 'bg-rose-100 text-rose-700',
      title: 'Erro',
    }
  }

  if (type === 'warning') {
    return {
      frame: 'border-amber-200 bg-amber-50/95 text-amber-950 shadow-[0_22px_50px_rgba(217,119,6,0.16)]',
      badge: 'bg-amber-100 text-amber-700',
      title: 'Aviso',
    }
  }

  return {
    frame: 'border-cyan-200 bg-cyan-50/95 text-cyan-950 shadow-[0_22px_50px_rgba(8,145,178,0.16)]',
    badge: 'bg-cyan-100 text-cyan-700',
    title: 'Informacao',
  }
})

watch(
  () => props.notification?.id,
  (notificationId) => {
    clearCloseTimer()

    if (!notificationId) {
      return
    }

    closeTimer = window.setTimeout(() => {
      emit('close')
    }, props.duration)
  },
)

onBeforeUnmount(() => {
  clearCloseTimer()
})

function clearCloseTimer() {
  if (closeTimer !== null) {
    window.clearTimeout(closeTimer)
    closeTimer = null
  }
}

function closeNotification() {
  clearCloseTimer()
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="translate-y-2 opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-1 opacity-0"
    >
      <div
        v-if="notification?.message"
        class="pointer-events-none fixed right-4 top-4 z-[140] w-[min(92vw,380px)]"
      >
        <button
          type="button"
          class="pointer-events-auto grid w-full gap-3 rounded-[24px] border p-4 text-left backdrop-blur"
          :class="tone.frame"
          @click="closeNotification"
        >
          <div class="flex items-start justify-between gap-3">
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-black uppercase tracking-[0.2em]" :class="tone.badge">
              {{ tone.title }}
            </span>
            <span class="text-xs font-semibold opacity-70">Fechar</span>
          </div>

          <p class="text-sm font-semibold leading-6">
            {{ notification.message }}
          </p>
        </button>
      </div>
    </Transition>
  </Teleport>
</template>
