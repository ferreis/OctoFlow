<script setup>
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
import { useI18n } from '../../composables/useI18n'

const { t } = useI18n()

onBeforeMount(() => {})
onMounted(() => {})
onBeforeUpdate(() => {})
onUpdated(() => {})
onBeforeUnmount(() => {})
onUnmounted(() => {})
onActivated(() => {})
onDeactivated(() => {})

onErrorCaptured((error, instance, info) => {
  console.error('EmptyState Error:', error, info)
  return false
})
</script>

<template>
  <div class="empty-state">
    <slot>{{ t('base.empty.defaultText') }}</slot>
  </div>
</template>

