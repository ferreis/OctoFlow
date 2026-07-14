<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useScopedI18n } from '../../composables/useScopedI18n'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  settlementForm: { type: Object, required: true },
  availableSettlementEntries: { type: Array, default: () => [] },
  filteredInstallmentPlans: { type: Array, default: () => [] },
  bankAccounts: { type: Array, default: () => [] },
  creditCardAccounts: { type: Array, default: () => [] },
  settlementTypeOptions: { type: Array, default: () => [] },
  loadingEntries: { type: Boolean, default: false },
  processing: { type: Boolean, default: false },
  canWrite: { type: Boolean, default: true },
})

const emit = defineEmits(['submit', 'close', 'update:settlementForm', 'search-entries', 'select-entry'])

const { translateScoped } = useScopedI18n('financeModule.accountsView')
const searchSelectElement = ref(null)
const entrySearchTerm = ref('')
const entryOptionsOpen = ref(false)

const shouldShowEntryOptions = computed(() => entryOptionsOpen.value)
const selectedEntryLabel = computed(() => String(props.settlementForm.entrySearch || '').trim())

watch(
  () => props.settlementForm.entrySearch,
  (nextEntrySearch) => {
    entrySearchTerm.value = String(nextEntrySearch || '')
  },
  { immediate: true },
)

function formatCurrency(value) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2 }).format(Number(value || 0))
}

function formatSettlementEntryOptionLabel(entryItem) {
  const installmentLabel = entryItem.installmentNumber && entryItem.installmentsCount
    ? ` - ${entryItem.installmentNumber}/${entryItem.installmentsCount}`
    : ''
  return `${entryItem.title}${installmentLabel} - ${entryItem.dueDate} - ${formatCurrency(entryItem.remainingAmountBrl)}`
}

function onOverlayClick() {
  if (!props.processing) emit('close')
}

function openEntryOptions() {
  entryOptionsOpen.value = true
  entrySearchTerm.value = ''
  emit('search-entries', '', false)
}

function searchEntries() {
  emit('search-entries', entrySearchTerm.value, true)
}

function selectEntry(entryItem) {
  entryOptionsOpen.value = false
  emit('select-entry', entryItem)
}

function closeEntryOptions() {
  entryOptionsOpen.value = false
}

function closeEntryOptionsWhenClickingOutside(pointerEvent) {
  const searchSelectContainer = searchSelectElement.value
  const clickedElement = pointerEvent.target

  if (!(searchSelectContainer instanceof HTMLElement) || !(clickedElement instanceof Node)) {
    return
  }

  if (!searchSelectContainer.contains(clickedElement)) {
    closeEntryOptions()
  }
}

onMounted(() => {
  window.addEventListener('pointerdown', closeEntryOptionsWhenClickingOutside)
})

onBeforeUnmount(() => {
  window.removeEventListener('pointerdown', closeEntryOptionsWhenClickingOutside)
})
</script>

