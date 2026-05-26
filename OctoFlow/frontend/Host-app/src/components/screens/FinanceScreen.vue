<script setup>
import { storeToRefs } from 'pinia'
import {
  computed,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  reactive,
  ref,
  watch,
} from 'vue'
import { useI18n } from '../../composables/useI18n'
import {
  RemoteFinanceEmptyState,
  RemoteFinanceKpiCard,
  RemoteFinanceStatusBadge,
  RemoteFinanceTrendMiniChart,
} from '../../federation/remoteComponents'
import {
  createFinanceBankAccount,
  createFinanceCategory,
  createFinanceDebtPlan,
  createFinanceEntry,
  createFinanceExport,
  createFinanceInstallmentPlan,
  createFinanceOpenFinanceConnection,
  createFinanceRecurringRule,
  createFinanceRecurringType,
  createFinanceCurrencyRateManual,
  createFinanceSettlement,
  deleteFinanceRecurringRule,
  deleteFinanceRecurringType,
  fetchFinanceBankAccounts,
  fetchFinanceCategories,
  fetchFinanceDashboardCashflow,
  fetchFinanceDashboardCategories,
  fetchFinanceDashboardSummary,
  fetchFinanceDebtPlans,
  fetchFinanceCurrencies,
  fetchFinanceCurrencyRates,
  fetchFinanceEntries,
  fetchFinanceExports,
  fetchFinanceInstallmentPlans,
  fetchFinanceOpenFinanceConnections,
  fetchFinanceOpenFinanceProviders,
  fetchFinanceMigrationSnapshot,
  fetchFinanceRecurringRules,
  fetchFinanceRecurringTypes,
  generateFinanceRecurringRuleManually,
  importFinanceMigrationSnapshot,
  previewFinanceDebtPlan,
  renegotiateFinanceInstallmentPlan,
  syncFinanceOpenFinanceConnection,
  deleteFinanceDebtPlan,
  deleteFinanceEntry,
  deleteFinanceExport,
  deleteFinanceOpenFinanceConnection,
  updateFinanceCategory,
  updateFinanceBankAccount,
  updateFinanceBankAccountStatus,
  updateFinanceEntry,
  updateFinanceInstallmentPlan,
  updateFinanceRecurringRule,
  updateFinanceRecurringType,
} from '../../services/finance'
import {
  convertFinanceSimulationToPlan,
  createFinanceSimulation,
  fetchFinanceInvestmentPlans,
  updateFinanceInvestmentPlan,
} from '../../services/financeInvestments'
import {
  FINANCE_BANK_ACCOUNT_TYPE_OPTIONS,
  FINANCE_DIRECTION_OPTIONS,
  FINANCE_ENTRY_TYPE_OPTIONS,
  FINANCE_ENTRY_STATUS_OPTIONS,
  FINANCE_EXPORT_TYPE_OPTIONS,
  FINANCE_INVESTMENT_TYPE_OPTIONS,
  FINANCE_INVESTMENT_YIELD_MODE_OPTIONS,
  OPEN_FINANCE_CONNECTION_STATUS_OPTIONS,
  translateFinanceExportStatus,
  translateFinanceExportType,
  translateFinanceTerm,
} from '../../constants/financeTerms'
import { useNotification } from '../../composables/useNotification'
import { buildCurrentMonthDateRange, formatDate, formatDateTime } from '../../utils/date'
import { extractHttpMessage } from '../../utils/httpErrors'
import { useSessionStore } from '../../stores/sessionStore'
import AppConfirmDialog from '../shared/AppConfirmDialog.vue'
import FinanceEntriesListPanel from '../shared/FinanceEntriesListPanel.vue'

const props = defineProps({
  request: {
    type: Function,
    default: null,
  },
  notify: {
    type: Function,
    default: null,
  },
  currentUser: {
    type: Object,
    default: null,
  },
  initialSection: {
    type: String,
    default: 'accounts',
  },
})
const sessionStore = useSessionStore()
const { translate } = useI18n()
const { currentUser: sessionCurrentUser } = storeToRefs(sessionStore)
const requestClient = props.request || sessionStore.authRequest
const effectiveCurrentUser = computed(() => props.currentUser || sessionCurrentUser.value)

const { notifyUser } = useNotification(props.notify)

const sectionToTabKey = {
  accounts: 'accounts',
  banks: 'banks',
  investments: 'investments',
  debts: 'accounts',
  settings: 'settings',
  currencies: 'settings',
  reports: 'reports',
}

const activeTab = ref(sanitizeFinanceTab(sectionToTabKey[props.initialSection] || 'accounts'))
const accountsTabOptions = [
  { key: 'overview', label: 'Visão Geral' },
  { key: 'payable', label: 'Contas a Pagar' },
  { key: 'receivable', label: 'Contas a Receber' },
  { key: 'debts', label: 'Dívidas' },
]
const ALLOWED_ACCOUNT_SUBTABS = Object.freeze(accountsTabOptions.map((tabOption) => tabOption.key))
const activeAccountsTab = ref('overview')
const directionOptions = FINANCE_DIRECTION_OPTIONS
const manualEntryTypeOptions = FINANCE_ENTRY_TYPE_OPTIONS.filter((entryTypeOption) => (
  ['ONE_OFF', 'DEBT', 'ADJUSTMENT'].includes(entryTypeOption.value)
))
const entryStatusOptions = FINANCE_ENTRY_STATUS_OPTIONS
const investmentTypeOptions = FINANCE_INVESTMENT_TYPE_OPTIONS
const investmentYieldModeOptions = FINANCE_INVESTMENT_YIELD_MODE_OPTIONS
const exportTypeOptions = FINANCE_EXPORT_TYPE_OPTIONS
const bankAccountTypeOptions = FINANCE_BANK_ACCOUNT_TYPE_OPTIONS
const openFinanceConnectionStatusOptions = OPEN_FINANCE_CONNECTION_STATUS_OPTIONS
const LIST_ITEMS_PER_PAGE = 10
const FINANCE_SEARCH_MAX_LENGTH = 180
const ALLOWED_FINANCE_TABS = Object.freeze(['accounts', 'banks', 'investments', 'settings', 'reports'])
const loadingState = reactive({
  dashboard: false,
  entries: false,
  recurring: false,
  installments: false,
  investments: false,
  debts: false,
  currencies: false,
  exports: false,
  migration: false,
  catalogs: false,
  openFinance: false,
})

const dashboardSummary = ref(null)
const accountsOverviewSummary = ref(null)
const accountsOverviewMonth = ref(getNextMonthInputValue())
const dashboardCashflow = ref([])
const dashboardCategories = ref([])

const entriesState = ref([])
const entriesMeta = ref({ page: 1, itemsPerPage: 10, total: 0 })

const recurringRules = ref([])
const installmentPlans = ref([])
const investmentPlans = ref([])
const debtPlans = ref([])
const debtPreview = ref(null)
const debtPreviewRequestPayload = ref(null)
const debtPlanCreationSuggestionKey = ref('')
const currenciesCatalog = ref([])
const currencyRates = ref([])
const exportJobs = ref([])
const latestSimulation = ref(null)
const openFinanceProviders = ref([])
const openFinanceConnections = ref([])

const categories = ref([])
const recurringTypes = ref([])
const bankAccounts = ref([])
const exportMeta = ref({ page: 1, itemsPerPage: LIST_ITEMS_PER_PAGE, total: 0 })
const localListPages = reactive({
  bankAccounts: 1,
  categories: 1,
  recurringTypes: 1,
  recurringRules: 1,
  installmentPlans: 1,
  investmentPlans: 1,
  debtPlans: 1,
  currencyRates: 1,
  openFinanceConnections: 1,
})
const financeKeepAlivePaused = ref(false)
const financeRuntimeError = ref('')

function translateFinanceScreen(messageKey, fallbackMessage = '', variables = {}) {
  const translatedMessage = translate(messageKey, variables)
  return translatedMessage === messageKey ? fallbackMessage : translatedMessage
}

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

function sanitizeSingleLineText(rawValue, maxLength = FINANCE_SEARCH_MAX_LENGTH) {
  const normalizedText = replaceControlCharactersWithSpaces(rawValue)
    .replace(/\s+/g, ' ')
    .trim()

  return normalizedText.slice(0, maxLength)
}

function sanitizeFinanceTab(rawTabKey) {
  const normalizedTabKey = String(rawTabKey || '').trim().toLowerCase()
  return ALLOWED_FINANCE_TABS.includes(normalizedTabKey) ? normalizedTabKey : 'accounts'
}

function sanitizeAccountSubtab(rawSubtabKey) {
  const normalizedSubtabKey = String(rawSubtabKey || '').trim().toLowerCase()
  return ALLOWED_ACCOUNT_SUBTABS.includes(normalizedSubtabKey) ? normalizedSubtabKey : 'overview'
}

function sanitizeDateInput(rawDateInput) {
  const normalizedDateInput = String(rawDateInput || '').trim()
  if (normalizedDateInput === '') {
    return ''
  }

  return /^\d{4}-\d{2}-\d{2}$/.test(normalizedDateInput) ? normalizedDateInput : ''
}

function sanitizeFinanceFilterByCatalog(rawValue, catalogOptions = []) {
  const normalizedValue = String(rawValue || '').trim().toUpperCase()
  if (normalizedValue === '') {
    return ''
  }

  const allowedValues = Array.isArray(catalogOptions)
    ? catalogOptions
      .map((catalogOption) => String(catalogOption?.value || '').trim().toUpperCase())
      .filter((value) => value !== '')
    : []

  return allowedValues.includes(normalizedValue) ? normalizedValue : ''
}

const entryFilters = reactive({
  direction: '',
  status: '',
  search: '',
  startDate: '',
  endDate: '',
  page: 1,
  itemsPerPage: 10,
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
const entryEditingId = ref(null)
const confirmDialogState = reactive({
  isOpen: false,
  title: '',
  message: '',
  confirmLabel: 'Confirmar',
  confirmTone: 'danger',
  processing: false,
})
const confirmDialogAction = ref(null)

const categoryForm = reactive({
  name: '',
  kind: 'BOTH',
})
const categoryEditingId = ref(null)

const recurringTypeCatalogForm = reactive({
  name: '',
  description: '',
})
const recurringTypeEditingId = ref(null)

const bankAccountForm = reactive({
  name: '',
  branch: '',
  accountNumber: '',
  accountType: 'CHECKING',
  currentBalanceBrl: '0',
})
const bankAccountEditingId = ref(null)

const settlementForm = reactive({
  entryId: '',
  amountBrl: '',
  settledAt: '',
  bankAccountId: '',
  useCreditCard: false,
  creditCardId: '',
  creditCardInterestRatePercent: '2.99',
  creditCardIofRatePercent: '0.38',
  creditCardDueDate: '',
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
const recurringRuleEditingId = ref(null)
const manualRecurringGenerationRuleId = ref(null)

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
const installmentEditingId = ref(null)

const renegotiationForm = reactive({
  planId: '',
  installmentsCount: 6,
  reason: 'Renegociação manual',
  categoryId: '',
  defaultBankAccountId: '',
})

const simulationForm = reactive({
  investmentType: 'SELIC',
  label: 'Simulação padrão',
  initialAmountBrl: '1000',
  monthlyContributionBrl: '300',
  periodMonths: 24,
  rateInputType: 'ANNUAL',
  rateValue: '12',
})

const convertPlanForm = reactive({
  label: 'Plano de investimento',
  startDate: '',
  contributionDay: 5,
  generateYieldEntries: false,
  yieldMode: 'NONE',
  categoryId: '',
  defaultBankAccountId: '',
})

function getCurrentDateInputValue() {
  return new Date().toISOString().slice(0, 10)
}

function getCurrentYearMonthInputValue(referenceDate = new Date()) {
  const normalizedYear = referenceDate.getFullYear()
  const normalizedMonth = String(referenceDate.getMonth() + 1).padStart(2, '0')
  return `${normalizedYear}-${normalizedMonth}`
}

function getNextMonthInputValue() {
  const nextMonthReferenceDate = new Date()
  nextMonthReferenceDate.setMonth(nextMonthReferenceDate.getMonth() + 1)
  return getCurrentYearMonthInputValue(nextMonthReferenceDate)
}

function buildReferenceDateFromYearMonth(yearMonthValue) {
  const [yearChunk, monthChunk] = String(yearMonthValue || '').split('-')
  const parsedYear = Number(yearChunk)
  const parsedMonth = Number(monthChunk)

  if (!Number.isInteger(parsedYear) || !Number.isInteger(parsedMonth) || parsedMonth < 1 || parsedMonth > 12) {
    return new Date()
  }

  return new Date(parsedYear, parsedMonth - 1, 1)
}

function formatYearMonthLabel(yearMonthValue) {
  const referenceDate = buildReferenceDateFromYearMonth(yearMonthValue)
  return referenceDate.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })
}

const exportForm = reactive({
  exportType: 'MONTHLY_SUMMARY',
})
const migrationForm = reactive({
  replaceExisting: true,
})
const migrationImportFile = ref(null)

const debtForm = reactive({
  title: '',
  creditorName: '',
  totalAmountBrl: '',
  negotiatedAmountBrl: '',
  proposedAmountBrl: '',
  discountAmountBrl: '',
  downPaymentBrl: '0',
  maxCommitmentPercent: '30',
  desiredInstallmentsCount: 12,
  firstDueDate: getCurrentDateInputValue(),
  categoryId: '',
  defaultBankAccountId: '',
})

const accountActionModalState = reactive({
  isOpen: false,
  actionType: 'ENTRY',
})
const selectedAccountActionType = ref('ENTRY')

const currencyForm = reactive({
  date: getCurrentDateInputValue(),
  codes: ['USD', 'EUR', 'GBP', 'ARS'],
})
const currencyCodePickerForm = reactive({
  selectedCode: 'USD',
})
const manualCurrencyRateForm = reactive({
  quoteDate: getCurrentDateInputValue(),
  currencyCode: 'USD',
  currencyName: '',
  rateBrl: '',
})

const openFinanceForm = reactive({
  providerId: '',
  status: 'ACTIVE',
  createMockData: false,
})

const categoryKindOptions = [
  { value: 'BOTH', label: 'Ambos (pagar e receber)' },
  { value: 'PAYABLE', label: 'Somente pagar' },
  { value: 'RECEIVABLE', label: 'Somente receber' },
  { value: 'INVESTMENT', label: 'Investimento' },
]

const kpiCards = computed(() => {
  if (!dashboardSummary.value) {
    return []
  }

  const expectedIncomeBrl = Number(dashboardSummary.value.expectedIncomeBrl || 0)
  const expectedExpenseBrl = Number(dashboardSummary.value.expectedExpenseBrl || 0)
  const realizedIncomeBrl = Number(dashboardSummary.value.realizedIncomeBrl || 0)
  const realizedExpenseBrl = Number(dashboardSummary.value.realizedExpenseBrl || 0)
  const expectedSettlementPercent = expectedIncomeBrl > 0
    ? roundMoney((realizedIncomeBrl / expectedIncomeBrl) * 100)
    : 0
  const expectedExpenseExecutionPercent = expectedExpenseBrl > 0
    ? roundMoney((realizedExpenseBrl / expectedExpenseBrl) * 100)
    : 0

  return [
    {
      key: 'expectedNet',
      label: 'Saldo previsto',
      value: formatCurrency(dashboardSummary.value.expectedNetBrl),
      caption: 'Entradas previstas - saídas previstas',
      tone: Number(dashboardSummary.value.expectedNetBrl) >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'realizedNet',
      label: 'Saldo realizado',
      value: formatCurrency(dashboardSummary.value.realizedNetBrl),
      caption: 'Entradas recebidas - pagamentos',
      tone: Number(dashboardSummary.value.realizedNetBrl) >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'remaining',
      label: 'Saldo pendente',
      value: formatCurrency(dashboardSummary.value.remainingTotalBrl),
      caption: 'Valor ainda aberto em lançamentos',
      tone: 'warning',
    },
    {
      key: 'overdue',
      label: 'Atrasos',
      value: String(dashboardSummary.value.overdueEntriesCount || 0),
      caption: 'Lançamentos vencidos não quitados',
      tone: Number(dashboardSummary.value.overdueEntriesCount || 0) > 0 ? 'negative' : 'neutral',
    },
    {
      key: 'expectedIncome',
      label: 'Receita prevista',
      value: formatCurrency(expectedIncomeBrl),
      caption: 'Total de entradas previstas no período',
      tone: 'positive',
    },
    {
      key: 'expectedExpense',
      label: 'Despesa prevista',
      value: formatCurrency(expectedExpenseBrl),
      caption: 'Total de saídas previstas no período',
      tone: 'warning',
    },
    {
      key: 'accountsBalance',
      label: 'Saldo em contas',
      value: formatCurrency(dashboardSummary.value.accountsBalanceBrl),
      caption: 'Soma das contas bancárias ativas',
      tone: Number(dashboardSummary.value.accountsBalanceBrl) >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'incomeSettlement',
      label: 'Execução de recebimentos',
      value: formatPercent(expectedSettlementPercent),
      caption: 'Percentual realizado sobre o previsto',
      tone: expectedSettlementPercent >= 100 ? 'positive' : 'neutral',
    },
    {
      key: 'expenseExecution',
      label: 'Execução de pagamentos',
      value: formatPercent(expectedExpenseExecutionPercent),
      caption: 'Percentual pago sobre o previsto',
      tone: expectedExpenseExecutionPercent > 100 ? 'negative' : 'neutral',
    },
  ]
})

const dashboardCashflowSeries = computed(() => {
  const cashflowItems = Array.isArray(dashboardCashflow.value) ? dashboardCashflow.value : []

  return {
    monthLabels: cashflowItems.map((cashflowItem) => formatDate(cashflowItem.competenceMonth, '-')),
    expectedIncomeSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.expectedIncomeBrl || 0)),
    expectedExpenseSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.expectedExpenseBrl || 0)),
    expectedNetSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.expectedNetBrl || 0)),
    realizedIncomeSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.realizedIncomeBrl || 0)),
    realizedExpenseSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.realizedExpenseBrl || 0)),
    realizedNetSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.realizedNetBrl || 0)),
    netGapSeries: cashflowItems.map((cashflowItem) => roundMoney(
      Number(cashflowItem.expectedNetBrl || 0) - Number(cashflowItem.realizedNetBrl || 0),
    )),
  }
})

const dashboardCashflowMonthsLegend = computed(() => {
  const monthLabels = dashboardCashflowSeries.value.monthLabels
  if (monthLabels.length === 0) {
    return ''
  }

  const visibleMonthLabels = monthLabels.slice(-8)
  const hiddenMonthsCount = Math.max(0, monthLabels.length - visibleMonthLabels.length)

  if (hiddenMonthsCount > 0) {
    return `Período: ${visibleMonthLabels.join(' • ')} (+${hiddenMonthsCount} meses anteriores)`
  }

  return `Período: ${visibleMonthLabels.join(' • ')}`
})

