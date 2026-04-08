<script setup>
import { computed, reactive, watch } from 'vue'
import { useI18n } from '../../composables/useI18n'
import {
  RemoteFinanceEmptyState,
  RemoteFinanceStatusBadge,
} from '../../federation/remoteComponents'
import { formatDate } from '../../utils/date'

const props = defineProps({
  panelTitle: {
    type: String,
    default: '',
  },
  totalsLabel: {
    type: String,
    default: '',
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
    default: '',
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

const FILTER_SEARCH_MAX_LENGTH = 180
const { translate, currentLocale } = useI18n()

const localFilters = reactive({
  search: '',
  direction: '',
  status: '',
  startDate: '',
  endDate: '',
})

const directionOptionsCatalog = computed(() => (
  Array.isArray(props.directionOptions) ? props.directionOptions : []
))

const statusOptionsCatalog = computed(() => (
  Array.isArray(props.statusOptions) ? props.statusOptions : []
))

const resolvedPanelTitle = computed(() => {
  const normalizedTitle = String(props.panelTitle || '').trim()
  return normalizedTitle !== '' ? normalizedTitle : translate('shared.financeEntriesPanel.title')
})

const resolvedTotalsLabel = computed(() => {
  const normalizedTotalsLabel = String(props.totalsLabel || '').trim()
  return normalizedTotalsLabel !== '' ? normalizedTotalsLabel : translate('shared.financeEntriesPanel.pagination.empty')
})

function replaceControlCharactersWithSpaces(rawValue) {
  let sanitizedText = ''
  const inputText = String(rawValue || '')

  for (const currentCharacter of inputText) {
    const characterCode = currentCharacter.charCodeAt(0)
    const isControlCharacter = characterCode < 32 || characterCode === 127
    sanitizedText += isControlCharacter ? ' ' : currentCharacter
  }

  return sanitizedText
}

function sanitizeSingleLineText(rawValue, maxLength = FILTER_SEARCH_MAX_LENGTH) {
  return replaceControlCharactersWithSpaces(rawValue)
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, maxLength)
}

function sanitizeDateInput(rawDateInput) {
  const normalizedDateInput = String(rawDateInput || '').trim()
  if (normalizedDateInput === '') {
    return ''
  }

  return /^\d{4}-\d{2}-\d{2}$/.test(normalizedDateInput) ? normalizedDateInput : ''
}

function sanitizeFilterOptionByCatalog(rawValue, catalogOptions = []) {
  const normalizedValue = String(rawValue || '').trim().toUpperCase()
  if (normalizedValue === '') {
    return ''
  }

  const allowedOption = catalogOptions.find((catalogOption) => (
    String(catalogOption?.value || '').trim().toUpperCase() === normalizedValue
  ))

  return allowedOption ? String(allowedOption.value || '').trim() : ''
}

function sanitizeFiltersPayload(rawFilters = {}) {
  const normalizedFilters = {
    search: sanitizeSingleLineText(rawFilters.search),
    direction: props.showDirectionFilter
      ? sanitizeFilterOptionByCatalog(rawFilters.direction, directionOptionsCatalog.value)
      : '',
    status: sanitizeFilterOptionByCatalog(rawFilters.status, statusOptionsCatalog.value),
    startDate: sanitizeDateInput(rawFilters.startDate),
    endDate: sanitizeDateInput(rawFilters.endDate),
  }

  if (
    normalizedFilters.startDate !== ''
    && normalizedFilters.endDate !== ''
    && normalizedFilters.startDate > normalizedFilters.endDate
  ) {
    const startDateBackup = normalizedFilters.startDate
    normalizedFilters.startDate = normalizedFilters.endDate
    normalizedFilters.endDate = startDateBackup
  }

  return normalizedFilters
}

watch(
  () => props.filters,
  (nextFilters) => {
    const normalizedFilters = sanitizeFiltersPayload(nextFilters || {})
    localFilters.search = normalizedFilters.search
    localFilters.direction = normalizedFilters.direction
    localFilters.status = normalizedFilters.status
    localFilters.startDate = normalizedFilters.startDate
    localFilters.endDate = normalizedFilters.endDate
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

  return new Intl.NumberFormat(currentLocale.value === 'en-US' ? 'en-US' : 'pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(numericValue)
}

function submitFilters() {
  const normalizedFilters = sanitizeFiltersPayload(localFilters)
  localFilters.search = normalizedFilters.search
  localFilters.direction = normalizedFilters.direction
  localFilters.status = normalizedFilters.status
  localFilters.startDate = normalizedFilters.startDate
  localFilters.endDate = normalizedFilters.endDate
  emit('submit-filters', normalizedFilters)
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
        <h3>{{ resolvedPanelTitle }}</h3>
      </div>
      <small>{{ resolvedTotalsLabel }}</small>
      <small v-if="loading">{{ translate('shared.financeEntriesPanel.loading') }}</small>
    </header>

    <form class="finance-filter-grid" @submit.prevent="submitFilters">
      <label>
        <span>{{ translate('shared.financeEntriesPanel.filters.search') }}</span>
        <input
          v-model="localFilters.search"
          type="text"
          :placeholder="translate('shared.financeEntriesPanel.filters.searchPlaceholder')"
        >
      </label>

      <label v-if="showDirectionFilter">
        <span>{{ translate('shared.financeEntriesPanel.filters.direction') }}</span>
        <select v-model="localFilters.direction">
          <option value="">{{ translate('shared.financeEntriesPanel.filters.directionAll') }}</option>
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
        <span>{{ translate('shared.financeEntriesPanel.filters.status') }}</span>
        <select v-model="localFilters.status">
          <option value="">{{ translate('shared.financeEntriesPanel.filters.statusAll') }}</option>
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
        <span>{{ translate('shared.financeEntriesPanel.filters.startDate') }}</span>
        <input v-model="localFilters.startDate" type="date">
      </label>

      <label>
        <span>{{ translate('shared.financeEntriesPanel.filters.endDate') }}</span>
        <input v-model="localFilters.endDate" type="date">
      </label>

      <button class="finance-action-button" type="submit">{{ translate('shared.financeEntriesPanel.filters.apply') }}</button>
    </form>

    <div v-if="entriesItems.length" class="finance-inline-table-wrap">
      <table class="finance-inline-table">
        <thead>
          <tr>
            <th>{{ translate('shared.financeEntriesPanel.table.title') }}</th>
            <th>{{ translate('shared.financeEntriesPanel.table.type') }}</th>
            <th>{{ translate('shared.financeEntriesPanel.table.status') }}</th>
            <th>{{ translate('shared.financeEntriesPanel.table.dueDate') }}</th>
            <th>{{ translate('shared.financeEntriesPanel.table.expected') }}</th>
            <th>{{ translate('shared.financeEntriesPanel.table.remaining') }}</th>
            <th v-if="shouldShowEntryActions" class="finance-actions-header">{{ translate('shared.financeEntriesPanel.table.action') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entryItem in entriesItems" :key="entryItem.id">
            <td>
              <strong>{{ entryItem.title }}</strong>
              <small class="finance-muted-block">
                {{ entryItem.categoryName || translate('shared.financeEntriesPanel.labels.noCategory') }}
              </small>
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
                {{ translate('shared.financeEntriesPanel.actions.edit') }}
              </button>
              <button
                type="button"
                class="finance-inline-action finance-inline-action-danger"
                @click="deleteEntry(entryItem)"
              >
                {{ translate('shared.financeEntriesPanel.actions.delete') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="entriesItems.length" class="finance-pagination">
      <span class="finance-pagination-summary">{{ resolvedTotalsLabel }}</span>
      <div v-if="totalEntriesPages > 1" class="finance-pagination-actions">
        <button
          type="button"
          class="finance-inline-action finance-pagination-button"
          :disabled="currentEntriesPage <= 1"
          @click="goToPreviousPage"
        >
          {{ translate('shared.financeEntriesPanel.pagination.previous') }}
        </button>
        <span class="finance-pagination-page">
          {{
            translate('shared.financeEntriesPanel.pagination.pageSummary', {
              page: currentEntriesPage,
              totalPages: totalEntriesPages,
            })
          }}
        </span>
        <button
          type="button"
          class="finance-inline-action finance-pagination-button"
          :disabled="currentEntriesPage >= totalEntriesPages"
          @click="goToNextPage"
        >
          {{ translate('shared.financeEntriesPanel.pagination.next') }}
        </button>
      </div>
    </div>

    <RemoteFinanceEmptyState
      v-if="!entriesItems.length"
      :title="translate('shared.financeEntriesPanel.empty.title')"
      :description="translate('shared.financeEntriesPanel.empty.description')"
    />
  </article>
</template>
