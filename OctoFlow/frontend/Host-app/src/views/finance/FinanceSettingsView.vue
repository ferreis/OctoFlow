<script setup>
import {
  computed,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
  reactive,
  ref,
  watch,
} from 'vue'
import { storeToRefs } from 'pinia'
import AppConfirmDialog from '../../components/shared/AppConfirmDialog.vue'
import FinancePageHeader from '../../components/finance/FinancePageHeader.vue'
import FinancePagination from '../../components/finance/FinancePagination.vue'
import { useFinancePermissions } from '../../composables/useFinancePermissions'
import { useNotification } from '../../composables/useNotification'
import { useScopedI18n } from '../../composables/useScopedI18n'
import { RemoteFinanceEmptyState } from '../../federation/remoteComponents'
import {
  createFinanceCategory,
  createFinanceCurrencyRateManual,
  createFinanceRecurringType,
  deleteFinanceRecurringType,
  updateFinanceCategory,
  updateFinanceRecurringType,
} from '../../services/finance'
import { useFinanceStore } from '../../stores/financeStore'
import { extractHttpMessage } from '../../utils/httpErrors'
import {
  sanitizeCurrencyCode,
  sanitizeDateInput,
  sanitizeDecimal,
  sanitizeIdentifier,
  sanitizeSingleLineText,
} from '../../utils/financeInputSanitizers'

const LIST_ITEMS_PER_PAGE = 10

const financeStore = useFinanceStore()
const { categories, recurringTypes, currencyRates, loading } = storeToRefs(financeStore)
const { notifyUser } = useNotification()
const { translateScoped, currentLocale } = useScopedI18n('financeModule.settingsView')
const { canWriteFinance } = useFinancePermissions()
const financeViewIsActive = ref(false)
const settingsCountsBeforeDomUpdate = ref({
  categories: 0,
  recurringTypes: 0,
  currencyRates: 0,
})

const categoryForm = reactive({
  name: '',
  kind: 'BOTH',
})
const categoryEditingId = ref('')
const categoryPage = ref(1)

const recurringTypeForm = reactive({
  name: '',
  description: '',
})
const recurringTypeEditingId = ref('')
const recurringTypePage = ref(1)

const currencyPage = ref(1)
const manualCurrencyRateForm = reactive({
  quoteDate: new Date().toISOString().slice(0, 10),
  currencyCode: 'USD',
  currencyName: '',
  rateBrl: '',
})

const confirmRecurringDeleteState = reactive({
  isOpen: false,
  recurringTypeId: '',
  recurringTypeName: '',
  processing: false,
})

const submittingCategory = ref(false)
const submittingRecurringType = ref(false)
const submittingCurrencyRate = ref(false)

const categoryKindOptions = computed(() => ([
  { value: 'BOTH', label: translateScoped('categories.kindOptions.both', 'Ambos (pagar e receber)') },
  { value: 'PAYABLE', label: translateScoped('categories.kindOptions.payable', 'Somente pagar') },
  { value: 'RECEIVABLE', label: translateScoped('categories.kindOptions.receivable', 'Somente receber') },
  { value: 'INVESTMENT', label: translateScoped('categories.kindOptions.investment', 'Investimento') },
]))

const filteredRecurringTypes = computed(() => (
  recurringTypes.value.filter((recurringType) => String(recurringType?.name || '').trim().toLowerCase() !== 'mensal')
))

const paginatedCategories = computed(() => {
  const startIndex = (categoryPage.value - 1) * LIST_ITEMS_PER_PAGE
  return categories.value.slice(startIndex, startIndex + LIST_ITEMS_PER_PAGE)
})

const categoryTotalPages = computed(() => Math.max(1, Math.ceil(categories.value.length / LIST_ITEMS_PER_PAGE)))

const paginatedRecurringTypes = computed(() => {
  const startIndex = (recurringTypePage.value - 1) * LIST_ITEMS_PER_PAGE
  return filteredRecurringTypes.value.slice(startIndex, startIndex + LIST_ITEMS_PER_PAGE)
})