const dashboardCashflowChartRows = computed(() => {
  const cashflowSeries = dashboardCashflowSeries.value
  if (cashflowSeries.monthLabels.length === 0) {
    return []
  }

  return [
    {
      key: 'cashflow-net',
      columns: 2,
      charts: [
        {
          key: 'expected-net-monthly',
          title: 'Saldo previsto por mês',
          caption: 'Entradas previstas menos saídas previstas.',
          points: cashflowSeries.expectedNetSeries,
          strokeColor: '#2563eb',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(cashflowSeries.expectedNetSeries))}`,
          secondaryLabel: `Último mês: ${formatCurrency(getLastSeriesValue(cashflowSeries.expectedNetSeries))}`,
        },
        {
          key: 'realized-net-monthly',
          title: 'Saldo realizado por mês',
          caption: 'Entradas recebidas menos pagamentos efetivos.',
          points: cashflowSeries.realizedNetSeries,
          strokeColor: '#16a34a',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(cashflowSeries.realizedNetSeries))}`,
          secondaryLabel: `Último mês: ${formatCurrency(getLastSeriesValue(cashflowSeries.realizedNetSeries))}`,
        },
      ],
    },
    {
      key: 'cashflow-expected',
      columns: 2,
      charts: [
        {
          key: 'expected-income-monthly',
          title: 'Receitas previstas',
          caption: 'Evolução mensal do que ainda deve entrar.',
          points: cashflowSeries.expectedIncomeSeries,
          strokeColor: '#0284c7',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(cashflowSeries.expectedIncomeSeries))}`,
          secondaryLabel: `Média mensal: ${formatCurrency(averageSeriesValue(cashflowSeries.expectedIncomeSeries))}`,
        },
        {
          key: 'expected-expense-monthly',
          title: 'Despesas previstas',
          caption: 'Evolução mensal do que ainda deve sair.',
          points: cashflowSeries.expectedExpenseSeries,
          strokeColor: '#f97316',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(cashflowSeries.expectedExpenseSeries))}`,
          secondaryLabel: `Média mensal: ${formatCurrency(averageSeriesValue(cashflowSeries.expectedExpenseSeries))}`,
        },
      ],
    },
    {
      key: 'cashflow-realized',
      columns: 2,
      charts: [
        {
          key: 'realized-income-monthly',
          title: 'Receitas realizadas',
          caption: 'Entradas que já foram efetivamente recebidas.',
          points: cashflowSeries.realizedIncomeSeries,
          strokeColor: '#0369a1',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(cashflowSeries.realizedIncomeSeries))}`,
          secondaryLabel: `Média mensal: ${formatCurrency(averageSeriesValue(cashflowSeries.realizedIncomeSeries))}`,
        },
        {
          key: 'realized-expense-monthly',
          title: 'Despesas realizadas',
          caption: 'Pagamentos que já foram efetivamente quitados.',
          points: cashflowSeries.realizedExpenseSeries,
          strokeColor: '#ea580c',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(cashflowSeries.realizedExpenseSeries))}`,
          secondaryLabel: `Média mensal: ${formatCurrency(averageSeriesValue(cashflowSeries.realizedExpenseSeries))}`,
        },
      ],
    },
    {
      key: 'cashflow-gap',
      columns: 1,
      charts: [
        {
          key: 'gap-net-monthly',
          title: 'Gap mensal entre previsto e realizado',
          caption: 'Positivo: previsão acima do realizado. Negativo: realizado acima da previsão.',
          points: cashflowSeries.netGapSeries,
          strokeColor: '#7c3aed',
          summaryLabel: `Média mensal: ${formatCurrency(averageSeriesValue(cashflowSeries.netGapSeries))}`,
          secondaryLabel: `Maior desvio: ${formatCurrency(getMaxAbsoluteSeriesValue(cashflowSeries.netGapSeries))}`,
        },
      ],
    },
  ]
})

const dashboardCategorySeries = computed(() => {
  const categoryItems = Array.isArray(dashboardCategories.value) ? dashboardCategories.value : []

  return {
    categoryNames: categoryItems.map((categoryItem) => String(categoryItem.categoryName || 'Sem categoria')),
    entriesCountSeries: categoryItems.map((categoryItem) => Number(categoryItem.entriesCount || 0)),
    expectedIncomeSeries: categoryItems.map((categoryItem) => Number(categoryItem.expectedIncomeBrl || 0)),
    expectedExpenseSeries: categoryItems.map((categoryItem) => Number(categoryItem.expectedExpenseBrl || 0)),
    expectedNetSeries: categoryItems.map((categoryItem) => Number(categoryItem.expectedNetBrl || 0)),
    realizedIncomeSeries: categoryItems.map((categoryItem) => Number(categoryItem.realizedIncomeBrl || 0)),
    realizedExpenseSeries: categoryItems.map((categoryItem) => Number(categoryItem.realizedExpenseBrl || 0)),
    realizedNetSeries: categoryItems.map((categoryItem) => Number(categoryItem.realizedNetBrl || 0)),
  }
})

const dashboardCategoriesLegend = computed(() => {
  const categoryNames = dashboardCategorySeries.value.categoryNames
  if (categoryNames.length === 0) {
    return ''
  }

  const visibleCategoryNames = categoryNames.slice(0, 8)
  const hiddenCategoriesCount = Math.max(0, categoryNames.length - visibleCategoryNames.length)

  if (hiddenCategoriesCount > 0) {
    return `Categorias: ${visibleCategoryNames.join(' • ')} (+${hiddenCategoriesCount} categorias)`
  }

  return `Categorias: ${visibleCategoryNames.join(' • ')}`
})

const dashboardCategoryChartRows = computed(() => {
  const categorySeries = dashboardCategorySeries.value
  if (categorySeries.categoryNames.length === 0) {
    return []
  }

  return [
    {
      key: 'categories-net',
      columns: 2,
      charts: [
        {
          key: 'categories-expected-net',
          title: 'Saldo previsto por categoria',
          caption: 'Entradas previstas menos saídas previstas em cada categoria.',
          points: categorySeries.expectedNetSeries,
          strokeColor: '#1d4ed8',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(categorySeries.expectedNetSeries))}`,
          secondaryLabel: `Maior valor: ${formatCurrency(getMaxSeriesValue(categorySeries.expectedNetSeries))}`,
        },
        {
          key: 'categories-realized-net',
          title: 'Saldo realizado por categoria',
          caption: 'Entradas recebidas menos pagamentos em cada categoria.',
          points: categorySeries.realizedNetSeries,
          strokeColor: '#16a34a',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(categorySeries.realizedNetSeries))}`,
          secondaryLabel: `Maior valor: ${formatCurrency(getMaxSeriesValue(categorySeries.realizedNetSeries))}`,
        },
      ],
    },
    {
      key: 'categories-movements',
      columns: 2,
      charts: [
        {
          key: 'categories-expected-expense',
          title: 'Despesas previstas por categoria',
          caption: 'Volume de saídas planejadas em cada categoria.',
          points: categorySeries.expectedExpenseSeries,
          strokeColor: '#f97316',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(categorySeries.expectedExpenseSeries))}`,
          secondaryLabel: `Maior valor: ${formatCurrency(getMaxSeriesValue(categorySeries.expectedExpenseSeries))}`,
        },
        {
          key: 'categories-realized-expense',
          title: 'Despesas realizadas por categoria',
          caption: 'Volume de saídas efetivamente pagas por categoria.',
          points: categorySeries.realizedExpenseSeries,
          strokeColor: '#ea580c',
          summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(categorySeries.realizedExpenseSeries))}`,
          secondaryLabel: `Maior valor: ${formatCurrency(getMaxSeriesValue(categorySeries.realizedExpenseSeries))}`,
        },
      ],
    },
    {
      key: 'categories-volume',
      columns: 1,
      charts: [
        {
          key: 'categories-entries-volume',
          title: 'Quantidade de lançamentos por categoria',
          caption: 'Mostra onde existe maior concentração de movimentações.',
          points: categorySeries.entriesCountSeries,
          strokeColor: '#9333ea',
          summaryLabel: `Total: ${formatInteger(sumCountValues(categorySeries.entriesCountSeries))} lançamentos`,
          secondaryLabel: `Maior volume: ${formatInteger(getMaxSeriesValue(categorySeries.entriesCountSeries))} lançamentos`,
        },
      ],
    },
  ]
})

const totalsLabel = computed(() => {
  const totalEntries = Number(entriesMeta.value.total || 0)
  if (totalEntries <= 0) {
    return '0 lançamentos'
  }

  const firstItemIndex = (Number(entriesMeta.value.page || 1) - 1) * Number(entriesMeta.value.itemsPerPage || LIST_ITEMS_PER_PAGE) + 1
  const lastItemIndex = Math.min(firstItemIndex + entriesState.value.length - 1, totalEntries)

  return `${firstItemIndex}-${lastItemIndex} de ${totalEntries} lançamentos`
})
const paginatedBankAccounts = computed(() => getPaginatedLocalItems(bankAccounts.value, 'bankAccounts'))
const paginatedCategories = computed(() => getPaginatedLocalItems(categories.value, 'categories'))
const paginatedRecurringTypes = computed(() => getPaginatedLocalItems(
  recurringTypes.value.filter((recurringTypeItem) => (
    normalizeTextForComparison(recurringTypeItem?.name) !== 'mensal'
  )),
  'recurringTypes',
))
const paginatedInvestmentPlans = computed(() => getPaginatedLocalItems(investmentPlans.value, 'investmentPlans'))
const paginatedDebtPlans = computed(() => getPaginatedLocalItems(debtPlans.value, 'debtPlans'))
const paginatedCurrencyRates = computed(() => getPaginatedLocalItems(currencyRates.value, 'currencyRates'))
const paginatedOpenFinanceConnections = computed(() => getPaginatedLocalItems(openFinanceConnections.value, 'openFinanceConnections'))
const exportTotalsLabel = computed(() => {
  const totalItems = Number(exportMeta.value.total || 0)
  if (totalItems <= 0) {
    return '0 de 0 exportações'
  }

  const currentPage = Math.max(1, Number(exportMeta.value.page || 1))
  const pageSize = Math.max(1, Number(exportMeta.value.itemsPerPage || LIST_ITEMS_PER_PAGE))
  const startIndex = (currentPage - 1) * pageSize + 1
  const endIndex = Math.min(startIndex + exportJobs.value.length - 1, totalItems)

  return `${startIndex}-${endIndex} de ${totalItems} exportações`
})
const exportPageCount = computed(() => (
  Math.max(1, Math.ceil(Number(exportMeta.value.total || 0) / Math.max(1, Number(exportMeta.value.itemsPerPage || LIST_ITEMS_PER_PAGE))))
))

const investmentSummary = computed(() => {
  if (!latestSimulation.value) {
    return null
  }

  return {
    invested: formatCurrency(latestSimulation.value.totalInvestedBrl),
    yield: formatCurrency(latestSimulation.value.totalYieldBrl),
    finalAmount: formatCurrency(latestSimulation.value.finalAmountBrl),
  }
})

const accountsDirectionByTab = computed(() => {
  if (activeAccountsTab.value === 'payable') {
    return 'PAYABLE'
  }

  if (activeAccountsTab.value === 'receivable') {
    return 'RECEIVABLE'
  }

  if (activeAccountsTab.value === 'debts') {
    return 'PAYABLE'
  }

  return ''
})

const isOverviewAccountsTab = computed(() => activeAccountsTab.value === 'overview')
const isPayableAccountsTab = computed(() => activeAccountsTab.value === 'payable')
const isReceivableAccountsTab = computed(() => activeAccountsTab.value === 'receivable')
const isDebtsAccountsTab = computed(() => activeAccountsTab.value === 'debts')
const shouldShowEntryManagement = computed(() => isPayableAccountsTab.value || isReceivableAccountsTab.value)
const shouldShowRecurringSection = computed(() => isPayableAccountsTab.value || isReceivableAccountsTab.value)
const shouldShowInstallmentSection = computed(() => isPayableAccountsTab.value || isDebtsAccountsTab.value)
const availableAccountActionOptions = computed(() => {
  const options = []

  if (shouldShowEntryManagement.value) {
    options.push(
      { value: 'ENTRY', label: 'Lançamento avulso' },
      { value: 'SETTLEMENT', label: 'Baixa de lançamento' },
    )
  }

  if (isDebtsAccountsTab.value) {
    options.push({ value: 'SETTLEMENT', label: 'Baixa de lançamento' })
  }

  if (shouldShowRecurringSection.value) {
    options.push({ value: 'RECURRING_RULE', label: 'Regra recorrente' })
  }

  if (shouldShowInstallmentSection.value) {
    options.push(
      { value: 'INSTALLMENT_PLAN', label: 'Plano de parcelamento' },
      { value: 'RENEGOTIATION', label: 'Renegociar plano' },
    )
  }

  return options
})
const accountActionModalTitle = computed(() => {
  if (accountActionModalState.actionType === 'ENTRY') {
    return entryEditingId.value ? 'Editar lançamento' : 'Novo lançamento'
  }

  if (accountActionModalState.actionType === 'SETTLEMENT') {
    return 'Baixa de lançamento'
  }

  if (accountActionModalState.actionType === 'RECURRING_RULE') {
    return recurringRuleEditingId.value ? 'Editar recorrência' : 'Nova recorrência'
  }

  if (accountActionModalState.actionType === 'INSTALLMENT_PLAN') {
    return installmentEditingId.value ? 'Editar parcelamento' : 'Novo parcelamento'
  }

  if (accountActionModalState.actionType === 'RENEGOTIATION') {
    return 'Renegociar plano'
  }

  return 'Gerenciar lançamento'
})

const recurringDirectionByTab = computed(() => {
  if (isPayableAccountsTab.value) {
    return 'PAYABLE'
  }

  if (isReceivableAccountsTab.value) {
    return 'RECEIVABLE'
  }

  return ''
})

const installmentDirectionByTab = computed(() => (
  shouldShowInstallmentSection.value
    ? 'PAYABLE'
    : ''
))

const filteredRecurringRules = computed(() => {
  if (!shouldShowRecurringSection.value) {
    return []
  }

  const targetDirection = recurringDirectionByTab.value
  return recurringRules.value.filter((ruleItem) => {
    const normalizedDirection = String(ruleItem?.direction || '').toUpperCase()
    if (normalizedDirection === '') {
      return targetDirection === 'PAYABLE'
    }

    return normalizedDirection === targetDirection
  })
})

const filteredInstallmentPlans = computed(() => {
  if (!shouldShowInstallmentSection.value) {
    return []
  }

  const targetDirection = installmentDirectionByTab.value
  return installmentPlans.value.filter((planItem) => {
    const normalizedDirection = String(planItem?.direction || '').toUpperCase()
    if (normalizedDirection === '') {
      return targetDirection === 'PAYABLE'
    }

    return normalizedDirection === targetDirection
  })
})

const paginatedRecurringRules = computed(() => getPaginatedLocalItems(filteredRecurringRules.value, 'recurringRules'))
const paginatedInstallmentPlans = computed(() => getPaginatedLocalItems(filteredInstallmentPlans.value, 'installmentPlans'))

const accountsCurrentDirectionLabel = computed(() => (
  accountsDirectionByTab.value
    ? translateFinanceTerm(accountsDirectionByTab.value, 'Todas as direções')
    : 'Todas as direções'
))

const accountsEntriesTitle = computed(() => {
  if (activeAccountsTab.value === 'payable') {
    return 'Lançamentos - Contas a pagar'
  }

  if (activeAccountsTab.value === 'receivable') {
    return 'Lançamentos - Contas a receber'
  }

  return 'Lançamentos unificados (pagar e receber)'
})

const entryTypeOptionsForForm = computed(() => (
  entryForm.direction === 'RECEIVABLE'
    ? manualEntryTypeOptions.filter((entryTypeOption) => entryTypeOption.value !== 'DEBT')
    : manualEntryTypeOptions
))

const accountsOverviewComparisonRows = computed(() => {
  const summary = accountsOverviewSummary.value || dashboardSummary.value
  if (!summary) {
    return []
  }

  const expectedIncomeBrl = Number(summary.expectedIncomeBrl || 0)
  const realizedIncomeBrl = Number(summary.realizedIncomeBrl || 0)
  const expectedExpenseBrl = Number(summary.expectedExpenseBrl || 0)
  const realizedExpenseBrl = Number(summary.realizedExpenseBrl || 0)

  const incomeRemainingBrl = Math.max(0, roundMoney(expectedIncomeBrl - realizedIncomeBrl))
  const expenseRemainingBrl = Math.max(0, roundMoney(expectedExpenseBrl - realizedExpenseBrl))
  const totalExpectedBrl = roundMoney(expectedIncomeBrl - expectedExpenseBrl)
  const totalRealizedBrl = roundMoney(realizedIncomeBrl - realizedExpenseBrl)
  const totalRemainingBrl = roundMoney(incomeRemainingBrl - expenseRemainingBrl)

  return [
    {
      key: 'receivable',
      label: 'Contas a receber',
      expectedBrl: expectedIncomeBrl,
      realizedBrl: realizedIncomeBrl,
      remainingBrl: incomeRemainingBrl,
      progressPercent: expectedIncomeBrl > 0 ? Math.min(100, roundMoney((realizedIncomeBrl / expectedIncomeBrl) * 100)) : 0,
    },
    {
      key: 'payable',
      label: 'Contas a pagar',
      expectedBrl: expectedExpenseBrl,
      realizedBrl: realizedExpenseBrl,
      remainingBrl: expenseRemainingBrl,
      progressPercent: expectedExpenseBrl > 0 ? Math.min(100, roundMoney((realizedExpenseBrl / expectedExpenseBrl) * 100)) : 0,
    },
    {
      key: 'total',
      label: 'Total (Receber - Pagar)',
      expectedBrl: totalExpectedBrl,
      realizedBrl: totalRealizedBrl,
      remainingBrl: totalRemainingBrl,
      progressPercent: null,
    },
  ]
})

const accountsOverviewSelectedMonthLabel = computed(() => formatYearMonthLabel(accountsOverviewMonth.value))

const selectedCurrenciesLabel = computed(() => {
  const selectedCount = Array.isArray(currencyForm.codes) ? currencyForm.codes.length : 0
  if (selectedCount <= 0) {
    return 'Nenhuma moeda selecionada'
  }

  if (selectedCount === 1) {
    return '1 moeda selecionada'
  }

  return `${selectedCount} moedas selecionadas`
})

const debtPreviewSuggestions = computed(() => {
  const suggestions = debtPreview.value?.suggestions
  return Array.isArray(suggestions) ? suggestions : []
})

const availableCreditCardAccounts = computed(() => (
  bankAccounts.value.filter((bankAccountItem) => {
    const accountTypeCode = String(bankAccountItem?.accountType || '').toUpperCase()
    return accountTypeCode === 'CREDIT' && Boolean(bankAccountItem?.isActive)
  })
))

const availableRecurringTypes = computed(() => (
  recurringTypes.value.filter((recurringTypeItem) => {
    const normalizedRecurringTypeName = normalizeTextForComparison(recurringTypeItem?.name)
    return normalizedRecurringTypeName !== 'mensal'
  })
))

const availableSettlementEntries = computed(() => (
  entriesState.value.filter((entryItem) => {
    const remainingAmountBrl = Number(entryItem?.remainingAmountBrl || 0)
    const entryStatusCode = String(entryItem?.status || '').toUpperCase()
    return remainingAmountBrl > 0 && !['PAID', 'RECEIVED', 'CANCELED'].includes(entryStatusCode)
  })
))

const selectedSettlementEntry = computed(() => {
  const selectedEntryId = normalizeOptionalNumber(settlementForm.entryId)
  if (!selectedEntryId) {
    return null
  }

  return availableSettlementEntries.value.find((entryItem) => (
    Number(entryItem?.id) === selectedEntryId
  )) || null
})

const selectedSettlementRemainingAmountBrl = computed(() => Number(selectedSettlementEntry.value?.remainingAmountBrl || 0))
const selectedSettlementEntryTypeCode = computed(() => String(selectedSettlementEntry.value?.entryType || '').toUpperCase())

function autofillSettlementAmountFromSelectedEntry(forceReplace = false) {
  const remainingAmountBrl = Number(selectedSettlementEntry.value?.remainingAmountBrl || 0)
  if (!Number.isFinite(remainingAmountBrl) || remainingAmountBrl <= 0) {
    if (forceReplace) {
      settlementForm.amountBrl = ''
    }
    return
  }

  const currentSettlementAmountBrl = normalizeMoneyInputValue(settlementForm.amountBrl, 0)
  if (!forceReplace && currentSettlementAmountBrl > 0) {
    return
  }

  settlementForm.amountBrl = remainingAmountBrl.toFixed(2)
}

onBeforeMount(() => {
  activeTab.value = sanitizeFinanceTab(activeTab.value)
})

onMounted(async () => {
  if (!effectiveCurrentUser.value?.id) {
    return
  }

  await loadInitialData()
})

onActivated(async () => {
  if (!financeKeepAlivePaused.value) {
    return
  }

  financeKeepAlivePaused.value = false
  if (!effectiveCurrentUser.value?.id) {
    return
  }

  await loadInitialData()
})

onDeactivated(() => {
  financeKeepAlivePaused.value = true
})

onBeforeUnmount(() => {
  financeKeepAlivePaused.value = true
  financeRuntimeError.value = ''
})

onErrorCaptured((capturedError) => {
  financeRuntimeError.value = String(capturedError?.message || capturedError || '')
  return false
})

watch(
  () => effectiveCurrentUser.value?.id,
  async (userId, previousUserId) => {
    if (!userId) {
      resetLocalState()
      return
    }

    if (userId !== previousUserId) {
      await loadInitialData()
    }
  },
)

