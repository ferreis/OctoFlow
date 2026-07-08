<script setup>
import { computed } from 'vue'
import { useScopedI18n } from '../../composables/useScopedI18n'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  recurringForm: { type: Object, required: true },
  recurringRuleEditingId: { type: String, default: '' },
  selectableCategories: { type: Array, default: () => [] },
  bankAccounts: { type: Array, default: () => [] },
  availableRecurringTypes: { type: Array, default: () => [] },
  processing: { type: Boolean, default: false },
  canWrite: { type: Boolean, default: true },
})

const emit = defineEmits(['submit', 'close', 'update:recurringForm', 'update:recurringRuleEditingId'])

const { translateScoped } = useScopedI18n('financeModule.accountsView')

const formTitle = computed(() => (
  props.recurringRuleEditingId
    ? translateScoped('modal.titles.editRecurring', 'Editar recorrência')
    : translateScoped('modal.titles.newRecurring', 'Nova recorrência'
  )
))

const submitLabel = computed(() => {
  if (props.processing) return translateScoped('actions.saving', 'Salvando...')
  if (props.recurringRuleEditingId) return translateScoped('actions.save', 'Salvar')
  return translateScoped('actions.createRecurring', 'Criar recorrência')
})

function onOverlayClick() {
  if (!props.processing) emit('close')
}
</script>

<template>
  <div v-if="isOpen" class="app-modal-overlay" role="dialog" aria-modal="true" @click.self="onOverlayClick">
    <div class="app-modal-frame" style="max-width: 56rem; width: 100%; padding: 24px;">
      <header class="finance-modal-header">
        <h3>{{ formTitle }}</h3>
        <p>{{ translateScoped('modal.subtitle', 'Os dados serão aplicados nas listagens desta aba.') }}</p>
      </header>

      <form class="finance-form-grid" @submit.prevent="emit('submit')">
        <label>
          <span>{{ translateScoped('modal.recurring.title', 'Título') }}</span>
          <input v-model="recurringForm.title" type="text" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.amount', 'Valor mensal') }}</span>
          <input v-model="recurringForm.amountBrl" type="number" step="0.01" min="0.01" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.dayOfMonth', 'Dia do mês') }}</span>
          <input v-model="recurringForm.dayOfMonth" type="number" min="1" max="31" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.startsAt', 'Início') }}</span>
          <input v-model="recurringForm.startsAt" type="date" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.recurringType', 'Tipo recorrente') }}</span>
          <select v-model="recurringForm.recurringTypeId" :disabled="processing || !canWrite" required>
            <option value="">{{ translateScoped('options.select', 'Selecione') }}</option>
            <option v-for="rt in availableRecurringTypes" :key="rt.id" :value="rt.id">{{ rt.name }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.category', 'Categoria') }}</span>
          <select v-model="recurringForm.categoryId" :disabled="processing || !canWrite" required>
            <option value="" disabled>{{ translateScoped('options.selectCategory', 'Selecione uma categoria') }}</option>
            <option v-for="cat in selectableCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
          </select>
          <small class="finance-field-hint">
            {{ translateScoped('modal.categoryHint', 'Categoria ativa obrigatória para salvar este cadastro.') }}
          </small>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.bankAccount', 'Conta bancária padrão') }}</span>
          <select v-model="recurringForm.defaultBankAccountId" :disabled="processing || !canWrite">
            <option value="">{{ translateScoped('options.noAccount', 'Sem conta') }}</option>
            <option v-for="acc in bankAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
          </select>
        </label>

        <div class="finance-modal-actions">
          <button type="button" class="finance-inline-action" :disabled="processing" @click="emit('close')">
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>
          <button class="finance-action-button" type="submit" :disabled="processing || !canWrite">
            {{ submitLabel }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
