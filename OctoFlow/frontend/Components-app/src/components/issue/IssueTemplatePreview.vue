<script setup>
import BaseCard from '../base/BaseCard.vue'
import SectionHeader from '../base/SectionHeader.vue'
import MarkdownPreview from '../content/MarkdownPreview.vue'

defineProps({
  title: {
    type: String,
    default: 'Preview',
  },
  body: {
    type: String,
    default: '',
  },
  kicker: {
    type: String,
    default: 'Preview Markdown',
  },
  heading: {
    type: String,
    default: 'Como o conteudo vai subir',
  },
})
</script>

<template>
  <BaseCard class="issue-template-preview">
    <SectionHeader :kicker="kicker" :title="heading" />

    <div class="preview-title">
      {{ title }}
    </div>

    <div class="preview-body">
      <MarkdownPreview :content="body" />
    </div>
  </BaseCard>
</template>

<style scoped>
.issue-template-preview {
  display: grid;
  gap: 1rem;
}

.preview-title {
  background: color-mix(in srgb, var(--color-secondary, #06b6d4) 10%, white);
  border: 1px solid color-mix(in srgb, var(--color-secondary, #06b6d4) 28%, white);
  border-radius: 18px;
  color: var(--ink, #0f172a);
  font-size: 0.95rem;
  font-weight: 700;
  padding: 0.85rem 1rem;
}

.preview-body {
  background: white;
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 24px;
  min-height: 420px;
  overflow: auto;
  padding: 1rem 1.25rem;
}
</style>