watch(
  () => props.initialSection,
  (nextSection) => {
    const mappedTabKey = sanitizeFinanceTab(sectionToTabKey[nextSection] || 'accounts')
    if (activeTab.value !== mappedTabKey) {
      activeTab.value = mappedTabKey
    }

    if (nextSection === 'debts') {
      activeAccountsTab.value = 'debts'
    }
  },
  { immediate: true },
)

watch(activeTab, async (nextTabKey) => {
  const normalizedTabKey = sanitizeFinanceTab(nextTabKey)
  if (normalizedTabKey !== nextTabKey) {
    activeTab.value = normalizedTabKey
    return
  }

  if (normalizedTabKey === 'accounts') {
    await loadAccountsCurrentTabData()
    return
  }

  if (normalizedTabKey === 'banks') {
    await loadCatalogs()
    return
  }

  if (normalizedTabKey === 'investments') {
    await loadInvestments()
    return
  }

  if (normalizedTabKey === 'settings') {
    await Promise.all([
      loadCatalogs(),
      refreshCurrencyData(),
    ])
    return
  }

  if (normalizedTabKey === 'reports') {
    await Promise.all([
      loadDashboard(),
      loadExports(),
      loadOpenFinance(),
    ])
  }
})

watch(activeAccountsTab, async (nextAccountsTab) => {
  const normalizedAccountsTab = sanitizeAccountSubtab(nextAccountsTab)
  if (normalizedAccountsTab !== nextAccountsTab) {
    activeAccountsTab.value = normalizedAccountsTab
    return
  }

  if (activeTab.value !== 'accounts') {
    return
  }

  if (entryEditingId.value) {
    resetEntryForm()
  }

  if (recurringRuleEditingId.value) {
    resetRecurringRuleForm()
  }

  if (accountActionModalState.isOpen) {
    closeAccountActionModal()
  }

  applyAccountsDirectionContext()
  entryFilters.page = 1
  localListPages.recurringRules = 1
  localListPages.installmentPlans = 1

  await loadAccountsCurrentTabData(normalizedAccountsTab)
})

watch(
  availableAccountActionOptions,
  (nextOptions) => {
    if (!Array.isArray(nextOptions) || nextOptions.length <= 0) {
      return
    }

    const selectedOptionExists = nextOptions.some((actionOption) => actionOption.value === selectedAccountActionType.value)
    if (!selectedOptionExists) {
      selectedAccountActionType.value = nextOptions[0].value
    }

    const modalOptionExists = nextOptions.some((actionOption) => actionOption.value === accountActionModalState.actionType)
    if (!modalOptionExists) {
      accountActionModalState.actionType = nextOptions[0].value
    }
  },
  { immediate: true },
)

watch(
  () => entryForm.direction,
  (nextDirection) => {
    if (nextDirection === 'RECEIVABLE' && entryForm.entryType === 'DEBT') {
      entryForm.entryType = 'ONE_OFF'
    }
  },
)

watch(
  () => settlementForm.useCreditCard,
  (useCreditCardSettlement) => {
    if (useCreditCardSettlement) {
      settlementForm.bankAccountId = ''
      if (!normalizeOptionalNumber(settlementForm.creditCardId) && availableCreditCardAccounts.value.length > 0) {
        settlementForm.creditCardId = String(availableCreditCardAccounts.value[0].id)
      }

      return
    }

    settlementForm.creditCardId = ''
    settlementForm.creditCardDueDate = ''
  },
)

watch(
  () => settlementForm.entryId,
  () => {
    autofillSettlementAmountFromSelectedEntry(true)
  },
)

watch(
  availableCreditCardAccounts,
  (nextCreditCards) => {
    if (!Array.isArray(nextCreditCards) || nextCreditCards.length <= 0) {
      settlementForm.creditCardId = ''
      return
    }

    if (normalizeOptionalNumber(settlementForm.creditCardId)) {
      return
    }

    settlementForm.creditCardId = String(nextCreditCards[0].id)
  },
  { immediate: true },
)

watch(
  () => manualCurrencyRateForm.currencyCode,
  (nextCurrencyCode) => {
    const resolvedCurrencyName = resolveCurrencyNameByCode(nextCurrencyCode)
    if (resolvedCurrencyName !== '') {
      manualCurrencyRateForm.currencyName = resolvedCurrencyName
    }
  },
)

watch(financeRuntimeError, (runtimeErrorMessage) => {
  if (!runtimeErrorMessage) {
    return
  }

  notifyUser(
    translateFinanceScreen('financeScreen.errors.unexpectedChild', 'Ocorreu um erro inesperado na tela financeira.'),
    'error',
  )
  financeRuntimeError.value = ''
})

async function loadAccountsCurrentTabData(accountsTabKey = activeAccountsTab.value) {
  const normalizedAccountsTabKey = sanitizeAccountSubtab(accountsTabKey)
  if (normalizedAccountsTabKey !== activeAccountsTab.value) {
    activeAccountsTab.value = normalizedAccountsTabKey
  }

  if (activeTab.value !== 'accounts') {
    return
  }

  applyAccountsDirectionContext()

  if (normalizedAccountsTabKey === 'overview') {
    await loadEntries()
    await loadDashboard()
    return
  }

  if (normalizedAccountsTabKey === 'debts') {
    await Promise.all([
      loadDebtPlans(),
      loadCatalogs(),
      loadInstallments(),
      loadEntries(),
    ])
    return
  }

  const accountRequests = [loadEntries()]

  if (normalizedAccountsTabKey === 'payable' || normalizedAccountsTabKey === 'receivable') {
    accountRequests.push(loadRecurringRules(), loadInstallments())
  }

  await Promise.all(accountRequests)
}

async function loadInitialData() {
  if (!effectiveCurrentUser.value?.id) {
    return
  }

  activeTab.value = sanitizeFinanceTab(activeTab.value)

  await Promise.all([
    loadCatalogs(),
    loadRecurringRules(),
    loadInstallments(),
    loadInvestments(),
    loadDebtPlans(),
    loadExports(),
    loadOpenFinance(),
  ])

  await loadEntries()
  await loadDashboard()

  if (activeTab.value === 'settings') {
    await refreshCurrencyData()
  }
}

function resetLocalState() {
  dashboardSummary.value = null
  accountsOverviewSummary.value = null
  dashboardCashflow.value = []
  dashboardCategories.value = []
  entriesState.value = []
  entriesMeta.value = { page: 1, itemsPerPage: LIST_ITEMS_PER_PAGE, total: 0 }
  recurringRules.value = []
  installmentPlans.value = []
  investmentPlans.value = []
  debtPlans.value = []
  debtPreview.value = null
  debtPreviewRequestPayload.value = null
  debtPlanCreationSuggestionKey.value = ''
  accountActionModalState.isOpen = false
  accountActionModalState.actionType = 'ENTRY'
  selectedAccountActionType.value = 'ENTRY'
  currenciesCatalog.value = []
  currencyRates.value = []
  exportJobs.value = []
  exportMeta.value = { page: 1, itemsPerPage: LIST_ITEMS_PER_PAGE, total: 0 }
  migrationImportFile.value = null
  latestSimulation.value = null
  openFinanceProviders.value = []
  openFinanceConnections.value = []
  categories.value = []
  recurringTypes.value = []
  bankAccounts.value = []
}

async function loadCatalogs() {
  loadingState.catalogs = true

  try {
    const [categoriesResponse, recurringTypesResponse, bankAccountsResponse] = await Promise.all([
      fetchFinanceCategories(),
      fetchFinanceRecurringTypes(),
      fetchFinanceBankAccounts(),
    ])

    categories.value = Array.isArray(categoriesResponse.data?.items) ? categoriesResponse.data.items : []

    const loadedRecurringTypes = Array.isArray(recurringTypesResponse.data?.items) ? recurringTypesResponse.data.items : []
    recurringTypes.value = loadedRecurringTypes.filter((recurringTypeItem) => (
      normalizeTextForComparison(recurringTypeItem?.name) !== 'mensal'
    ))

    bankAccounts.value = Array.isArray(bankAccountsResponse.data?.items) ? bankAccountsResponse.data.items : []
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateFinanceScreen('financeScreen.errors.loadCatalog', 'Falha ao carregar catálogo financeiro.'),
      ),
      'error',
    )
  } finally {
    loadingState.catalogs = false
  }
}

async function loadDashboard() {
  loadingState.dashboard = true

  try {
    const currentMonthDateRange = buildCurrentMonthDateRange()
    const selectedMonthReferenceDate = buildReferenceDateFromYearMonth(accountsOverviewMonth.value)
    const selectedMonthDateRange = buildCurrentMonthDateRange(selectedMonthReferenceDate)

    const [summaryResponse, selectedMonthSummaryResponse, cashflowResponse, categoriesResponse] = await Promise.all([
      fetchFinanceDashboardSummary(currentMonthDateRange),
      fetchFinanceDashboardSummary(selectedMonthDateRange),
      fetchFinanceDashboardCashflow(),
      fetchFinanceDashboardCategories({ limit: LIST_ITEMS_PER_PAGE }),
    ])

    dashboardSummary.value = summaryResponse.data?.item || null
    accountsOverviewSummary.value = selectedMonthSummaryResponse.data?.item || null
    dashboardCashflow.value = Array.isArray(cashflowResponse.data?.items) ? cashflowResponse.data.items : []
    dashboardCategories.value = Array.isArray(categoriesResponse.data?.items) ? categoriesResponse.data.items : []
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateFinanceScreen('financeScreen.errors.loadDashboard', 'Falha ao carregar dashboard financeiro.'),
      ),
      'error',
    )
  } finally {
    loadingState.dashboard = false
  }
}

async function handleAccountsOverviewMonthChange() {
  if (!/^\d{4}-\d{2}$/.test(String(accountsOverviewMonth.value || ''))) {
    accountsOverviewMonth.value = getCurrentYearMonthInputValue()
  }

  await loadDashboard()
}

async function refreshAccountsOverviewValues() {
  await Promise.all([
    loadDashboard(),
    loadEntries(),
  ])
}

async function loadEntries() {
  loadingState.entries = true

  try {
    const normalizedDirectionFilter = sanitizeFinanceFilterByCatalog(entryFilters.direction, directionOptions)
    const normalizedStatusFilter = sanitizeFinanceFilterByCatalog(entryFilters.status, entryStatusOptions)
    const normalizedSearchFilter = sanitizeSingleLineText(entryFilters.search, FINANCE_SEARCH_MAX_LENGTH)
    const normalizedStartDateFilter = sanitizeDateInput(entryFilters.startDate)
    const normalizedEndDateFilter = sanitizeDateInput(entryFilters.endDate)

    const response = await fetchFinanceEntries({
      direction: normalizedDirectionFilter || undefined,
      status: normalizedStatusFilter || undefined,
      search: normalizedSearchFilter || undefined,
      startDate: normalizedStartDateFilter || undefined,
      endDate: normalizedEndDateFilter || undefined,
    }, {
      page: entryFilters.page,
      itemsPerPage: entryFilters.itemsPerPage,
    })

    entriesState.value = Array.isArray(response.data?.items) ? response.data.items : []
    entriesMeta.value = response.data?.meta || { page: 1, itemsPerPage: LIST_ITEMS_PER_PAGE, total: 0 }
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateFinanceScreen('financeScreen.errors.loadEntries', 'Falha ao carregar lançamentos.'),
      ),
      'error',
    )
  } finally {
    loadingState.entries = false
  }
}

function applyEntryFilters(nextFilters = {}) {
  entryFilters.search = sanitizeSingleLineText(nextFilters.search, FINANCE_SEARCH_MAX_LENGTH)
  entryFilters.direction = sanitizeFinanceFilterByCatalog(nextFilters.direction, directionOptions)
  entryFilters.status = sanitizeFinanceFilterByCatalog(nextFilters.status, entryStatusOptions)
  entryFilters.startDate = sanitizeDateInput(nextFilters.startDate)
  entryFilters.endDate = sanitizeDateInput(nextFilters.endDate)

  if (entryFilters.startDate !== '' && entryFilters.endDate !== '' && entryFilters.startDate > entryFilters.endDate) {
    const startDateSnapshot = entryFilters.startDate
    entryFilters.startDate = entryFilters.endDate
    entryFilters.endDate = startDateSnapshot
  }

  entryFilters.page = 1
  void loadEntries()
}

function setEntriesPage(page) {
  const totalPages = Math.max(1, Math.ceil(Number(entriesMeta.value.total || 0) / Math.max(1, Number(entriesMeta.value.itemsPerPage || LIST_ITEMS_PER_PAGE))))
  const rawPageValue = Number(page || 1)
  const normalizedPage = Number.isFinite(rawPageValue)
    ? Math.min(totalPages, Math.max(1, rawPageValue))
    : 1

  if (normalizedPage === Number(entryFilters.page || 1)) {
    return
  }

  entryFilters.page = normalizedPage
  void loadEntries()
}

async function loadRecurringRules() {
  loadingState.recurring = true

  try {
    const response = await fetchFinanceRecurringRules()
    recurringRules.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateFinanceScreen('financeScreen.errors.loadRecurringRules', 'Falha ao carregar recorrências.'),
      ),
      'error',
    )
  } finally {
    loadingState.recurring = false
  }
}

async function loadInstallments() {
  loadingState.installments = true

  try {
    const response = await fetchFinanceInstallmentPlans()
    installmentPlans.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateFinanceScreen('financeScreen.errors.loadInstallments', 'Falha ao carregar parcelamentos.'),
      ),
      'error',
    )
  } finally {
    loadingState.installments = false
  }
}

async function loadInvestments() {
  loadingState.investments = true

  try {
    const response = await fetchFinanceInvestmentPlans()
    investmentPlans.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateFinanceScreen('financeScreen.errors.loadInvestments', 'Falha ao carregar planos de investimento.'),
      ),
      'error',
    )
  } finally {
    loadingState.investments = false
  }
}

async function loadDebtPlans() {
  loadingState.debts = true

  try {
    const response = await fetchFinanceDebtPlans()
    debtPlans.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(
      extractHttpMessage(
        requestError,
        translateFinanceScreen('financeScreen.errors.loadDebtPlans', 'Falha ao carregar planos de dívida.'),
      ),
      'error',
    )
  } finally {
    loadingState.debts = false
  }
}

function buildDebtSimulationPayload() {
  const normalizedNegotiatedAmountBrl = normalizeOptionalMoneyInputValue(debtForm.negotiatedAmountBrl)
  const normalizedProposedAmountBrl = normalizeOptionalMoneyInputValue(debtForm.proposedAmountBrl)
  const normalizedDiscountAmountBrl = normalizeOptionalMoneyInputValue(debtForm.discountAmountBrl)

  return {
    title: debtForm.title,
    creditorName: debtForm.creditorName || null,
    totalAmountBrl: normalizeMoneyInputValue(debtForm.totalAmountBrl, 0),
    negotiatedAmountBrl: normalizedNegotiatedAmountBrl,
    proposedAmountBrl: normalizedProposedAmountBrl,
    discountAmountBrl: normalizedDiscountAmountBrl,
    downPaymentBrl: normalizeMoneyInputValue(debtForm.downPaymentBrl, 0),
    maxCommitmentPercent: normalizeMoneyInputValue(debtForm.maxCommitmentPercent, 30),
    desiredInstallmentsCount: Number(debtForm.desiredInstallmentsCount || 0),
    firstDueDate: debtForm.firstDueDate || null,
    categoryId: normalizeOptionalNumber(debtForm.categoryId),
    defaultBankAccountId: normalizeOptionalNumber(debtForm.defaultBankAccountId),
  }
}

async function previewDebtPlan() {
  try {
    const debtSimulationPayload = buildDebtSimulationPayload()
    const response = await previewFinanceDebtPlan(debtSimulationPayload)

    debtPreview.value = response.data?.item || null
    debtPreviewRequestPayload.value = debtSimulationPayload

    notifyUser('Simulação de dívida atualizada.', 'success')
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível simular o plano de dívida.'), 'error')
  }
}

function isCreatingDebtPlanFromSuggestion(suggestionOption) {
  return debtPlanCreationSuggestionKey.value !== '' && debtPlanCreationSuggestionKey.value === String(suggestionOption?.key || '')
}

function hasDebtRecommendationLimit() {
  return Number(debtPreview.value?.maxRecommendedPaymentBrl || 0) > 0
}

function canCreateDebtPlanFromSuggestion(suggestionOption) {
  if (!suggestionOption) {
    return false
  }

  if (!hasDebtRecommendationLimit()) {
    return true
  }

  return suggestionOption.withinLimit === true
}

function getDebtSuggestionLimitBadgeStatus(suggestionOption) {
  if (!hasDebtRecommendationLimit()) {
    return 'SCHEDULED'
  }

  return suggestionOption?.withinLimit === true ? 'PAID' : 'OVERDUE'
}

function getDebtSuggestionLimitBadgeLabel(suggestionOption) {
  if (!hasDebtRecommendationLimit()) {
    return 'Sem base'
  }

  return suggestionOption?.withinLimit === true ? 'Sim' : 'Não'
}

async function createDebtPlanFromSuggestion(suggestionOption) {
  if (!debtPreview.value || !debtPreviewRequestPayload.value) {
    notifyUser('Execute a simulação antes de criar o plano de dívida.', 'warning')
    return
  }

  if (!canCreateDebtPlanFromSuggestion(suggestionOption)) {
    notifyUser('Selecione uma opção válida para criar o plano.', 'warning')
    return
  }

  const selectedInstallmentsCount = Number(suggestionOption.installmentsCount || 0)
  if (selectedInstallmentsCount <= 0) {
    notifyUser('Não foi possível identificar a quantidade de parcelas da opção selecionada.', 'warning')
    return
  }

  const selectedSuggestionKey = String(suggestionOption.key || '')
  if (selectedSuggestionKey === '') {
    notifyUser('Não foi possível identificar a opção selecionada. Refaça a simulação.', 'warning')
    return
  }

  debtPlanCreationSuggestionKey.value = selectedSuggestionKey

  try {
    await createFinanceDebtPlan({
      ...debtPreviewRequestPayload.value,
      selectedSuggestionKey,
      settlementMode: suggestionOption.settlementMode,
      selectedInstallmentsCount,
    })

    notifyUser(`Plano criado com ${selectedInstallmentsCount} parcela(s).`, 'success')
    await Promise.all([loadDebtPlans(), loadEntries(), loadInstallments()])
    await loadDashboard()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar o plano de dívida.'), 'error')
  } finally {
    debtPlanCreationSuggestionKey.value = ''
  }
}

async function loadCurrenciesCatalog() {
  try {
    const response = await fetchFinanceCurrencies()
    currenciesCatalog.value = Array.isArray(response.data?.items) ? response.data.items : []

    if (currencyForm.codes.length === 0 && currenciesCatalog.value.length > 0) {
      currencyForm.codes = currenciesCatalog.value.slice(0, 4).map((currencyItem) => String(currencyItem.code))
    }

    const availableCurrencyCodes = currenciesCatalog.value.map((currencyItem) => String(currencyItem.code))
    currencyForm.codes = currencyForm.codes.filter((currencyCode) => availableCurrencyCodes.includes(String(currencyCode)))

    if ((!currencyCodePickerForm.selectedCode || !availableCurrencyCodes.includes(currencyCodePickerForm.selectedCode)) && currenciesCatalog.value.length > 0) {
      currencyCodePickerForm.selectedCode = String(currenciesCatalog.value[0].code)
    }

    if ((!manualCurrencyRateForm.currencyCode || !availableCurrencyCodes.includes(manualCurrencyRateForm.currencyCode)) && currenciesCatalog.value.length > 0) {
      manualCurrencyRateForm.currencyCode = String(currenciesCatalog.value[0].code)
    }

    if (manualCurrencyRateForm.currencyName.trim() === '') {
      manualCurrencyRateForm.currencyName = resolveCurrencyNameByCode(manualCurrencyRateForm.currencyCode)
    }
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar catálogo de moedas.'), 'error')
  }
}

async function loadCurrencyRates(options = {}) {
  loadingState.currencies = true

  try {
    const selectedCurrencyCodes = Array.isArray(currencyForm.codes) ? currencyForm.codes : []
    const forceRefreshFromApi = Boolean(options.forceRefresh)

    const response = await fetchFinanceCurrencyRates({
      date: currencyForm.date || undefined,
      codes: selectedCurrencyCodes.join(',') || undefined,
      forceRefresh: forceRefreshFromApi ? '1' : undefined,
    })

    currencyRates.value = Array.isArray(response.data?.items) ? response.data.items : []
    if (typeof response.data?.requestedDate === 'string' && response.data.requestedDate !== '') {
      currencyForm.date = response.data.requestedDate
    }

    if (forceRefreshFromApi) {
      notifyUser('Cotações atualizadas com nova consulta da API.', 'success')
    }
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar cotações de moedas.'), 'error')
  } finally {
    loadingState.currencies = false
  }
}

