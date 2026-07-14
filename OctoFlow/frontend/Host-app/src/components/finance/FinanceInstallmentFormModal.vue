<script setup>
import { computed } from 'vue'
import { useScopedI18n } from '../../composables/useScopedI18n'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  installmentForm: { type: Object, required: true },
  installmentEditingId: { type: String, default: '' },
  selectableCategories: { type: Array, default: () => [] },
  bankAccounts: { type: Array, default: () => [] },
  processing: { type: Boolean, default: false },
  canWrite: { type: Boolean, default: true },
})

const emit = defineEmits(['submit', 'close', 'update:installmentForm', 'update:installmentEditingId'])

const { translateScoped } = useScopedI18n('financeModule.accountsView')

const formTitle = computed(() => (
  props.installmentEditingId
    ? translateScoped('modal.titles.editInstallment', 'Editar parcelamento')
    : translateScoped('modal.titles.newInstallment', 'Novo parcelamento'
  )
))

const submitLabel = computed(() => {
  if (props.processing) return translateScoped('actions.saving', 'Salvando...')
  if (props.installmentEditingId) return translateScoped('actions.saveChanges', 'Salvar alterações')
  return translateScoped('actions.createInstallment', 'Criar parcelamento')
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
          <span>{{ translateScoped('modal.installment.title', 'Título') }}</span>
          <input v-model="installmentForm.title" type="text" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.totalAmount', 'Valor total') }}</span>
          <input v-model="installmentForm.totalAmountBrl" type="number" step="0.01" min="0.01" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.downPayment', 'Entrada') }}</span>
          <input v-model="installmentForm.downPaymentBrl" type="number" step="0.01" min="0" :disabled="processing || !canWrite">
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.count', 'Parcelas') }}</span>
          <input v-model="installmentForm.installmentsCount" type="number" min="1" :disabled="processing || !canWrite" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.firstDueDate', 'Primeiro vencimento') }}</span>
          <input v-model="installmentForm.firstDueDate" type="date" :disabled="processing || !canWrite">
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.category', 'Categoria') }}</span>
          <select v-model="installmentForm.categoryId" :disabled="processing || !canWrite" required>
            <option value="" disabled>{{ translateScoped('options.selectCategory', 'Selecione uma categoria') }}</option>
            <option v-for="cat in selectableCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
          </select>
          <small class="finance-field-hint">
            {{ translateScoped('modal.categoryHint', 'Categoria ativa obrigatória para salvar este cadastro.') }}
          </small>
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.bankAccount', 'Conta bancária padrão') }}</span>
          <select v-model="installmentForm.defaultBankAccountId" :disabled="processing || !canWrite">
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
