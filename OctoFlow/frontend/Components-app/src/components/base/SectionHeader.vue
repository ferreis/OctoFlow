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

defineProps({
  kicker: {
    type: String,
    default: '',
  },
  title: {
    type: String,
    default: '',
  },
  description: {
    type: String,
    default: '',
  },
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
  console.error('SectionHeader Error:', error, info)
  return false
})
</script>

<template>
  <div class="section-header">
    <p v-if="kicker" class="section-kicker">{{ kicker }}</p>
    <h3 v-if="title" class="section-title">{{ title }}</h3>
    <p v-if="description" class="section-description">{{ description }}</p>
    <slot />
  </div>
</template>
