<script setup>
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
} from 'vue'
import { useI18n } from '../../composables/useI18n'

const props = defineProps({
  type: {
    type: String,
    default: 'info',
    validator(value) {
      return ['info', 'success', 'warning', 'error'].includes(value)
    }
  },
})

const { t } = useI18n()

const alertClass = computed(() => {
  switch (props.type) {
    case 'error':
      return 'alert-error'
    case 'success':
      return 'alert-success'
    case 'warning':
      return 'alert-warning'
    default:
      return 'alert-info'
  }
})

onBeforeMount(() => {})
onMounted(() => {})
onBeforeUpdate(() => {})
onUpdated(() => {})
onBeforeUnmount(() => {})
onUnmounted(() => {})
onActivated(() => {})
onDeactivated(() => {})

onErrorCaptured((error, instance, info) => {
  console.error('BaseAlert Error:', error, info)
  return false
})
</script>

<template>
  <div class="base-alert" :class="alertClass" role="alert" aria-live="polite">
    <slot>{{ t('base.alert.defaultError') }}</slot>
  </div>
</template>
