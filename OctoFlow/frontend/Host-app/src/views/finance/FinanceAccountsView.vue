<script setup>
import {
  computed,
  nextTick,
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
import FinanceEntriesListPanel from '../../components/shared/FinanceEntriesListPanel.vue'
import { useFinancePermissions } from '../../composables/useFinancePermissions'
import { useNotification } from '../../composables/useNotification'
import { useScopedI18n } from '../../composables/useScopedI18n'
import {
  FINANCE_DIRECTION_OPTIONS,
  FINANCE_ENTRY_STATUS_OPTIONS,
  FINANCE_ENTRY_TYPE_OPTIONS,
  translateFinanceTerm,
} from '../../constants/financeTerms'
import { RemoteFinanceEmptyState } from '../../federation/remoteComponents'
import {
  createFinanceEntry,
  createFinanceInstallmentPlan,
  createFinanceRecurringRule,
  createFinanceSettlement,
  deleteFinanceEntry,
  deleteFinanceRecurringRule,
  fetchFinanceDashboardSummary,
  fetchFinanceEntries,
  fetchFinanceInstallmentPlans,
  fetchFinanceRecurringRules,
  generateFinanceRecurringRuleManually,
  renegotiateFinanceInstallmentPlan,
  updateFinanceEntry,
  updateFinanceRecurringRule,
} from '../../services/finance'
import { useFinanceStore } from '../../stores/financeStore'
import { buildCurrentMonthDateRange } from '../../utils/date'
import {
  sanitizeDateInput,
  sanitizeDateTimeLocalInput,
  sanitizeDecimal,
  sanitizeIdentifier,
  sanitizeInteger,
  sanitizeMonthInput,
  sanitizeSearchText,
  sanitizeSingleLineText,
} from '../../utils/financeInputSanitizers'
import { extractHttpMessage } from '../../utils/httpErrors'

const LIST_ITEMS_PER_PAGE = 10
const MAX_TITLE_LENGTH = 120
const MAX_REASON_LENGTH = 180

const financeStore = useFinanceStore()
const { categories, bankAccounts, recurringTypes } = storeToRefs(financeStore)

const { notifyUser } = useNotification()
const { translateScoped, currentLocale } = useScopedI18n('financeModule.accountsView')
const { canWriteFinance } = useFinancePermissions()

const directionOptions = FINANCE_DIRECTION_OPTIONS
const entryStatusOptions = FINANCE_ENTRY_STATUS_OPTIONS
const manualEntryTypeOptions = FINANCE_ENTRY_TYPE_OPTIONS.filter((entryTypeOption) => (
  ['ONE_OFF', 'ADJUSTMENT'].includes(entryTypeOption.value)
))

const activeAccountsTab = ref('overview')
const entriesState = ref([])
const entriesMeta = ref({ page: 1, itemsPerPage: LIST_ITEMS_PER_PAGE, total: 0 })
const recurringRules = ref([])
const installmentPlans = ref([])
const accountsOverviewSummary = ref(null)
const accountsOverviewMonth = ref(getNextMonthInputValue())

const loadingEntries = ref(false)
const loadingDashboard = ref(false)
const loadingRecurring = ref(false)
const loadingInstallments = ref(false)
const accountActionProcessing = ref(false)
const financeViewIsActive = ref(false)

const entryFilters = reactive({
  direction: '',
  status: '',
  search: '',
  startDate: '',
  endDate: '',
  page: 1,
  itemsPerPage: LIST_ITEMS_PER_PAGE,
})

const entryForm = reactive({
  direction: 'PAYABLE',
  entryType: 'ONE_OFF',
  title: '',
  expectedAmountBrl: '',
  dueDate: getCurrentDateInputValue(),
  categoryId: '',
  bankAccountId: '',
})
const entryEditingId = ref('')

const settlementForm = reactive({
  entryId: '',
  amountBrl: '',
  settledAt: '',
  bankAccountId: '',
})

const recurringForm = reactive({
  direction: 'PAYABLE',
  title: '',
  amountBrl: '',
  dayOfMonth: 5,
  startsAt: getCurrentDateInputValue(),
  recurringTypeId: '',
  categoryId: '',
  defaultBankAccountId: '',
})
const recurringRuleEditingId = ref('')

const installmentForm = reactive({
  direction: 'PAYABLE',
  title: '',
  totalAmountBrl: '',
  downPaymentBrl: '0',
  installmentsCount: 12,
  interestAmountBrl: '0',
  discountAmountBrl: '0',
  fineAmountBrl: '0',
  firstDueDate: '',
  categoryId: '',
  defaultBankAccountId: '',
})

const renegotiationForm = reactive({
  planId: '',
  installmentsCount: 6,
  reason: translateScoped('renegotiation.defaultReason', 'Renegociação manual'),
  categoryId: '',
  defaultBankAccountId: '',
})

const selectedAccountActionType = ref('ENTRY')
const accountActionModalState = reactive({
  isOpen: false,
  actionType: 'ENTRY',
})

const confirmDialogState = reactive({
  isOpen: false,
  title: '',
  message: '',
  confirmLabel: translateScoped('confirm.defaultAction', 'Confirmar'),
  confirmTone: 'danger',
  processing: false,
})
const confirmDialogAction = ref(null)

const localRecurringPage = ref(1)
const localInstallmentPage = ref(1)

const accountActionPrimaryButtonRef = ref(null)
const focusModalPrimaryActionAfterUpdate = ref(false)

const localeForFormatting = computed(() => (
  String(currentLocale.value || '').toLowerCase() === 'en-us' ? 'en-US' : 'pt-BR'
))

const accountsTabOptions = computed(() => ([
  {
    key: 'overview',
    label: translateScoped('tabs.overview', 'Visão geral'),
  },
  {
    key: 'payable',
    label: translateScoped('tabs.payable', 'Contas a pagar'),
  },
  {
    key: 'receivable',
    label: translateScoped('tabs.receivable', 'Contas a receber'),
  },
]))

const isOverview = computed(() => activeAccountsTab.value === 'overview')
const isPayable = computed(() => activeAccountsTab.value === 'payable')
const isReceivable = computed(() => activeAccountsTab.value === 'receivable')
const showEntryManagement = computed(() => isPayable.value || isReceivable.value)
const showRecurring = computed(() => isPayable.value || isReceivable.value)
const showInstallment = computed(() => isPayable.value)

const accountsDirectionByTab = computed(() => {
  if (isPayable.value) {
    return 'PAYABLE'
  }

  if (isReceivable.value) {
    return 'RECEIVABLE'
  }

  return ''
})

const accountsEntriesTitle = computed(() => {
  if (isPayable.value) {
    return translateScoped('entries.titlePayable', 'Lançamentos - Contas a pagar')
  }

  if (isReceivable.value) {
    return translateScoped('entries.titleReceivable', 'Lançamentos - Contas a receber')
  }

  return translateScoped('entries.titleUnified', 'Lançamentos unificados (pagar e receber)')
})

const totalsLabel = computed(() => {
  const totalEntries = Number(entriesMeta.value.total || 0)
  if (totalEntries <= 0) {
    return translateScoped('entries.totalsEmpty', '0 lançamentos')
  }

  const firstEntryIndex = (Number(entriesMeta.value.page || 1) - 1) * LIST_ITEMS_PER_PAGE + 1
  const lastEntryIndex = Math.min(firstEntryIndex + entriesState.value.length - 1, totalEntries)

  return translateScoped('entries.totalsRange', '{first}-{last} de {total} lançamentos', {
    first: firstEntryIndex,
    last: lastEntryIndex,
    total: totalEntries,
  })
})

const filteredRecurringRules = computed(() => {
  if (!showRecurring.value) {
    return []
  }

  const directionFilterByTab = accountsDirectionByTab.value

  return recurringRules.value.filter((recurringRule) => {
    const normalizedRuleDirection = String(recurringRule?.direction || '').trim().toUpperCase()
    return normalizedRuleDirection === directionFilterByTab
  })
})

const filteredInstallmentPlans = computed(() => {
  if (!showInstallment.value) {
    return []
  }

  return installmentPlans.value.filter((installmentPlan) => {
    const normalizedPlanDirection = String(installmentPlan?.direction || '').trim().toUpperCase()
    return normalizedPlanDirection === 'PAYABLE'
  })
})

const paginatedRecurringRules = computed(() => {
  const firstIndex = (localRecurringPage.value - 1) * LIST_ITEMS_PER_PAGE
  return filteredRecurringRules.value.slice(firstIndex, firstIndex + LIST_ITEMS_PER_PAGE)
})

const paginatedInstallmentPlans = computed(() => {
  const firstIndex = (localInstallmentPage.value - 1) * LIST_ITEMS_PER_PAGE
  return filteredInstallmentPlans.value.slice(firstIndex, firstIndex + LIST_ITEMS_PER_PAGE)
})

const accountsOverviewSelectedMonthLabel = computed(() => formatYearMonthLabel(accountsOverviewMonth.value))

const accountsOverviewComparisonRows = computed(() => {
  const summary = accountsOverviewSummary.value
  if (!summary) {
    return []
  }

  const expectedIncomeAmount = Number(summary.expectedIncomeBrl || 0)
  const realizedIncomeAmount = Number(summary.realizedIncomeBrl || 0)
  const expectedExpenseAmount = Number(summary.expectedExpenseBrl || 0)
  const realizedExpenseAmount = Number(summary.realizedExpenseBrl || 0)

  const remainingIncomeAmount = Math.max(0, roundMoney(expectedIncomeAmount - realizedIncomeAmount))
  const remainingExpenseAmount = Math.max(0, roundMoney(expectedExpenseAmount - realizedExpenseAmount))

  return [
    {
      key: 'receivable',
      label: translateScoped('overview.rows.receivable', 'Contas a receber'),
      expectedBrl: expectedIncomeAmount,
      realizedBrl: realizedIncomeAmount,
      remainingBrl: remainingIncomeAmount,
      progressPercent: expectedIncomeAmount > 0 ? roundMoney((realizedIncomeAmount / expectedIncomeAmount) * 100) : null,
    },
    {
      key: 'payable',
      label: translateScoped('overview.rows.payable', 'Contas a pagar'),
      expectedBrl: expectedExpenseAmount,
      realizedBrl: realizedExpenseAmount,
      remainingBrl: remainingExpenseAmount,
      progressPercent: expectedExpenseAmount > 0 ? roundMoney((realizedExpenseAmount / expectedExpenseAmount) * 100) : null,
    },
    {
      key: 'total',
      label: translateScoped('overview.rows.total', 'Total (Receber - Pagar)'),
      expectedBrl: roundMoney(expectedIncomeAmount - expectedExpenseAmount),
      realizedBrl: roundMoney(realizedIncomeAmount - realizedExpenseAmount),
      remainingBrl: roundMoney(remainingIncomeAmount - remainingExpenseAmount),
      progressPercent: null,
    },
  ]
})

const availableAccountActionOptions = computed(() => {
  const options = []

  if (showEntryManagement.value) {
    options.push(
      {
        value: 'ENTRY',
        label: translateScoped('actions.entry', 'Lançamento avulso'),
      },
      {
        value: 'SETTLEMENT',
        label: translateScoped('actions.settlement', 'Baixa de lançamento'),
      },
    )
  }

  if (showRecurring.value) {
    options.push({
      value: 'RECURRING_RULE',
      label: translateScoped('actions.recurringRule', 'Regra recorrente'),
    })
  }

  if (showInstallment.value) {
    options.push(
      {
        value: 'INSTALLMENT_PLAN',
        label: translateScoped('actions.installmentPlan', 'Plano de parcelamento'),
      },
      {
        value: 'RENEGOTIATION',
        label: translateScoped('actions.renegotiation', 'Renegociar plano'),
      },
    )
  }

  return options
})

const accountActionModalTitle = computed(() => {
  const actionType = accountActionModalState.actionType

  if (actionType === 'ENTRY') {
    return entryEditingId.value
      ? translateScoped('modal.titles.editEntry', 'Editar lançamento')
      : translateScoped('modal.titles.newEntry', 'Novo lançamento')
  }

  if (actionType === 'SETTLEMENT') {
    return translateScoped('modal.titles.settlement', 'Baixa de lançamento')
  }

  if (actionType === 'RECURRING_RULE') {
    return recurringRuleEditingId.value
      ? translateScoped('modal.titles.editRecurring', 'Editar recorrência')
      : translateScoped('modal.titles.newRecurring', 'Nova recorrência')
  }

  if (actionType === 'INSTALLMENT_PLAN') {
    return translateScoped('modal.titles.newInstallment', 'Novo parcelamento')
  }

  if (actionType === 'RENEGOTIATION') {
    return translateScoped('modal.titles.renegotiation', 'Renegociar plano')
  }

  return translateScoped('modal.titles.default', 'Gerenciar lançamento')
})

const entryTypeOptionsForForm = computed(() => {
  if (entryForm.direction === 'RECEIVABLE') {
    return manualEntryTypeOptions.filter((entryTypeOption) => entryTypeOption.value !== 'DEBT')
  }

  return manualEntryTypeOptions
})

const availableSettlementEntries = computed(() => {
  return entriesState.value.filter((entryItem) => {
    const remainingAmount = Number(entryItem?.remainingAmountBrl || 0)
    const normalizedStatus = String(entryItem?.status || '').trim().toUpperCase()

    return remainingAmount > 0 && !['PAID', 'RECEIVED', 'CANCELED'].includes(normalizedStatus)
  })
})

const selectedSettlementEntry = computed(() => {
  const normalizedEntryId = sanitizeIdentifier(settlementForm.entryId)

  return availableSettlementEntries.value.find((entryItem) => (
    sanitizeIdentifier(entryItem?.id) === normalizedEntryId
  )) || null
})

const availableRecurringTypes = computed(() => (
  recurringTypes.value.filter((recurringType) => String(recurringType?.name || '').trim().toLowerCase() !== 'mensal')
))

function getCurrentDateInputValue() {
  return new Date().toISOString().slice(0, 10)
}

function getNextMonthInputValue() {
  const nextMonthDate = new Date()
  nextMonthDate.setMonth(nextMonthDate.getMonth() + 1)

  return `${nextMonthDate.getFullYear()}-${String(nextMonthDate.getMonth() + 1).padStart(2, '0')}`
}

function formatYearMonthLabel(rawMonthInput) {
  const normalizedMonthInput = sanitizeMonthInput(rawMonthInput)
  if (normalizedMonthInput === '') {
    return ''
  }

  const [yearPart, monthPart] = normalizedMonthInput.split('-')
  const formattedDate = new Date(Number(yearPart), Number(monthPart) - 1, 1)

  return formattedDate.toLocaleDateString(localeForFormatting.value, {
    month: 'long',
    year: 'numeric',
  })
}

function formatCurrency(rawValue) {
  return new Intl.NumberFormat(localeForFormatting.value, {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(Number(rawValue || 0))
}

function formatPercent(rawValue) {
  return `${Number(rawValue || 0).toFixed(1)}%`
}

function roundMoney(rawValue) {
  return Math.round(Number(rawValue || 0) * 100) / 100
}

function getFinanceLabel(termCode, fallbackLabel = '-') {
  return translateFinanceTerm(termCode, fallbackLabel)
}

function sanitizeFilterByCatalog(rawValue, catalogOptions = []) {
  const normalizedValue = String(rawValue || '').trim().toUpperCase()
  if (normalizedValue === '') {
    return ''
  }

  const allowedValues = catalogOptions
    .map((catalogOption) => String(catalogOption?.value || '').trim().toUpperCase())
    .filter((catalogValue) => catalogValue !== '')

  return allowedValues.includes(normalizedValue) ? normalizedValue : ''
}

function normalizeEntryFilters(rawFilters = {}) {
  let normalizedStartDate = sanitizeDateInput(rawFilters.startDate)
  let normalizedEndDate = sanitizeDateInput(rawFilters.endDate)

  if (normalizedStartDate !== '' && normalizedEndDate !== '' && normalizedStartDate > normalizedEndDate) {
    const previousStartDate = normalizedStartDate
    normalizedStartDate = normalizedEndDate
    normalizedEndDate = previousStartDate
  }

  return {
    direction: sanitizeFilterByCatalog(rawFilters.direction, directionOptions),
    status: sanitizeFilterByCatalog(rawFilters.status, entryStatusOptions),
    search: sanitizeSearchText(rawFilters.search, 180),
    startDate: normalizedStartDate,
    endDate: normalizedEndDate,
    page: sanitizeInteger(rawFilters.page, {
      min: 1,
      max: 99999,
      defaultValue: 1,
    }),
    itemsPerPage: LIST_ITEMS_PER_PAGE,
  }
}

function normalizeDirection(rawDirection, fallbackDirection = 'PAYABLE') {
  const normalizedDirection = String(rawDirection || '').trim().toUpperCase()
  const allowedDirections = directionOptions.map((option) => option.value)

  return allowedDirections.includes(normalizedDirection) ? normalizedDirection : fallbackDirection
}

function normalizeEntryType(rawEntryType) {
  const normalizedEntryType = String(rawEntryType || '').trim().toUpperCase()
  return manualEntryTypeOptions.some((entryTypeOption) => entryTypeOption.value === normalizedEntryType)
    ? normalizedEntryType
    : 'ONE_OFF'
}

function normalizeActionType(rawActionType) {
  const normalizedActionType = String(rawActionType || '').trim().toUpperCase()
  const allowedActionTypes = availableAccountActionOptions.value.map((option) => option.value)

  return allowedActionTypes.includes(normalizedActionType)
    ? normalizedActionType
    : (allowedActionTypes[0] || 'ENTRY')
}

function canMutateFinance() {
  if (!canWriteFinance.value) {
    notifyUser(
      translateScoped('notifications.writeDenied', 'Você não possui permissão para alterar dados financeiros.'),
      'warning',
    )
    return false
  }

  return true
}

function resetEntryForm() {
  Object.assign(entryForm, {
    direction: normalizeDirection(accountsDirectionByTab.value || 'PAYABLE'),
    entryType: 'ONE_OFF',
    title: '',
    expectedAmountBrl: '',
    dueDate: getCurrentDateInputValue(),
    categoryId: '',
    bankAccountId: '',
  })

  entryEditingId.value = ''
}

function resetSettlementForm() {
  Object.assign(settlementForm, {
    entryId: '',
    amountBrl: '',
    settledAt: '',
    bankAccountId: '',
  })
}

function resetRecurringForm() {
  Object.assign(recurringForm, {
    direction: normalizeDirection(accountsDirectionByTab.value || 'PAYABLE'),
    title: '',
    amountBrl: '',
    dayOfMonth: 5,
    startsAt: getCurrentDateInputValue(),
    recurringTypeId: '',
    categoryId: '',
    defaultBankAccountId: '',
  })

  recurringRuleEditingId.value = ''
}

function resetInstallmentForm() {
  Object.assign(installmentForm, {
    direction: 'PAYABLE',
    title: '',
    totalAmountBrl: '',
    downPaymentBrl: '0',
    installmentsCount: 12,
    interestAmountBrl: '0',
    discountAmountBrl: '0',
    fineAmountBrl: '0',
    firstDueDate: '',
    categoryId: '',
    defaultBankAccountId: '',
  })
}

function resetRenegotiationForm() {
  Object.assign(renegotiationForm, {
    planId: '',
    installmentsCount: 6,
    reason: translateScoped('renegotiation.defaultReason', 'Renegociação manual'),
    categoryId: '',
    defaultBankAccountId: '',
  })
}

function resetAllAccountForms() {
  resetEntryForm()
  resetSettlementForm()
  resetRecurringForm()
  resetInstallmentForm()
  resetRenegotiationForm()
}

function focusModalPrimaryActionButton() {
  if (!accountActionModalState.isOpen) {
    return
  }

  const modalPrimaryActionButton = accountActionPrimaryButtonRef.value
  if (modalPrimaryActionButton && typeof modalPrimaryActionButton.focus === 'function') {
    modalPrimaryActionButton.focus({ preventScroll: true })
  }
}

async function loadEntries(showNotificationOnError = false) {
  loadingEntries.value = true

  const normalizedFilters = normalizeEntryFilters(entryFilters)
  Object.assign(entryFilters, normalizedFilters)
  const requestFilters = { ...normalizedFilters }
  if (accountsDirectionByTab.value !== '') {
    requestFilters.direction = accountsDirectionByTab.value
  }

  try {
    const response = await fetchFinanceEntries(
      {
        direction: requestFilters.direction,
        status: requestFilters.status,
        search: requestFilters.search,
        startDate: requestFilters.startDate,
        endDate: requestFilters.endDate,
      },
      {
        page: normalizedFilters.page,
        itemsPerPage: LIST_ITEMS_PER_PAGE,
      },
    )

    if (!financeViewIsActive.value) {
      return
    }

    entriesState.value = Array.isArray(response.data?.items) ? response.data.items : []

    const responseMeta = response.data?.meta || response.data?.item || {}
    entriesMeta.value = {
      page: sanitizeInteger(responseMeta.page || normalizedFilters.page, {
        min: 1,
        defaultValue: 1,
      }),
      itemsPerPage: LIST_ITEMS_PER_PAGE,
      total: sanitizeInteger(responseMeta.totalItems || responseMeta.total, {
        min: 0,
        defaultValue: 0,
      }),
    }
  } catch (error) {
    if (showNotificationOnError) {
      notifyUser(
        extractHttpMessage(error, translateScoped('notifications.loadEntriesError', 'Erro ao carregar lançamentos.')),
        'error',
      )
    }
  } finally {
    loadingEntries.value = false
  }
}

async function loadOverviewSummary(showNotificationOnError = false) {
  loadingDashboard.value = true

  try {
    const normalizedMonth = sanitizeMonthInput(accountsOverviewMonth.value)
    if (normalizedMonth === '') {
      accountsOverviewMonth.value = getNextMonthInputValue()
    }

    const [yearPart, monthPart] = (sanitizeMonthInput(accountsOverviewMonth.value) || getNextMonthInputValue()).split('-')
    const referenceDate = new Date()
    referenceDate.setFullYear(Number(yearPart))
    referenceDate.setMonth(Number(monthPart) - 1)

    const dateRange = buildCurrentMonthDateRange(referenceDate)
    const response = await fetchFinanceDashboardSummary(dateRange)

    if (!financeViewIsActive.value) {
      return
    }

    accountsOverviewSummary.value = response.data?.item || null
  } catch (error) {
    if (showNotificationOnError) {
      notifyUser(
        extractHttpMessage(error, translateScoped('notifications.loadOverviewError', 'Erro ao carregar resumo de contas.')),
        'error',
      )
    }
  } finally {
    loadingDashboard.value = false
  }
}

async function loadRecurringRules(showNotificationOnError = false) {
  loadingRecurring.value = true

  try {
    const response = await fetchFinanceRecurringRules()

    if (!financeViewIsActive.value) {
      return
    }

    recurringRules.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (error) {
    if (showNotificationOnError) {
      notifyUser(
        extractHttpMessage(error, translateScoped('notifications.loadRecurringError', 'Erro ao carregar recorrências.')),
        'error',
      )
    }
  } finally {
    loadingRecurring.value = false
  }
}

async function loadInstallments(showNotificationOnError = false) {
  loadingInstallments.value = true

  try {
    const response = await fetchFinanceInstallmentPlans()

    if (!financeViewIsActive.value) {
      return
    }

    installmentPlans.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (error) {
    if (showNotificationOnError) {
      notifyUser(
        extractHttpMessage(error, translateScoped('notifications.loadInstallmentsError', 'Erro ao carregar parcelamentos.')),
        'error',
      )
    }
  } finally {
    loadingInstallments.value = false
  }
}

async function loadTabData(showNotificationOnError = false) {
  if (!financeViewIsActive.value) {
    return
  }

  if (activeAccountsTab.value === 'overview') {
    await Promise.all([
      loadEntries(showNotificationOnError),
      loadOverviewSummary(showNotificationOnError),
    ])

    return
  }

  await Promise.all([
    loadEntries(showNotificationOnError),
    loadRecurringRules(showNotificationOnError),
    loadInstallments(showNotificationOnError),
  ])
}

function startEditingEntry(entryItem) {
  entryForm.direction = normalizeDirection(entryItem?.direction, 'PAYABLE')
  entryForm.entryType = normalizeEntryType(entryItem?.entryType)
  entryForm.title = sanitizeSingleLineText(entryItem?.title, MAX_TITLE_LENGTH)

  const expectedAmount = sanitizeDecimal(entryItem?.expectedAmountBrl, {
    min: 0,
    max: 999999999,
    decimals: 2,
    defaultValue: 0,
  })
  entryForm.expectedAmountBrl = String(expectedAmount)

  entryForm.dueDate = sanitizeDateInput(entryItem?.dueDate)
    || getCurrentDateInputValue()
  entryForm.categoryId = sanitizeIdentifier(entryItem?.categoryId)
  entryForm.bankAccountId = sanitizeIdentifier(entryItem?.bankAccountId)
  entryEditingId.value = sanitizeIdentifier(entryItem?.id)

  openAccountActionModal('ENTRY')
}

function buildEntryPayload() {
  const normalizedDirection = normalizeDirection(entryForm.direction, normalizeDirection(accountsDirectionByTab.value || 'PAYABLE'))

  return {
    direction: normalizedDirection,
    entryType: normalizeEntryType(entryForm.entryType),
    title: sanitizeSingleLineText(entryForm.title, MAX_TITLE_LENGTH),
    expectedAmountBrl: sanitizeDecimal(entryForm.expectedAmountBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    dueDate: sanitizeDateInput(entryForm.dueDate),
    categoryId: sanitizeIdentifier(entryForm.categoryId) || undefined,
    bankAccountId: sanitizeIdentifier(entryForm.bankAccountId) || undefined,
  }
}

async function submitEntry() {
  if (!canMutateFinance() || accountActionProcessing.value) {
    return
  }

  const payload = buildEntryPayload()

  if (payload.title === '' || payload.expectedAmountBrl <= 0 || payload.dueDate === '') {
    notifyUser(translateScoped('notifications.entryInvalid', 'Preencha título, valor e vencimento válidos.'), 'warning')
    return
  }

  accountActionProcessing.value = true

  try {
    const normalizedEntryId = sanitizeIdentifier(entryEditingId.value)
    if (normalizedEntryId !== '') {
      await updateFinanceEntry(normalizedEntryId, payload)
      notifyUser(translateScoped('notifications.entryUpdated', 'Lançamento atualizado com sucesso.'), 'success')
    } else {
      await createFinanceEntry(payload)
      notifyUser(translateScoped('notifications.entryCreated', 'Lançamento criado com sucesso.'), 'success')
    }

    closeAccountActionModal()
    await loadEntries(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.entrySaveError', 'Erro ao salvar lançamento.')), 'error')
  } finally {
    accountActionProcessing.value = false
  }
}

function requestDeleteEntry(entryItem) {
  const normalizedEntryId = sanitizeIdentifier(entryItem?.id)
  if (normalizedEntryId === '') {
    notifyUser(translateScoped('notifications.invalidEntry', 'Lançamento inválido para exclusão.'), 'warning')
    return
  }

  confirmDialogState.isOpen = true
  confirmDialogState.title = translateScoped('confirm.deleteEntryTitle', 'Excluir lançamento')
  confirmDialogState.message = translateScoped('confirm.deleteEntryMessage', 'Tem certeza que deseja excluir "{title}"?', {
    title: sanitizeSingleLineText(entryItem?.title, MAX_TITLE_LENGTH),
  })
  confirmDialogState.confirmLabel = translateScoped('actions.delete', 'Excluir')
  confirmDialogState.confirmTone = 'danger'

  confirmDialogAction.value = async () => {
    confirmDialogState.processing = true

    try {
      await deleteFinanceEntry(normalizedEntryId)
      notifyUser(translateScoped('notifications.entryDeleted', 'Lançamento removido com sucesso.'), 'success')
      await loadEntries(false)
    } catch (error) {
      notifyUser(extractHttpMessage(error, translateScoped('notifications.entryDeleteError', 'Erro ao excluir lançamento.')), 'error')
    } finally {
      confirmDialogState.processing = false
      closeConfirmDialog()
    }
  }
}

function buildSettlementPayload() {
  return {
    entryId: sanitizeIdentifier(settlementForm.entryId),
    amountBrl: sanitizeDecimal(settlementForm.amountBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    settledAt: sanitizeDateTimeLocalInput(settlementForm.settledAt),
    bankAccountId: sanitizeIdentifier(settlementForm.bankAccountId),
  }
}

async function submitSettlement() {
  if (!canMutateFinance() || accountActionProcessing.value) {
    return
  }

  const payload = buildSettlementPayload()
  if (payload.entryId === '' || payload.amountBrl <= 0) {
    notifyUser(translateScoped('notifications.settlementInvalid', 'Selecione um lançamento e um valor de baixa válido.'), 'warning')
    return
  }

  const requestPayload = { amountBrl: payload.amountBrl }
  if (payload.settledAt !== '') {
    requestPayload.settledAt = payload.settledAt
  }
  if (payload.bankAccountId !== '') {
    requestPayload.bankAccountId = payload.bankAccountId
  }

  accountActionProcessing.value = true

  try {
    await createFinanceSettlement(payload.entryId, requestPayload)
    notifyUser(translateScoped('notifications.settlementCreated', 'Baixa registrada com sucesso.'), 'success')
    closeAccountActionModal()
    await loadEntries(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.settlementError', 'Erro ao registrar baixa.')), 'error')
  } finally {
    accountActionProcessing.value = false
  }
}

function startEditingRecurringRule(recurringRule) {
  recurringForm.direction = normalizeDirection(recurringRule?.direction, 'PAYABLE')
  recurringForm.title = sanitizeSingleLineText(recurringRule?.title, MAX_TITLE_LENGTH)
  recurringForm.amountBrl = String(sanitizeDecimal(recurringRule?.amountBrl, {
    min: 0,
    max: 999999999,
    decimals: 2,
    defaultValue: 0,
  }))
  recurringForm.dayOfMonth = sanitizeInteger(recurringRule?.dayOfMonth, {
    min: 1,
    max: 31,
    defaultValue: 5,
  })
  recurringForm.startsAt = sanitizeDateInput(recurringRule?.startsAt) || getCurrentDateInputValue()
  recurringForm.recurringTypeId = sanitizeIdentifier(recurringRule?.recurringTypeId)
  recurringForm.categoryId = sanitizeIdentifier(recurringRule?.categoryId)
  recurringForm.defaultBankAccountId = sanitizeIdentifier(recurringRule?.defaultBankAccountId)

  recurringRuleEditingId.value = sanitizeIdentifier(recurringRule?.id)
  openAccountActionModal('RECURRING_RULE')
}

function buildRecurringPayload() {
  return {
    direction: normalizeDirection(recurringForm.direction, normalizeDirection(accountsDirectionByTab.value || 'PAYABLE')),
    title: sanitizeSingleLineText(recurringForm.title, MAX_TITLE_LENGTH),
    amountBrl: sanitizeDecimal(recurringForm.amountBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    dayOfMonth: sanitizeInteger(recurringForm.dayOfMonth, {
      min: 1,
      max: 31,
      defaultValue: 5,
    }),
    startsAt: sanitizeDateInput(recurringForm.startsAt),
    recurringTypeId: sanitizeIdentifier(recurringForm.recurringTypeId),
    categoryId: sanitizeIdentifier(recurringForm.categoryId) || undefined,
    defaultBankAccountId: sanitizeIdentifier(recurringForm.defaultBankAccountId) || undefined,
  }
}

async function submitRecurringRule() {
  if (!canMutateFinance() || accountActionProcessing.value) {
    return
  }

  const payload = buildRecurringPayload()

  if (
    payload.title === ''
    || payload.amountBrl <= 0
    || payload.startsAt === ''
    || payload.recurringTypeId === ''
  ) {
    notifyUser(translateScoped('notifications.recurringInvalid', 'Preencha título, valor, início e tipo recorrente válidos.'), 'warning')
    return
  }

  accountActionProcessing.value = true

  try {
    const normalizedRecurringRuleId = sanitizeIdentifier(recurringRuleEditingId.value)

    if (normalizedRecurringRuleId !== '') {
      await updateFinanceRecurringRule(normalizedRecurringRuleId, payload)
      notifyUser(translateScoped('notifications.recurringUpdated', 'Recorrência atualizada com sucesso.'), 'success')
    } else {
      await createFinanceRecurringRule(payload)
      notifyUser(translateScoped('notifications.recurringCreated', 'Recorrência criada com sucesso.'), 'success')
    }

    closeAccountActionModal()
    await loadRecurringRules(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.recurringSaveError', 'Erro ao salvar recorrência.')), 'error')
  } finally {
    accountActionProcessing.value = false
  }
}

function requestDeleteRecurringRule(recurringRule) {
  const normalizedRecurringRuleId = sanitizeIdentifier(recurringRule?.id)
  if (normalizedRecurringRuleId === '') {
    notifyUser(translateScoped('notifications.invalidRecurring', 'Recorrência inválida.'), 'warning')
    return
  }

  confirmDialogState.isOpen = true
  confirmDialogState.title = translateScoped('confirm.deleteRecurringTitle', 'Excluir recorrência')
  confirmDialogState.message = translateScoped('confirm.deleteRecurringMessage', 'Excluir "{title}"?', {
    title: sanitizeSingleLineText(recurringRule?.title, MAX_TITLE_LENGTH),
  })
  confirmDialogState.confirmLabel = translateScoped('actions.delete', 'Excluir')
  confirmDialogState.confirmTone = 'danger'

  confirmDialogAction.value = async () => {
    confirmDialogState.processing = true

    try {
      await deleteFinanceRecurringRule(normalizedRecurringRuleId)
      notifyUser(translateScoped('notifications.recurringDeleted', 'Recorrência removida com sucesso.'), 'success')
      await loadRecurringRules(false)
    } catch (error) {
      notifyUser(extractHttpMessage(error, translateScoped('notifications.recurringDeleteError', 'Erro ao excluir recorrência.')), 'error')
    } finally {
      confirmDialogState.processing = false
      closeConfirmDialog()
    }
  }
}

async function generateRecurringManually(recurringRule) {
  if (!canMutateFinance()) {
    return
  }

  const normalizedRecurringRuleId = sanitizeIdentifier(recurringRule?.id)
  if (normalizedRecurringRuleId === '') {
    notifyUser(translateScoped('notifications.invalidRecurring', 'Recorrência inválida.'), 'warning')
    return
  }

  accountActionProcessing.value = true

  try {
    await generateFinanceRecurringRuleManually(normalizedRecurringRuleId)
    notifyUser(translateScoped('notifications.recurringGenerated', 'Lançamento gerado com sucesso.'), 'success')
    await loadEntries(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.recurringGenerateError', 'Erro ao gerar lançamento manualmente.')), 'error')
  } finally {
    accountActionProcessing.value = false
  }
}

function buildInstallmentPayload() {
  return {
    direction: 'PAYABLE',
    title: sanitizeSingleLineText(installmentForm.title, MAX_TITLE_LENGTH),
    totalAmountBrl: sanitizeDecimal(installmentForm.totalAmountBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    downPaymentBrl: sanitizeDecimal(installmentForm.downPaymentBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    installmentsCount: sanitizeInteger(installmentForm.installmentsCount, {
      min: 1,
      max: 480,
      defaultValue: 1,
    }),
    interestAmountBrl: sanitizeDecimal(installmentForm.interestAmountBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    discountAmountBrl: sanitizeDecimal(installmentForm.discountAmountBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    fineAmountBrl: sanitizeDecimal(installmentForm.fineAmountBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    firstDueDate: sanitizeDateInput(installmentForm.firstDueDate) || undefined,
    categoryId: sanitizeIdentifier(installmentForm.categoryId) || undefined,
    defaultBankAccountId: sanitizeIdentifier(installmentForm.defaultBankAccountId) || undefined,
  }
}

async function submitInstallmentPlan() {
  if (!canMutateFinance() || accountActionProcessing.value) {
    return
  }

  const payload = buildInstallmentPayload()

  if (payload.title === '' || payload.totalAmountBrl <= 0) {
    notifyUser(translateScoped('notifications.installmentInvalid', 'Informe título e valor total válidos para o parcelamento.'), 'warning')
    return
  }

  accountActionProcessing.value = true

  try {
    await createFinanceInstallmentPlan(payload)
    notifyUser(translateScoped('notifications.installmentCreated', 'Parcelamento criado com sucesso.'), 'success')
    closeAccountActionModal()
    await Promise.all([
      loadInstallments(false),
      loadEntries(false),
    ])
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.installmentError', 'Erro ao criar parcelamento.')), 'error')
  } finally {
    accountActionProcessing.value = false
  }
}

function buildRenegotiationPayload() {
  return {
    planId: sanitizeIdentifier(renegotiationForm.planId),
    installmentsCount: sanitizeInteger(renegotiationForm.installmentsCount, {
      min: 1,
      max: 480,
      defaultValue: 1,
    }),
    reason: sanitizeSingleLineText(renegotiationForm.reason, MAX_REASON_LENGTH),
    categoryId: sanitizeIdentifier(renegotiationForm.categoryId) || undefined,
    defaultBankAccountId: sanitizeIdentifier(renegotiationForm.defaultBankAccountId) || undefined,
  }
}

async function submitRenegotiation() {
  if (!canMutateFinance() || accountActionProcessing.value) {
    return
  }

  const payload = buildRenegotiationPayload()

  if (payload.planId === '' || payload.reason === '') {
    notifyUser(translateScoped('notifications.renegotiationInvalid', 'Selecione um plano e informe um motivo válido.'), 'warning')
    return
  }

  accountActionProcessing.value = true

  try {
    await renegotiateFinanceInstallmentPlan(payload.planId, {
      installmentsCount: payload.installmentsCount,
      reason: payload.reason,
      categoryId: payload.categoryId,
      defaultBankAccountId: payload.defaultBankAccountId,
    })

    notifyUser(translateScoped('notifications.renegotiationSuccess', 'Plano renegociado com sucesso.'), 'success')
    closeAccountActionModal()
    await Promise.all([
      loadInstallments(false),
      loadEntries(false),
    ])
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.renegotiationError', 'Erro ao renegociar plano.')), 'error')
  } finally {
    accountActionProcessing.value = false
  }
}

function openAccountActionModal(actionType) {
  if (!canMutateFinance()) {
    return
  }

  const normalizedActionType = normalizeActionType(actionType || selectedAccountActionType.value)

  selectedAccountActionType.value = normalizedActionType
  accountActionModalState.actionType = normalizedActionType
  accountActionModalState.isOpen = true

  focusModalPrimaryActionAfterUpdate.value = true

  if (normalizedActionType === 'ENTRY' && entryEditingId.value === '') {
    resetEntryForm()
  }
}

function closeAccountActionModal() {
  if (accountActionProcessing.value) {
    return
  }

  accountActionModalState.isOpen = false
  accountActionModalState.actionType = 'ENTRY'
  resetAllAccountForms()
}

function handleModalOverlayClose() {
  if (accountActionProcessing.value) {
    return
  }

  closeAccountActionModal()
}

function closeConfirmDialog() {
  if (confirmDialogState.processing) {
    return
  }

  confirmDialogState.isOpen = false
  confirmDialogState.title = ''
  confirmDialogState.message = ''
  confirmDialogState.confirmLabel = translateScoped('confirm.defaultAction', 'Confirmar')
  confirmDialogState.confirmTone = 'danger'
  confirmDialogAction.value = null
}

function handleConfirmDialogAction() {
  if (typeof confirmDialogAction.value === 'function') {
    void confirmDialogAction.value()
  }
}

function openSelectedAccountActionModal() {
  openAccountActionModal(selectedAccountActionType.value)
}

function applyEntryFilters(rawFilters) {
  const normalizedFilters = normalizeEntryFilters({
    ...entryFilters,
    ...rawFilters,
    page: 1,
  })

  Object.assign(entryFilters, normalizedFilters)
  void loadEntries(false)
}

function setEntriesPage(nextPage) {
  const normalizedPage = sanitizeInteger(nextPage, {
    min: 1,
    max: 99999,
    defaultValue: 1,
  })

  entryFilters.page = normalizedPage
  void loadEntries(false)
}

async function handleAccountsOverviewMonthChange() {
  accountsOverviewMonth.value = sanitizeMonthInput(accountsOverviewMonth.value) || getNextMonthInputValue()
  await loadOverviewSummary(false)
}

async function refreshAccountsOverviewValues() {
  await Promise.all([
    loadOverviewSummary(false),
    loadEntries(false),
  ])
}

function formatSettlementEntryOptionLabel(entryItem) {
  return `${entryItem.title} — ${formatCurrency(entryItem.remainingAmountBrl)} ${translateScoped('settlement.remainingSuffix', 'restante')}`
}

watch(activeAccountsTab, () => {
  const normalizedTab = String(activeAccountsTab.value || '').trim().toLowerCase()
  if (!['overview', 'payable', 'receivable'].includes(normalizedTab)) {
    activeAccountsTab.value = 'overview'
    return
  }

  entryFilters.page = 1
  localRecurringPage.value = 1
  localInstallmentPage.value = 1

  if (accountActionModalState.isOpen) {
    closeAccountActionModal()
  }

  void loadTabData(false)
})

watch(
  () => settlementForm.entryId,
  () => {
    const remainingAmount = Number(selectedSettlementEntry.value?.remainingAmountBrl || 0)
    if (remainingAmount > 0) {
      settlementForm.amountBrl = remainingAmount.toFixed(2)
    }
  },
)

watch(
  () => accountActionModalState.isOpen,
  (isModalOpen) => {
    if (!isModalOpen) {
      focusModalPrimaryActionAfterUpdate.value = false
      return
    }

    focusModalPrimaryActionAfterUpdate.value = true
  },
)

watch(availableAccountActionOptions, (nextOptions) => {
  const allowedActionTypes = nextOptions.map((option) => option.value)
  if (!allowedActionTypes.includes(selectedAccountActionType.value)) {
    selectedAccountActionType.value = allowedActionTypes[0] || 'ENTRY'
  }
}, { immediate: true })

onBeforeMount(() => {
  void financeStore.loadCatalogs(false)
})

onMounted(() => {
  financeViewIsActive.value = true
  void loadTabData(true)
})

onBeforeUpdate(() => {
  if (isOverview.value) {
    accountsOverviewMonth.value = sanitizeMonthInput(accountsOverviewMonth.value) || getNextMonthInputValue()
  }
})

onUpdated(() => {
  if (focusModalPrimaryActionAfterUpdate.value) {
    focusModalPrimaryActionAfterUpdate.value = false
    void nextTick(() => {
      focusModalPrimaryActionButton()
    })
  }
})

onActivated(() => {
  financeViewIsActive.value = true
  void loadTabData(false)
})

onDeactivated(() => {
  financeViewIsActive.value = false
  accountActionProcessing.value = false
})

onBeforeUnmount(() => {
  financeViewIsActive.value = false
  accountActionProcessing.value = false
  closeConfirmDialog()
  if (accountActionModalState.isOpen) {
    accountActionModalState.isOpen = false
  }
})

onUnmounted(() => {
  resetAllAccountForms()
  confirmDialogAction.value = null
})

onErrorCaptured((error) => {
  console.error('[FinanceAccountsView] child render error:', error)
  notifyUser(
    translateScoped('notifications.childRenderError', 'Erro inesperado ao renderizar contas financeiras.'),
    'error',
  )
  return false
})
</script>

<template>
  <section class="finance-section">
    <nav
      class="finance-nav-tabs"
      :aria-label="translateScoped('tabs.navigationAriaLabel', 'Abas de contas')"
    >
      <button
        v-for="tabOption in accountsTabOptions"
        :key="tabOption.key"
        type="button"
        class="finance-nav-tabs-button"
        :class="{ active: activeAccountsTab === tabOption.key }"
        @click="activeAccountsTab = tabOption.key"
      >
        {{ tabOption.label }}
      </button>
    </nav>

    <article v-if="isOverview" class="finance-panel">
      <header>
        <div>
          <h3>{{ translateScoped('overview.title', 'Resumo rápido: pagar × receber') }}</h3>
          <small>
            {{ translateScoped('overview.subtitle', 'Mostrando o previsto de {month}.', {
              month: accountsOverviewSelectedMonthLabel,
            }) }}
          </small>
        </div>

        <div class="finance-form-actions">
          <label class="finance-overview-month-field">
            <span>{{ translateScoped('overview.monthLabel', 'Mês') }}</span>
            <input v-model="accountsOverviewMonth" type="month" @change="handleAccountsOverviewMonthChange">
          </label>

          <button
            type="button"
            class="finance-inline-action"
            :disabled="loadingDashboard"
            @click="refreshAccountsOverviewValues"
          >
            {{ loadingDashboard
              ? translateScoped('actions.updating', 'Atualizando...')
              : translateScoped('actions.refreshValues', 'Atualizar valores') }}
          </button>
        </div>
      </header>

      <div v-if="accountsOverviewComparisonRows.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('overview.table.type', 'Tipo') }}</th>
              <th>{{ translateScoped('overview.table.expected', 'Previsto') }}</th>
              <th>{{ translateScoped('overview.table.realized', 'Realizado') }}</th>
              <th>{{ translateScoped('overview.table.remaining', 'Restante') }}</th>
              <th>{{ translateScoped('overview.table.execution', 'Execução') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in accountsOverviewComparisonRows"
              :key="row.key"
              :class="{ 'finance-total-summary-row': row.key === 'total' }"
            >
              <td>{{ row.label }}</td>
              <td>{{ formatCurrency(row.expectedBrl) }}</td>
              <td>{{ formatCurrency(row.realizedBrl) }}</td>
              <td>{{ formatCurrency(row.remainingBrl) }}</td>
              <td>{{ row.progressPercent === null ? '-' : formatPercent(row.progressPercent) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <RemoteFinanceEmptyState
        v-else
        :title="translateScoped('overview.empty.title', 'Sem dados')"
        :description="translateScoped('overview.empty.description', 'Cadastre lançamentos para visualizar o comparativo.')"
      />
    </article>

    <article v-if="showEntryManagement" class="finance-panel">
      <header>
        <h3>{{ translateScoped('management.title', 'Gerenciamento de lançamentos') }}</h3>
      </header>

      <div class="finance-form-grid">
        <label>
          <span>{{ translateScoped('management.actionLabel', 'O que deseja adicionar') }}</span>
          <select v-model="selectedAccountActionType" :disabled="!canWriteFinance || accountActionProcessing">
            <option
              v-for="actionOption in availableAccountActionOptions"
              :key="actionOption.value"
              :value="actionOption.value"
            >
              {{ actionOption.label }}
            </option>
          </select>
        </label>

        <button
          type="button"
          class="finance-action-button"
          :disabled="!canWriteFinance || accountActionProcessing"
          @click="openSelectedAccountActionModal"
        >
          {{ translateScoped('actions.add', 'Adicionar') }}
        </button>
      </div>

      <p v-if="!canWriteFinance" class="finance-muted-block">
        {{ translateScoped('notifications.writeDenied', 'Você não possui permissão para alterar dados financeiros.') }}
      </p>
    </article>

    <FinanceEntriesListPanel
      :panel-title="accountsEntriesTitle"
      :totals-label="totalsLabel"
      :entries-items="entriesState"
      :entries-meta="entriesMeta"
      :filters="entryFilters"
      :show-direction-filter="isOverview"
      :direction-options="directionOptions"
      :status-options="entryStatusOptions"
      :resolve-finance-label="getFinanceLabel"
      :loading="loadingEntries"
      @submit-filters="applyEntryFilters"
      @set-page="setEntriesPage"
      @edit-entry="startEditingEntry"
      @delete-entry="requestDeleteEntry"
    />

    <article v-if="showRecurring && filteredRecurringRules.length > 0" class="finance-panel">
      <header>
        <h3>{{ translateScoped('recurring.title', 'Regras recorrentes') }}</h3>
        <small>
          {{ translateScoped('recurring.summary', '{count} regra(s)', {
            count: filteredRecurringRules.length,
          }) }}
        </small>
      </header>

      <div class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('recurring.table.title', 'Título') }}</th>
              <th>{{ translateScoped('recurring.table.amount', 'Valor') }}</th>
              <th>{{ translateScoped('recurring.table.day', 'Dia') }}</th>
              <th>{{ translateScoped('recurring.table.type', 'Tipo') }}</th>
              <th>{{ translateScoped('recurring.table.actions', 'Ação') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="recurringRule in paginatedRecurringRules" :key="recurringRule.id">
              <td><strong>{{ recurringRule.title }}</strong></td>
              <td>{{ formatCurrency(recurringRule.amountBrl) }}</td>
              <td>{{ recurringRule.dayOfMonth }}</td>
              <td>{{ recurringRule.recurringTypeName || '-' }}</td>
              <td class="finance-actions-cell">
                <button
                  type="button"
                  class="finance-inline-action"
                  :disabled="accountActionProcessing || !canWriteFinance"
                  @click="startEditingRecurringRule(recurringRule)"
                >
                  {{ translateScoped('actions.edit', 'Editar') }}
                </button>
                <button
                  type="button"
                  class="finance-inline-action"
                  :disabled="accountActionProcessing || !canWriteFinance"
                  @click="generateRecurringManually(recurringRule)"
                >
                  {{ translateScoped('actions.generate', 'Gerar') }}
                </button>
                <button
                  type="button"
                  class="finance-inline-action finance-inline-action-danger"
                  :disabled="accountActionProcessing || !canWriteFinance"
                  @click="requestDeleteRecurringRule(recurringRule)"
                >
                  {{ translateScoped('actions.delete', 'Excluir') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <article v-if="showInstallment && filteredInstallmentPlans.length > 0" class="finance-panel">
      <header>
        <h3>{{ translateScoped('installments.title', 'Planos de parcelamento') }}</h3>
        <small>
          {{ translateScoped('installments.summary', '{count} plano(s)', {
            count: filteredInstallmentPlans.length,
          }) }}
        </small>
      </header>

      <div class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('installments.table.title', 'Título') }}</th>
              <th>{{ translateScoped('installments.table.total', 'Total') }}</th>
              <th>{{ translateScoped('installments.table.count', 'Parcelas') }}</th>
              <th>{{ translateScoped('installments.table.remaining', 'Restante') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="installmentPlan in paginatedInstallmentPlans" :key="installmentPlan.id">
              <td><strong>{{ installmentPlan.title }}</strong></td>
              <td>{{ formatCurrency(installmentPlan.totalAmountBrl) }}</td>
              <td>{{ installmentPlan.installmentsCount }}</td>
              <td>{{ formatCurrency(installmentPlan.remainingAmountBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <div
    v-if="accountActionModalState.isOpen"
    class="app-modal-overlay"
    role="dialog"
    aria-modal="true"
    @click.self="handleModalOverlayClose"
  >
    <div class="app-modal-frame" style="max-width: 56rem; width: 100%; padding: 24px;">
      <header class="finance-modal-header">
        <h3>{{ accountActionModalTitle }}</h3>
        <p>{{ translateScoped('modal.subtitle', 'Os dados serão aplicados nas listagens desta aba.') }}</p>
      </header>

      <form
        v-if="accountActionModalState.actionType === 'ENTRY'"
        class="finance-form-grid"
        @submit.prevent="submitEntry"
      >
        <label>
          <span>{{ translateScoped('modal.entry.title', 'Título') }}</span>
          <input v-model="entryForm.title" type="text" :disabled="accountActionProcessing || !canWriteFinance" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.type', 'Tipo') }}</span>
          <select v-model="entryForm.entryType" :disabled="accountActionProcessing || !canWriteFinance">
            <option v-for="entryTypeOption in entryTypeOptionsForForm" :key="entryTypeOption.value" :value="entryTypeOption.value">
              {{ entryTypeOption.label }}
            </option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.amount', 'Valor (BRL)') }}</span>
          <input
            v-model="entryForm.expectedAmountBrl"
            type="number"
            step="0.01"
            min="0.01"
            :disabled="accountActionProcessing || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.dueDate', 'Vencimento') }}</span>
          <input v-model="entryForm.dueDate" type="date" :disabled="accountActionProcessing || !canWriteFinance" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.category', 'Categoria') }}</span>
          <select v-model="entryForm.categoryId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.noCategory', 'Sem categoria') }}</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.entry.bankAccount', 'Conta bancária') }}</span>
          <select v-model="entryForm.bankAccountId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.noAccount', 'Sem conta') }}</option>
            <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
          </select>
        </label>

        <div class="finance-modal-actions">
          <button
            type="button"
            class="finance-inline-action"
            :disabled="accountActionProcessing"
            @click="closeAccountActionModal"
          >
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>

          <button
            ref="accountActionPrimaryButtonRef"
            class="finance-action-button"
            type="submit"
            :disabled="accountActionProcessing || !canWriteFinance"
          >
            {{ accountActionProcessing
              ? translateScoped('actions.saving', 'Salvando...')
              : entryEditingId
                ? translateScoped('actions.saveChanges', 'Salvar alterações')
                : translateScoped('actions.saveEntry', 'Salvar lançamento') }}
          </button>
        </div>
      </form>

      <form
        v-else-if="accountActionModalState.actionType === 'SETTLEMENT'"
        class="finance-form-grid"
        @submit.prevent="submitSettlement"
      >
        <label>
          <span>{{ translateScoped('modal.settlement.entry', 'Lançamento') }}</span>
          <select v-model="settlementForm.entryId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.select', 'Selecione') }}</option>
            <option v-for="entryItem in availableSettlementEntries" :key="entryItem.id" :value="entryItem.id">
              {{ formatSettlementEntryOptionLabel(entryItem) }}
            </option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.settlement.amount', 'Valor da baixa') }}</span>
          <input
            v-model="settlementForm.amountBrl"
            type="number"
            step="0.01"
            min="0.01"
            :disabled="accountActionProcessing || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.settlement.dateTime', 'Data/hora da baixa') }}</span>
          <input
            v-model="settlementForm.settledAt"
            type="datetime-local"
            :disabled="accountActionProcessing || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.settlement.bankAccount', 'Conta bancária') }}</span>
          <select v-model="settlementForm.bankAccountId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.select', 'Selecione') }}</option>
            <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
          </select>
        </label>

        <div class="finance-modal-actions">
          <button
            type="button"
            class="finance-inline-action"
            :disabled="accountActionProcessing"
            @click="closeAccountActionModal"
          >
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>

          <button
            ref="accountActionPrimaryButtonRef"
            class="finance-action-button"
            type="submit"
            :disabled="accountActionProcessing || !canWriteFinance"
          >
            {{ accountActionProcessing
              ? translateScoped('actions.saving', 'Salvando...')
              : translateScoped('actions.registerSettlement', 'Registrar baixa') }}
          </button>
        </div>
      </form>

      <form
        v-else-if="accountActionModalState.actionType === 'RECURRING_RULE'"
        class="finance-form-grid"
        @submit.prevent="submitRecurringRule"
      >
        <label>
          <span>{{ translateScoped('modal.recurring.title', 'Título') }}</span>
          <input v-model="recurringForm.title" type="text" :disabled="accountActionProcessing || !canWriteFinance" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.amount', 'Valor mensal') }}</span>
          <input
            v-model="recurringForm.amountBrl"
            type="number"
            step="0.01"
            min="0.01"
            :disabled="accountActionProcessing || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.dayOfMonth', 'Dia do mês') }}</span>
          <input
            v-model="recurringForm.dayOfMonth"
            type="number"
            min="1"
            max="31"
            :disabled="accountActionProcessing || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.startsAt', 'Início') }}</span>
          <input v-model="recurringForm.startsAt" type="date" :disabled="accountActionProcessing || !canWriteFinance" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.recurringType', 'Tipo recorrente') }}</span>
          <select v-model="recurringForm.recurringTypeId" :disabled="accountActionProcessing || !canWriteFinance" required>
            <option value="">{{ translateScoped('options.select', 'Selecione') }}</option>
            <option v-for="recurringType in availableRecurringTypes" :key="recurringType.id" :value="recurringType.id">{{ recurringType.name }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.category', 'Categoria') }}</span>
          <select v-model="recurringForm.categoryId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.noCategory', 'Sem categoria') }}</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.recurring.bankAccount', 'Conta bancária padrão') }}</span>
          <select v-model="recurringForm.defaultBankAccountId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.noAccount', 'Sem conta') }}</option>
            <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
          </select>
        </label>

        <div class="finance-modal-actions">
          <button
            type="button"
            class="finance-inline-action"
            :disabled="accountActionProcessing"
            @click="closeAccountActionModal"
          >
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>

          <button
            ref="accountActionPrimaryButtonRef"
            class="finance-action-button"
            type="submit"
            :disabled="accountActionProcessing || !canWriteFinance"
          >
            {{ accountActionProcessing
              ? translateScoped('actions.saving', 'Salvando...')
              : recurringRuleEditingId
                ? translateScoped('actions.save', 'Salvar')
                : translateScoped('actions.createRecurring', 'Criar recorrência') }}
          </button>
        </div>
      </form>

      <form
        v-else-if="accountActionModalState.actionType === 'INSTALLMENT_PLAN'"
        class="finance-form-grid"
        @submit.prevent="submitInstallmentPlan"
      >
        <label>
          <span>{{ translateScoped('modal.installment.title', 'Título') }}</span>
          <input v-model="installmentForm.title" type="text" :disabled="accountActionProcessing || !canWriteFinance" required>
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.totalAmount', 'Valor total') }}</span>
          <input
            v-model="installmentForm.totalAmountBrl"
            type="number"
            step="0.01"
            min="0.01"
            :disabled="accountActionProcessing || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.downPayment', 'Entrada') }}</span>
          <input
            v-model="installmentForm.downPaymentBrl"
            type="number"
            step="0.01"
            min="0"
            :disabled="accountActionProcessing || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.count', 'Parcelas') }}</span>
          <input
            v-model="installmentForm.installmentsCount"
            type="number"
            min="1"
            :disabled="accountActionProcessing || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.firstDueDate', 'Primeiro vencimento') }}</span>
          <input
            v-model="installmentForm.firstDueDate"
            type="date"
            :disabled="accountActionProcessing || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.category', 'Categoria') }}</span>
          <select v-model="installmentForm.categoryId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.noCategory', 'Sem categoria') }}</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.installment.bankAccount', 'Conta bancária padrão') }}</span>
          <select v-model="installmentForm.defaultBankAccountId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.noAccount', 'Sem conta') }}</option>
            <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
          </select>
        </label>

        <div class="finance-modal-actions">
          <button
            type="button"
            class="finance-inline-action"
            :disabled="accountActionProcessing"
            @click="closeAccountActionModal"
          >
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>

          <button
            ref="accountActionPrimaryButtonRef"
            class="finance-action-button"
            type="submit"
            :disabled="accountActionProcessing || !canWriteFinance"
          >
            {{ accountActionProcessing
              ? translateScoped('actions.saving', 'Salvando...')
              : translateScoped('actions.createInstallment', 'Criar parcelamento') }}
          </button>
        </div>
      </form>

      <form
        v-else-if="accountActionModalState.actionType === 'RENEGOTIATION'"
        class="finance-form-grid"
        @submit.prevent="submitRenegotiation"
      >
        <label>
          <span>{{ translateScoped('modal.renegotiation.plan', 'Plano') }}</span>
          <select v-model="renegotiationForm.planId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.select', 'Selecione') }}</option>
            <option v-for="installmentPlan in filteredInstallmentPlans" :key="installmentPlan.id" :value="installmentPlan.id">
              {{ installmentPlan.title }}
            </option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.renegotiation.installmentsCount', 'Nova quantidade de parcelas') }}</span>
          <input
            v-model="renegotiationForm.installmentsCount"
            type="number"
            min="1"
            :disabled="accountActionProcessing || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.renegotiation.reason', 'Motivo') }}</span>
          <input
            v-model="renegotiationForm.reason"
            type="text"
            :disabled="accountActionProcessing || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('modal.renegotiation.category', 'Categoria') }}</span>
          <select v-model="renegotiationForm.categoryId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.keepCurrent', 'Manter atual') }}</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('modal.renegotiation.bankAccount', 'Conta bancária') }}</span>
          <select v-model="renegotiationForm.defaultBankAccountId" :disabled="accountActionProcessing || !canWriteFinance">
            <option value="">{{ translateScoped('options.keepCurrent', 'Manter atual') }}</option>
            <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
          </select>
        </label>

        <div class="finance-modal-actions">
          <button
            type="button"
            class="finance-inline-action"
            :disabled="accountActionProcessing"
            @click="closeAccountActionModal"
          >
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>

          <button
            ref="accountActionPrimaryButtonRef"
            class="finance-action-button"
            type="submit"
            :disabled="accountActionProcessing || !canWriteFinance"
          >
            {{ accountActionProcessing
              ? translateScoped('actions.saving', 'Salvando...')
              : translateScoped('actions.renegotiate', 'Renegociar plano') }}
          </button>
        </div>
      </form>
    </div>
  </div>

  <AppConfirmDialog
    :is-open="confirmDialogState.isOpen"
    :title="confirmDialogState.title"
    :message="confirmDialogState.message"
    :confirm-label="confirmDialogState.confirmLabel"
    :confirm-tone="confirmDialogState.confirmTone"
    :processing="confirmDialogState.processing"
    @cancel="closeConfirmDialog"
    @confirm="handleConfirmDialogAction"
  />
</template>
