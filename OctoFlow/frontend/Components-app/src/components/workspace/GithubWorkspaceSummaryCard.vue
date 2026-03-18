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

  return primaryEmail || fallbackEmail || 'usuario autenticado'
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
          {{ repository?.nameWithOwner || 'Repositorio nao configurado' }}
        </h3>
        <p class="repository-copy">
          {{ repository?.description || 'O host monta cada painel remoto de forma isolada para carregar apenas o que fizer sentido para a sessao atual.' }}
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
        Abrir repositorio
      </a>
      <span class="owner-pill">
        Owner: {{ repository?.ownerLogin || 'nao definido' }}
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
  color: var(--ink, #0f172a);
  font-size: clamp(1.45rem, 2vw, 2rem);
  font-weight: 700;
  margin: 0.25rem 0 0;
  word-break: break-word;
}

.repository-copy {
  color: var(--muted, #475569);
  line-height: 1.75;
  margin: 0.75rem 0 0;
  max-width: 48rem;
}

.requester-card {
  background: color-mix(in srgb, var(--surface-muted, #f8fafc) 88%, white);
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 18px;
  color: var(--muted, #475569);
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
  color: var(--ink, #0f172a);
  font-size: 1.35rem;
  font-weight: 700;
}

.metrics-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.metric-card {
  background: color-mix(in srgb, var(--surface-muted, #f8fafc) 88%, white);
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 18px;
  display: grid;
  gap: 0.35rem;
  padding: 1rem;
}

.metric-card-accent {
  background: color-mix(in srgb, var(--color-secondary, #06b6d4) 10%, white);
  border-color: color-mix(in srgb, var(--color-secondary, #06b6d4) 28%, white);
}

.summary-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.link-button {
  align-items: center;
  background: white;
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 14px;
  color: var(--ink, #0f172a);
  display: inline-flex;
  font-size: 0.95rem;
  font-weight: 700;
  justify-content: center;
  padding: 0.75rem 1rem;
  text-decoration: none;
}

.owner-pill {
  align-items: center;
  background: color-mix(in srgb, var(--surface-muted, #f8fafc) 88%, white);
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 999px;
  color: var(--muted, #475569);
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