const recurringTypeTotalPages = computed(() => Math.max(1, Math.ceil(filteredRecurringTypes.value.length / LIST_ITEMS_PER_PAGE)))

const paginatedCurrencyRates = computed(() => {
  const startIndex = (currencyPage.value - 1) * LIST_ITEMS_PER_PAGE
  return currencyRates.value.slice(startIndex, startIndex + LIST_ITEMS_PER_PAGE)
})

const currencyTotalPages = computed(() => Math.max(1, Math.ceil(currencyRates.value.length / LIST_ITEMS_PER_PAGE)))

const categoriesSummaryLabel = computed(() => (
  translateScoped('categories.summary', '{count} categoria(s)', { count: categories.value.length })
))

const recurringTypesSummaryLabel = computed(() => (
  translateScoped('recurringTypes.summary', '{count} tipo(s)', { count: filteredRecurringTypes.value.length })
))

const currencySummaryLabel = computed(() => (
  translateScoped('currency.summary', '{count} cotação(ões)', { count: currencyRates.value.length })
))

const isSettingsLoading = computed(() => {
  return loading.value.categories === true
    || loading.value.recurring === true
    || loading.value.currency === true
    || loading.value.catalogs === true
})
const localeForFormatting = computed(() => (
  String(currentLocale.value || '').toLowerCase() === 'en-us' ? 'en-US' : 'pt-BR'
))

function formatCurrency(rawValue) {
  return new Intl.NumberFormat(localeForFormatting.value, {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 4,
  }).format(Number(rawValue || 0))
}

function resetCategoryForm() {
  categoryForm.name = ''
  categoryForm.kind = 'BOTH'
  categoryEditingId.value = ''
}

function resetRecurringTypeForm() {
  recurringTypeForm.name = ''
  recurringTypeForm.description = ''
  recurringTypeEditingId.value = ''
}

function resetManualCurrencyRateForm() {
  manualCurrencyRateForm.quoteDate = new Date().toISOString().slice(0, 10)
  manualCurrencyRateForm.currencyCode = 'USD'
  manualCurrencyRateForm.currencyName = ''
  manualCurrencyRateForm.rateBrl = ''
}

function startEditingCategory(category) {
  categoryForm.name = sanitizeSingleLineText(category?.name, 80)

  const normalizedCategoryKind = String(category?.kind || '').trim().toUpperCase()
  categoryForm.kind = categoryKindOptions.value.some((option) => option.value === normalizedCategoryKind)
    ? normalizedCategoryKind
    : 'BOTH'

  categoryEditingId.value = sanitizeIdentifier(category?.id)
}

function startEditingRecurringType(recurringType) {
  recurringTypeForm.name = sanitizeSingleLineText(recurringType?.name, 80)
  recurringTypeForm.description = sanitizeSingleLineText(recurringType?.description, 160)
  recurringTypeEditingId.value = sanitizeIdentifier(recurringType?.id)
}

function openDeleteRecurringTypeDialog(recurringType) {
  const recurringTypeId = sanitizeIdentifier(recurringType?.id)
  if (recurringTypeId === '') {
    notifyUser(translateScoped('notifications.invalidRecurringType', 'Tipo recorrente inválido para exclusão.'), 'warning')
    return
  }

  confirmRecurringDeleteState.isOpen = true
  confirmRecurringDeleteState.recurringTypeId = recurringTypeId
  confirmRecurringDeleteState.recurringTypeName = sanitizeSingleLineText(recurringType?.name, 80)
}

function closeDeleteRecurringTypeDialog() {
  confirmRecurringDeleteState.isOpen = false
  confirmRecurringDeleteState.recurringTypeId = ''
  confirmRecurringDeleteState.recurringTypeName = ''
  confirmRecurringDeleteState.processing = false
}

function normalizeCategoryPayload() {
  const normalizedKind = String(categoryForm.kind || '').trim().toUpperCase()

  return {
    name: sanitizeSingleLineText(categoryForm.name, 80),
    kind: categoryKindOptions.value.some((option) => option.value === normalizedKind)
      ? normalizedKind
      : 'BOTH',
  }
}

