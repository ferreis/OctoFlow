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
const shouldShowEntryActions = computed(() => !isUnifiedEntriesPanel.value)

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
  if (!shouldShowEntryActions.value) {
    return
  }

  emit('edit-entry', entryItem)
}

function deleteEntry(entryItem) {
  if (!shouldShowEntryActions.value) {
    return
  }

  emit('delete-entry', entryItem)
}
</script>

<template>
  <article class="finance-panel" :class="{ 'finance-panel-unified': isUnifiedEntriesPanel }">
    <header>
      <div class="finance-panel-heading">
        <h3>{{ panelTitle }}</h3>
      </div>
      <small>{{ totalsLabel }}</small>
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
            <th v-if="shouldShowEntryActions" class="finance-actions-header">Ação</th>
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
            <td v-if="shouldShowEntryActions" class="finance-actions-cell">
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
.finance-panel-heading {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.finance-muted-block {
  display: block;
  color: var(--muted, #64748b);
  font-size: 0.74rem;
}
</style>
