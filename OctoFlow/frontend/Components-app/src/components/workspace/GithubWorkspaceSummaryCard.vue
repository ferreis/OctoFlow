<script setup>
import { computed } from 'vue'
import BaseCard from '../base/BaseCard.vue'
import SectionHeader from '../base/SectionHeader.vue'

const props = defineProps({
  workspace: {
    type: Object,
    required: true,
  },
  currentUser: {
    type: Object,
    default: null,
  },
})

const repository = computed(() => props.workspace?.repository || null)
const templatesCount = computed(() => Array.isArray(props.workspace?.templates) ? props.workspace.templates.length : 0)
const projectsCount = computed(() => Array.isArray(props.workspace?.projects) ? props.workspace.projects.length : 0)
const labelsCount = computed(() => Array.isArray(repository.value?.labels) ? repository.value.labels.length : 0)
const requesterEmail = computed(() => {
  const primaryEmail = typeof props.currentUser?.defaultEmail === 'string' ? props.currentUser.defaultEmail.trim() : ''
  const fallbackEmail = typeof props.currentUser?.email === 'string' ? props.currentUser.email.trim() : ''

  return primaryEmail || fallbackEmail || 'usuário autenticado'
})
</script>

<template>
  <BaseCard class="summary-card">
    <div class="summary-header">
      <div class="min-w-0">
        <SectionHeader
          kicker="OctoFlow Dashboard"
          title=""
          description=""
        />
        <h3 class="repository-title">
          {{ repository?.nameWithOwner || 'Repositório não configurado' }}
        </h3>
        <p class="repository-copy">
          {{ repository?.description || 'O host monta cada painel remoto de forma isolada para carregar apenas o que fizer sentido para a sessão atual.' }}
        </p>
      </div>

      <div class="requester-card">
        <span>Solicitante</span>
        <strong>{{ requesterEmail }}</strong>
      </div>
    </div>

    <div class="metrics-grid">
      <div class="metric-card">
        <span>Templates</span>
        <strong>{{ templatesCount }}</strong>
      </div>
      <div class="metric-card">
        <span>Labels</span>
        <strong>{{ labelsCount }}</strong>
      </div>
      <div class="metric-card">
        <span>Projects</span>
        <strong>{{ projectsCount }}</strong>
      </div>
      <div class="metric-card metric-card-accent">
        <span>Origem</span>
        <strong>Componentes federados</strong>
      </div>
    </div>

    <div class="summary-actions">
      <a
        v-if="repository?.url"
        :href="repository.url"
        target="_blank"
        rel="noreferrer noopener"
        class="link-button"
      >
        Abrir repositório
      </a>
      <span class="owner-pill">
        Owner: {{ repository?.ownerLogin || 'não definido' }}
      </span>
    </div>
  </BaseCard>
</template>

<style scoped>
.summary-card {
  display: grid;
  gap: 1rem;
}

.summary-header {
  align-items: flex-start;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  justify-content: space-between;
}

.repository-title {
  color: var(--ink);
  font-size: clamp(1.45rem, 2vw, 2rem);
  font-weight: 700;
  margin: 0.25rem 0 0;
  word-break: break-word;
}

.repository-copy {
  color: var(--muted);
  line-height: 1.75;
  margin: 0.75rem 0 0;
  max-width: 48rem;
}

.requester-card {
  background: color-mix(in srgb, var(--surface-muted) 88%, white);
  border: 1px solid var(--line);
  border-radius: 18px;
  color: var(--muted);
  display: grid;
  gap: 0.3rem;
  min-width: 220px;
  padding: 1rem;
}

.requester-card span,
.metric-card span {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.requester-card strong,
.metric-card strong {
  color: var(--ink);
  font-size: 1.35rem;
  font-weight: 700;
}

.metrics-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.metric-card {
  background: var(--app-panel-bg-muted, color-mix(in srgb, var(--surface-muted) 88%, white));
  border: 1px solid var(--app-panel-border);
  border-radius: 18px;
  display: grid;
  gap: 0.35rem;
  padding: 1rem;
}

.metric-card-accent {
  background: var(--app-choice-bg-active, color-mix(in srgb, var(--color-secondary) 10%, white));
  border-color: var(--app-choice-border-active, color-mix(in srgb, var(--color-secondary) 28%, white));
}

.summary-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.link-button {
  align-items: center;
  background: var(--app-field-bg);
  border: 1px solid var(--app-field-border);
  border-radius: 14px;
  color: var(--ink);
  display: inline-flex;
  font-size: 0.95rem;
  font-weight: 700;
  justify-content: center;
  padding: 0.75rem 1rem;
  text-decoration: none;
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    transform 0.2s ease;
}

.link-button:hover {
  background: var(--app-field-bg-hover);
  border-color: var(--app-panel-border-strong, var(--color-secondary));
  transform: translateY(-1px);
}

.owner-pill {
  align-items: center;
  background: var(--app-chip-bg, color-mix(in srgb, var(--surface-muted) 88%, white));
  border: 1px solid var(--app-chip-border, var(--line));
  border-radius: 999px;
  color: var(--app-chip-text, var(--muted));
  display: inline-flex;
  font-size: 0.8rem;
  font-weight: 700;
  padding: 0.4rem 0.8rem;
}

@media (min-width: 1024px) {
  .summary-header {
    flex-direction: row;
  }
}
</style>