function normalizeRecurringTypePayload() {
  return {
    name: sanitizeSingleLineText(recurringTypeForm.name, 80),
    description: sanitizeSingleLineText(recurringTypeForm.description, 160),
  }
}

function normalizeCurrencyRatePayload() {
  return {
    quoteDate: sanitizeDateInput(manualCurrencyRateForm.quoteDate),
    currencyCode: sanitizeCurrencyCode(manualCurrencyRateForm.currencyCode),
    currencyName: sanitizeSingleLineText(manualCurrencyRateForm.currencyName, 80),
    rateBrl: sanitizeDecimal(manualCurrencyRateForm.rateBrl, {
      min: 0,
      max: 999999,
      decimals: 4,
      defaultValue: 0,
    }),
  }
}

async function loadSettingsData(showNotificationOnError = false) {
  const [categoriesLoaded, recurringLoaded, currencyLoaded] = await Promise.all([
    financeStore.reloadCategories(),
    financeStore.reloadRecurringData(),
    financeStore.loadCurrencyData(true),
  ])

  if (!financeViewIsActive.value) {
    return
  }

  if (showNotificationOnError && (!categoriesLoaded || !recurringLoaded || !currencyLoaded)) {
    notifyUser(translateScoped('notifications.loadError', 'Não foi possível carregar as configurações financeiras.'), 'error')
  }
}

async function submitCategory() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para alterar categorias.'), 'warning')
    return
  }

  if (submittingCategory.value) {
    return
  }

  const payload = normalizeCategoryPayload()
  if (payload.name === '') {
    notifyUser(translateScoped('notifications.categoryNameRequired', 'Informe o nome da categoria.'), 'warning')
    return
  }

  submittingCategory.value = true

  try {
    const normalizedCategoryId = sanitizeIdentifier(categoryEditingId.value)
    if (normalizedCategoryId !== '') {
      await updateFinanceCategory(normalizedCategoryId, payload)
      notifyUser(translateScoped('notifications.categoryUpdated', 'Categoria atualizada com sucesso.'), 'success')
    } else {
      await createFinanceCategory(payload)
      notifyUser(translateScoped('notifications.categoryCreated', 'Categoria criada com sucesso.'), 'success')
    }

    resetCategoryForm()
    await financeStore.reloadCategories()
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.categorySaveError', 'Erro ao salvar categoria.')), 'error')
  } finally {
    submittingCategory.value = false
  }
}

async function toggleCategoryStatus(category) {
  const categoryId = sanitizeIdentifier(category?.id)
  if (categoryId === '' || submittingCategory.value || !canWriteFinance.value) {
    return
  }

  submittingCategory.value = true

  try {
    const shouldActivate = category?.isActive === false
    await updateFinanceCategory(categoryId, { isActive: shouldActivate })
    notifyUser(
      shouldActivate
        ? translateScoped('notifications.categoryActivated', 'Categoria reativada com sucesso.')
        : translateScoped('notifications.categoryInactivated', 'Categoria inativada com sucesso.'),
      'success',
    )
    await financeStore.loadCatalogs(true)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.categoryStatusError', 'Erro ao alterar o status da categoria.')), 'error')
  } finally {
    submittingCategory.value = false
  }
}

async function submitRecurringType() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para alterar tipos recorrentes.'), 'warning')
    return
  }

  if (submittingRecurringType.value) {
    return
  }

  const payload = normalizeRecurringTypePayload()
  if (payload.name === '') {
    notifyUser(translateScoped('notifications.recurringNameRequired', 'Informe o nome do tipo recorrente.'), 'warning')
    return
  }

  submittingRecurringType.value = true

  try {
    const normalizedRecurringTypeId = sanitizeIdentifier(recurringTypeEditingId.value)
    if (normalizedRecurringTypeId !== '') {
      await updateFinanceRecurringType(normalizedRecurringTypeId, payload)
      notifyUser(translateScoped('notifications.recurringUpdated', 'Tipo recorrente atualizado com sucesso.'), 'success')
    } else {
      await createFinanceRecurringType(payload)
      notifyUser(translateScoped('notifications.recurringCreated', 'Tipo recorrente criado com sucesso.'), 'success')
    }

    resetRecurringTypeForm()
    await financeStore.reloadRecurringData()
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.recurringSaveError', 'Erro ao salvar tipo recorrente.')), 'error')
  } finally {
    submittingRecurringType.value = false
  }
}