async function refreshCurrencyData() {
  await loadCurrenciesCatalog()
  await loadCurrencyRates()
}

function resolveCurrencyNameByCode(currencyCode) {
  const normalizedCurrencyCode = String(currencyCode || '').toUpperCase()
  const matchedCurrency = currenciesCatalog.value.find((currencyItem) => (
    String(currencyItem?.code || '').toUpperCase() === normalizedCurrencyCode
  ))

  return String(matchedCurrency?.name || normalizedCurrencyCode)
}

function addCurrencyCodeToSelection() {
  const selectedCurrencyCode = String(currencyCodePickerForm.selectedCode || '').toUpperCase()
  if (!selectedCurrencyCode) {
    notifyUser('Selecione uma moeda para adicionar.', 'warning')
    return
  }

  const currentCodes = Array.isArray(currencyForm.codes) ? [...currencyForm.codes] : []
  if (currentCodes.includes(selectedCurrencyCode)) {
    notifyUser('Essa moeda já está selecionada.', 'warning')
    return
  }

  currencyForm.codes = [...currentCodes, selectedCurrencyCode]
  notifyUser(`Moeda ${selectedCurrencyCode} adicionada na seleção.`, 'success')
}

function requestForceCurrencyRefresh() {
  openConfirmDialog({
    title: 'Forçar atualização da API',
    message: 'Isso fará nova consulta na API externa agora. Deseja continuar?',
    confirmLabel: 'Atualizar da API',
    confirmTone: 'danger',
    onConfirm: async () => {
      await loadCurrencyRates({ forceRefresh: true })
    },
  })
}

async function submitManualCurrencyRate() {
  const manualCurrencyCode = String(manualCurrencyRateForm.currencyCode || '').toUpperCase()
  const manualRateBrl = Number(manualCurrencyRateForm.rateBrl)

  if (!manualCurrencyCode || manualCurrencyCode.length !== 3) {
    notifyUser('Informe uma sigla válida de moeda (3 letras).', 'warning')
    return
  }

  if (!Number.isFinite(manualRateBrl) || manualRateBrl <= 0) {
    notifyUser('Informe uma taxa válida maior que zero.', 'warning')
    return
  }

  try {
    await createFinanceCurrencyRateManual({
      quoteDate: manualCurrencyRateForm.quoteDate || getCurrentDateInputValue(),
      currencyCode: manualCurrencyCode,
      currencyName: manualCurrencyRateForm.currencyName || resolveCurrencyNameByCode(manualCurrencyCode),
      rateBrl: manualRateBrl,
    })

    notifyUser('Cotação manual salva com sucesso.', 'success')
    manualCurrencyRateForm.rateBrl = ''

    if (!Array.isArray(currencyForm.codes) || !currencyForm.codes.includes(manualCurrencyCode)) {
      currencyForm.codes = [...(Array.isArray(currencyForm.codes) ? currencyForm.codes : []), manualCurrencyCode]
    }

    await loadCurrencyRates()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível salvar a cotação manual.'), 'error')
  }
}

async function loadExports(page = Number(exportMeta.value.page || 1)) {
  loadingState.exports = true

  try {
    const response = await fetchFinanceExports({
      page,
      itemsPerPage: LIST_ITEMS_PER_PAGE,
    })
    exportJobs.value = Array.isArray(response.data?.items) ? response.data.items : []
    exportMeta.value = response.data?.meta || { page, itemsPerPage: LIST_ITEMS_PER_PAGE, total: exportJobs.value.length }
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar exportações.'), 'error')
  } finally {
    loadingState.exports = false
  }
}

function setExportPage(page) {
  const normalizedPage = Math.min(exportPageCount.value, Math.max(1, Number(page || 1)))
  if (normalizedPage === Number(exportMeta.value.page || 1)) {
    return
  }

  void loadExports(normalizedPage)
}

async function loadOpenFinance() {
  loadingState.openFinance = true

  try {
    const [providersResponse, connectionsResponse] = await Promise.all([
      fetchFinanceOpenFinanceProviders(),
      fetchFinanceOpenFinanceConnections(),
    ])

    openFinanceProviders.value = Array.isArray(providersResponse.data?.items) ? providersResponse.data.items : []
    openFinanceConnections.value = Array.isArray(connectionsResponse.data?.items) ? connectionsResponse.data.items : []
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar Open Finance.'), 'error')
  } finally {
    loadingState.openFinance = false
  }
}

async function submitEntry() {
  try {
    const entryDueDate = entryForm.dueDate || getCurrentDateInputValue()

    const entryPayload = {
      direction: entryForm.direction,
      entryType: entryForm.entryType,
      title: entryForm.title,
      expectedAmountBrl: Number(entryForm.expectedAmountBrl),
      dueDate: entryDueDate,
      categoryId: normalizeOptionalNumber(entryForm.categoryId),
      bankAccountId: normalizeOptionalNumber(entryForm.bankAccountId),
    }

    if (entryEditingId.value) {
      await updateFinanceEntry(entryEditingId.value, entryPayload)
      notifyUser('Lançamento atualizado com sucesso.', 'success')
    } else {
      await createFinanceEntry(entryPayload)
      notifyUser('Lançamento criado com sucesso.', 'success')
    }

    resetEntryForm()
    closeAccountActionModal()
    await loadEntries()
    await loadDashboard()
  } catch (requestError) {
    const fallbackMessage = entryEditingId.value
      ? 'Não foi possível atualizar o lançamento.'
      : 'Não foi possível criar o lançamento.'
    notifyUser(extractHttpMessage(requestError, fallbackMessage), 'error')
  }
}

function toDateInputValue(rawDateValue) {
  const normalizedDateLabel = formatDate(rawDateValue, '')
  if (normalizedDateLabel === '') {
    return ''
  }

  const dateParts = normalizedDateLabel.split('/')
  if (dateParts.length !== 3) {
    return ''
  }

  const [dayPart, monthPart, yearPart] = dateParts
  return `${yearPart}-${monthPart}-${dayPart}`
}

function startEditingEntry(entryItem) {
  if (!entryItem?.id) {
    return
  }

  entryEditingId.value = entryItem.id
  entryForm.direction = String(entryItem.direction || accountsDirectionByTab.value || 'PAYABLE')
  entryForm.entryType = String(entryItem.entryType || 'ONE_OFF')
  entryForm.title = String(entryItem.title || '')
  entryForm.expectedAmountBrl = String(entryItem.expectedAmountBrl ?? '')
  entryForm.dueDate = toDateInputValue(entryItem.dueDate) || getCurrentDateInputValue()
  entryForm.categoryId = entryItem.categoryId ? String(entryItem.categoryId) : ''
  entryForm.bankAccountId = entryItem.bankAccountId ? String(entryItem.bankAccountId) : ''
  openAccountActionModal('ENTRY')
}

function resetEntryForm() {
  entryEditingId.value = null
  entryForm.title = ''
  entryForm.expectedAmountBrl = ''
  entryForm.dueDate = getCurrentDateInputValue()
  entryForm.entryType = 'ONE_OFF'
  entryForm.categoryId = ''
  entryForm.bankAccountId = ''
  entryForm.direction = accountsDirectionByTab.value || 'PAYABLE'
}

function resetSettlementForm() {
  settlementForm.entryId = ''
  settlementForm.amountBrl = ''
  settlementForm.settledAt = ''
  settlementForm.bankAccountId = ''
  settlementForm.useCreditCard = false
  settlementForm.creditCardId = ''
  settlementForm.creditCardInterestRatePercent = '2.99'
  settlementForm.creditCardIofRatePercent = '0.38'
  settlementForm.creditCardDueDate = ''
}

function resetInstallmentForm() {
  installmentEditingId.value = null
  installmentForm.direction = installmentDirectionByTab.value || 'PAYABLE'
  installmentForm.title = ''
  installmentForm.totalAmountBrl = ''
  installmentForm.downPaymentBrl = '0'
  installmentForm.installmentsCount = 12
  installmentForm.interestAmountBrl = '0'
  installmentForm.discountAmountBrl = '0'
  installmentForm.fineAmountBrl = '0'
  installmentForm.firstDueDate = ''
  installmentForm.categoryId = ''
  installmentForm.defaultBankAccountId = ''
}

function resetRenegotiationForm() {
  renegotiationForm.planId = ''
  renegotiationForm.installmentsCount = 6
  renegotiationForm.reason = 'Renegociação manual'
  renegotiationForm.categoryId = ''
  renegotiationForm.defaultBankAccountId = ''
}

function closeAccountActionModal() {
  accountActionModalState.isOpen = false
}

function prepareAccountActionFormForCreate(actionType) {
  if (actionType === 'ENTRY') {
    resetEntryForm()
    return
  }

  if (actionType === 'SETTLEMENT') {
    resetSettlementForm()
    return
  }

  if (actionType === 'RECURRING_RULE') {
    resetRecurringRuleForm()
    return
  }

  if (actionType === 'INSTALLMENT_PLAN') {
    resetInstallmentForm()
    return
  }

  if (actionType === 'RENEGOTIATION') {
    resetRenegotiationForm()
  }
}

function openAccountActionModal(actionType, shouldPrepareCreate = false) {
  const normalizedActionType = String(actionType || '').toUpperCase()
  const actionExists = availableAccountActionOptions.value.some((actionOption) => actionOption.value === normalizedActionType)
  if (!actionExists) {
    return
  }

  if (shouldPrepareCreate) {
    prepareAccountActionFormForCreate(normalizedActionType)
  }

  accountActionModalState.actionType = normalizedActionType
  selectedAccountActionType.value = normalizedActionType
  accountActionModalState.isOpen = true
}

function openSelectedAccountActionModal() {
  openAccountActionModal(selectedAccountActionType.value, true)
}

function openConfirmDialog(options) {
  confirmDialogState.title = String(options?.title || 'Confirmar ação')
  confirmDialogState.message = String(options?.message || '')
  confirmDialogState.confirmLabel = String(options?.confirmLabel || 'Confirmar')
  confirmDialogState.confirmTone = String(options?.confirmTone || 'danger')
  confirmDialogState.processing = false
  confirmDialogState.isOpen = true
  confirmDialogAction.value = typeof options?.onConfirm === 'function' ? options.onConfirm : null
}

function closeConfirmDialog() {
  if (confirmDialogState.processing) {
    return
  }

  confirmDialogState.isOpen = false
  confirmDialogState.title = ''
  confirmDialogState.message = ''
  confirmDialogState.confirmLabel = 'Confirmar'
  confirmDialogState.confirmTone = 'danger'
  confirmDialogAction.value = null
}

async function handleConfirmDialogAction() {
  if (confirmDialogState.processing) {
    return
  }

  if (typeof confirmDialogAction.value !== 'function') {
    closeConfirmDialog()
    return
  }

  confirmDialogState.processing = true

  try {
    await confirmDialogAction.value()
  } finally {
    confirmDialogState.processing = false
    closeConfirmDialog()
  }
}

function requestDeleteEntry(entryItem) {
  if (!entryItem?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir lançamento',
    message: 'Esse lançamento será removido. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => deleteEntry(entryItem),
  })
}

async function deleteEntry(entryItem) {
  try {
    await deleteFinanceEntry(entryItem.id)

    if (entryEditingId.value === entryItem.id) {
      resetEntryForm()
    }

    notifyUser('Lançamento removido com sucesso.', 'success')
    await Promise.all([loadEntries(), loadInstallments()])
    await loadDashboard()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível remover o lançamento.'), 'error')
  }
}

async function submitCategory() {
  try {
    const payload = {
      name: categoryForm.name,
      kind: categoryForm.kind,
    }

    if (categoryEditingId.value) {
      await updateFinanceCategory(categoryEditingId.value, payload)
      notifyUser('Categoria atualizada com sucesso.', 'success')
    } else {
      await createFinanceCategory(payload)
      notifyUser('Categoria criada com sucesso.', 'success')
    }

    resetCategoryForm()
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível salvar a categoria.'), 'error')
  }
}

function startEditingCategory(categoryItem) {
  if (!categoryItem?.id) {
    return
  }

  categoryEditingId.value = categoryItem.id
  categoryForm.name = String(categoryItem.name || '')
  categoryForm.kind = String(categoryItem.kind || 'BOTH')
}

function resetCategoryForm() {
  categoryEditingId.value = null
  categoryForm.name = ''
  categoryForm.kind = 'BOTH'
}

async function toggleCategoryStatus(categoryItem) {
  if (!categoryItem?.id) {
    return
  }

  try {
    await updateFinanceCategory(categoryItem.id, {
      isActive: !categoryItem.isActive,
    })

    notifyUser('Status da categoria atualizado.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível atualizar a categoria.'), 'error')
  }
}

async function deleteCategory(categoryItem) {
  if (!categoryItem?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir categoria',
    message: 'A categoria será inativada e não aparecerá mais para novos lançamentos. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteCategory(categoryItem),
  })
}

async function executeDeleteCategory(categoryItem) {
  try {
    await updateFinanceCategory(categoryItem.id, {
      isActive: false,
    })

    if (categoryEditingId.value === categoryItem.id) {
      resetCategoryForm()
    }

    notifyUser('Categoria excluída com sucesso.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível excluir a categoria.'), 'error')
  }
}

async function submitRecurringTypeCatalog() {
  try {
    const payload = {
      name: recurringTypeCatalogForm.name,
      description: recurringTypeCatalogForm.description || null,
    }

    if (recurringTypeEditingId.value) {
      await updateFinanceRecurringType(recurringTypeEditingId.value, payload)
      notifyUser('Tipo de recorrência atualizado com sucesso.', 'success')
    } else {
      await createFinanceRecurringType(payload)
      notifyUser('Tipo de recorrência criado com sucesso.', 'success')
    }

    resetRecurringTypeForm()
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível salvar o tipo de recorrência.'), 'error')
  }
}

function startEditingRecurringType(recurringTypeItem) {
  if (!recurringTypeItem?.id) {
    return
  }

  recurringTypeEditingId.value = recurringTypeItem.id
  recurringTypeCatalogForm.name = String(recurringTypeItem.name || '')
  recurringTypeCatalogForm.description = String(recurringTypeItem.description || '')
}

function resetRecurringTypeForm() {
  recurringTypeEditingId.value = null
  recurringTypeCatalogForm.name = ''
  recurringTypeCatalogForm.description = ''
}

async function toggleRecurringTypeStatus(recurringTypeItem) {
  if (!recurringTypeItem?.id) {
    return
  }

  try {
    await updateFinanceRecurringType(recurringTypeItem.id, {
      isActive: !recurringTypeItem.isActive,
    })

    notifyUser('Status do tipo recorrente atualizado.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível atualizar o tipo recorrente.'), 'error')
  }
}

async function deleteRecurringType(recurringTypeItem) {
  if (!recurringTypeItem?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir tipo recorrente',
    message: 'O tipo de recorrência será excluído do catálogo. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteRecurringType(recurringTypeItem),
  })
}

async function executeDeleteRecurringType(recurringTypeItem) {
  try {
    await deleteFinanceRecurringType(recurringTypeItem.id)

    if (recurringTypeEditingId.value === recurringTypeItem.id) {
      resetRecurringTypeForm()
    }

    notifyUser('Tipo de recorrência excluído com sucesso.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível excluir o tipo de recorrência.'), 'error')
  }
}

async function submitBankAccount() {
  try {
    const payload = {
      name: bankAccountForm.name,
      branch: bankAccountForm.branch || null,
      accountNumber: bankAccountForm.accountNumber || null,
      accountType: bankAccountForm.accountType,
      currentBalanceBrl: Number(bankAccountForm.currentBalanceBrl || 0),
    }

    if (bankAccountEditingId.value) {
      await updateFinanceBankAccount(bankAccountEditingId.value, payload)
      notifyUser('Conta bancária atualizada com sucesso.', 'success')
    } else {
      await createFinanceBankAccount(payload)
      notifyUser('Conta bancária criada com sucesso.', 'success')
    }

    resetBankAccountForm()
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível salvar a conta bancária.'), 'error')
  }
}

function startEditingBankAccount(bankAccount) {
  if (!bankAccount?.id) {
    return
  }

  bankAccountEditingId.value = bankAccount.id
  bankAccountForm.name = String(bankAccount.name || '')
  bankAccountForm.branch = String(bankAccount.branch || '')
  bankAccountForm.accountNumber = String(bankAccount.accountNumber || '')
  bankAccountForm.accountType = String(bankAccount.accountType || 'CHECKING')
  bankAccountForm.currentBalanceBrl = String(bankAccount.currentBalanceBrl ?? '0')
}

function resetBankAccountForm() {
  bankAccountEditingId.value = null
  bankAccountForm.name = ''
  bankAccountForm.branch = ''
  bankAccountForm.accountNumber = ''
  bankAccountForm.accountType = 'CHECKING'
  bankAccountForm.currentBalanceBrl = '0'
}

async function toggleBankAccountStatus(bankAccount) {
  if (!bankAccount?.id) {
    return
  }

  try {
    await updateFinanceBankAccountStatus(bankAccount.id, {
      isActive: !bankAccount.isActive,
    })

    notifyUser('Status da conta bancária atualizado.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível atualizar a conta bancária.'), 'error')
  }
}

async function deleteBankAccount(bankAccount) {
  if (!bankAccount?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir conta bancária',
    message: 'A conta bancária será inativada. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteBankAccount(bankAccount),
  })
}

async function executeDeleteBankAccount(bankAccount) {
  try {
    await updateFinanceBankAccountStatus(bankAccount.id, {
      isActive: false,
    })

    if (bankAccountEditingId.value === bankAccount.id) {
      resetBankAccountForm()
    }

    notifyUser('Conta bancária removida com sucesso.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível remover a conta bancária.'), 'error')
  }
}

async function submitSettlement() {
  const entryId = normalizeOptionalNumber(settlementForm.entryId)
  if (!entryId) {
    notifyUser('Selecione um lançamento para baixa.', 'warning')
    return
  }

  const settlementAmountBrl = normalizeMoneyInputValue(settlementForm.amountBrl, 0)
  if (!Number.isFinite(settlementAmountBrl) || settlementAmountBrl <= 0) {
    notifyUser('Informe um valor de baixa maior que zero.', 'warning')
    return
  }

  const selectedEntryRemainingAmountBrl = Number(selectedSettlementEntry.value?.remainingAmountBrl || 0)
  const selectedEntryTypeCode = selectedSettlementEntryTypeCode.value
  const isInstallmentEntry = selectedEntryTypeCode === 'INSTALLMENT'
  if (!isInstallmentEntry && selectedEntryRemainingAmountBrl > 0 && settlementAmountBrl - selectedEntryRemainingAmountBrl > 0.009) {
    notifyUser(
      `O valor da baixa não pode ser maior que o saldo restante (${formatCurrency(selectedEntryRemainingAmountBrl)}).`,
      'warning',
    )
    return
  }

  const useCreditCardSettlement = Boolean(settlementForm.useCreditCard)
  const settlementPayload = {
    amountBrl: settlementAmountBrl,
    settledAt: settlementForm.settledAt || null,
    bankAccountId: null,
  }

  if (useCreditCardSettlement) {
    const selectedCreditCardId = normalizeOptionalNumber(settlementForm.creditCardId)
    if (!selectedCreditCardId) {
      notifyUser('Selecione o cartão de crédito para registrar a baixa em crédito.', 'warning')
      return
    }

    settlementPayload.useCreditCard = true
    settlementPayload.creditCardId = selectedCreditCardId
    settlementPayload.creditCardInterestRatePercent = Number(settlementForm.creditCardInterestRatePercent || 0)
    settlementPayload.creditCardIofRatePercent = Number(settlementForm.creditCardIofRatePercent || 0)
    settlementPayload.creditCardDueDate = settlementForm.creditCardDueDate || null
  } else {
    const selectedBankAccountId = normalizeOptionalNumber(settlementForm.bankAccountId)
    if (!selectedBankAccountId) {
      notifyUser('Conta bancária é obrigatória para registrar baixa bancária.', 'warning')
      return
    }

    settlementPayload.bankAccountId = selectedBankAccountId
  }

  try {
    await createFinanceSettlement(entryId, settlementPayload)

    resetSettlementForm()
    closeAccountActionModal()

    notifyUser(
      useCreditCardSettlement
        ? 'Baixa em crédito registrada e novo lançamento no cartão criado.'
        : 'Baixa registrada com sucesso.',
      'success',
    )
    await Promise.all([loadEntries(), loadInstallments(), loadCatalogs()])
    await loadDashboard()
  } catch (requestError) {
    const settlementErrorMessage = extractHttpMessage(requestError, 'Não foi possível registrar a baixa.')
    if (settlementErrorMessage.includes('The settlement amount cannot exceed the remaining amount.')) {
      notifyUser('O valor da baixa não pode ser maior que o saldo restante do lançamento.', 'error')
      return
    }

    notifyUser(settlementErrorMessage, 'error')
  }
}

