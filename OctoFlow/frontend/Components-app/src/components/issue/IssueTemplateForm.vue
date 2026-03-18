<script setup>
import { computed } from 'vue'
import BaseAlert from '../base/BaseAlert.vue'
import MultiSelect from '../form/MultiSelect.vue'

const props = defineProps({
  template: {
    type: Object,
    default: null,
  },
  title: {
    type: String,
    default: '',
  },
  assigneeId: {
    type: String,
    default: '',
  },
  assigneeOptions: {
    type: Array,
    default: () => [],
  },
  assigneePlaceholder: {
    type: String,
    default: 'Sem atribuicao inicial',
  },
  fieldValues: {
    type: Object,
    default: () => ({}),
  },
  selectedLabels: {
    type: Array,
    default: () => [],
  },
  labelOptions: {
    type: Array,
    default: () => [],
  },
  submitError: {
    type: String,
    default: '',
  },
  submitting: {
    type: Boolean,
    default: false,
  },
  showLabelField: {
    type: Boolean,
    default: false,
  },
  showAssigneeField: {
    type: Boolean,
    default: true,
  },
  titleLabel: {
    type: String,
    default: 'Titulo',
  },
  assigneeLabel: {
    type: String,
    default: 'Responsavel',
  },
  submitLabel: {
    type: String,
    default: 'Salvar',
  },
  submittingLabel: {
    type: String,
    default: 'Salvando...',
  },
  resetLabel: {
    type: String,
    default: 'Limpar formulario',
  },
})

const emit = defineEmits([
  'update:title',
  'update:assigneeId',
  'update:selectedLabels',
  'update:fieldValues',
  'submit',
  'reset',
])

const isFeatureRequestTemplate = computed(() => props.template?.key === 'feature-request')
const featureRequestFields = computed(() => {
  const catalog = new Map()

  for (const field of props.template?.fields || []) {
    const fieldKey = typeof field?.key === 'string' ? field.key.trim() : ''
    if (fieldKey !== '') {
      catalog.set(fieldKey, field)
    }
  }

  return {
    description: catalog.get('description') || null,
    businessRule: catalog.get('businessRule') || null,
    acceptanceCriteria: catalog.get('acceptanceCriteria') || null,
  }
})

function updateFieldValue(fieldKey, nextValue) {
  emit('update:fieldValues', {
    ...(props.fieldValues || {}),
    [fieldKey]: nextValue,
  })
}
</script>

<template>
  <form v-if="template" class="issue-template-form" @submit.prevent="$emit('submit')">
    <div class="field-grid" :class="props.showAssigneeField ? 'field-grid-two' : 'field-grid-one'">
      <label class="field">
        <span>{{ titleLabel }}</span>
        <input
          :value="title"
          type="text"
          placeholder="Ex.: Ajustar fluxo de atendimento no portal"
          required
          @input="$emit('update:title', $event.target.value)"
        >
      </label>

      <label v-if="showAssigneeField" class="field">
        <span>{{ assigneeLabel }}</span>
        <select
          :value="assigneeId"
          @change="$emit('update:assigneeId', $event.target.value)"
        >
          <option value="">{{ assigneePlaceholder }}</option>
          <option
            v-for="assignableUser in assigneeOptions"
            :key="assignableUser.id || assignableUser.login"
            :value="assignableUser.id"
          >
            {{ assignableUser.name ? `${assignableUser.name} (${assignableUser.login})` : assignableUser.login }}
          </option>
        </select>
      </label>
    </div>

    <template v-if="isFeatureRequestTemplate">
      <label v-if="featureRequestFields.description" class="field">
        <span>{{ featureRequestFields.description.label }}</span>
        <textarea
          :value="fieldValues[featureRequestFields.description.key] || ''"
          rows="5"
          :placeholder="featureRequestFields.description.placeholder || ''"
          :required="featureRequestFields.description.required"
          @input="updateFieldValue(featureRequestFields.description.key, $event.target.value)"
        />
      </label>

      <label v-if="featureRequestFields.businessRule" class="field">
        <span>{{ featureRequestFields.businessRule.label }}</span>
        <textarea
          :value="fieldValues[featureRequestFields.businessRule.key] || ''"
          rows="5"
          :placeholder="featureRequestFields.businessRule.placeholder || ''"
          :required="featureRequestFields.businessRule.required"
          @input="updateFieldValue(featureRequestFields.businessRule.key, $event.target.value)"
        />
      </label>

      <label v-if="featureRequestFields.acceptanceCriteria" class="field">
        <span>{{ featureRequestFields.acceptanceCriteria.label }}</span>
        <textarea
          :value="fieldValues[featureRequestFields.acceptanceCriteria.key] || ''"
          rows="5"
          :placeholder="featureRequestFields.acceptanceCriteria.placeholder || ''"
          :required="featureRequestFields.acceptanceCriteria.required"
          @input="updateFieldValue(featureRequestFields.acceptanceCriteria.key, $event.target.value)"
        />
      </label>

      <label v-if="showLabelField" class="field">
        <span>Tags</span>
        <MultiSelect
          :model-value="selectedLabels"
          :options="labelOptions"
          search-placeholder="Pesquisar ou criar tag"
          helper-text="Pesquise tags existentes ou crie uma nova no proprio campo."
          selected-count-suffix="selecionada(s)"
          create-label-prefix="Criar tag"
          create-helper-text="A tag sera criada no GitHub ao enviar a issue."
          existing-option-helper-text="Tag existente no repositorio"
          empty-options-text="Nenhuma tag cadastrada. Digite para criar a primeira."
          empty-search-text="Nenhuma tag encontrada para essa busca."
          empty-idle-text="Digite para pesquisar tags existentes."
          new-option-badge="Nova"
          @update:model-value="$emit('update:selectedLabels', $event)"
        />
      </label>
    </template>

    <div v-else class="field-grid field-grid-two">
      <template v-for="field in template.fields || []" :key="field.key">
        <label v-if="field.type === 'textarea'" class="field field-span">
          <span>{{ field.label }}</span>
          <textarea
            :value="fieldValues[field.key] || ''"
            rows="5"
            :placeholder="field.placeholder || ''"
            :required="field.required"
            @input="updateFieldValue(field.key, $event.target.value)"
          />
        </label>

        <label v-else-if="field.type === 'list'" class="field field-span">
          <span>{{ field.label }}</span>
          <textarea
            :value="fieldValues[field.key] || ''"
            rows="4"
            :placeholder="field.placeholder || 'Um item por linha.'"
            :required="field.required"
            @input="updateFieldValue(field.key, $event.target.value)"
          />
          <small>Use uma linha por item. O preview vira lista automaticamente.</small>
        </label>

        <label v-else-if="field.type === 'select'" class="field">
          <span>{{ field.label }}</span>
          <select
            :value="fieldValues[field.key] || ''"
            :required="field.required"
            @change="updateFieldValue(field.key, $event.target.value)"
          >
            <option value="">Selecione</option>
            <option v-for="option in field.options || []" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>

        <label v-else class="field">
          <span>{{ field.label }}</span>
          <input
            :value="fieldValues[field.key] || ''"
            type="text"
            :placeholder="field.placeholder || ''"
            :required="field.required"
            @input="updateFieldValue(field.key, $event.target.value)"
          >
        </label>
      </template>
    </div>

    <div class="form-actions">
      <button type="submit" class="primary-action" :disabled="submitting">
        {{ submitting ? submittingLabel : submitLabel }}
      </button>
      <button type="button" class="secondary-action" :disabled="submitting" @click="$emit('reset')">
        {{ resetLabel }}
      </button>
    </div>

    <BaseAlert v-if="submitError" type="error">
      {{ submitError }}
    </BaseAlert>
  </form>
