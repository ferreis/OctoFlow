<script setup>
import { computed } from 'vue'
import { useScopedI18n } from '../../composables/useScopedI18n'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  entryForm: { type: Object, required: true },
  entryEditingId: { type: String, default: '' },
  selectableCategories: { type: Array, default: () => [] },
  bankAccounts: { type: Array, default: () => [] },
  processing: { type: Boolean, default: false },
  canWrite: { type: Boolean, default: true },
  entryTypeOptions: { type: Array, default: () => [] },
  direction: { type: String, default: 'PAYABLE' },
})

const emit = defineEmits(['submit', 'close', 'update:entryForm', 'update:entryEditingId'])

const { translateScoped } = useScopedI18n('financeModule.accountsView')

const formTitle = computed(() => (
  props.entryEditingId
    ? translateScoped('modal.titles.editEntry', 'Editar lançamento')
    : translateScoped('modal.titles.newEntry', 'Novo lançamento'
  )
))

const submitLabel = computed(() => {
  if (props.processing) return translateScoped('actions.saving', 'Salvando...')
  if (props.entryEditingId) return translateScoped('actions.saveChanges', 'Salvar alterações')
  return translateScoped('actions.saveEntry', 'Salvar lançamento')
})

function onOverlayClick() {
  if (!props.processing) emit('close')
}
</script>

<template>
  <div v-if="isOpen" class="app-modal-overlay" role="dialog" aria-modal="true" @click.self="onOverlayClick">
    <div class="app-modal-frame finance-modal-frame">
      <header class="finance-modal-header">
        <h3>{{ formTitle }}</h3>
        <p>{{ translateScoped('modal.subtitle', 'Os dados serão aplicados nas listagens desta aba.') }}</p>
      </header>

      <form class="finance-modal-form" @submit.prevent="emit('submit')">
        <label>
          <span>{{ translateScoped('modal.entry.title', 'Título') }}</span>
          <input v-model="entryForm.title" type="text" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.type', 'Tipo') }}</span>
          <select v-model="entryForm.entryType" :disabled="processing || !canWrite">
            <option v-for="opt in entryTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.amount', 'Valor (BRL)') }}</span>
          <input v-model="entryForm.expectedAmountBrl" type="number" step="0.01" min="0.01" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.dueDate', 'Vencimento') }}</span>
          <input v-model="entryForm.dueDate" type="date" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.category', 'Categoria') }}</span>
          <select v-model="entryForm.categoryId" :disabled="processing || !canWrite" required>
            <option value="" disabled>{{ translateScoped('options.selectCategory', 'Selecione uma categoria') }}</option>
            <option v-for="cat in selectableCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
          </select>
          <small class="finance-field-hint">
            {{ translateScoped('modal.categoryHint', 'Categoria ativa obrigatória para salvar este cadastro.') }}
          </small>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.bankAccount', 'Conta bancária') }}</span>
          <select v-model="entryForm.bankAccountId" :disabled="processing || !canWrite">
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