async function submitRecurringRule() {
  const targetDirection = recurringDirectionByTab.value
  if (targetDirection === '') {
    notifyUser('Abra Contas a pagar ou Contas a receber para criar recorrência.', 'warning')
    return
  }

  try {
    const recurringRulePayload = {
      direction: targetDirection,
      title: recurringForm.title,
      amountBrl: Number(recurringForm.amountBrl),
      dayOfMonth: Number(recurringForm.dayOfMonth),
      startsAt: recurringForm.startsAt || getCurrentDateInputValue(),
      recurringTypeId: Number(recurringForm.recurringTypeId),
      categoryId: normalizeOptionalNumber(recurringForm.categoryId),
      defaultBankAccountId: normalizeOptionalNumber(recurringForm.defaultBankAccountId),
    }

    if (recurringRuleEditingId.value) {
      await updateFinanceRecurringRule(recurringRuleEditingId.value, recurringRulePayload)
      notifyUser('Recorrência atualizada com sucesso.', 'success')
    } else {
      await createFinanceRecurringRule(recurringRulePayload)
      notifyUser('Recorrência criada com sucesso.', 'success')
    }

    resetRecurringRuleForm()
    closeAccountActionModal()
    await Promise.all([loadRecurringRules(), loadEntries()])
    await loadDashboard()
  } catch (requestError) {
    const fallbackErrorMessage = recurringRuleEditingId.value
      ? 'Não foi possível atualizar a recorrência.'
      : 'Não foi possível criar a recorrência.'
    notifyUser(extractHttpMessage(requestError, fallbackErrorMessage), 'error')
  }
}

function startEditingRecurringRule(recurringRuleItem) {
  if (!recurringRuleItem?.id) {
    return
  }

  recurringRuleEditingId.value = Number(recurringRuleItem.id)
  recurringForm.direction = recurringDirectionByTab.value || String(recurringRuleItem.direction || 'PAYABLE')
  recurringForm.title = String(recurringRuleItem.title || '')
  recurringForm.amountBrl = String(recurringRuleItem.amountBrl ?? '')
  recurringForm.dayOfMonth = Number(recurringRuleItem.dayOfMonth || 1)
  recurringForm.startsAt = String(recurringRuleItem.startsAt || '').slice(0, 10) || getCurrentDateInputValue()
  recurringForm.recurringTypeId = recurringRuleItem.recurringTypeId ? String(recurringRuleItem.recurringTypeId) : ''
  recurringForm.categoryId = recurringRuleItem.categoryId ? String(recurringRuleItem.categoryId) : ''
  recurringForm.defaultBankAccountId = recurringRuleItem.bankAccountId ? String(recurringRuleItem.bankAccountId) : ''
  openAccountActionModal('RECURRING_RULE')
}

function resetRecurringRuleForm() {
  recurringRuleEditingId.value = null
  recurringForm.direction = recurringDirectionByTab.value || 'PAYABLE'
  recurringForm.title = ''
  recurringForm.amountBrl = ''
  recurringForm.dayOfMonth = 5
  recurringForm.startsAt = getCurrentDateInputValue()
  recurringForm.recurringTypeId = ''
  recurringForm.categoryId = ''
  recurringForm.defaultBankAccountId = ''
}

function resolveManualRecurringCompetenceDate(recurringRuleItem) {
  const rawNextRunDate = String(recurringRuleItem?.nextRunDate || '').trim().slice(0, 10)
  if (/^\d{4}-\d{2}-\d{2}$/.test(rawNextRunDate)) {
    return rawNextRunDate
  }

  return `${getCurrentYearMonthInputValue()}-01`
}

async function generateRecurringRuleManually(recurringRuleItem) {
  if (!recurringRuleItem?.id) {
    return
  }

  const recurringRuleId = Number(recurringRuleItem.id)
  if (!Number.isFinite(recurringRuleId) || recurringRuleId <= 0) {
    return
  }

  manualRecurringGenerationRuleId.value = recurringRuleId

  try {
    const response = await generateFinanceRecurringRuleManually(recurringRuleId, {
      competenceMonth: resolveManualRecurringCompetenceDate(recurringRuleItem),
    })
    const generatedCount = Number(response.data?.item?.generatedCount || 0)

    if (generatedCount > 0) {
      notifyUser('Lançamento recorrente gerado manualmente com sucesso.', 'success')
    } else {
      notifyUser('Já existe um lançamento ativo para essa competência.', 'warning')
    }

    await Promise.all([loadRecurringRules(), loadEntries()])
    await loadDashboard()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível gerar o lançamento manual da recorrência.'), 'error')
  } finally {
    manualRecurringGenerationRuleId.value = null
  }
}

function requestDeleteRecurringRule(recurringRuleItem) {
  if (!recurringRuleItem?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir recorrência',
    message: 'A recorrência será excluída e deixará de gerar novos lançamentos. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteRecurringRule(recurringRuleItem),
  })
}

async function executeDeleteRecurringRule(recurringRuleItem) {
  try {
    await deleteFinanceRecurringRule(recurringRuleItem.id)

    if (recurringRuleEditingId.value === Number(recurringRuleItem.id)) {
      resetRecurringRuleForm()
    }

    notifyUser('Recorrência excluída com sucesso.', 'success')
    await Promise.all([loadRecurringRules(), loadEntries()])
    await loadDashboard()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível excluir a recorrência.'), 'error')
  }
}

async function submitInstallmentPlan() {
  const targetDirection = installmentDirectionByTab.value
  if (targetDirection === '') {
    notifyUser('Abra Contas a pagar ou Dívidas para criar parcelamento.', 'warning')
    return
  }

  try {
    const isEditingInstallmentPlan = Boolean(installmentEditingId.value)
    const installmentPayload = {
      direction: targetDirection,
      title: installmentForm.title,
      totalAmountBrl: normalizeMoneyInputValue(installmentForm.totalAmountBrl, 0),
      downPaymentBrl: normalizeMoneyInputValue(installmentForm.downPaymentBrl, 0),
      installmentsCount: Number(installmentForm.installmentsCount),
      interestAmountBrl: normalizeMoneyInputValue(installmentForm.interestAmountBrl, 0),
      discountAmountBrl: normalizeMoneyInputValue(installmentForm.discountAmountBrl, 0),
      fineAmountBrl: normalizeMoneyInputValue(installmentForm.fineAmountBrl, 0),
      firstDueDate: installmentForm.firstDueDate || getCurrentDateInputValue(),
      categoryId: normalizeOptionalNumber(installmentForm.categoryId),
      defaultBankAccountId: normalizeOptionalNumber(installmentForm.defaultBankAccountId),
    }

    if (isEditingInstallmentPlan) {
      await updateFinanceInstallmentPlan(installmentEditingId.value, installmentPayload)
    } else {
      await createFinanceInstallmentPlan(installmentPayload)
    }

    resetInstallmentForm()
    closeAccountActionModal()

    notifyUser(isEditingInstallmentPlan ? 'Parcelamento atualizado com sucesso.' : 'Parcelamento criado com sucesso.', 'success')
    await Promise.all([loadInstallments(), loadEntries()])
    await loadDashboard()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível salvar o parcelamento.'), 'error')
  }
}

async function submitRenegotiation() {
  const planId = normalizeOptionalNumber(renegotiationForm.planId)
  if (!planId) {
    notifyUser('Selecione um plano para renegociar.', 'warning')
    return
  }

  try {
    await renegotiateFinanceInstallmentPlan(planId, {
      installmentsCount: Number(renegotiationForm.installmentsCount),
      reason: renegotiationForm.reason,
      categoryId: normalizeOptionalNumber(renegotiationForm.categoryId),
      defaultBankAccountId: normalizeOptionalNumber(renegotiationForm.defaultBankAccountId),
    })

    resetRenegotiationForm()
    closeAccountActionModal()
    notifyUser('Plano renegociado com sucesso.', 'success')
    await Promise.all([loadInstallments(), loadEntries()])
    await loadDashboard()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível renegociar o plano.'), 'error')
  }
}

function notifyUnavailableListAction(listLabel, actionLabel) {
  notifyUser(`A opção "${actionLabel}" ainda não está disponível para ${listLabel}.`, 'warning')
}

function startEditingInstallmentPlan(installmentPlan) {
  if (!installmentPlan?.id) {
    return
  }

  installmentEditingId.value = Number(installmentPlan.id)
  installmentForm.direction = String(installmentPlan.direction || installmentDirectionByTab.value || 'PAYABLE')
  installmentForm.title = String(installmentPlan.title || '')
  installmentForm.totalAmountBrl = String(installmentPlan.totalAmountBrl || '')
  installmentForm.downPaymentBrl = String(installmentPlan.downPaymentBrl || '0')
  installmentForm.installmentsCount = Number(installmentPlan.installmentsCount || 1)
  installmentForm.interestAmountBrl = String(installmentPlan.interestAmountBrl || '0')
  installmentForm.discountAmountBrl = String(installmentPlan.discountAmountBrl || '0')
  installmentForm.fineAmountBrl = String(installmentPlan.fineAmountBrl || '0')
  installmentForm.firstDueDate = sanitizeDateInput(installmentPlan.firstDueDate) || getCurrentDateInputValue()
  installmentForm.categoryId = installmentPlan.categoryId ? String(installmentPlan.categoryId) : ''
  installmentForm.defaultBankAccountId = installmentPlan.bankAccountId ? String(installmentPlan.bankAccountId) : ''
  openAccountActionModal('INSTALLMENT_PLAN')
}

function requestDeleteInstallmentPlan(installmentPlan) {
  if (!installmentPlan?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir plano de parcelamento',
    message: 'O plano e as parcelas vinculadas serão removidos da lista e dos cálculos. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteInstallmentPlan(installmentPlan),
  })
}

async function executeDeleteInstallmentPlan(installmentPlan) {
  try {
    await updateFinanceInstallmentPlan(installmentPlan.id, {
      status: 'CANCELED',
    })
    notifyUser('Plano de parcelamento cancelado com sucesso.', 'success')
    await Promise.all([loadInstallments(), loadEntries()])
    await loadDashboard()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível excluir o plano de parcelamento.'), 'error')
  }
}

function startEditingInvestmentPlan() {
  notifyUnavailableListAction('planos de investimento', 'Editar')
}

function requestDeleteInvestmentPlan(investmentPlan) {
  if (!investmentPlan?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir plano de investimento',
    message: 'O plano será marcado como cancelado. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteInvestmentPlan(investmentPlan),
  })
}

async function executeDeleteInvestmentPlan(investmentPlan) {
  try {
    await updateFinanceInvestmentPlan(investmentPlan.id, {
      status: 'CANCELED',
    })
    notifyUser('Plano de investimento cancelado com sucesso.', 'success')
    await loadInvestments()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível excluir o plano de investimento.'), 'error')
  }
}

function startEditingDebtPlan() {
  notifyUnavailableListAction('planos de dívida', 'Editar')
}

function requestDeleteDebtPlan(debtPlan) {
  if (!debtPlan?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir plano de dívida',
    message: 'O plano de dívida e seus vínculos serão removidos da visão e dos cálculos. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteDebtPlan(debtPlan),
  })
}

async function executeDeleteDebtPlan(debtPlan) {
  try {
    await deleteFinanceDebtPlan(debtPlan.id)
    notifyUser('Plano de dívida excluído com sucesso.', 'success')
    await Promise.all([loadDebtPlans(), loadEntries(), loadInstallments()])
    await loadDashboard()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível excluir o plano de dívida.'), 'error')
  }
}

function startEditingCurrencyRate() {
  notifyUnavailableListAction('cotações de moedas', 'Editar')
}

function requestDeleteCurrencyRate() {
  notifyUnavailableListAction('cotações de moedas', 'Excluir')
}

function startEditingExportJob() {
  notifyUnavailableListAction('exportações', 'Editar')
}

function requestDeleteExportJob(exportJob) {
  if (!exportJob?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir exportação',
    message: 'A exportação será removida da lista. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteExportJob(exportJob),
  })
}

async function executeDeleteExportJob(exportJob) {
  try {
    await deleteFinanceExport(exportJob.id)
    notifyUser('Exportação excluída com sucesso.', 'success')
    await loadExports(Number(exportMeta.value.page || 1))
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível excluir a exportação.'), 'error')
  }
}

function startEditingOpenFinanceConnection() {
  notifyUnavailableListAction('conexões Open Finance', 'Editar')
}

function requestDeleteOpenFinanceConnection(connection) {
  if (!connection?.id) {
    return
  }

  openConfirmDialog({
    title: 'Excluir conexão Open Finance',
    message: 'A conexão e os dados importados vinculados serão removidos. Deseja continuar?',
    confirmLabel: 'Excluir',
    confirmTone: 'danger',
    onConfirm: () => executeDeleteOpenFinanceConnection(connection),
  })
}

async function executeDeleteOpenFinanceConnection(connection) {
  try {
    await deleteFinanceOpenFinanceConnection(connection.id)
    notifyUser('Conexão Open Finance excluída com sucesso.', 'success')
    await loadOpenFinance()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível excluir a conexão Open Finance.'), 'error')
  }
}

async function submitSimulation() {
  try {
    const response = await createFinanceSimulation({
      investmentType: simulationForm.investmentType,
      label: simulationForm.label,
      initialAmountBrl: Number(simulationForm.initialAmountBrl),
      monthlyContributionBrl: Number(simulationForm.monthlyContributionBrl),
      periodMonths: Number(simulationForm.periodMonths),
      rateInputType: simulationForm.rateInputType,
      rateValue: Number(simulationForm.rateValue),
    })

    latestSimulation.value = response.data?.item || null
    convertPlanForm.label = `${simulationForm.label} - Plano`

    notifyUser('Simulação gerada com sucesso.', 'success')
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível gerar a simulação.'), 'error')
  }
}

async function convertSimulationToPlan() {
  if (!latestSimulation.value?.id) {
    notifyUser('Gere uma simulação antes de converter.', 'warning')
    return
  }

  try {
    await convertFinanceSimulationToPlan(latestSimulation.value.id, {
      label: convertPlanForm.label,
      startDate: convertPlanForm.startDate || getCurrentDateInputValue(),
      contributionDay: Number(convertPlanForm.contributionDay),
      generateYieldEntries: Boolean(convertPlanForm.generateYieldEntries),
      yieldMode: convertPlanForm.yieldMode,
      categoryId: normalizeOptionalNumber(convertPlanForm.categoryId),
      defaultBankAccountId: normalizeOptionalNumber(convertPlanForm.defaultBankAccountId),
    })

    notifyUser('Simulação convertida para plano real.', 'success')
    await Promise.all([loadInvestments(), loadEntries()])
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível converter a simulação.'), 'error')
  }
}

