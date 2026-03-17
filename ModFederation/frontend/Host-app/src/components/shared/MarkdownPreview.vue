<script setup>
import DOMPurify from 'dompurify'
import { marked } from 'marked'
import { computed } from 'vue'

const props = defineProps({
  content: {
    type: String,
    default: '',
  },
  emptyLabel: {
    type: String,
    default: 'Nenhum conteudo em Markdown para visualizar.',
  },
})

const renderedHtml = computed(() => {
  const source = String(props.content || '').trim()

  if (source === '') {
    return `<p class="markdown-empty">${escapeHtml(props.emptyLabel)}</p>`
  }

  const parsedHtml = marked.parse(source, {
    gfm: true,
    breaks: true,
  })

  return DOMPurify.sanitize(parsedHtml)
})

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}
</script>

<template>
  <div class="markdown-preview" v-html="renderedHtml" />
</template>

<style scoped>
.markdown-preview {
  color: #0f172a;
  line-height: 1.75;
  word-break: break-word;
}

.markdown-preview :deep(.markdown-empty) {
  color: #64748b;
  margin: 0;
}

.markdown-preview :deep(h1),
.markdown-preview :deep(h2),
.markdown-preview :deep(h3),
.markdown-preview :deep(h4),
.markdown-preview :deep(h5),
.markdown-preview :deep(h6) {
  color: #020617;
  font-weight: 700;
  line-height: 1.3;
  margin: 1.3em 0 0.45em;
}

.markdown-preview :deep(h1) {
  font-size: 1.5rem;
}

.markdown-preview :deep(h2) {
  font-size: 1.25rem;
}

.markdown-preview :deep(h3) {
  font-size: 1.05rem;
}

.markdown-preview :deep(p),
.markdown-preview :deep(ul),
.markdown-preview :deep(ol),
.markdown-preview :deep(pre),
.markdown-preview :deep(blockquote),
.markdown-preview :deep(table) {
  margin: 0.9em 0;
}

.markdown-preview :deep(ul),
.markdown-preview :deep(ol) {
  padding-left: 1.35rem;
}

.markdown-preview :deep(li + li) {
  margin-top: 0.35rem;
}

.markdown-preview :deep(blockquote) {
  background: #f8fafc;
  border-left: 4px solid #22c55e;
  border-radius: 0 16px 16px 0;
  color: #334155;
  margin-left: 0;
  padding: 0.85rem 1rem;
}

.markdown-preview :deep(code) {
  background: rgba(15, 23, 42, 0.08);
  border-radius: 0.4rem;
  color: #0f172a;
  font-size: 0.92em;
  padding: 0.12rem 0.35rem;
}

.markdown-preview :deep(pre) {
  background: #0f172a;
  border-radius: 1rem;
  color: #e2e8f0;
  overflow-x: auto;
  padding: 1rem;
}

.markdown-preview :deep(pre code) {
  background: transparent;
  color: inherit;
  padding: 0;
}

.markdown-preview :deep(a) {
  color: #0369a1;
  font-weight: 600;
  text-decoration: underline;
}

.markdown-preview :deep(hr) {
  border: 0;
  border-top: 1px solid #cbd5e1;
  margin: 1.25rem 0;
}

.markdown-preview :deep(table) {
  border-collapse: collapse;
  width: 100%;
}

.markdown-preview :deep(th),
.markdown-preview :deep(td) {
  border: 1px solid #cbd5e1;
  padding: 0.6rem 0.75rem;
  text-align: left;
}

.markdown-preview :deep(th) {
  background: #f8fafc;
}

.markdown-preview :deep(input[type='checkbox']) {
  accent-color: #0891b2;
  margin-right: 0.45rem;
  pointer-events: none;
}

.markdown-preview :deep(img) {
  border-radius: 1rem;
  max-width: 100%;
}
</style>