</template>

<style scoped>
.issue-template-form {
  display: grid;
  gap: 1rem;
}

.field-grid {
  display: grid;
  gap: 1rem;
}

.field-grid-two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field-grid-one {
  grid-template-columns: 1fr;
}

.field {
  display: grid;
  gap: 0.5rem;
}

.field-span {
  grid-column: 1 / -1;
}

.field span {
  color: var(--ink, #0f172a);
  font-size: 0.95rem;
  font-weight: 700;
}

.field input,
.field textarea,
.field select {
  background: var(--app-field-bg, var(--surface-strong, rgba(15, 23, 42, 0.9)));
  border: 1px solid var(--app-field-border, var(--line, rgba(148, 163, 184, 0.22)));
  border-radius: 16px;
  color: var(--ink, #0f172a);
  font: inherit;
  outline: none;
  padding: 0.85rem 0.9rem;
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    box-shadow 0.2s ease;
  width: 100%;
}

.field input::placeholder,
.field textarea::placeholder,
.field select::placeholder {
  color: var(--app-field-placeholder, var(--muted, #475569));
}

.field input:hover,
.field textarea:hover,
.field select:hover {
  background: var(--app-field-bg-hover, var(--surface, rgba(19, 28, 50, 0.82)));
}

.field input:focus,
.field textarea:focus,
.field select:focus {
  background: var(--app-field-bg-focus, var(--surface, rgba(19, 28, 50, 0.82)));
  border-color: var(--app-field-border-focus, var(--color-secondary, #06b6d4));
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-secondary, #06b6d4) 18%, transparent);
}

.field textarea {
  min-height: 132px;
  resize: vertical;
}

.field small {
  color: var(--muted, #475569);
}

.form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.primary-action,
.secondary-action {
  align-items: center;
  border-radius: 14px;
  cursor: pointer;
  display: inline-flex;
  font: inherit;
  font-weight: 700;
  justify-content: center;
  min-height: 2.9rem;
  padding: 0 1.1rem;
}

.primary-action {
  background: var(--button-gradient, linear-gradient(135deg, #4f46e5 0%, #06b6d4 54%, #3b82f6 100%));
  border: 0;
  color: var(--button-primary-text, #f8fafc);
  box-shadow: var(--button-shadow, 0 14px 30px rgba(6, 182, 212, 0.22));
}

.secondary-action {
  background: var(--app-panel-solid-bg, var(--surface-strong, rgba(15, 23, 42, 0.9)));
  border: 1px solid var(--app-panel-border, var(--line, rgba(148, 163, 184, 0.22)));
  color: var(--ink, #0f172a);
}

@media (max-width: 768px) {
  .field-grid-two {
    grid-template-columns: 1fr;
  }
}
</style>