async function toggleRecurringTypeStatus(recurringType) {
  const recurringTypeId = sanitizeIdentifier(recurringType?.id)
  if (recurringTypeId === '' || submittingRecurringType.value || !canWriteFinance.value) {
    return
  }

  submittingRecurringType.value = true

  try {
    const shouldActivate = recurringType?.isActive === false
    await updateFinanceRecurringType(recurringTypeId, { isActive: shouldActivate })
    notifyUser(
      shouldActivate
        ? translateScoped('notifications.recurringTypeActivated', 'Tipo recorrente reativado com sucesso.')
        : translateScoped('notifications.recurringTypeInactivated', 'Tipo recorrente inativado com sucesso.'),
      'success',
    )
    await financeStore.loadCatalogs(true)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.recurringTypeStatusError', 'Erro ao alterar o status do tipo recorrente.')), 'error')
  } finally {
    submittingRecurringType.value = false
  }
}

async function confirmDeleteRecurringType() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para excluir tipos recorrentes.'), 'warning')
    return
  }

  if (confirmRecurringDeleteState.processing) {
    return
  }

  const recurringTypeId = sanitizeIdentifier(confirmRecurringDeleteState.recurringTypeId)
  if (recurringTypeId === '') {
    closeDeleteRecurringTypeDialog()
    return
  }

  confirmRecurringDeleteState.processing = true

  try {
    await deleteFinanceRecurringType(recurringTypeId)
    notifyUser(translateScoped('notifications.recurringDeleted', 'Tipo recorrente removido com sucesso.'), 'success')
    await financeStore.reloadRecurringData()
    closeDeleteRecurringTypeDialog()
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.recurringDeleteError', 'Erro ao excluir tipo recorrente.')), 'error')
    confirmRecurringDeleteState.processing = false
  }
}

async function submitManualCurrencyRate() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para registrar cotações.'), 'warning')
    return
  }

  if (submittingCurrencyRate.value) {
    return
  }

  const payload = normalizeCurrencyRatePayload()
  if (payload.quoteDate === '' || payload.currencyCode === '' || payload.rateBrl <= 0) {
    notifyUser(translateScoped('notifications.currencyRequired', 'Preencha data, moeda e cotação válidas.'), 'warning')
    return
  }

  submittingCurrencyRate.value = true

  try {
    await createFinanceCurrencyRateManual(payload)
    notifyUser(translateScoped('notifications.currencySaved', 'Cotação registrada com sucesso.'), 'success')
    resetManualCurrencyRateForm()
    await financeStore.reloadCurrencyRates()
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.currencySaveError', 'Erro ao registrar cotação.')), 'error')
  } finally {
    submittingCurrencyRate.value = false
  }
}

watch(categoryTotalPages, (nextTotalPages) => {
  if (categoryPage.value > nextTotalPages) {
    categoryPage.value = nextTotalPages
  }
})

watch(recurringTypeTotalPages, (nextTotalPages) => {
  if (recurringTypePage.value > nextTotalPages) {
    recurringTypePage.value = nextTotalPages
  }
})

watch(currencyTotalPages, (nextTotalPages) => {
  if (currencyPage.value > nextTotalPages) {
    currencyPage.value = nextTotalPages
  }
})

onBeforeMount(() => {
  void financeStore.loadCatalogs(false)
})

onMounted(() => {
  financeViewIsActive.value = true
  void loadSettingsData(true)
})

onBeforeUpdate(() => {
  settingsCountsBeforeDomUpdate.value = {
    categories: categories.value.length,
    recurringTypes: filteredRecurringTypes.value.length,
    currencyRates: currencyRates.value.length,
  }
})

