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
  background: var(--app-chip-bg, color-mix(in srgb, var(--color-secondary, #06b6d4) 10%, white));
  border: 1px solid var(--app-chip-border, color-mix(in srgb, var(--color-secondary, #06b6d4) 28%, white));
  border-radius: 18px;
  color: var(--app-chip-text, var(--ink, #0f172a));
  font-size: 0.95rem;
  font-weight: 700;
  padding: 0.85rem 1rem;
}

.preview-body {
  background: var(--app-panel-bg-muted, var(--surface-strong, rgba(15, 23, 42, 0.9)));
  border: 1px solid var(--app-panel-border, var(--line, rgba(148, 163, 184, 0.22)));
  border-radius: 24px;
  box-shadow: var(--app-panel-shadow, 0 18px 48px rgba(15, 23, 42, 0.07));
  min-height: 420px;
  overflow: auto;
  padding: 1rem 1.25rem;
}
</style>
