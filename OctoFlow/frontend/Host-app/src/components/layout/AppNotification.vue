<script setup>
import {
  computed,
  onActivated,
  onBeforeUnmount,
  onDeactivated,
  onMounted,
  watch,
} from 'vue'
import { useI18n } from '../../composables/useI18n'
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
const { translate } = useI18n()

let closeTimerId = null
let timerStartedAt = 0
let remainingDuration = 0

const normalizedNotificationMessage = computed(() => {
  const rawMessage = typeof props.notification?.message === 'string'
    ? props.notification.message.trim()
    : ''

  return rawMessage.slice(0, 1400)
})

const hasVisibleNotification = computed(() => {
  return normalizedNotificationMessage.value !== ''
})

const normalizedNotificationKey = computed(() => {
  const rawNotificationId = props.notification?.id
  if (rawNotificationId !== null && rawNotificationId !== undefined) {
    return String(rawNotificationId)
  }

  return normalizedNotificationMessage.value
})

const effectiveDuration = computed(() => {
  const payloadDuration = Number(props.notification?.duration)
  if (Number.isFinite(payloadDuration) && payloadDuration > 0) {
    return Math.min(Math.max(payloadDuration, 2800), 18000)
  }

  const messageLength = normalizedNotificationMessage.value.length
  const dynamicDuration = props.duration + Math.max(0, messageLength - 90) * 42

  return Math.min(Math.max(dynamicDuration, 2800), 14000)
})

const tone = computed(() => {
  return resolveNotificationToneClasses(props.notification?.type)
})

const toneTitle = computed(() => {
  const toneLabelByKey = {
    success: 'Success',
    error: 'Error',
    warning: 'Warning',
    info: 'Information',
  }

  const toneKey = tone.value.toneKey
  const fallbackLabel = toneLabelByKey[toneKey] || toneLabelByKey.info

  return translate(`notification.toneTitle.${toneKey}`, {
    tone: fallbackLabel,
  })
})

const closeHintLabel = computed(() => {
  return translate('notification.closeHint')
})

const closeAriaLabel = computed(() => {
  return translate('notification.closeAriaLabel')
})

watch(
  () => normalizedNotificationKey.value,
  (notificationKey) => {
    clearCloseTimer()

    if (!notificationKey || !hasVisibleNotification.value) {
      return
    }

    remainingDuration = effectiveDuration.value
    startCloseTimer()
  },
  {
    immediate: true,
  },
)

onMounted(() => {
  if (!hasVisibleNotification.value || closeTimerId !== null) {
    return
  }

  remainingDuration = effectiveDuration.value
  startCloseTimer()
})

onActivated(() => {
  resumeCloseTimer()
})

onDeactivated(() => {
  pauseCloseTimer()
})

onBeforeUnmount(() => {
  clearCloseTimer()
})

function clearCloseTimer() {
  if (closeTimerId !== null && typeof window !== 'undefined') {
    window.clearTimeout(closeTimerId)
    closeTimerId = null
  }
}

function startCloseTimer() {
  if (typeof window === 'undefined' || !hasVisibleNotification.value) {
    return
  }

  if (remainingDuration <= 0) {
    remainingDuration = effectiveDuration.value
  }

  timerStartedAt = Date.now()
  closeTimerId = window.setTimeout(() => {
    emit('close')
  }, remainingDuration)
}

function pauseCloseTimer() {
  if (closeTimerId === null) {
    return
  }

  const elapsed = Date.now() - timerStartedAt
  remainingDuration = Math.max(1100, remainingDuration - elapsed)
  clearCloseTimer()
}

function resumeCloseTimer() {
  if (!hasVisibleNotification.value || closeTimerId !== null) {
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
        v-if="hasVisibleNotification"
        class="app-notification-container"
      >
        <button
          type="button"
          class="app-notification-button app-notification-frame"
          :class="tone.frame"
          :aria-label="closeAriaLabel"
          @mouseenter="pauseCloseTimer"
          @mouseleave="resumeCloseTimer"
          @click="closeNotification"
        >
          <div class="app-notification-header">
            <span class="app-notification-badge app-notification-badge-label" :class="tone.badge">
              {{ toneTitle }}
            </span>
            <span class="app-notification-close-hint">{{ closeHintLabel }}</span>
          </div>

          <p class="app-notification-message">
            {{ normalizedNotificationMessage }}
          </p>
        </button>
      </div>
    </Transition>
  </Teleport>
</template>