onUpdated(() => {
  if (settingsCountsBeforeDomUpdate.value.categories !== categories.value.length && categoryPage.value > categoryTotalPages.value) {
    categoryPage.value = categoryTotalPages.value
  }

  if (settingsCountsBeforeDomUpdate.value.recurringTypes !== filteredRecurringTypes.value.length && recurringTypePage.value > recurringTypeTotalPages.value) {
    recurringTypePage.value = recurringTypeTotalPages.value
  }

  if (settingsCountsBeforeDomUpdate.value.currencyRates !== currencyRates.value.length && currencyPage.value > currencyTotalPages.value) {
    currencyPage.value = currencyTotalPages.value
  }
})

onActivated(() => {
  financeViewIsActive.value = true
  void loadSettingsData(false)
})

onDeactivated(() => {
  financeViewIsActive.value = false
  submittingCategory.value = false
  submittingRecurringType.value = false
  submittingCurrencyRate.value = false
})

onBeforeUnmount(() => {
  financeViewIsActive.value = false
  resetCategoryForm()
  resetRecurringTypeForm()
  resetManualCurrencyRateForm()
  closeDeleteRecurringTypeDialog()
})

onUnmounted(() => {
  settingsCountsBeforeDomUpdate.value = {
    categories: 0,
    recurringTypes: 0,
    currencyRates: 0,
  }
})

onErrorCaptured((error) => {
  if (!financeViewIsActive.value) {
    return false
  }

  console.error('[FinanceSettingsView] child render error:', error)
  notifyUser(translateScoped('notifications.childRenderError', 'Erro inesperado ao renderizar configurações financeiras.'), 'error')
  return false
})
</script>

