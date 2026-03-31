<script setup>
import { computed, reactive, watch } from 'vue'
import {
  RemoteFinanceEmptyState,
  RemoteFinanceStatusBadge,
} from '../../federation/remoteComponents'
import { formatDate } from '../../utils/date'

const props = defineProps({
  panelTitle: {
    type: String,
    default: 'Lançamentos',
  },
  totalsLabel: {
    type: String,
    default: '0 lançamentos',
  },
  entriesItems: {
    type: Array,
    default: () => [],
  },
  entriesMeta: {
    type: Object,
    default: () => ({
      page: 1,
      itemsPerPage: 10,
      total: 0,
    }),
  },
  filters: {
    type: Object,
    required: true,
  },
  showDirectionFilter: {
    type: Boolean,
    default: false,
  },
  currentDirectionLabel: {
    type: String,
    default: 'Todas as direções',
  },
  directionOptions: {
    type: Array,
    default: () => [],
  },
  statusOptions: {
    type: Array,
    default: () => [],
  },
  resolveFinanceLabel: {
    type: Function,
    required: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits([
  'submit-filters',
  'set-page',
  'edit-entry',
  'delete-entry',
])

const localFilters = reactive({
  search: '',
  direction: '',
  status: '',
  startDate: '',
  endDate: '',
})

watch(
  () => props.filters,
  (nextFilters) => {
    localFilters.search = String(nextFilters?.search || '')
    localFilters.direction = String(nextFilters?.direction || '')
    localFilters.status = String(nextFilters?.status || '')
    localFilters.startDate = String(nextFilters?.startDate || '')
    localFilters.endDate = String(nextFilters?.endDate || '')
  },
  {
    immediate: true,
    deep: true,
  },
)

const totalEntriesPages = computed(() => (
  Math.max(
    1,
    Math.ceil(
      Number(props.entriesMeta?.total || 0) / Math.max(1, Number(props.entriesMeta?.itemsPerPage || 1)),
    ),
  )
))

const currentEntriesPage = computed(() => (
  Math.min(totalEntriesPages.value, Math.max(1, Number(props.entriesMeta?.page || 1)))
))

const isUnifiedEntriesPanel = computed(() => props.showDirectionFilter)

function formatCurrency(rawValue) {
  const numericValue = Number(rawValue || 0)

  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(numericValue)
}

function submitFilters() {
  emit('submit-filters', {
    search: localFilters.search,
    direction: localFilters.direction,
    status: localFilters.status,
    startDate: localFilters.startDate,
    endDate: localFilters.endDate,
  })
}

function goToPreviousPage() {
  if (currentEntriesPage.value <= 1) {
    return
  }

  emit('set-page', currentEntriesPage.value - 1)
}

function goToNextPage() {
  if (currentEntriesPage.value >= totalEntriesPages.value) {
    return
  }

  emit('set-page', currentEntriesPage.value + 1)
}

function editEntry(entryItem) {
  emit('edit-entry', entryItem)
}

function deleteEntry(entryItem) {
  emit('delete-entry', entryItem)
}
</script>

<template>
  <article class="finance-panel" :class="{ 'finance-panel-unified': isUnifiedEntriesPanel }">
    <header>
      <div class="finance-panel-heading">
        <h3>{{ panelTitle }}</h3>
        <span v-if="isUnifiedEntriesPanel" class="finance-unified-badge">Unificado</span>
      </div>
      <small>{{ totalsLabel }}</small>
      <small v-if="isUnifiedEntriesPanel" class="finance-unified-hint">
        Exibindo contas a pagar e a receber na mesma listagem.
      </small>
      <small v-if="loading">Atualizando...</small>
    </header>

    <form class="finance-filter-grid" @submit.prevent="submitFilters">
      <label>
        <span>Busca</span>
        <input v-model="localFilters.search" type="text" placeholder="Título ou descrição">
      </label>

      <label v-if="showDirectionFilter">
        <span>Direção</span>
        <select v-model="localFilters.direction">
          <option value="">Todas as direções</option>
          <option
            v-for="directionOption in directionOptions"
            :key="directionOption.value"
            :value="directionOption.value"
          >
            {{ directionOption.label }}
          </option>
        </select>
      </label>
      <label>
        <span>Status</span>
        <select v-model="localFilters.status">
          <option value="">Todos</option>
          <option
            v-for="statusOption in statusOptions"
            :key="statusOption.value"
            :value="statusOption.value"
          >
            {{ statusOption.label }}
          </option>
        </select>
      </label>

      <label>
        <span>Início</span>
        <input v-model="localFilters.startDate" type="date">
      </label>

      <label>
        <span>Fim</span>
        <input v-model="localFilters.endDate" type="date">
      </label>

      <button class="finance-action-button" type="submit">Filtrar</button>
    </form>

    <div v-if="entriesItems.length" class="finance-inline-table-wrap">
      <table class="finance-inline-table">
        <thead>
          <tr>
            <th>Título</th>
            <th>Tipo</th>
            <th>Status</th>
            <th>Vencimento</th>
            <th>Esperado</th>
            <th>Restante</th>
            <th>Ação</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entryItem in entriesItems" :key="entryItem.id">
            <td>
              <strong>{{ entryItem.title }}</strong>
              <small class="finance-muted-block">{{ entryItem.categoryName || 'Sem categoria' }}</small>
            </td>
            <td>{{ resolveFinanceLabel(entryItem.entryType, '-') }}</td>
            <td>
              <RemoteFinanceStatusBadge
                :status="entryItem.status"
                :label="resolveFinanceLabel(entryItem.status)"
              />
            </td>
            <td>{{ formatDate(entryItem.dueDate, '-') }}</td>
            <td>{{ formatCurrency(entryItem.expectedAmountBrl) }}</td>
            <td>{{ formatCurrency(entryItem.remainingAmountBrl) }}</td>
            <td class="finance-actions-cell">
              <button
                type="button"
                class="finance-inline-action"
                @click="editEntry(entryItem)"
              >
                Editar
              </button>
              <button
                type="button"
                class="finance-inline-action finance-inline-action-danger"
                @click="deleteEntry(entryItem)"
              >
                Excluir
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="entriesItems.length" class="finance-pagination">
      <span class="finance-pagination-summary">{{ totalsLabel }}</span>
      <div v-if="totalEntriesPages > 1" class="finance-pagination-actions">
        <button
          type="button"
          class="finance-inline-action finance-pagination-button"
          :disabled="currentEntriesPage <= 1"
          @click="goToPreviousPage"
        >
          Anterior
        </button>
        <span class="finance-pagination-page">
          Página {{ currentEntriesPage }} de {{ totalEntriesPages }}
        </span>
        <button
          type="button"
          class="finance-inline-action finance-pagination-button"
          :disabled="currentEntriesPage >= totalEntriesPages"
          @click="goToNextPage"
        >
          Próxima
        </button>
      </div>
    </div>

    <RemoteFinanceEmptyState
      v-if="!entriesItems.length"
      title="Sem lançamentos"
      description="Cadastre o primeiro lançamento para começar seu fluxo financeiro."
    />
  </article>
</template>

<style scoped>
.finance-panel {
  display: grid;
  gap: 14px;
}

.finance-panel-unified {
  border-color: color-mix(in srgb, var(--accent, #1d4ed8) 32%, var(--line, #cbd5e1));
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--accent, #1d4ed8) 7%, #ffffff) 0%,
    var(--surface-strong, #ffffff) 65%
  );
}

.finance-panel header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.finance-panel-heading {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.finance-panel h3 {
  margin: 0;
  font-size: 1rem;
  color: var(--ink, #0f172a);
}

.finance-panel small {
  color: var(--muted, #475569);
}

.finance-unified-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 24px;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent, #1d4ed8) 36%, transparent);
  background: color-mix(in srgb, var(--accent, #1d4ed8) 12%, #ffffff);
  color: var(--accent, #1d4ed8);
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0 8px;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.finance-unified-hint {
  flex-basis: 100%;
  font-size: 0.75rem;
}

.finance-filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
  gap: 14px;
  align-items: end;
}

.finance-filter-grid label {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.finance-filter-grid label span {
  font-size: 0.78rem;
  color: var(--muted, #475569);
  font-weight: 700;
  line-height: 1.3;
}

.finance-filter-grid input,
.finance-filter-grid select {
  border-radius: 10px;
  border: 1px solid var(--line, #cbd5e1);
  min-height: 42px;
  padding: 0 12px;
  width: 100%;
  min-width: 0;
  color: var(--ink, #0f172a);
  background: var(--app-field-bg, color-mix(in srgb, var(--surface-strong, #ffffff) 88%, transparent));
}

.finance-action-button,
.finance-inline-action {
  border: 1px solid var(--accent, #1d4ed8);
  background: var(--accent, #1d4ed8);
  color: var(--button-primary-text, #ffffff);
  border-radius: 10px;
  min-height: 38px;
  padding: 0 12px;
  font-size: 0.8rem;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}

.finance-action-button {
  justify-self: start;
  min-width: 120px;
}

.finance-inline-action:disabled {
  border-color: var(--line, #94a3b8);
  background: color-mix(in srgb, var(--muted, #94a3b8) 72%, transparent);
  color: var(--ink, #0f172a);
  cursor: not-allowed;
}

.finance-inline-action-danger {
  background: var(--danger-bg);
  border-color: color-mix(in srgb, var(--danger) 28%, transparent);
  color: var(--danger);
}

.finance-inline-action-danger:hover:not(:disabled) {
  background: color-mix(in srgb, var(--danger) 22%, transparent);
  border-color: color-mix(in srgb, var(--danger) 42%, transparent);
}

.finance-inline-table-wrap {
  overflow-x: auto;
  max-width: 100%;
}

.finance-inline-table {
  width: 100%;
  border-collapse: collapse;
}

.finance-inline-table th,
.finance-inline-table td {
  border-bottom: 1px solid var(--line, #e2e8f0);
  padding: 10px 8px;
  text-align: left;
  vertical-align: middle;
  color: var(--ink, #0f172a);
}

.finance-inline-table th {
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted, #475569);
}

.finance-actions-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.finance-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: 12px;
  flex-wrap: wrap;
}

.finance-pagination-summary,
.finance-pagination-page {
  font-size: 0.78rem;
  color: var(--muted, #475569);
}

.finance-pagination-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.finance-pagination-button {
  min-height: 36px;
}

.finance-muted-block {
  display: block;
  color: var(--muted, #64748b);
  font-size: 0.74rem;
}

@media (max-width: 768px) {
  .finance-filter-grid {
    grid-template-columns: 1fr;
    gap: 12px;
  }

  .finance-action-button {
    width: 100%;
  }
}
</style>