<template>
  <div v-if="isOpen" class="app-modal-overlay" role="dialog" aria-modal="true" @click.self="onOverlayClick">
    <div class="app-modal-frame finance-modal-frame finance-modal-frame--with-search-select">
      <header class="finance-modal-header">
        <h3>{{ translateScoped('modal.titles.settlement', 'Baixa de lançamento') }}</h3>
        <p>{{ translateScoped('modal.subtitle', 'Os dados serão aplicados nas listagens desta aba.') }}</p>
      </header>

      <form class="finance-modal-form" @submit.prevent="emit('submit')">
        <div
          v-if="settlementForm.settlementType === 'PAYMENT'"
          ref="searchSelectElement"
          class="finance-search-select"
          :class="{ 'is-open': shouldShowEntryOptions }"
          @keydown.esc.stop="closeEntryOptions"
        >
          <span>{{ translateScoped('modal.settlement.entry', 'Lançamento') }}</span>
          <button
            type="button"
            class="finance-search-select-trigger"
            role="combobox"
            :aria-expanded="shouldShowEntryOptions"
            aria-controls="finance-settlement-entry-options"
            :disabled="processing || !canWrite"
            @click="openEntryOptions"
          >
            <span class="finance-search-select-value" :class="{ 'is-placeholder': selectedEntryLabel === '' }">
              {{ selectedEntryLabel || translateScoped('modal.settlement.selectEntry', 'Selecione um lançamento') }}
            </span>
            <span class="finance-search-select-chevron" aria-hidden="true">⌄</span>
          </button>

          <div v-if="shouldShowEntryOptions" id="finance-settlement-entry-options" class="finance-search-select-options" role="listbox">
            <label class="finance-search-select-filter">
              <span class="sr-only">{{ translateScoped('modal.settlement.entrySearch', 'Buscar lançamento') }}</span>
              <input
                v-model="entrySearchTerm"
                type="search"
                :placeholder="loadingEntries ? translateScoped('actions.loading', 'Buscando lançamentos...') : translateScoped('modal.settlement.entrySearchPlaceholder', 'Digite para buscar...')"
                :disabled="processing || !canWrite"
                autocomplete="off"
                @input="searchEntries"
              >
            </label>
            <p v-if="loadingEntries" class="finance-search-select-state">
              {{ translateScoped('actions.loading', 'Buscando lançamentos...') }}
            </p>
            <p v-else-if="!availableSettlementEntries.length" class="finance-search-select-state">
              {{ translateScoped('modal.settlement.noEntriesFound', 'Nenhum lançamento encontrado.') }}
            </p>
            <button
              v-for="entryItem in availableSettlementEntries"
              :key="entryItem.id"
              type="button"
              class="finance-search-select-option"
              role="option"
              @click="selectEntry(entryItem)"
            >
              <strong>{{ entryItem.title }}</strong>
              <span>{{ formatSettlementEntryOptionLabel(entryItem) }}</span>
            </button>
          </div>
        </div>

        <label v-if="settlementForm.settlementType === 'ADVANCE' || settlementForm.settlementType === 'DISCOUNT'">
          <span>{{ translateScoped('modal.settlement.installmentPlan', 'Plano de parcelamento') }}</span>
          <select v-model="settlementForm.installmentPlanId" :disabled="processing || !canWrite">
            <option value="">{{ translateScoped('options.select', 'Selecione') }}</option>
            <option v-for="plan in filteredInstallmentPlans" :key="plan.id" :value="plan.id">
              {{ plan.title }} - {{ formatCurrency(plan.remainingAmountBrl) }}
            </option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.settlement.type', 'Tipo de baixa') }}</span>
          <select v-model="settlementForm.settlementType" :disabled="processing || !canWrite">
            <option v-for="opt in settlementTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.settlement.amount', 'Valor') }}</span>
          <input v-model="settlementForm.amountBrl" type="number" step="0.01" min="0.01" :disabled="processing || !canWrite || settlementForm.settlementType === 'PAYMENT'" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.settlement.dateTime', 'Data/hora da baixa') }}</span>
          <input v-model="settlementForm.settledAt" type="datetime-local" :disabled="processing || !canWrite">
        </label>

        <label v-if="settlementForm.settlementType === 'PAYMENT'">
          <span>{{ translateScoped('modal.settlement.bankAccount', 'Conta bancária') }}</span>
          <select v-model="settlementForm.bankAccountId" :disabled="processing || !canWrite">
            <option value="">{{ translateScoped('options.select', 'Selecione') }}</option>
            <option v-for="acc in bankAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
          </select>
        </label>

        <label v-if="settlementForm.settlementType === 'PAYMENT'" class="finance-modal-checkbox">
          <input v-model="settlementForm.useCreditCard" type="checkbox" :disabled="processing || !canWrite">
          <span>{{ translateScoped('modal.settlement.useCreditCard', 'Baixa de valor em Crédito') }}</span>
        </label>

        <label v-if="settlementForm.useCreditCard && settlementForm.settlementType === 'PAYMENT'">
          <span>{{ translateScoped('modal.settlement.creditCard', 'Cartão de crédito') }}</span>
          <select v-model="settlementForm.creditCardId" :disabled="processing || !canWrite" required>
            <option value="">{{ translateScoped('options.select', 'Selecione') }}</option>
            <option v-for="card in creditCardAccounts" :key="card.id" :value="card.id">
              {{ card.name }} - {{ card.bankName || card.name }}
            </option>
          </select>
        </label>

        <div class="finance-modal-actions">
          <button type="button" class="finance-inline-action" :disabled="processing" @click="emit('close')">
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>
          <button class="finance-action-button" type="submit" :disabled="processing || !canWrite">
            {{ processing ? translateScoped('actions.saving', 'Salvando...') : translateScoped('actions.registerSettlement', 'Registrar baixa') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
