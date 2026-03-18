<script setup>
import { computed } from 'vue'
import BaseAlert from '../base/BaseAlert.vue'
import BaseCard from '../base/BaseCard.vue'
import EmptyState from '../base/EmptyState.vue'
import SectionHeader from '../base/SectionHeader.vue'

const props = defineProps({
  workspace: {
    type: Object,
    required: true,
  },
})

const projects = computed(() => Array.isArray(props.workspace?.projects) ? props.workspace.projects : [])
const projectsMeta = computed(() => props.workspace?.projectsMeta || { available: true, message: null })

function projectStatusStyle(option) {
  const palette = {
    BLUE: '#2563eb',
    GRAY: '#475569',
    GREEN: '#15803d',
    ORANGE: '#ea580c',
    PINK: '#db2777',
    PURPLE: '#7c3aed',
    RED: '#dc2626',
    YELLOW: '#ca8a04',
  }

  const normalizedColor = typeof option?.color === 'string' ? option.color.toUpperCase() : ''
  const tone = palette[normalizedColor] || '#0f766e'

  return {
    borderColor: tone,
    background: `${tone}18`,
    color: tone,
  }
}
</script>

<template>
  <BaseCard class="projects-card">
    <SectionHeader
      kicker="Projects"
      title="Painel do owner"
      description="O host monta este bloco apenas quando o workspace do usuario estiver pronto, sem puxar a tela remota inteira."
    />

    <BaseAlert
      v-if="!projectsMeta.available && projectsMeta.message"
      type="warning"
    >
      {{ projectsMeta.message }}
    </BaseAlert>

    <div v-if="projects.length" class="projects-grid">
      <article
        v-for="project in projects"
        :key="project.id"
        class="project-item"
      >
        <div class="project-top">
          <div class="project-copy">
            <strong>{{ project.title }}</strong>
            <p>{{ project.shortDescription || 'Sem Descricao curta.' }}</p>
          </div>

          <a
            v-if="project.url"
            :href="project.url"
            target="_blank"
            rel="noreferrer noopener"
            class="project-link"
          >
            Abrir
          </a>
        </div>

        <div v-if="project.statusField?.options?.length" class="status-grid">
          <span
            v-for="statusOption in project.statusField.options"
            :key="statusOption.id"
            class="status-pill"
            :style="projectStatusStyle(statusOption)"
          >
            {{ statusOption.name }}
          </span>
        </div>
      </article>
    </div>

    <EmptyState v-else>
      Nenhum project disponivel para este owner ou o token nao possui o escopo necessario.
    </EmptyState>
  </BaseCard>
</template>

<style scoped>
.projects-card {
  display: grid;
  gap: 1rem;
}

.projects-grid {
  display: grid;
  gap: 0.75rem;
}

.project-item {
  background: color-mix(in srgb, var(--surface-muted, #f8fafc) 88%, white);
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 18px;
  display: grid;
  gap: 0.75rem;
  padding: 1rem;
}

.project-top {
  align-items: flex-start;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: space-between;
}

.project-copy strong {
  color: var(--ink, #0f172a);
  display: block;
  font-size: 1rem;
}

.project-copy p {
  color: var(--muted, #475569);
  margin: 0.45rem 0 0;
}

.project-link {
  align-items: center;
  background: var(--app-field-bg, var(--surface-strong, rgba(15, 23, 42, 0.9)));
  border: 1px solid var(--app-field-border, var(--line, rgba(148, 163, 184, 0.22)));
  border-radius: 14px;
  color: var(--ink, #0f172a);
  display: inline-flex;
  font-size: 0.9rem;
  font-weight: 700;
  justify-content: center;
  padding: 0.65rem 0.9rem;
  text-decoration: none;
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    transform 0.2s ease;
}

.project-link:hover {
  background: var(--app-field-bg-hover, var(--surface, rgba(19, 28, 50, 0.82)));
  border-color: var(--app-panel-border-strong, var(--color-secondary, #06b6d4));
  transform: translateY(-1px);
}

.status-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.status-pill {
  border: 1px solid;
  border-radius: 999px;
  display: inline-flex;
  font-size: 0.76rem;
  font-weight: 700;
  max-width: 100%;
  padding: 0.35rem 0.75rem;
}
</style>