<template>
  <section class="finance-section">
    <FinancePageHeader
      eyebrow="Organização"
      title="Configurações financeiras"
      description="Defina as categorias, recorrências e cotações que organizam seus lançamentos."
    />

    <article class="finance-panel">
      <header>
        <h3>
          {{ categoryEditingId
            ? translateScoped('categories.form.titleEdit', 'Editar categoria')
            : translateScoped('categories.form.titleCreate', 'Nova categoria') }}
        </h3>
        <small>{{ categoriesSummaryLabel }}</small>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitCategory">
        <label>
          <span>{{ translateScoped('categories.form.fields.name.label', 'Nome da categoria') }}</span>
          <input
            v-model="categoryForm.name"
            type="text"
            :placeholder="translateScoped('categories.form.fields.name.placeholder', 'Ex.: Moradia')"
            :disabled="submittingCategory || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('categories.form.fields.kind.label', 'Aplicação') }}</span>
          <select v-model="categoryForm.kind" :disabled="submittingCategory || !canWriteFinance">
            <option v-for="option in categoryKindOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>

        <div class="finance-form-actions">
          <button class="finance-action-button" type="submit" :disabled="submittingCategory || !canWriteFinance">
            {{ submittingCategory
              ? translateScoped('actions.saving', 'Salvando...')
              : categoryEditingId
                ? translateScoped('actions.saveChanges', 'Salvar alterações')
                : translateScoped('actions.createCategory', 'Criar categoria') }}
          </button>
          <button v-if="categoryEditingId" type="button" class="finance-inline-action" :disabled="submittingCategory" @click="resetCategoryForm">
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>
        </div>
      </form>

      <div v-if="isSettingsLoading && !categories.length" class="finance-muted-block">
        {{ translateScoped('categories.loading', 'Carregando categorias...') }}
      </div>

      <div v-else-if="paginatedCategories.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('categories.columns.name', 'Nome') }}</th>
              <th>{{ translateScoped('categories.columns.kind', 'Tipo') }}</th>
              <th>{{ translateScoped('categories.columns.actions', 'Ação') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="category in paginatedCategories" :key="category.id">
              <td>
                <strong>{{ category.name }}</strong>
                <small v-if="category.isActive === false" class="finance-muted-block">Inativa</small>
              </td>
              <td>{{ category.kind || 'BOTH' }}</td>
              <td class="finance-actions-cell">
                <button type="button" class="finance-inline-action" :disabled="submittingCategory || !canWriteFinance" @click="startEditingCategory(category)">
                  {{ translateScoped('actions.edit', 'Editar') }}
                </button>
                <button type="button" class="finance-inline-action" :disabled="submittingCategory || !canWriteFinance" @click="toggleCategoryStatus(category)">
                  {{ category.isActive === false ? 'Reativar' : 'Inativar' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <RemoteFinanceEmptyState
        v-else
        :title="translateScoped('categories.empty.title', 'Sem categorias')"
        :description="translateScoped('categories.empty.description', 'Crie sua primeira categoria.')"
      />

      <FinancePagination
        :current-page="categoryPage"
        :total-pages="categoryTotalPages"
        :summary="translateScoped('pagination.summary', 'Página {page} de {totalPages}', { page: categoryPage, totalPages: categoryTotalPages })"
        :previous-label="translateScoped('pagination.previous', 'Anterior')"
        :next-label="translateScoped('pagination.next', 'Próxima')"
        @previous="categoryPage--"
        @next="categoryPage++"
      />
    </article>

    <article class="finance-panel">
      <header>
        <h3>
          {{ recurringTypeEditingId
            ? translateScoped('recurringTypes.form.titleEdit', 'Editar tipo recorrente')
            : translateScoped('recurringTypes.form.titleCreate', 'Novo tipo recorrente') }}
        </h3>
        <small>{{ recurringTypesSummaryLabel }}</small>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitRecurringType">
        <label>
          <span>{{ translateScoped('recurringTypes.form.fields.name.label', 'Nome') }}</span>
          <input
            v-model="recurringTypeForm.name"
            type="text"
            :placeholder="translateScoped('recurringTypes.form.fields.name.placeholder', 'Ex.: Quinzenal')"
            :disabled="submittingRecurringType || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('recurringTypes.form.fields.description.label', 'Descrição') }}</span>
          <input
            v-model="recurringTypeForm.description"
            type="text"
            :placeholder="translateScoped('recurringTypes.form.fields.description.placeholder', 'Descrição opcional')"
            :disabled="submittingRecurringType || !canWriteFinance"
          >
        </label>

        <div class="finance-form-actions">
          <button class="finance-action-button" type="submit" :disabled="submittingRecurringType || !canWriteFinance">
            {{ submittingRecurringType
              ? translateScoped('actions.saving', 'Salvando...')
              : recurringTypeEditingId
                ? translateScoped('actions.saveChanges', 'Salvar alterações')
                : translateScoped('actions.createRecurringType', 'Criar tipo') }}
          </button>
          <button v-if="recurringTypeEditingId" type="button" class="finance-inline-action" :disabled="submittingRecurringType" @click="resetRecurringTypeForm">
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>
        </div>
      </form>

      <div v-if="paginatedRecurringTypes.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('recurringTypes.columns.name', 'Nome') }}</th>
              <th>{{ translateScoped('recurringTypes.columns.description', 'Descrição') }}</th>
              <th>{{ translateScoped('recurringTypes.columns.actions', 'Ação') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="recurringType in paginatedRecurringTypes" :key="recurringType.id">
              <td>
                <strong>{{ recurringType.name }}</strong>
                <small v-if="recurringType.isActive === false" class="finance-muted-block">Inativo</small>
              </td>
              <td>{{ recurringType.description || '-' }}</td>
              <td class="finance-actions-cell">
                <button type="button" class="finance-inline-action" :disabled="submittingRecurringType || !canWriteFinance" @click="startEditingRecurringType(recurringType)">
                  {{ translateScoped('actions.edit', 'Editar') }}
                </button>
                <button type="button" class="finance-inline-action" :disabled="submittingRecurringType || !canWriteFinance" @click="toggleRecurringTypeStatus(recurringType)">
                  {{ recurringType.isActive === false ? 'Reativar' : 'Inativar' }}
                </button>
                <button
                  type="button"
                  class="finance-inline-action finance-inline-action-danger"
                  :disabled="submittingRecurringType || !canWriteFinance"
                  @click="openDeleteRecurringTypeDialog(recurringType)"
                >
                  Excluir definitivamente
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <RemoteFinanceEmptyState
        v-else
        :title="translateScoped('recurringTypes.empty.title', 'Sem tipos recorrentes')"
        :description="translateScoped('recurringTypes.empty.description', 'Crie tipos para organizar suas recorrências.')"
      />

      <FinancePagination
        :current-page="recurringTypePage"
        :total-pages="recurringTypeTotalPages"
        :summary="translateScoped('pagination.summary', 'Página {page} de {totalPages}', { page: recurringTypePage, totalPages: recurringTypeTotalPages })"
        :previous-label="translateScoped('pagination.previous', 'Anterior')"
        :next-label="translateScoped('pagination.next', 'Próxima')"
        @previous="recurringTypePage--"
        @next="recurringTypePage++"
      />
    </article>

    <article class="finance-panel">
      <header>
        <h3>{{ translateScoped('currency.title', 'Cotações de moeda') }}</h3>
        <small>{{ currencySummaryLabel }}</small>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitManualCurrencyRate">
        <label>
          <span>{{ translateScoped('currency.form.fields.quoteDate.label', 'Data') }}</span>
          <input
            v-model="manualCurrencyRateForm.quoteDate"
            type="date"
            :disabled="submittingCurrencyRate || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('currency.form.fields.currencyCode.label', 'Moeda (código)') }}</span>
          <input
            v-model="manualCurrencyRateForm.currencyCode"
            type="text"
            :placeholder="translateScoped('currency.form.fields.currencyCode.placeholder', 'USD')"
            :disabled="submittingCurrencyRate || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('currency.form.fields.rateBrl.label', 'Cotação (R$)') }}</span>
          <input
            v-model="manualCurrencyRateForm.rateBrl"
            type="number"
            step="0.0001"
            min="0"
            :disabled="submittingCurrencyRate || !canWriteFinance"
            required
          >
        </label>

        <button class="finance-action-button" type="submit" :disabled="submittingCurrencyRate || !canWriteFinance">
          {{ submittingCurrencyRate
            ? translateScoped('actions.saving', 'Salvando...')
            : translateScoped('actions.saveCurrencyRate', 'Registrar cotação') }}
        </button>
      </form>

      <div v-if="paginatedCurrencyRates.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('currency.columns.quoteDate', 'Data') }}</th>
              <th>{{ translateScoped('currency.columns.currencyCode', 'Moeda') }}</th>
              <th>{{ translateScoped('currency.columns.rateBrl', 'Cotação (R$)') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="rate in paginatedCurrencyRates" :key="`${rate.currencyCode}-${rate.quoteDate}`">
              <td>{{ rate.quoteDate }}</td>
              <td><strong>{{ rate.currencyCode }}</strong></td>
              <td>{{ formatCurrency(rate.rateBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <RemoteFinanceEmptyState
        v-else
        :title="translateScoped('currency.empty.title', 'Sem cotações')"
        :description="translateScoped('currency.empty.description', 'Registre cotações manualmente ou atualize via API do Bacen.')"
      />

      <FinancePagination
        :current-page="currencyPage"
        :total-pages="currencyTotalPages"
        :summary="translateScoped('pagination.summary', 'Página {page} de {totalPages}', { page: currencyPage, totalPages: currencyTotalPages })"
        :previous-label="translateScoped('pagination.previous', 'Anterior')"
        :next-label="translateScoped('pagination.next', 'Próxima')"
        @previous="currencyPage--"
        @next="currencyPage++"
      />

      <p v-if="!canWriteFinance" class="finance-muted-block">
        {{ translateScoped('permissions.readOnlyHint', 'Sua conta está em modo de leitura para configurações financeiras.') }}
      </p>
    </article>
  </section>

  <AppConfirmDialog
    :is-open="confirmRecurringDeleteState.isOpen"
    :title="translateScoped('recurringTypes.confirmDelete.title', 'Excluir tipo recorrente')"
    :message="translateScoped('recurringTypes.confirmDelete.message', 'Deseja realmente excluir {name}?', { name: confirmRecurringDeleteState.recurringTypeName || '-' })"
    :confirm-label="translateScoped('actions.delete', 'Excluir')"
    confirm-tone="danger"
    :processing="confirmRecurringDeleteState.processing"
    @cancel="closeDeleteRecurringTypeDialog"
    @confirm="confirmDeleteRecurringType"
  />
</template>
