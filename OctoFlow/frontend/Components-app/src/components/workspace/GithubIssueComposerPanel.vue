<script setup>
import { computed } from 'vue'
import BaseCard from '../base/BaseCard.vue'
import EmptyState from '../base/EmptyState.vue'
import SectionHeader from '../base/SectionHeader.vue'
import IssueTemplateForm from '../issue/IssueTemplateForm.vue'
import IssueTemplatePreview from '../issue/IssueTemplatePreview.vue'

const props = defineProps({
  workspace: {
    type: Object,
    required: true,
  },
  composer: {
    type: Object,
    required: true,
  },
  assigneePlaceholderLabel: {
    type: String,
    default: 'Sem atribuicao inicial',
  },
  submitting: {
    type: Boolean,
    default: false,
  },
  submitError: {
    type: String,
    default: '',
  },
})

defineEmits([
  'update:selectedTemplateKey',
  'update:title',
  'update:assigneeId',
  'update:selectedLabels',
  'update:fieldValues',
  'submit',
  'reset',
])

const templates = computed(() => Array.isArray(props.workspace?.templates) ? props.workspace.templates : [])
const repository = computed(() => props.workspace?.repository || null)
const labels = computed(() => Array.isArray(repository.value?.labels) ? repository.value.labels : [])
const assignableUsers = computed(() => Array.isArray(repository.value?.assignableUsers) ? repository.value.assignableUsers : [])
const selectedTemplate = computed(() => templates.value.find((template) => template.key === props.composer?.selectedTemplateKey) || null)
const labelOptions = computed(() => labels.value
  .map((label) => ({
    id: String(label?.id || '').trim(),
    name: String(label?.name || '').trim(),
    color: String(label?.color || '').trim(),
    description: typeof label?.description === 'string' && label.description.trim() !== '' ? label.description.trim() : null,
  }))
  .filter((label) => label.id !== '' && label.name !== '')
)
</script>

<template>
  <section class="workspace-grid">
    <BaseCard class="workspace-card">
      <SectionHeader
        kicker="Templates"
        title="Abrir novo chamado"
        description="O host resolve permissao, dados e callbacks. O remote apenas renderiza o fluxo compartilhado."
      />

      <div v-if="templates.length" class="template-grid">
        <button
          v-for="template in templates"
          :key="template.key"
          type="button"
          class="template-tile"
          :class="{ active: template.key === composer?.selectedTemplateKey }"
          @click="$emit('update:selectedTemplateKey', template.key)"
        >
          <span class="template-prefix">[{{ template.titlePrefix || 'issue' }}]</span>
          <strong>{{ template.name }}</strong>
          <small>{{ template.description }}</small>
        </button>
      </div>

      <EmptyState v-else>
        O backend nao retornou templates disponiveis para este workspace.
      </EmptyState>

      <IssueTemplateForm
        v-if="selectedTemplate"
        :template="selectedTemplate"
        :title="composer?.title || ''"
        :assignee-id="composer?.assigneeId || ''"
        :assignee-options="assignableUsers"
        :assignee-placeholder="assigneePlaceholderLabel"
        :field-values="composer?.fieldValues || {}"
        :selected-labels="composer?.selectedLabels || []"
        :label-options="labelOptions"
        :show-label-field="true"
        :submit-error="submitError"
        :submitting="submitting"
        submit-label="Criar issue no GitHub"
        submitting-label="Criando issue..."
        @update:title="$emit('update:title', $event)"
        @update:assignee-id="$emit('update:assigneeId', $event)"
        @update:selected-labels="$emit('update:selectedLabels', $event)"
        @update:field-values="$emit('update:fieldValues', $event)"
        @submit="$emit('submit')"
        @reset="$emit('reset')"
      />
    </BaseCard>

    <IssueTemplatePreview
      :title="composer?.previewTitle || 'Titulo da issue'"
      :body="composer?.previewBody || ''"
      kicker="Preview"
      heading="Como a issue vai subir"
    />
  </section>
</template>

<style scoped>
.workspace-grid {
  display: grid;
  gap: 1.25rem;
  grid-template-columns: minmax(0, 1.08fr) minmax(320px, 0.92fr);
}

.workspace-card {
  display: grid;
  gap: 1rem;
}

.template-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.template-tile {
  background: var(--app-choice-bg, var(--surface-strong));
  border: 1px solid var(--app-choice-border, var(--line));
  border-radius: 20px;
  cursor: pointer;
  display: grid;
  gap: 0.45rem;
  padding: 1rem;
  text-align: left;
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    box-shadow 0.2s ease,
    transform 0.2s ease;
}

.template-tile:hover {
  background: var(--app-field-bg-hover);
  border-color: var(--app-panel-border-strong, var(--color-secondary));
  transform: translateY(-1px);
}

.template-tile.active {
  background: var(--app-choice-bg-active, color-mix(in srgb, var(--color-secondary) 10%, white));
  border-color: var(--app-choice-border-active, color-mix(in srgb, var(--color-secondary) 32%, white));
  box-shadow: var(--app-choice-shadow-active);
}

.template-prefix {
  color: var(--color-secondary);
  font-size: 0.72rem;
  font-weight: 900;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.template-tile strong {
  color: var(--ink);
  font-size: 1rem;
}

.template-tile small {
  color: var(--muted);
  line-height: 1.5;
}

@media (max-width: 1200px) {
  .workspace-grid {
    grid-template-columns: 1fr;
  }
}
</style>