async function submitExport() {
  try {
    notifyUser('Gerando relatório, aguarde...', 'info')

    // Busca um lote grande com todos os possíveis filtros da tela
    const response = await fetchFinanceEntries({
      direction: entryFilters.direction || null,
      status: entryFilters.status || null,
    }, { itemsPerPage: 5000 })

    const items = response.data?.items || []
    if (!items.length) {
      notifyUser('Nenhum dado encontrado para exportar com os filtros atuais.', 'warning')
      return
    }

    const headers = ['Data Vencto/Referencia', 'Titulo', 'Categoria', 'Conta Bancaria', 'Valor Previsto', 'Valor Realizado', 'Status']
    const rows = items.map(e => [
      e.dueDate || e.expectedDate || '',
      `"${(e.title || '').replace(/"/g, '""')}"`,
      `"${(e.categoryName || '').replace(/"/g, '""')}"`,
      `"${(e.bankAccountName || '').replace(/"/g, '""')}"`,
      e.expectedAmountBrl || 0,
      e.realizedAmountBrl || 0,
      e.status || ''
    ])

    const csvContent = [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' })
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `relatorio-financeiro-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    window.URL.revokeObjectURL(url)

    notifyUser('Relatório exportado com sucesso!', 'success')
  } catch (error) {
    notifyUser('Falha ao gerar o relatório financeiro.', 'error')
  }
}

function buildMigrationSnapshotFileName(snapshotItem) {
  const exportedAtLabel = String(snapshotItem?.exportedAt || '').trim()
  const fileSafeDateToken = exportedAtLabel !== ''
    ? exportedAtLabel.replace(/:/g, '-').replace(/\s+/g, '_')
    : new Date().toISOString().replace(/:/g, '-')

  return `finance-snapshot-${fileSafeDateToken}.json`
}

async function downloadFinanceMigrationSnapshot() {
  loadingState.migration = true

  try {
    const response = await fetchFinanceMigrationSnapshot()
    const snapshotItem = response.data?.item || null
    if (!snapshotItem || typeof snapshotItem !== 'object') {
      notifyUser('Não foi possível gerar o snapshot financeiro.', 'error')
      return
    }

    const serializedSnapshot = JSON.stringify(snapshotItem, null, 2)
    const snapshotBlob = new Blob([serializedSnapshot], { type: 'application/json;charset=utf-8' })
    const snapshotBlobUrl = window.URL.createObjectURL(snapshotBlob)
    const downloadAnchor = document.createElement('a')

    downloadAnchor.href = snapshotBlobUrl
    downloadAnchor.download = buildMigrationSnapshotFileName(snapshotItem)
    document.body.appendChild(downloadAnchor)
    downloadAnchor.click()
    downloadAnchor.remove()
    window.URL.revokeObjectURL(snapshotBlobUrl)

    notifyUser('Snapshot financeiro exportado com sucesso.', 'success')
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao exportar snapshot financeiro.'), 'error')
  } finally {
    loadingState.migration = false
  }
}

function onMigrationImportFileChange(importFileChangeEvent) {
  const selectedFile = importFileChangeEvent?.target?.files?.[0] || null
  migrationImportFile.value = selectedFile || null
}

async function submitFinanceMigrationImport() {
  if (!migrationImportFile.value) {
    notifyUser('Selecione um arquivo JSON para importar.', 'warning')
    return
  }

  loadingState.migration = true

  try {
    const importedFileContent = await migrationImportFile.value.text()
    const parsedSnapshot = JSON.parse(importedFileContent)

    const response = await importFinanceMigrationSnapshot({
      snapshot: parsedSnapshot,
      replaceExisting: Boolean(migrationForm.replaceExisting),
    })

    const importedSummary = response.data?.item?.imported || {}
    const importedEntriesCount = Number(importedSummary.entries || 0)

    notifyUser(`Importação financeira concluída. ${importedEntriesCount} lançamento(s) restaurado(s).`, 'success')
    migrationImportFile.value = null
    await loadInitialData()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao importar snapshot financeiro.'), 'error')
  } finally {
    loadingState.migration = false
  }
}

async function downloadExport(exportJob) {
  if (!exportJob?.id) {
    return
  }

  try {
    const response = await requestClient({
      url: `/finance/exports/${encodeURIComponent(exportJob.id)}/download`,
      method: 'GET',
      responseType: 'blob',
    })

    const blob = new Blob([response.data])
    const url = window.URL.createObjectURL(blob)
    const downloadAnchor = document.createElement('a')
    downloadAnchor.href = url
    downloadAnchor.download = exportJob.fileName || `finance-export-${exportJob.id}.xlsx`
    document.body.appendChild(downloadAnchor)
    downloadAnchor.click()
    downloadAnchor.remove()
    window.URL.revokeObjectURL(url)
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha no download da exportação.'), 'error')
  }
}

async function createOpenFinanceConnection() {
  try {
    await createFinanceOpenFinanceConnection({
      providerId: Number(openFinanceForm.providerId),
      status: openFinanceForm.status,
    })

    notifyUser('Conexão Open Finance criada.', 'success')
    await loadOpenFinance()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar conexão Open Finance.'), 'error')
  }
}

async function runOpenFinanceSync(connectionId) {
  try {
    await syncFinanceOpenFinanceConnection(connectionId, {
      createMockData: Boolean(openFinanceForm.createMockData),
    })

    notifyUser('Sincronização Open Finance executada.', 'success')
    await loadOpenFinance()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível sincronizar a conexão.'), 'error')
  }
}

function roundMoney(rawValue) {
  return Math.round(Number(rawValue || 0) * 100) / 100
}

function sumSeriesValues(seriesValues) {
  if (!Array.isArray(seriesValues)) {
    return 0
  }

  const totalValue = seriesValues.reduce((accumulatedValue, seriesValue) => (
    accumulatedValue + Number(seriesValue || 0)
  ), 0)

  return roundMoney(totalValue)
}

function averageSeriesValue(seriesValues) {
  if (!Array.isArray(seriesValues) || seriesValues.length === 0) {
    return 0
  }

  return roundMoney(sumSeriesValues(seriesValues) / seriesValues.length)
}

function getLastSeriesValue(seriesValues) {
  if (!Array.isArray(seriesValues) || seriesValues.length === 0) {
    return 0
  }

  return Number(seriesValues[seriesValues.length - 1] || 0)
}

function getMaxSeriesValue(seriesValues) {
  if (!Array.isArray(seriesValues) || seriesValues.length === 0) {
    return 0
  }

  const normalizedSeriesValues = seriesValues.map((seriesValue) => Number(seriesValue || 0))
  return Math.max(...normalizedSeriesValues)
}

function getMaxAbsoluteSeriesValue(seriesValues) {
  if (!Array.isArray(seriesValues) || seriesValues.length === 0) {
    return 0
  }

  const normalizedSeriesValues = seriesValues.map((seriesValue) => Math.abs(Number(seriesValue || 0)))
  return Math.max(...normalizedSeriesValues)
}

function sumCountValues(seriesValues) {
  if (!Array.isArray(seriesValues)) {
    return 0
  }

  return seriesValues.reduce((accumulatedCount, seriesValue) => (
    accumulatedCount + Math.max(0, Math.round(Number(seriesValue || 0)))
  ), 0)
}

function formatCurrency(rawValue) {
  const numericValue = Number(rawValue || 0)

  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(numericValue)
}

function formatPercent(rawValue) {
  const numericValue = Number(rawValue || 0)
  return `${numericValue.toFixed(1)}%`
}

function formatInteger(rawValue) {
  const numericValue = Number(rawValue || 0)

  return new Intl.NumberFormat('pt-BR', {
    maximumFractionDigits: 0,
  }).format(numericValue)
}

function formatExchangeRate(rawValue) {
  const numericValue = Number(rawValue)
  if (!Number.isFinite(numericValue)) {
    return '-'
  }

  return numericValue.toFixed(6)
}

function resolveLastFourDigits(rawValue) {
  const normalizedDigits = String(rawValue || '').replace(/\D/g, '')
  if (normalizedDigits.length <= 0) {
    return '----'
  }

  return normalizedDigits.slice(-4).padStart(4, '0')
}

function formatCreditCardOptionLabel(creditCardAccount) {
  const creditCardAlias = String(creditCardAccount?.name || 'Cartão sem apelido')
  const creditCardInstitution = String(creditCardAccount?.bankName || 'Instituição não informada')
  const creditCardLastDigits = resolveLastFourDigits(creditCardAccount?.accountNumber)

  return `${creditCardAlias} - ${creditCardInstitution} - ${creditCardLastDigits}`
}

function formatSettlementEntryOptionLabel(entryItem) {
  const entryTitle = String(entryItem?.title || 'Lançamento')
  const entryDueDateLabel = formatDate(entryItem?.dueDate, '-')
  const entryRemainingAmountLabel = formatCurrency(entryItem?.remainingAmountBrl)
  return `${entryTitle} - ${entryDueDateLabel} - Em aberto ${entryRemainingAmountLabel}`
}

function normalizeMoneyInputValue(value, fallbackValue = 0) {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : fallbackValue
  }

  const normalizedTextValue = String(value ?? '').trim()
  if (normalizedTextValue === '') {
    return fallbackValue
  }

  const compactTextValue = normalizedTextValue.replace(/\s+/g, '')
  const hasCommaSeparator = compactTextValue.includes(',')
  const hasDotSeparator = compactTextValue.includes('.')
  let canonicalNumericValue = compactTextValue

  if (hasCommaSeparator && hasDotSeparator) {
    const lastCommaPosition = canonicalNumericValue.lastIndexOf(',')
    const lastDotPosition = canonicalNumericValue.lastIndexOf('.')

    if (lastCommaPosition > lastDotPosition) {
      canonicalNumericValue = canonicalNumericValue.replace(/\./g, '').replace(',', '.')
    } else {
      canonicalNumericValue = canonicalNumericValue.replace(/,/g, '')
    }
  } else if (hasCommaSeparator) {
    canonicalNumericValue = canonicalNumericValue.replace(/\./g, '').replace(',', '.')
  } else {
    canonicalNumericValue = canonicalNumericValue.replace(/,/g, '')
  }

  const parsedNumericValue = Number(canonicalNumericValue)
  return Number.isFinite(parsedNumericValue) ? parsedNumericValue : fallbackValue
}

function normalizeOptionalMoneyInputValue(value) {
  const normalizedTextValue = String(value ?? '').trim()
  if (normalizedTextValue === '') {
    return null
  }

  const parsedMoneyValue = normalizeMoneyInputValue(normalizedTextValue, Number.NaN)
  return Number.isFinite(parsedMoneyValue) ? parsedMoneyValue : null
}

function normalizeOptionalNumber(value) {
  const normalizedValue = Number(value)

  return Number.isFinite(normalizedValue) && normalizedValue > 0
    ? normalizedValue
    : null
}

function getLocalListPageCount(items) {
  const totalItems = Array.isArray(items) ? items.length : 0
  return Math.max(1, Math.ceil(totalItems / LIST_ITEMS_PER_PAGE))
}

function getLocalListPage(key, items) {
  const totalPages = getLocalListPageCount(items)
  const normalizedPage = Math.min(totalPages, Math.max(1, Number(localListPages[key] || 1)))

  if (localListPages[key] !== normalizedPage) {
    localListPages[key] = normalizedPage
  }

  return normalizedPage
}

function setLocalListPage(key, page, items) {
  const totalPages = getLocalListPageCount(items)
  localListPages[key] = Math.min(totalPages, Math.max(1, Number(page || 1)))
}

function getPaginatedLocalItems(items, key) {
  const normalizedItems = Array.isArray(items) ? items : []
  const currentPage = getLocalListPage(key, normalizedItems)
  const startIndex = (currentPage - 1) * LIST_ITEMS_PER_PAGE

  return normalizedItems.slice(startIndex, startIndex + LIST_ITEMS_PER_PAGE)
}

function getLocalListSummary(items, key, label) {
  const normalizedItems = Array.isArray(items) ? items : []
  if (normalizedItems.length === 0) {
    return `0 de 0 ${label}`
  }

  const currentPage = getLocalListPage(key, normalizedItems)
  const startIndex = (currentPage - 1) * LIST_ITEMS_PER_PAGE + 1
  const endIndex = Math.min(currentPage * LIST_ITEMS_PER_PAGE, normalizedItems.length)

  return `${startIndex}-${endIndex} de ${normalizedItems.length} ${label}`
}

function normalizeTextForComparison(rawValue) {
  return String(rawValue || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

function getFinanceLabel(termCode, fallbackLabel = '-') {
  return translateFinanceTerm(termCode, fallbackLabel)
}

function getFinanceExportTypeLabel(exportTypeCode, fallbackLabel = '-') {
  return translateFinanceExportType(exportTypeCode, fallbackLabel)
}

function getFinanceExportStatusLabel(exportStatusCode, fallbackLabel = '-') {
  return translateFinanceExportStatus(exportStatusCode, fallbackLabel)
}

function getDebtReferenceTypeLabel(referenceTypeCode) {
  if (referenceTypeCode === 'NEGOTIATED') {
    return 'Negociado'
  }

  if (referenceTypeCode === 'PROPOSED') {
    return 'Proposta'
  }

  return 'Valor original'
}

function getDebtSettlementModeLabel(settlementModeCode) {
  if (settlementModeCode === 'FULL') {
    return 'À vista'
  }

  return 'Parcelado'
}

function applyAccountsDirectionContext() {
  entryFilters.direction = accountsDirectionByTab.value
  if (accountsDirectionByTab.value !== '') {
    entryForm.direction = accountsDirectionByTab.value
  }

  if (recurringDirectionByTab.value !== '') {
    recurringForm.direction = recurringDirectionByTab.value
  }

  if (installmentDirectionByTab.value !== '') {
    installmentForm.direction = installmentDirectionByTab.value
  }

  if (entryForm.direction === 'RECEIVABLE' && entryForm.entryType === 'DEBT') {
    entryForm.entryType = 'ONE_OFF'
  }
}
</script>

<template>
  <section class="finance-screen">
    <section v-if="activeTab === 'reports'" class="finance-section finance-dashboard-section">
      <div class="finance-kpi-grid">
        <RemoteFinanceKpiCard v-for="kpiCard in kpiCards" :key="kpiCard.key" :label="kpiCard.label"
          :value="kpiCard.value" :caption="kpiCard.caption" :tone="kpiCard.tone" />
      </div>


      <article class="finance-panel">
        <header>
          <h3>Detalhamento do fluxo mensal</h3>
        </header>

        <div v-if="dashboardCashflow.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Mês</th>
                <th>Receita prevista</th>
                <th>Despesa prevista</th>
                <th>Saldo previsto</th>
                <th>Receita realizada</th>
                <th>Despesa realizada</th>
                <th>Saldo realizado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="cashflowItem in dashboardCashflow" :key="cashflowItem.competenceMonth">
                <td>{{ formatDate(cashflowItem.competenceMonth, '-') }}</td>
                <td>{{ formatCurrency(cashflowItem.expectedIncomeBrl) }}</td>
                <td>{{ formatCurrency(cashflowItem.expectedExpenseBrl) }}</td>
                <td>{{ formatCurrency(cashflowItem.expectedNetBrl) }}</td>
                <td>{{ formatCurrency(cashflowItem.realizedIncomeBrl) }}</td>
                <td>{{ formatCurrency(cashflowItem.realizedExpenseBrl) }}</td>
                <td>{{ formatCurrency(cashflowItem.realizedNetBrl) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem detalhamento de fluxo"
          description="Cadastre lançamentos para visualizar o histórico mensal completo." />
      </article>

      <article class="finance-panel">
        <header>
          <h3>Detalhamento por categoria</h3>
        </header>

        <div v-if="dashboardCategories.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Categoria</th>
                <th>Lançamentos</th>
                <th>Receita prevista</th>
                <th>Despesa prevista</th>
                <th>Saldo previsto</th>
                <th>Receita realizada</th>
                <th>Despesa realizada</th>
                <th>Saldo realizado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="categoryItem in dashboardCategories"
                :key="`${categoryItem.categoryId || 'none'}-${categoryItem.categoryName}`">
                <td>{{ categoryItem.categoryName }}</td>
                <td>{{ formatInteger(categoryItem.entriesCount) }}</td>
                <td>{{ formatCurrency(categoryItem.expectedIncomeBrl) }}</td>
                <td>{{ formatCurrency(categoryItem.expectedExpenseBrl) }}</td>
                <td>{{ formatCurrency(categoryItem.expectedNetBrl) }}</td>
                <td>{{ formatCurrency(categoryItem.realizedIncomeBrl) }}</td>
                <td>{{ formatCurrency(categoryItem.realizedExpenseBrl) }}</td>
                <td>{{ formatCurrency(categoryItem.realizedNetBrl) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem categorias no período"
          description="Nenhum lançamento encontrado para montar análise por categoria." />
      </article>
    </section>

    <section v-if="activeTab === 'accounts'" class="finance-section">
      <nav class="finance-nav-tabs" aria-label="Abas da tela de contas">
        <button v-for="accountsTabOption in accountsTabOptions" :key="accountsTabOption.key" type="button"
          class="finance-nav-tabs-button" :class="{ active: activeAccountsTab === accountsTabOption.key }"
          @click="activeAccountsTab = accountsTabOption.key">
          {{ accountsTabOption.label }}
        </button>
      </nav>

      <article v-if="activeAccountsTab === 'overview'" class="finance-panel">
        <header class="finance-overview-header">
          <div class="finance-overview-header-main">
            <h3>Resumo rápido: pagar x receber</h3>
            <small>Mostrando o previsto de {{ accountsOverviewSelectedMonthLabel }}. Detalhes completos em
              Relatórios.</small>
          </div>

          <div class="finance-overview-header-actions">
            <label class="finance-overview-month-field">
              <span>Mês</span>
              <input v-model="accountsOverviewMonth" type="month" class="finance-overview-month-input"
                @change="handleAccountsOverviewMonthChange">
            </label>

            <button type="button" class="finance-inline-action"
              :disabled="loadingState.dashboard || loadingState.entries" @click="refreshAccountsOverviewValues">
              {{ loadingState.dashboard || loadingState.entries ? 'Atualizando...' : 'Atualizar valores' }}
            </button>
          </div>
        </header>

        <div v-if="accountsOverviewComparisonRows.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Previsto</th>
                <th>Realizado</th>
                <th>Restante</th>
                <th>Execução</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="comparisonRow in accountsOverviewComparisonRows" :key="comparisonRow.key"
                :class="{ 'finance-total-summary-row': comparisonRow.key === 'total' }">
                <td>{{ comparisonRow.label }}</td>
                <td>{{ formatCurrency(comparisonRow.expectedBrl) }}</td>
                <td>{{ formatCurrency(comparisonRow.realizedBrl) }}</td>
                <td>{{ formatCurrency(comparisonRow.remainingBrl) }}</td>
                <td>
                  {{ comparisonRow.progressPercent === null ? '-' : formatPercent(comparisonRow.progressPercent) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem dados de análise"
          description="Cadastre lançamentos para visualizar o comparativo entre pagar e receber." />
      </article>

      <article v-if="shouldShowEntryManagement || shouldShowRecurringSection || shouldShowInstallmentSection"
        class="finance-panel">
        <header>
          <h3>Gerenciamento de lançamentos</h3>
          <small>Mantenha a tela limpa: use Adicionar para abrir o formulário em popup.</small>
        </header>

        <div class="finance-form-grid">
          <label>
            <span>O que deseja adicionar</span>
            <select v-model="selectedAccountActionType">
              <option v-for="actionOption in availableAccountActionOptions" :key="actionOption.value"
                :value="actionOption.value">
                {{ actionOption.label }}
              </option>
            </select>
          </label>

          <button type="button" class="finance-action-button" :disabled="availableAccountActionOptions.length <= 0"
            @click="openSelectedAccountActionModal">
            Adicionar
          </button>
        </div>
      </article>

      <FinanceEntriesListPanel v-if="!isDebtsAccountsTab" :panel-title="accountsEntriesTitle"
        :totals-label="totalsLabel" :entries-items="entriesState" :entries-meta="entriesMeta" :filters="entryFilters"
        :show-direction-filter="isOverviewAccountsTab" :current-direction-label="accountsCurrentDirectionLabel"
        :direction-options="directionOptions" :status-options="entryStatusOptions"
        :resolve-finance-label="getFinanceLabel" :loading="loadingState.entries" @submit-filters="applyEntryFilters"
        @set-page="setEntriesPage" @edit-entry="startEditingEntry" @delete-entry="requestDeleteEntry" />
    </section>

    <section v-if="activeTab === 'banks'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>{{ bankAccountEditingId ? 'Editar conta bancária' : 'Nova conta bancária' }}</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitBankAccount">
          <label>
            <span>Nome da conta (banco)</span>
            <input v-model="bankAccountForm.name" type="text" required>
          </label>

          <label>
            <span>Agência</span>
            <input v-model="bankAccountForm.branch" type="text" placeholder="0001">
          </label>

          <label>
            <span>Número da conta</span>
            <input v-model="bankAccountForm.accountNumber" type="text" placeholder="12345-6">
          </label>

          <label>
            <span>Tipo</span>
            <select v-model="bankAccountForm.accountType">
              <option v-for="bankAccountTypeOption in bankAccountTypeOptions" :key="bankAccountTypeOption.value"
                :value="bankAccountTypeOption.value">
                {{ bankAccountTypeOption.label }}
              </option>
            </select>
          </label>

          <label>
            <span>Saldo atual (BRL)</span>
            <input v-model="bankAccountForm.currentBalanceBrl" type="number" step="0.01">
          </label>

          <button class="finance-action-button" type="submit">
            {{ bankAccountEditingId ? 'Salvar alterações' : 'Salvar conta bancária' }}
          </button>
          <button v-if="bankAccountEditingId" type="button" class="finance-inline-action" @click="resetBankAccountForm">
            Cancelar edição
          </button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Contas bancárias</h3>
        </header>

        <div v-if="bankAccounts.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Conta</th>
                <th>Agência</th>
                <th>Número</th>
                <th>Tipo</th>
                <th>Saldo</th>
                <th>Status</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="bankAccount in paginatedBankAccounts" :key="bankAccount.id">
                <td>{{ bankAccount.name }}</td>
                <td>{{ bankAccount.branch || '-' }}</td>
                <td>{{ bankAccount.accountNumber || '-' }}</td>
                <td>{{ getFinanceLabel(bankAccount.accountType, '-') }}</td>
                <td>{{ formatCurrency(bankAccount.currentBalanceBrl) }}</td>
                <td>
                  <RemoteFinanceStatusBadge :status="bankAccount.isActive ? 'ACTIVE' : 'INACTIVE'"
                    :label="bankAccount.isActive ? 'Ativa' : 'Inativa'" />
                </td>
                <td class="finance-actions-cell">
                  <button type="button" class="finance-inline-action" @click="startEditingBankAccount(bankAccount)">
                    Editar
                  </button>
                  <button type="button" class="finance-inline-action" @click="toggleBankAccountStatus(bankAccount)">
                    {{ bankAccount.isActive ? 'Inativar' : 'Ativar' }}
                  </button>
                  <button type="button" class="finance-inline-action finance-inline-action-danger"
                    :disabled="!bankAccount.isActive" @click="deleteBankAccount(bankAccount)">
                    Excluir
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="bankAccounts.length" class="finance-pagination">
          <span class="finance-pagination-summary">{{ getLocalListSummary(bankAccounts, 'bankAccounts', 'contas')
          }}</span>
          <div v-if="getLocalListPageCount(bankAccounts) > 1" class="finance-pagination-actions">
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('bankAccounts', bankAccounts) <= 1"
              @click="setLocalListPage('bankAccounts', getLocalListPage('bankAccounts', bankAccounts) - 1, bankAccounts)">
              Anterior
            </button>
            <span class="finance-pagination-page">
              Página {{ getLocalListPage('bankAccounts', bankAccounts) }} de {{ getLocalListPageCount(bankAccounts) }}
            </span>
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('bankAccounts', bankAccounts) >= getLocalListPageCount(bankAccounts)"
              @click="setLocalListPage('bankAccounts', getLocalListPage('bankAccounts', bankAccounts) + 1, bankAccounts)">
              Próxima
            </button>
          </div>
        </div>

        <RemoteFinanceEmptyState v-else title="Sem contas bancárias"
          description="Cadastre contas para vincular lançamentos e controlar saldo." />
      </article>
    </section>

    <section v-if="activeTab === 'settings'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>{{ categoryEditingId ? 'Editar categoria' : 'Nova categoria' }}</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitCategory">
          <label>
            <span>Nome</span>
            <input v-model="categoryForm.name" type="text" required>
          </label>

          <label>
            <span>Aplicação</span>
            <select v-model="categoryForm.kind">
              <option v-for="categoryKindOption in categoryKindOptions" :key="categoryKindOption.value"
                :value="categoryKindOption.value">
                {{ categoryKindOption.label }}
              </option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">
            {{ categoryEditingId ? 'Salvar alterações' : 'Salvar categoria' }}
          </button>
          <button v-if="categoryEditingId" type="button" class="finance-inline-action" @click="resetCategoryForm">
            Cancelar edição
          </button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Categorias financeiras</h3>
        </header>

        <div v-if="categories.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Categoria</th>
                <th>Aplicação</th>
                <th>Origem</th>
                <th>Status</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="category in paginatedCategories" :key="category.id">
                <td>{{ category.name }}</td>
                <td>{{ getFinanceLabel(category.kind, category.kind || '-') }}</td>
                <td>{{ category.isSystem ? 'Sistema' : 'Personalizada' }}</td>
                <td>
                  <RemoteFinanceStatusBadge :status="category.isActive ? 'ACTIVE' : 'INACTIVE'"
                    :label="category.isActive ? 'Ativa' : 'Inativa'" />
                </td>
                <td class="finance-actions-cell">
                  <button type="button" class="finance-inline-action" @click="startEditingCategory(category)">
                    Editar
                  </button>
                  <button type="button" class="finance-inline-action finance-inline-action-danger"
                    :disabled="!category.isActive" @click="deleteCategory(category)">
                    Excluir
                  </button>
                  <button type="button" class="finance-inline-action" @click="toggleCategoryStatus(category)">
                    {{ category.isActive ? 'Inativar' : 'Ativar' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="categories.length" class="finance-pagination">
          <span class="finance-pagination-summary">{{ getLocalListSummary(categories, 'categories', 'categorias')
          }}</span>
          <div v-if="getLocalListPageCount(categories) > 1" class="finance-pagination-actions">
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('categories', categories) <= 1"
              @click="setLocalListPage('categories', getLocalListPage('categories', categories) - 1, categories)">
              Anterior
            </button>
            <span class="finance-pagination-page">
              Página {{ getLocalListPage('categories', categories) }} de {{ getLocalListPageCount(categories) }}
            </span>
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('categories', categories) >= getLocalListPageCount(categories)"
              @click="setLocalListPage('categories', getLocalListPage('categories', categories) + 1, categories)">
              Próxima
            </button>
          </div>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem categorias"
          description="Cadastre categorias para padronizar análise financeira." />
      </article>

      <article class="finance-panel">
        <header>
          <h3>{{ recurringTypeEditingId ? 'Editar tipo de recorrência' : 'Novo tipo de recorrência' }}</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitRecurringTypeCatalog">
          <label>
            <span>Nome</span>
            <input v-model="recurringTypeCatalogForm.name" type="text" required>
          </label>

          <label>
            <span>Descrição</span>
            <input v-model="recurringTypeCatalogForm.description" type="text">
          </label>

          <button class="finance-action-button" type="submit">
            {{ recurringTypeEditingId ? 'Salvar alterações' : 'Salvar tipo recorrente' }}
          </button>
          <button v-if="recurringTypeEditingId" type="button" class="finance-inline-action"
            @click="resetRecurringTypeForm">
            Cancelar edição
          </button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Tipos de recorrência</h3>
        </header>

        <div v-if="availableRecurringTypes.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Descrição</th>
                <th>Origem</th>
                <th>Status</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="recurringType in paginatedRecurringTypes" :key="recurringType.id">
                <td>{{ recurringType.name }}</td>
                <td>{{ recurringType.description || '-' }}</td>
                <td>{{ recurringType.isSystem ? 'Sistema' : 'Personalizada' }}</td>
                <td>
                  <RemoteFinanceStatusBadge :status="recurringType.isActive ? 'ACTIVE' : 'INACTIVE'"
                    :label="recurringType.isActive ? 'Ativo' : 'Inativo'" />
                </td>
                <td class="finance-actions-cell">
                  <button type="button" class="finance-inline-action" @click="startEditingRecurringType(recurringType)">
                    Editar
                  </button>
                  <button type="button" class="finance-inline-action finance-inline-action-danger"
                    @click="deleteRecurringType(recurringType)">
                    Excluir
                  </button>
                  <button type="button" class="finance-inline-action" @click="toggleRecurringTypeStatus(recurringType)">
                    {{ recurringType.isActive ? 'Inativar' : 'Ativar' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="availableRecurringTypes.length" class="finance-pagination">
          <span class="finance-pagination-summary">{{ getLocalListSummary(availableRecurringTypes, 'recurringTypes',
            'tipos recorrentes') }}</span>
          <div v-if="getLocalListPageCount(availableRecurringTypes) > 1" class="finance-pagination-actions">
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('recurringTypes', availableRecurringTypes) <= 1"
              @click="setLocalListPage('recurringTypes', getLocalListPage('recurringTypes', availableRecurringTypes) - 1, availableRecurringTypes)">
              Anterior
            </button>
            <span class="finance-pagination-page">
              Página {{ getLocalListPage('recurringTypes', availableRecurringTypes) }} de {{
                getLocalListPageCount(availableRecurringTypes) }}
            </span>
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('recurringTypes', availableRecurringTypes) >= getLocalListPageCount(availableRecurringTypes)"
              @click="setLocalListPage('recurringTypes', getLocalListPage('recurringTypes', availableRecurringTypes) + 1, availableRecurringTypes)">
              Próxima
            </button>
          </div>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem tipos recorrentes"
          description="Cadastre os tipos recorrentes para usar nas regras automáticas." />
      </article>
    </section>

    <section v-if="activeTab === 'accounts' && shouldShowRecurringSection" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Regras recorrentes</h3>
        </header>

        <div v-if="filteredRecurringRules.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Título</th>
                <th>Tipo</th>
                <th>Valor</th>
                <th>Próxima execução</th>
                <th>Status</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rule in paginatedRecurringRules" :key="rule.id">
                <td>{{ rule.title }}</td>
                <td>{{ rule.recurringTypeName }}</td>
                <td>{{ formatCurrency(rule.amountBrl) }}</td>
                <td>{{ formatDate(rule.nextRunDate, '-') }}</td>
                <td>
                  <RemoteFinanceStatusBadge :status="rule.isActive ? 'ACTIVE' : 'INACTIVE'"
                    :label="rule.isActive ? 'Ativa' : 'Inativa'" />
                </td>
                <td class="finance-actions-cell">
                  <button type="button" class="finance-inline-action"
                    :disabled="manualRecurringGenerationRuleId === Number(rule.id) || loadingState.recurring"
                    @click="generateRecurringRuleManually(rule)">
                    {{ manualRecurringGenerationRuleId === Number(rule.id) ? 'Lançando...' : 'Lançar manual' }}
                  </button>
                  <button type="button" class="finance-inline-action" @click="startEditingRecurringRule(rule)">
                    Editar
                  </button>
                  <button type="button" class="finance-inline-action finance-inline-action-danger"
                    @click="requestDeleteRecurringRule(rule)">
                    Excluir
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="filteredRecurringRules.length" class="finance-pagination">
          <span class="finance-pagination-summary">{{ getLocalListSummary(filteredRecurringRules, 'recurringRules',
            'regras') }}</span>
          <div v-if="getLocalListPageCount(filteredRecurringRules) > 1" class="finance-pagination-actions">
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('recurringRules', filteredRecurringRules) <= 1"
              @click="setLocalListPage('recurringRules', getLocalListPage('recurringRules', filteredRecurringRules) - 1, filteredRecurringRules)">
              Anterior
            </button>
            <span class="finance-pagination-page">
              Página {{ getLocalListPage('recurringRules', filteredRecurringRules) }} de {{
                getLocalListPageCount(filteredRecurringRules) }}
            </span>
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('recurringRules', filteredRecurringRules) >= getLocalListPageCount(filteredRecurringRules)"
              @click="setLocalListPage('recurringRules', getLocalListPage('recurringRules', filteredRecurringRules) + 1, filteredRecurringRules)">
              Próxima
            </button>
          </div>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem regras recorrentes"
          description="Cadastre regras para gerar automaticamente os lançamentos da direção desta aba." />
      </article>
    </section>

    <section v-if="activeTab === 'accounts' && shouldShowInstallmentSection" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Planos de parcelamento</h3>
        </header>

        <div v-if="filteredInstallmentPlans.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Título</th>
                <th>Total</th>
                <th>Parcelas</th>
                <th>Restante</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="plan in paginatedInstallmentPlans" :key="plan.id">
                <td>{{ plan.title }}</td>
                <td>{{ formatCurrency(plan.totalAmountBrl) }}</td>
                <td>{{ plan.installmentsCount }}</td>
                <td>{{ formatCurrency(plan.remainingAmountBrl) }}</td>
                <td class="finance-actions-cell">
                  <button type="button" class="finance-inline-action" @click="startEditingInstallmentPlan(plan)">
                    Editar
                  </button>
                  <button type="button" class="finance-inline-action finance-inline-action-danger"
                    :disabled="String(plan.status || '').toUpperCase() === 'CANCELED'"
                    @click="requestDeleteInstallmentPlan(plan)">
                    Excluir
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="filteredInstallmentPlans.length" class="finance-pagination">
          <span class="finance-pagination-summary">{{ getLocalListSummary(filteredInstallmentPlans, 'installmentPlans',
            'parcelamentos') }}</span>
          <div v-if="getLocalListPageCount(filteredInstallmentPlans) > 1" class="finance-pagination-actions">
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('installmentPlans', filteredInstallmentPlans) <= 1"
              @click="setLocalListPage('installmentPlans', getLocalListPage('installmentPlans', filteredInstallmentPlans) - 1, filteredInstallmentPlans)">
              Anterior
            </button>
            <span class="finance-pagination-page">
              Página {{ getLocalListPage('installmentPlans', filteredInstallmentPlans) }} de {{
                getLocalListPageCount(filteredInstallmentPlans) }}
            </span>
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('installmentPlans', filteredInstallmentPlans) >= getLocalListPageCount(filteredInstallmentPlans)"
              @click="setLocalListPage('installmentPlans', getLocalListPage('installmentPlans', filteredInstallmentPlans) + 1, filteredInstallmentPlans)">
              Próxima
            </button>
          </div>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem parcelamentos"
          description="Crie parcelamentos para gerar automaticamente as parcelas deste fluxo." />
      </article>
    </section>

    <section v-if="activeTab === 'investments'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Simulador</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitSimulation">
          <label>
            <span>Tipo</span>
            <select v-model="simulationForm.investmentType">
              <option v-for="investmentTypeOption in investmentTypeOptions" :key="investmentTypeOption.value"
                :value="investmentTypeOption.value">
                {{ investmentTypeOption.label }}
              </option>
            </select>
          </label>

          <label>
            <span>Rótulo</span>
            <input v-model="simulationForm.label" type="text" required>
          </label>

          <label>
            <span>Aporte inicial</span>
            <input v-model="simulationForm.initialAmountBrl" type="number" step="0.01" min="0" required>
          </label>

          <label>
            <span>Aporte mensal</span>
            <input v-model="simulationForm.monthlyContributionBrl" type="number" step="0.01" min="0" required>
          </label>

          <label>
            <span>Prazo (meses)</span>
            <input v-model="simulationForm.periodMonths" type="number" min="1" required>
          </label>

          <label>
            <span>Tipo de taxa</span>
            <select v-model="simulationForm.rateInputType">
              <option value="ANNUAL">Anual</option>
              <option value="MONTHLY">Mensal</option>
            </select>
          </label>

          <label>
            <span>Taxa (%)</span>
            <input v-model="simulationForm.rateValue" type="number" step="0.0001" min="0" required>
          </label>

          <button class="finance-action-button" type="submit">Simular investimento</button>
        </form>
      </article>

      <article class="finance-panel" v-if="latestSimulation">
        <header>
          <h3>Resultado da simulação</h3>
        </header>

        <div class="finance-kpi-grid">
          <RemoteFinanceKpiCard label="Total investido" :value="investmentSummary?.invested || '-'" tone="neutral" />
          <RemoteFinanceKpiCard label="Rendimento" :value="investmentSummary?.yield || '-'" tone="positive" />
          <RemoteFinanceKpiCard label="Patrimônio final" :value="investmentSummary?.finalAmount || '-'"
            tone="positive" />
        </div>

        <RemoteFinanceTrendMiniChart :points="latestSimulation.points || []" stroke-color="#16a34a" />

        <form class="finance-form-grid" @submit.prevent="convertSimulationToPlan">
          <label>
            <span>Nome do plano</span>
            <input v-model="convertPlanForm.label" type="text" required>
          </label>

          <label>
            <span>Início</span>
            <input v-model="convertPlanForm.startDate" type="date">
          </label>

          <label>
            <span>Dia de aporte</span>
            <input v-model="convertPlanForm.contributionDay" type="number" min="1" max="31" required>
          </label>

          <label>
            <span>Gerar lançamentos de rendimento</span>
            <select v-model="convertPlanForm.yieldMode">
              <option v-for="investmentYieldModeOption in investmentYieldModeOptions"
                :key="investmentYieldModeOption.value" :value="investmentYieldModeOption.value">
                {{ investmentYieldModeOption.label }}
              </option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">Transformar em plano real</button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Planos de investimento</h3>
        </header>

        <div v-if="investmentPlans.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Plano</th>
                <th>Status</th>
                <th>Aporte mensal</th>
                <th>Taxa mensal efetiva</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="plan in paginatedInvestmentPlans" :key="plan.id">
                <td>{{ plan.label }}</td>
                <td>
                  <RemoteFinanceStatusBadge :status="plan.status" :label="getFinanceLabel(plan.status)" />
                </td>
                <td>{{ formatCurrency(plan.monthlyContributionBrl) }}</td>
                <td>{{ Number(plan.effectiveMonthlyRate || 0).toFixed(6) }}</td>
                <td class="finance-actions-cell">
                  <button type="button" class="finance-inline-action" @click="startEditingInvestmentPlan(plan)">
                    Editar
                  </button>
                  <button type="button" class="finance-inline-action finance-inline-action-danger"
                    :disabled="String(plan.status || '').toUpperCase() === 'CANCELED'"
                    @click="requestDeleteInvestmentPlan(plan)">
                    Excluir
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="investmentPlans.length" class="finance-pagination">
          <span class="finance-pagination-summary">{{ getLocalListSummary(investmentPlans, 'investmentPlans', 'planos')
          }}</span>
          <div v-if="getLocalListPageCount(investmentPlans) > 1" class="finance-pagination-actions">
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('investmentPlans', investmentPlans) <= 1"
              @click="setLocalListPage('investmentPlans', getLocalListPage('investmentPlans', investmentPlans) - 1, investmentPlans)">
              Anterior
            </button>
            <span class="finance-pagination-page">
              Página {{ getLocalListPage('investmentPlans', investmentPlans) }} de {{
                getLocalListPageCount(investmentPlans) }}
            </span>
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('investmentPlans', investmentPlans) >= getLocalListPageCount(investmentPlans)"
              @click="setLocalListPage('investmentPlans', getLocalListPage('investmentPlans', investmentPlans) + 1, investmentPlans)">
              Próxima
            </button>
          </div>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem planos de investimento"
          description="Gere uma simulação e converta para criar o primeiro plano real." />
      </article>
    </section>

    <section v-if="activeTab === 'accounts' && activeAccountsTab === 'debts'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Planejador de dívidas</h3>
          <small>O sistema cruza contas a pagar/receber e dívida atual para sugerir parcelas.</small>
        </header>

        <form class="finance-form-grid" @submit.prevent="previewDebtPlan">
          <label>
            <span>Título da dívida</span>
            <input v-model="debtForm.title" type="text" required>
          </label>

          <label>
            <span>Credor</span>
            <input v-model="debtForm.creditorName" type="text" placeholder="Banco, loja ou pessoa">
          </label>

          <label>
            <span>Valor total da dívida (BRL)</span>
            <input v-model="debtForm.totalAmountBrl" type="number" step="0.01" min="0.01" required>
          </label>

          <label>
            <span>Valor negociado (opcional)</span>
            <input v-model="debtForm.negotiatedAmountBrl" type="number" step="0.01" min="0">
          </label>

          <label>
            <span>Valor de proposta (opcional)</span>
            <input v-model="debtForm.proposedAmountBrl" type="number" step="0.01" min="0">
          </label>

          <label>
            <span>Desconto/crédito combinado (opcional)</span>
            <input v-model="debtForm.discountAmountBrl" type="number" step="0.01" min="0">
          </label>

          <label>
            <span>Adiantamento já pago (opcional)</span>
            <input v-model="debtForm.downPaymentBrl" type="number" step="0.01" min="0">
          </label>

          <label>
            <span>Limite da renda (%)</span>
            <input v-model="debtForm.maxCommitmentPercent" type="number" step="0.01" min="5" max="90">
          </label>

          <label>
            <span>Qt. de Parcelas Simuladas</span>
            <input v-model="debtForm.desiredInstallmentsCount" type="number" min="1" max="120">
          </label>

          <label>
            <span>Vencimento inicial</span>
            <input v-model="debtForm.firstDueDate" type="date">
          </label>

          <label>
            <span>Categoria</span>
            <select v-model="debtForm.categoryId">
              <option value="">Sem categoria</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Conta bancária</span>
            <select v-model="debtForm.defaultBankAccountId">
              <option value="">Sem conta</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{
                bankAccount.name }}</option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">Simular</button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Resultado da simulação</h3>
          <small v-if="debtPreview">Base para decisão de pagamento</small>
        </header>

        <div v-if="debtPreview" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <tbody>
              <tr>
                <th>Base escolhida e valor original</th>
                <td>{{ getDebtReferenceTypeLabel(debtPreview.selectedReferenceType) }} - {{
                  formatCurrency(debtPreview.totalAmountBrl) }}</td>
              </tr>
              <tr>
                <th>Valor planejado</th>
                <td>{{ formatCurrency(debtPreview.plannedTotalAmountBrl) }}</td>
              </tr>
              <tr>
                <th>Desconto/crédito combinado</th>
                <td>{{ formatCurrency(debtPreview.discountAmountBrl || 0) }}</td>
              </tr>
              <tr>
                <th>Adiantamento já pago</th>
                <td>{{ formatCurrency(debtPreview.downPaymentBrl || 0) }}</td>
              </tr>
              <tr>
                <th>Renda mensal usada</th>
                <td>{{ formatCurrency(debtPreview.monthlyIncomeBrl) }} (automática)</td>
              </tr>
              <tr>
                <th>Média mensal de recebimentos</th>
                <td>{{ formatCurrency(debtPreview.estimatedMonthlyReceivablesBrl) }}</td>
              </tr>
              <tr>
                <th>Média mensal de pagamentos</th>
                <td>{{ formatCurrency(debtPreview.estimatedMonthlyPayablesBrl) }}</td>
              </tr>
              <tr>
                <th>Comprometimento atual com dívidas</th>
                <td>{{ formatCurrency(debtPreview.existingDebtCommitmentBrl) }}</td>
              </tr>
              <tr>
                <th>Renda livre mensal</th>
                <td>{{ formatCurrency(debtPreview.monthlyDisposableIncomeBrl) }}</td>
              </tr>
              <tr>
                <th>Limite de parcela</th>
                <td>{{ formatCurrency(debtPreview.maxRecommendedPaymentBrl) }} ({{
                  formatPercent(debtPreview.maxCommitmentPercent) }})</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem simulação"
          description="Preencha o formulário e clique em simular para receber sugestões de pagamento." />

        <div v-if="debtPreviewSuggestions.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Opção</th>
                <th>Modo</th>
                <th>Parcelas</th>
                <th>Parcela mensal</th>
                <th>Comprometimento</th>
                <th>Dentro do limite</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="suggestion in debtPreviewSuggestions" :key="suggestion.key">
                <td>{{ suggestion.label }}</td>
                <td>{{ getDebtSettlementModeLabel(suggestion.settlementMode) }}</td>
                <td>{{ suggestion.installmentsCount }}</td>
                <td>{{ formatCurrency(suggestion.monthlyPaymentBrl) }}</td>
                <td>{{ suggestion.commitmentPercent !== null ? formatPercent(suggestion.commitmentPercent) : '-' }}</td>
                <td>
                  <RemoteFinanceStatusBadge :status="getDebtSuggestionLimitBadgeStatus(suggestion)"
                    :label="getDebtSuggestionLimitBadgeLabel(suggestion)" />
                </td>
                <td class="finance-actions-cell">
                  <button v-if="canCreateDebtPlanFromSuggestion(suggestion)" type="button" class="finance-inline-action"
                    :disabled="debtPlanCreationSuggestionKey !== ''" @click="createDebtPlanFromSuggestion(suggestion)">
                    {{ isCreatingDebtPlanFromSuggestion(suggestion) ? 'Criando...' : 'Criar plano' }}
                  </button>
                  <span v-else>-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Planos de dívida cadastrados</h3>
          <small v-if="loadingState.debts">Atualizando...</small>
        </header>

        <div v-if="debtPlans.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Título</th>
                <th>Credor</th>
                <th>Modo</th>
                <th>Valor planejado</th>
                <th>Parcela mensal</th>
                <th>Status</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="debtPlan in paginatedDebtPlans" :key="debtPlan.id">
                <td>{{ debtPlan.title }}</td>
                <td>{{ debtPlan.creditorName || '-' }}</td>
                <td>{{ getDebtSettlementModeLabel(debtPlan.settlementMode) }}</td>
                <td>{{ formatCurrency(debtPlan.plannedTotalAmountBrl) }}</td>
                <td>{{ formatCurrency(debtPlan.selectedMonthlyPaymentBrl) }}</td>
                <td>
                  <RemoteFinanceStatusBadge :status="debtPlan.status" :label="getFinanceLabel(debtPlan.status)" />
                </td>
                <td class="finance-actions-cell">
                  <button type="button" class="finance-inline-action" @click="startEditingDebtPlan(debtPlan)">
                    Editar
                  </button>
                  <button type="button" class="finance-inline-action finance-inline-action-danger"
                    @click="requestDeleteDebtPlan(debtPlan)">
                    Excluir
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="debtPlans.length" class="finance-pagination">
          <span class="finance-pagination-summary">{{ getLocalListSummary(debtPlans, 'debtPlans', 'planos de dívida')
          }}</span>
          <div v-if="getLocalListPageCount(debtPlans) > 1" class="finance-pagination-actions">
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('debtPlans', debtPlans) <= 1"
              @click="setLocalListPage('debtPlans', getLocalListPage('debtPlans', debtPlans) - 1, debtPlans)">
              Anterior
            </button>
            <span class="finance-pagination-page">
              Página {{ getLocalListPage('debtPlans', debtPlans) }} de {{ getLocalListPageCount(debtPlans) }}
            </span>
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('debtPlans', debtPlans) >= getLocalListPageCount(debtPlans)"
              @click="setLocalListPage('debtPlans', getLocalListPage('debtPlans', debtPlans) + 1, debtPlans)">
              Próxima
            </button>
          </div>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem planos de dívida"
          description="Crie seu primeiro plano de pagamento de dívidas para acompanhar negociações e parcelas." />
      </article>
    </section>

    <section v-if="activeTab === 'settings'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Moedas e câmbio (Bacen PTAX)</h3>
          <small>{{ selectedCurrenciesLabel }}</small>
        </header>

        <form class="finance-form-grid" @submit.prevent="loadCurrencyRates">
          <label>
            <span>Data de referência</span>
            <input v-model="currencyForm.date" type="date">
          </label>

          <label class="finance-multi-select-field">
            <span>Moedas</span>
            <select v-model="currencyForm.codes" class="finance-multi-select" multiple>
              <option v-for="currencyItem in currenciesCatalog" :key="currencyItem.code" :value="currencyItem.code">
                {{ currencyItem.code }} - {{ currencyItem.name }}
              </option>
            </select>
            <small class="finance-field-hint">Use Ctrl/Cmd para selecionar várias moedas.</small>
          </label>

          <label>
            <span>Adicionar moeda por sigla</span>
            <select v-model="currencyCodePickerForm.selectedCode">
              <option v-for="currencyItem in currenciesCatalog" :key="currencyItem.code" :value="currencyItem.code">
                {{ currencyItem.code }} - {{ currencyItem.name }}
              </option>
            </select>
          </label>

          <button class="finance-action-button" type="button" @click="addCurrencyCodeToSelection">
            Adicionar moeda
          </button>

          <button class="finance-action-button" type="submit">Atualizar cotações (cache diário)</button>
          <button class="finance-inline-action finance-inline-action-danger" type="button"
            @click="requestForceCurrencyRefresh">
            Forçar consulta da API
          </button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Cotação manual</h3>
          <small>Use quando precisar cadastrar ou corrigir uma taxa manualmente.</small>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitManualCurrencyRate">
          <label>
            <span>Data da cotação</span>
            <input v-model="manualCurrencyRateForm.quoteDate" type="date" required>
          </label>

          <label>
            <span>Sigla da moeda</span>
            <select v-model="manualCurrencyRateForm.currencyCode" required>
              <option v-for="currencyItem in currenciesCatalog" :key="currencyItem.code" :value="currencyItem.code">
                {{ currencyItem.code }}
              </option>
            </select>
          </label>

          <label>
            <span>Moeda</span>
            <input v-model="manualCurrencyRateForm.currencyName" type="text" required>
          </label>

          <label>
            <span>Taxa (BRL)</span>
            <input v-model="manualCurrencyRateForm.rateBrl" type="number" step="0.000001" min="0.000001" required>
          </label>

          <button class="finance-action-button" type="submit">Salvar cotação manual</button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Resultado das cotações</h3>
          <small v-if="loadingState.currencies">Atualizando...</small>
        </header>

        <div v-if="currencyRates.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Moeda</th>
                <th>Compra (BRL)</th>
                <th>Venda (BRL)</th>
                <th>Data da cotação</th>
                <th>Data/hora da API</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="currencyRateItem in paginatedCurrencyRates" :key="currencyRateItem.code">
                <td>{{ currencyRateItem.code }} - {{ currencyRateItem.name }}</td>
                <td>{{ formatExchangeRate(currencyRateItem.buyRateBrl) }}</td>
                <td>{{ formatExchangeRate(currencyRateItem.sellRateBrl) }}</td>
                <td>{{ formatDate(currencyRateItem.quoteDate, '-') }}</td>
                <td>{{ formatDateTime(currencyRateItem.quoteDateTime, '-') }}</td>
                <td class="finance-actions-cell">
                  <button type="button" class="finance-inline-action"
                    @click="startEditingCurrencyRate(currencyRateItem)">
                    Editar
                  </button>
                  <button type="button" class="finance-inline-action finance-inline-action-danger"
                    @click="requestDeleteCurrencyRate(currencyRateItem)">
                    Excluir
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="currencyRates.length" class="finance-pagination">
          <span class="finance-pagination-summary">{{ getLocalListSummary(currencyRates, 'currencyRates', 'cotações')
          }}</span>
          <div v-if="getLocalListPageCount(currencyRates) > 1" class="finance-pagination-actions">
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('currencyRates', currencyRates) <= 1"
              @click="setLocalListPage('currencyRates', getLocalListPage('currencyRates', currencyRates) - 1, currencyRates)">
              Anterior
            </button>
            <span class="finance-pagination-page">
              Página {{ getLocalListPage('currencyRates', currencyRates) }} de {{ getLocalListPageCount(currencyRates)
              }}
            </span>
            <button type="button" class="finance-inline-action finance-pagination-button"
              :disabled="getLocalListPage('currencyRates', currencyRates) >= getLocalListPageCount(currencyRates)"
              @click="setLocalListPage('currencyRates', getLocalListPage('currencyRates', currencyRates) + 1, currencyRates)">
              Próxima
            </button>
          </div>
        </div>
        <RemoteFinanceEmptyState v-else title="Sem cotações"
          description="Selecione moedas e atualize para buscar cotações da API do Bacen." />
      </article>
    </section>

    <section v-if="activeTab === 'reports'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Nova exportação XLSX</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitExport">
          <label>
            <span>Tipo de exportação</span>
            <select v-model="exportForm.exportType">
              <option v-for="exportTypeOption in exportTypeOptions" :key="exportTypeOption.value"
                :value="exportTypeOption.value">
                {{ exportTypeOption.label }}
              </option>
            </select>
          </label>

          <button class="button-primary" type="submit">Gerar Relatório (CSV)</button>
        </form>
      </article>

      <article class="finance-panel">
        <header style="margin-bottom: 24px;">
          <h3>Migração completa (JSON)</h3>
          <small>Exporte ou importe todo o histórico financeiro.</small>
        </header>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 40px;">

          <!-- Painel Exportar -->
          <div style="display: flex; flex-direction: column; gap: 16px;">
            <div>
              <h4 style="margin: 0 0 6px 0; font-size: 0.95rem; font-weight: 600;">Exportar dados</h4>
              <p style="margin: 0; font-size: 0.82rem; line-height: 1.4; color: var(--muted, #475569);">
                Gere um snapshot de todas as suas entradas para fazer backup, análises externas ou migrações seguras.
              </p>
            </div>
            <button type="button" class="button-primary" style="width: fit-content; margin-top: auto;"
              :disabled="loadingState.migration" @click="downloadFinanceMigrationSnapshot">
              {{ loadingState.migration ? 'Gerando JSON...' : 'Exportar snapshot JSON' }}
            </button>
          </div>

          <!-- Painel Importar -->
          <form @submit.prevent="submitFinanceMigrationImport"
            style="display: flex; flex-direction: column; gap: 16px;">
            <div>
              <h4 style="margin: 0 0 6px 0; font-size: 0.95rem; font-weight: 600;">Importar dados</h4>
              <p style="margin: 0; font-size: 0.82rem; line-height: 1.4; color: var(--muted, #475569);">
                Restaure um histórico financeiro a partir de um arquivo JSON exportado do sistema.
              </p>
            </div>

            <div class="flex flex-col gap-2 w-full">
              <label class="button-secondary w-full m-0 flex items-center justify-center cursor-pointer transition-colors duration-200">
                <span class="font-semibold text-center whitespace-nowrap">+ Escolher arquivo .json</span>
                <input type="file" accept=".json,application/json" class="hidden" @change="onMigrationImportFileChange">
              </label>

              <input v-if="migrationImportFile" type="text" :value="migrationImportFile.name" disabled
                class="w-full text-center px-4 py-2 border rounded-md bg-transparent text-sm font-medium cursor-not-allowed"
                style="border-color: var(--line, #cbd5e1); color: var(--muted, #475569);">
            </div>

            <label class="finance-toggle-label"
              style="display: flex; flex-direction: row; align-items: center; justify-content: space-between; padding: 12px 14px; border: 1px solid var(--line, #cbd5e1); border-radius: 8px; margin-top: 4px; cursor: pointer;">
              <span style="margin: 0; font-size: 0.78rem; color: var(--muted, #475569); font-weight: 700;">Substituir
                dados atuais da conta</span>
              <input v-model="migrationForm.replaceExisting" type="checkbox"
                style="margin: 0; cursor: pointer; transform: scale(1.1);">
            </label>

            <button class="button-primary" type="submit" style="margin-top: auto; width: 100%;"
              :disabled="loadingState.migration || !migrationImportFile">
              {{ loadingState.migration ? 'Importando aguarde...' : 'Importar snapshot JSON' }}
            </button>
          </form>

        </div>
      </article>
    </section>

    <div v-if="accountActionModalState.isOpen" class="app-modal-overlay flex items-center justify-center" role="dialog"
      aria-modal="true" @click.self="closeAccountActionModal">
      <div class="app-modal-frame w-full max-w-4xl p-6 md:p-7">
        <header class="finance-modal-header">
          <h3>{{ accountActionModalTitle }}</h3>
          <p>Os dados serão aplicados nas listagens desta aba.</p>
        </header>

        <form v-if="accountActionModalState.actionType === 'ENTRY'" class="finance-form-grid"
          @submit.prevent="submitEntry">
          <label>
            <span>Título</span>
            <input v-model="entryForm.title" type="text" required>
          </label>

          <label>
            <span>Tipo de lançamento</span>
            <select v-model="entryForm.entryType">
              <option v-for="entryTypeOption in entryTypeOptionsForForm" :key="entryTypeOption.value"
                :value="entryTypeOption.value">
                {{ entryTypeOption.label }}
              </option>
            </select>
          </label>

          <label>
            <span>Valor (BRL)</span>
            <input v-model="entryForm.expectedAmountBrl" type="number" step="0.01" min="0.01" required>
          </label>

          <label>
            <span>Vencimento</span>
            <input v-model="entryForm.dueDate" type="date" required>
          </label>

          <label>
            <span>Categoria</span>
            <select v-model="entryForm.categoryId">
              <option value="">Sem categoria</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Conta bancária</span>
            <select v-model="entryForm.bankAccountId">
              <option value="">Sem conta</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{
                bankAccount.name }}</option>
            </select>
          </label>

          <div class="finance-modal-actions">
            <button type="button" class="button-secondary" @click="closeAccountActionModal">Cancelar</button>
            <button class="button-primary" type="submit">
              {{ entryEditingId ? 'Salvar alterações' : 'Salvar lançamento' }}
            </button>
          </div>
        </form>

        <form v-else-if="accountActionModalState.actionType === 'SETTLEMENT'"
          class="finance-form-grid finance-settlement-form" @submit.prevent="submitSettlement">
          <label>
            <span>Lançamento</span>
            <select v-model="settlementForm.entryId">
              <option value="">Selecione</option>
              <option v-for="entry in availableSettlementEntries" :key="entry.id" :value="entry.id">
                {{ formatSettlementEntryOptionLabel(entry) }}
              </option>
            </select>
            <small v-if="selectedSettlementEntry" class="finance-field-hint">
              Saldo restante do lançamento selecionado: {{ formatCurrency(selectedSettlementRemainingAmountBrl) }}
            </small>
          </label>

          <label>
            <span>Valor da baixa</span>
            <input v-model="settlementForm.amountBrl" type="number" step="0.01" min="0.01" :max="selectedSettlementEntryTypeCode !== 'INSTALLMENT' && selectedSettlementRemainingAmountBrl > 0
              ? selectedSettlementRemainingAmountBrl
              : undefined" required>
          </label>

          <label>
            <span>Data/hora da baixa</span>
            <input v-model="settlementForm.settledAt" type="datetime-local">
            <small class="finance-field-hint">Se não informar, o sistema usa a data/hora atual.</small>
          </label>

          <div class="finance-settlement-toggle-field">
            <span>Forma da baixa</span>
            <label class="finance-toggle-label">
              <input v-model="settlementForm.useCreditCard" type="checkbox">
              <span>Baixa de valor em crédito</span>
            </label>
          </div>

          <label v-if="settlementForm.useCreditCard">
            <span>Cartão de crédito</span>
            <select v-model="settlementForm.creditCardId" required>
              <option value="">Selecione</option>
              <option v-for="creditCardAccount in availableCreditCardAccounts" :key="creditCardAccount.id"
                :value="creditCardAccount.id">
                {{ formatCreditCardOptionLabel(creditCardAccount) }}
              </option>
            </select>
            <small v-if="availableCreditCardAccounts.length <= 0" class="finance-field-hint">
              Cadastre uma conta do tipo Cartão para habilitar baixa em crédito.
            </small>
          </label>

          <label v-if="settlementForm.useCreditCard">
            <span>Juros mensais (%)</span>
            <input v-model="settlementForm.creditCardInterestRatePercent" type="number" step="0.01" min="0" max="100">
          </label>

          <label v-if="settlementForm.useCreditCard">
            <span>IOF (%)</span>
            <input v-model="settlementForm.creditCardIofRatePercent" type="number" step="0.01" min="0" max="100">
          </label>

          <label v-if="settlementForm.useCreditCard">
            <span>Vencimento da fatura (opcional)</span>
            <input v-model="settlementForm.creditCardDueDate" type="date">
          </label>

          <label v-if="!settlementForm.useCreditCard">
            <span>Conta bancária</span>
            <select v-model="settlementForm.bankAccountId" :required="!settlementForm.useCreditCard">
              <option value="">Selecione</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{
                bankAccount.name }}</option>
            </select>
          </label>

          <div class="finance-modal-actions">
            <button type="button" class="button-secondary" @click="closeAccountActionModal">Cancelar</button>
            <button class="button-primary" type="submit">Registrar baixa</button>
          </div>
        </form>

        <form v-else-if="accountActionModalState.actionType === 'RECURRING_RULE'" class="finance-form-grid"
          @submit.prevent="submitRecurringRule">
          <label>
            <span>Título</span>
            <input v-model="recurringForm.title" type="text" required>
          </label>

          <label>
            <span>Valor mensal</span>
            <input v-model="recurringForm.amountBrl" type="number" step="0.01" min="0.01" required>
          </label>

          <label>
            <span>Dia do mês</span>
            <input v-model="recurringForm.dayOfMonth" type="number" min="1" max="31" required>
          </label>

          <label>
            <span>Início</span>
            <input v-model="recurringForm.startsAt" type="date" required>
          </label>

          <label>
            <span>Tipo recorrente</span>
            <select v-model="recurringForm.recurringTypeId" required>
              <option value="">Selecione</option>
              <option v-for="recurringType in availableRecurringTypes" :key="recurringType.id"
                :value="recurringType.id">{{ recurringType.name }}</option>
            </select>
          </label>

          <label>
            <span>Categoria</span>
            <select v-model="recurringForm.categoryId">
              <option value="">Sem categoria</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Conta bancária padrão</span>
            <select v-model="recurringForm.defaultBankAccountId">
              <option value="">Sem conta</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{
                bankAccount.name }}</option>
            </select>
          </label>

          <div class="finance-modal-actions">
            <button type="button" class="button-secondary" @click="closeAccountActionModal">Cancelar</button>
            <button class="button-primary" type="submit">
              {{ recurringRuleEditingId ? 'Salvar alterações' : 'Criar recorrência' }}
            </button>
          </div>
        </form>

        <form v-else-if="accountActionModalState.actionType === 'INSTALLMENT_PLAN'" class="finance-form-grid"
          @submit.prevent="submitInstallmentPlan">
          <label>
            <span>Título</span>
            <input v-model="installmentForm.title" type="text" required>
          </label>

          <label>
            <span>Valor total</span>
            <input v-model="installmentForm.totalAmountBrl" type="number" step="0.01" min="0.01" required>
          </label>

          <label>
            <span>Entrada</span>
            <input v-model="installmentForm.downPaymentBrl" type="number" step="0.01" min="0">
          </label>

          <label>
            <span>Parcelas</span>
            <input v-model="installmentForm.installmentsCount" type="number" min="1" required>
          </label>

          <label>
            <span>Primeiro vencimento</span>
            <input v-model="installmentForm.firstDueDate" type="date">
          </label>

          <label>
            <span>Categoria</span>
            <select v-model="installmentForm.categoryId">
              <option value="">Sem categoria</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Conta bancária padrão</span>
            <select v-model="installmentForm.defaultBankAccountId">
              <option value="">Sem conta</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{
                bankAccount.name }}</option>
            </select>
          </label>

          <div class="finance-modal-actions">
            <button type="button" class="button-secondary" @click="closeAccountActionModal">Cancelar</button>
            <button class="button-primary" type="submit">
              {{ installmentEditingId ? 'Salvar alterações' : 'Criar parcelamento' }}
            </button>
          </div>
        </form>

        <form v-else-if="accountActionModalState.actionType === 'RENEGOTIATION'" class="finance-form-grid"
          @submit.prevent="submitRenegotiation">
          <label>
            <span>Plano</span>
            <select v-model="renegotiationForm.planId">
              <option value="">Selecione</option>
              <option v-for="plan in filteredInstallmentPlans" :key="plan.id" :value="plan.id">{{ plan.title }}</option>
            </select>
          </label>

          <label>
            <span>Nova quantidade de parcelas</span>
            <input v-model="renegotiationForm.installmentsCount" type="number" min="1" required>
          </label>

          <label>
            <span>Motivo</span>
            <input v-model="renegotiationForm.reason" type="text" required>
          </label>

          <label>
            <span>Categoria</span>
            <select v-model="renegotiationForm.categoryId">
              <option value="">Manter atual</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Conta bancária padrão</span>
            <select v-model="renegotiationForm.defaultBankAccountId">
              <option value="">Manter atual</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{
                bankAccount.name }}</option>
            </select>
          </label>

          <div class="finance-modal-actions">
            <button type="button" class="button-secondary" @click="closeAccountActionModal">Cancelar</button>
            <button class="button-primary" type="submit">Renegociar plano</button>
          </div>
        </form>
      </div>
    </div>

    <AppConfirmDialog :is-open="confirmDialogState.isOpen" :title="confirmDialogState.title"
      :message="confirmDialogState.message" :confirm-label="confirmDialogState.confirmLabel"
      :confirm-tone="confirmDialogState.confirmTone" :processing="confirmDialogState.processing"
      @cancel="closeConfirmDialog" @confirm="handleConfirmDialogAction" />
  </section>
</template>
