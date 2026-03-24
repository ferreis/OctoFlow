<script setup>
import { computed, onBeforeUnmount, watch } from 'vue'
import { resolveNotificationToneClasses } from '../../utils/statusTone'

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
let timerStartedAt = 0
let remainingDuration = 0

const effectiveDuration = computed(() => {
  const payloadDuration = Number(props.notification?.duration)
  if (Number.isFinite(payloadDuration) && payloadDuration > 0) {
    return Math.min(Math.max(payloadDuration, 3500), 18000)
  }

  const messageLength = String(props.notification?.message || '').trim().length
  const dynamicDuration = props.duration + Math.max(0, messageLength - 90) * 42

  return Math.min(Math.max(dynamicDuration, props.duration), 14000)
})

const tone = computed(() => {
  return resolveNotificationToneClasses(props.notification?.type)
})

watch(
  () => props.notification?.id,
  (notificationId) => {
    clearCloseTimer()

    if (!notificationId) {
      return
    }

    remainingDuration = effectiveDuration.value
    startCloseTimer()
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

function startCloseTimer() {
  timerStartedAt = Date.now()
  closeTimer = window.setTimeout(() => {
    emit('close')
  }, remainingDuration)
}

function pauseCloseTimer() {
  if (closeTimer === null) {
    return
  }

  const elapsed = Date.now() - timerStartedAt
  remainingDuration = Math.max(1200, remainingDuration - elapsed)
  clearCloseTimer()
}

function resumeCloseTimer() {
  if (!props.notification?.id || closeTimer !== null) {
    return
  }

  startCloseTimer()
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
        class="pointer-events-none fixed right-4 top-4 z-[140] w-[min(94vw,460px)]"
      >
        <button
          type="button"
          class="pointer-events-auto app-notification-frame grid w-full gap-3 rounded-[24px] p-4 text-left backdrop-blur"
          :class="tone.frame"
          @mouseenter="pauseCloseTimer"
          @mouseleave="resumeCloseTimer"
          @click="closeNotification"
        >
          <div class="flex items-start justify-between gap-3">
            <span class="app-notification-badge inline-flex items-center px-2.5 py-1 text-[11px] font-black uppercase tracking-[0.2em]" :class="tone.badge">
              {{ tone.title }}
            </span>
            <span class="text-xs font-semibold opacity-70">Clique para fechar</span>
          </div>

          <p class="whitespace-pre-line text-sm font-semibold leading-6">
            {{ notification.message }}
          </p>
        </button>
      </div>
    </Transition>
  </Teleport>
</template>
