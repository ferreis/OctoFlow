<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
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
  createFinanceSettlement,
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
  fetchFinanceRecurringRules,
  fetchFinanceRecurringTypes,
  previewFinanceDebtPlan,
  renegotiateFinanceInstallmentPlan,
  syncFinanceOpenFinanceConnection,
  updateFinanceCategory,
  updateFinanceBankAccount,
  updateFinanceBankAccountStatus,
  updateFinanceRecurringType,
} from '../../services/finance'
import {
  convertFinanceSimulationToPlan,
  createFinanceSimulation,
  fetchFinanceInvestmentPlans,
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
import { extractHttpMessage } from '../../utils/httpErrors'

const props = defineProps({
  request: {
    type: Function,
    required: true,
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

const activeTab = ref(sectionToTabKey[props.initialSection] || 'accounts')
const accountsTabOptions = [
  { key: 'overview', label: 'Visão Geral' },
  { key: 'payable', label: 'Contas a Pagar' },
  { key: 'receivable', label: 'Contas a Receber' },
  { key: 'debts', label: 'Dívidas' },
]
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
const loadingState = reactive({
  dashboard: false,
  entries: false,
  recurring: false,
  installments: false,
  investments: false,
  debts: false,
  currencies: false,
  exports: false,
  catalogs: false,
  openFinance: false,
})

const dashboardSummary = ref(null)
const dashboardCashflow = ref([])
const dashboardCategories = ref([])

const entriesState = ref([])
const entriesMeta = ref({ page: 1, itemsPerPage: 20, total: 0 })

const recurringRules = ref([])
const installmentPlans = ref([])
const investmentPlans = ref([])
const debtPlans = ref([])
const debtPreview = ref(null)
const currenciesCatalog = ref([])
const currencyRates = ref([])
const exportJobs = ref([])
const latestSimulation = ref(null)
const openFinanceProviders = ref([])
const openFinanceConnections = ref([])

const categories = ref([])
const recurringTypes = ref([])
const bankAccounts = ref([])

const entryFilters = reactive({
  direction: '',
  status: '',
  search: '',
  startDate: '',
  endDate: '',
  page: 1,
  itemsPerPage: 20,
})

const entryForm = reactive({
  direction: 'PAYABLE',
  entryType: 'ONE_OFF',
  title: '',
  expectedAmountBrl: '',
  dueDate: '',
  categoryId: '',
  bankAccountId: '',
})

const categoryForm = reactive({
  name: '',
  kind: 'BOTH',
})

const recurringTypeCatalogForm = reactive({
  name: '',
  description: '',
})

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
})

const recurringForm = reactive({
  direction: 'PAYABLE',
  title: '',
  amountBrl: '',
  dayOfMonth: 5,
  startsAt: '',
  recurringTypeId: '',
  categoryId: '',
  defaultBankAccountId: '',
})

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
  reason: 'Renegociação manual',
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

const exportForm = reactive({
  exportType: 'MONTHLY_SUMMARY',
})

const debtForm = reactive({
  title: '',
  creditorName: '',
  totalAmountBrl: '',
  negotiatedAmountBrl: '',
  proposedAmountBrl: '',
  downPaymentBrl: '0',
  monthlyIncomeBrl: '',
  maxCommitmentPercent: '40',
  desiredInstallmentsCount: 12,
  settlementMode: 'INSTALLMENT',
  selectedInstallmentsCount: 12,
  firstDueDate: '',
  categoryId: '',
  defaultBankAccountId: '',
  negotiationNote: '',
  proposalNote: '',
  notes: '',
  allowExceedLimit: false,
})

const currencyForm = reactive({
  date: new Date().toISOString().slice(0, 10),
  codes: ['USD', 'EUR', 'GBP', 'ARS'],
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
    monthLabels: cashflowItems.map((cashflowItem) => String(cashflowItem.competenceMonth || '-')),
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

  const firstItemIndex = (Number(entriesMeta.value.page || 1) - 1) * Number(entriesMeta.value.itemsPerPage || 20) + 1
  const lastItemIndex = Math.min(firstItemIndex + entriesState.value.length - 1, totalEntries)

  return `${firstItemIndex}-${lastItemIndex} de ${totalEntries} lançamentos`
})

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

  return ''
})

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

  return 'Lançamentos'
})

const entryTypeOptionsForForm = computed(() => (
  entryForm.direction === 'RECEIVABLE'
    ? manualEntryTypeOptions.filter((entryTypeOption) => entryTypeOption.value !== 'DEBT')
    : manualEntryTypeOptions
))

const accountsOverviewComparisonRows = computed(() => {
  const summary = dashboardSummary.value
  if (!summary) {
    return []
  }

  const expectedIncomeBrl = Number(summary.expectedIncomeBrl || 0)
  const realizedIncomeBrl = Number(summary.realizedIncomeBrl || 0)
  const expectedExpenseBrl = Number(summary.expectedExpenseBrl || 0)
  const realizedExpenseBrl = Number(summary.realizedExpenseBrl || 0)

  const incomeRemainingBrl = Math.max(0, roundMoney(expectedIncomeBrl - realizedIncomeBrl))
  const expenseRemainingBrl = Math.max(0, roundMoney(expectedExpenseBrl - realizedExpenseBrl))

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
  ]
})

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

onMounted(async () => {
  await loadInitialData()
})

watch(
  () => props.currentUser?.id,
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
    const mappedTabKey = sectionToTabKey[nextSection] || 'accounts'
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
  if (nextTabKey === 'accounts') {
    applyAccountsDirectionContext()
    if (activeAccountsTab.value === 'debts') {
      await Promise.all([
        loadDebtPlans(),
        loadCatalogs(),
      ])
      return
    }

    const accountRequests = [loadEntries()]
    if (activeAccountsTab.value === 'overview') {
      accountRequests.push(loadDashboard(), loadRecurringRules(), loadInstallments())
    }

    await Promise.all(accountRequests)
    return
  }

  if (nextTabKey === 'banks') {
    await loadCatalogs()
    return
  }

  if (nextTabKey === 'investments') {
    await loadInvestments()
    return
  }

  if (nextTabKey === 'settings') {
    await Promise.all([
      loadCatalogs(),
      refreshCurrencyData(),
    ])
    return
  }

  if (nextTabKey === 'reports') {
    await Promise.all([
      loadDashboard(),
      loadExports(),
      loadOpenFinance(),
    ])
  }
})

watch(activeAccountsTab, async (nextAccountsTab) => {
  if (activeTab.value !== 'accounts') {
    return
  }

  applyAccountsDirectionContext()
  entryFilters.page = 1

  if (nextAccountsTab === 'debts') {
    await Promise.all([
      loadDebtPlans(),
      loadCatalogs(),
    ])

    return
  }

  if (nextAccountsTab === 'overview') {
    await Promise.all([
      loadDashboard(),
      loadEntries(),
      loadRecurringRules(),
      loadInstallments(),
    ])

    return
  }

  await loadEntries()
})

watch(
  () => entryForm.direction,
  (nextDirection) => {
    if (nextDirection === 'RECEIVABLE' && entryForm.entryType === 'DEBT') {
      entryForm.entryType = 'ONE_OFF'
    }
  },
)

async function loadInitialData() {
  if (!props.currentUser?.id) {
    return
  }

  await Promise.all([
    loadCatalogs(),
    loadDashboard(),
    loadEntries(),
    loadRecurringRules(),
    loadInstallments(),
    loadInvestments(),
    loadDebtPlans(),
    loadExports(),
    loadOpenFinance(),
  ])

  if (activeTab.value === 'settings') {
    await refreshCurrencyData()
  }
}

function resetLocalState() {
  dashboardSummary.value = null
  dashboardCashflow.value = []
  dashboardCategories.value = []
  entriesState.value = []
  entriesMeta.value = { page: 1, itemsPerPage: 20, total: 0 }
  recurringRules.value = []
  installmentPlans.value = []
  investmentPlans.value = []
  debtPlans.value = []
  debtPreview.value = null
  currenciesCatalog.value = []
  currencyRates.value = []
  exportJobs.value = []
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
      fetchFinanceCategories(props.request),
      fetchFinanceRecurringTypes(props.request),
      fetchFinanceBankAccounts(props.request),
    ])

    categories.value = Array.isArray(categoriesResponse.data?.items) ? categoriesResponse.data.items : []
    recurringTypes.value = Array.isArray(recurringTypesResponse.data?.items) ? recurringTypesResponse.data.items : []
    bankAccounts.value = Array.isArray(bankAccountsResponse.data?.items) ? bankAccountsResponse.data.items : []
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar catálogo financeiro.'), 'error')
  } finally {
    loadingState.catalogs = false
  }
}

async function loadDashboard() {
  loadingState.dashboard = true

  try {
    const [summaryResponse, cashflowResponse, categoriesResponse] = await Promise.all([
      fetchFinanceDashboardSummary(props.request),
      fetchFinanceDashboardCashflow(props.request),
      fetchFinanceDashboardCategories(props.request),
    ])

    dashboardSummary.value = summaryResponse.data?.item || null
    dashboardCashflow.value = Array.isArray(cashflowResponse.data?.items) ? cashflowResponse.data.items : []
    dashboardCategories.value = Array.isArray(categoriesResponse.data?.items) ? categoriesResponse.data.items : []
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar dashboard financeiro.'), 'error')
  } finally {
    loadingState.dashboard = false
  }
}

async function loadEntries() {
  loadingState.entries = true

  try {
    const response = await fetchFinanceEntries(props.request, {
      direction: entryFilters.direction || undefined,
      status: entryFilters.status || undefined,
      search: entryFilters.search || undefined,
      startDate: entryFilters.startDate || undefined,
      endDate: entryFilters.endDate || undefined,
    }, {
      page: entryFilters.page,
      itemsPerPage: entryFilters.itemsPerPage,
    })

    entriesState.value = Array.isArray(response.data?.items) ? response.data.items : []
    entriesMeta.value = response.data?.meta || { page: 1, itemsPerPage: 20, total: 0 }
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar lançamentos.'), 'error')
  } finally {
    loadingState.entries = false
  }
}

async function loadRecurringRules() {
  loadingState.recurring = true

  try {
    const response = await fetchFinanceRecurringRules(props.request)
    recurringRules.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar recorrências.'), 'error')
  } finally {
    loadingState.recurring = false
  }
}

async function loadInstallments() {
  loadingState.installments = true

  try {
    const response = await fetchFinanceInstallmentPlans(props.request)
    installmentPlans.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar parcelamentos.'), 'error')
  } finally {
    loadingState.installments = false
  }
}

async function loadInvestments() {
  loadingState.investments = true

  try {
    const response = await fetchFinanceInvestmentPlans(props.request)
    investmentPlans.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar planos de investimento.'), 'error')
  } finally {
    loadingState.investments = false
  }
}

async function loadDebtPlans() {
  loadingState.debts = true

  try {
    const response = await fetchFinanceDebtPlans(props.request)
    debtPlans.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar planos de dívida.'), 'error')
  } finally {
    loadingState.debts = false
  }
}

async function previewDebtPlan() {
  try {
    const response = await previewFinanceDebtPlan(props.request, {
      title: debtForm.title,
      creditorName: debtForm.creditorName || null,
      totalAmountBrl: Number(debtForm.totalAmountBrl),
      negotiatedAmountBrl: debtForm.negotiatedAmountBrl === '' ? null : Number(debtForm.negotiatedAmountBrl),
      proposedAmountBrl: debtForm.proposedAmountBrl === '' ? null : Number(debtForm.proposedAmountBrl),
      downPaymentBrl: Number(debtForm.downPaymentBrl || 0),
      monthlyIncomeBrl: debtForm.monthlyIncomeBrl === '' ? null : Number(debtForm.monthlyIncomeBrl),
      maxCommitmentPercent: Number(debtForm.maxCommitmentPercent || 40),
      desiredInstallmentsCount: Number(debtForm.desiredInstallmentsCount || 0),
      firstDueDate: debtForm.firstDueDate || null,
      categoryId: normalizeOptionalNumber(debtForm.categoryId),
      defaultBankAccountId: normalizeOptionalNumber(debtForm.defaultBankAccountId),
      settlementMode: debtForm.settlementMode,
      negotiationNote: debtForm.negotiationNote || null,
      proposalNote: debtForm.proposalNote || null,
      notes: debtForm.notes || null,
    })

    debtPreview.value = response.data?.item || null

    const suggestedInstallmentsCount = Number(debtPreview.value?.recommendedOption?.installmentsCount || 0)
    if (suggestedInstallmentsCount > 1) {
      debtForm.selectedInstallmentsCount = suggestedInstallmentsCount
      debtForm.settlementMode = 'INSTALLMENT'
    } else if (suggestedInstallmentsCount === 1) {
      debtForm.selectedInstallmentsCount = 1
      debtForm.settlementMode = 'FULL'
    }

    notifyUser('Simulação de dívida atualizada.', 'success')
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível simular o plano de dívida.'), 'error')
  }
}

async function submitDebtPlan() {
  if (!debtPreview.value) {
    notifyUser('Execute a simulação antes de criar o plano de dívida.', 'warning')
    return
  }

  try {
    const response = await createFinanceDebtPlan(props.request, {
      title: debtForm.title,
      creditorName: debtForm.creditorName || null,
      totalAmountBrl: Number(debtForm.totalAmountBrl),
      negotiatedAmountBrl: debtForm.negotiatedAmountBrl === '' ? null : Number(debtForm.negotiatedAmountBrl),
      proposedAmountBrl: debtForm.proposedAmountBrl === '' ? null : Number(debtForm.proposedAmountBrl),
      downPaymentBrl: Number(debtForm.downPaymentBrl || 0),
      monthlyIncomeBrl: debtForm.monthlyIncomeBrl === '' ? null : Number(debtForm.monthlyIncomeBrl),
      maxCommitmentPercent: Number(debtForm.maxCommitmentPercent || 40),
      desiredInstallmentsCount: Number(debtForm.desiredInstallmentsCount || 0),
      selectedInstallmentsCount: Number(debtForm.selectedInstallmentsCount || 0),
      firstDueDate: debtForm.firstDueDate || null,
      categoryId: normalizeOptionalNumber(debtForm.categoryId),
      defaultBankAccountId: normalizeOptionalNumber(debtForm.defaultBankAccountId),
      settlementMode: debtForm.settlementMode,
      negotiationNote: debtForm.negotiationNote || null,
      proposalNote: debtForm.proposalNote || null,
      notes: debtForm.notes || null,
      allowExceedLimit: Boolean(debtForm.allowExceedLimit),
    })

    if (response.data?.item) {
      debtPlans.value = [response.data.item, ...debtPlans.value]
    }

    notifyUser('Plano de dívida criado com sucesso.', 'success')
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar o plano de dívida.'), 'error')
  }
}

async function loadCurrenciesCatalog() {
  try {
    const response = await fetchFinanceCurrencies(props.request)
    currenciesCatalog.value = Array.isArray(response.data?.items) ? response.data.items : []

    if (currencyForm.codes.length === 0 && currenciesCatalog.value.length > 0) {
      currencyForm.codes = currenciesCatalog.value.slice(0, 4).map((currencyItem) => String(currencyItem.code))
    }
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar catálogo de moedas.'), 'error')
  }
}

async function loadCurrencyRates() {
  loadingState.currencies = true

  try {
    const selectedCurrencyCodes = Array.isArray(currencyForm.codes) ? currencyForm.codes : []

    const response = await fetchFinanceCurrencyRates(props.request, {
      date: currencyForm.date || undefined,
      codes: selectedCurrencyCodes.join(',') || undefined,
    })

    currencyRates.value = Array.isArray(response.data?.items) ? response.data.items : []
    if (typeof response.data?.requestedDate === 'string' && response.data.requestedDate !== '') {
      currencyForm.date = response.data.requestedDate
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

async function loadExports() {
  loadingState.exports = true

  try {
    const response = await fetchFinanceExports(props.request)
    exportJobs.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Falha ao carregar exportações.'), 'error')
  } finally {
    loadingState.exports = false
  }
}

async function loadOpenFinance() {
  loadingState.openFinance = true

  try {
    const [providersResponse, connectionsResponse] = await Promise.all([
      fetchFinanceOpenFinanceProviders(props.request),
      fetchFinanceOpenFinanceConnections(props.request),
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
    if (shouldCreateSalaryRecurringRuleFromEntry()) {
      const recurringTypeId = resolveSalaryRecurringTypeId()
      if (!recurringTypeId) {
        notifyUser('Nao foi possivel localizar um tipo de recorrencia para salario.', 'warning')
        return
      }

      const dueDate = entryForm.dueDate || new Date().toISOString().slice(0, 10)
      const dueDay = Number(dueDate.split('-')[2] || 5)

      await createFinanceRecurringRule(props.request, {
        direction: 'RECEIVABLE',
        title: entryForm.title,
        amountBrl: Number(entryForm.expectedAmountBrl),
        dayOfMonth: Number.isFinite(dueDay) && dueDay >= 1 && dueDay <= 31 ? dueDay : 5,
        startsAt: dueDate,
        recurringTypeId,
        categoryId: resolveSalaryCategoryId(),
        defaultBankAccountId: normalizeOptionalNumber(entryForm.bankAccountId),
      })

      entryForm.title = ''
      entryForm.expectedAmountBrl = ''
      entryForm.dueDate = ''
      entryForm.entryType = 'ONE_OFF'

      notifyUser('Salario cadastrado como recorrencia com sucesso.', 'success')
      await Promise.all([loadRecurringRules(), loadEntries(), loadDashboard()])
      return
    }

    await createFinanceEntry(props.request, {
      direction: entryForm.direction,
      entryType: entryForm.entryType,
      title: entryForm.title,
      expectedAmountBrl: Number(entryForm.expectedAmountBrl),
      dueDate: entryForm.dueDate || null,
      categoryId: normalizeOptionalNumber(entryForm.categoryId),
      bankAccountId: normalizeOptionalNumber(entryForm.bankAccountId),
    })

    entryForm.title = ''
    entryForm.expectedAmountBrl = ''
    entryForm.dueDate = ''
    entryForm.entryType = 'ONE_OFF'

    notifyUser('Lançamento criado com sucesso.', 'success')
    await Promise.all([loadEntries(), loadDashboard()])
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar o lançamento.'), 'error')
  }
}

async function submitCategory() {
  try {
    await createFinanceCategory(props.request, {
      name: categoryForm.name,
      kind: categoryForm.kind,
    })

    categoryForm.name = ''
    categoryForm.kind = 'BOTH'

    notifyUser('Categoria criada com sucesso.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar a categoria.'), 'error')
  }
}

async function toggleCategoryStatus(categoryItem) {
  if (!categoryItem?.id) {
    return
  }

  try {
    await updateFinanceCategory(props.request, categoryItem.id, {
      isActive: !categoryItem.isActive,
    })

    notifyUser('Status da categoria atualizado.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível atualizar a categoria.'), 'error')
  }
}

async function submitRecurringTypeCatalog() {
  try {
    await createFinanceRecurringType(props.request, {
      name: recurringTypeCatalogForm.name,
      description: recurringTypeCatalogForm.description || null,
    })

    recurringTypeCatalogForm.name = ''
    recurringTypeCatalogForm.description = ''

    notifyUser('Tipo de recorrência criado com sucesso.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar o tipo de recorrência.'), 'error')
  }
}

async function toggleRecurringTypeStatus(recurringTypeItem) {
  if (!recurringTypeItem?.id) {
    return
  }

  try {
    await updateFinanceRecurringType(props.request, recurringTypeItem.id, {
      isActive: !recurringTypeItem.isActive,
    })

    notifyUser('Status do tipo recorrente atualizado.', 'success')
    await loadCatalogs()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível atualizar o tipo recorrente.'), 'error')
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
      await updateFinanceBankAccount(props.request, bankAccountEditingId.value, payload)
      notifyUser('Conta bancária atualizada com sucesso.', 'success')
    } else {
      await createFinanceBankAccount(props.request, payload)
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

async function deleteBankAccount(bankAccount) {
  if (!bankAccount?.id) {
    return
  }

  const confirmed = window.confirm('Confirma excluir esta conta bancária?')
  if (!confirmed) {
    return
  }

  try {
    await updateFinanceBankAccountStatus(props.request, bankAccount.id, {
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

  try {
    await createFinanceSettlement(props.request, entryId, {
      amountBrl: Number(settlementForm.amountBrl),
      settledAt: settlementForm.settledAt || null,
      bankAccountId: normalizeOptionalNumber(settlementForm.bankAccountId),
    })

    settlementForm.amountBrl = ''
    settlementForm.settledAt = ''

    notifyUser('Baixa registrada com sucesso.', 'success')
    await Promise.all([loadEntries(), loadDashboard(), loadInstallments()])
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível registrar a baixa.'), 'error')
  }
}

async function submitRecurringRule() {
  try {
    await createFinanceRecurringRule(props.request, {
      direction: recurringForm.direction,
      title: recurringForm.title,
      amountBrl: Number(recurringForm.amountBrl),
      dayOfMonth: Number(recurringForm.dayOfMonth),
      startsAt: recurringForm.startsAt || new Date().toISOString().slice(0, 10),
      recurringTypeId: Number(recurringForm.recurringTypeId),
      categoryId: normalizeOptionalNumber(recurringForm.categoryId),
      defaultBankAccountId: normalizeOptionalNumber(recurringForm.defaultBankAccountId),
    })

    recurringForm.title = ''
    recurringForm.amountBrl = ''
    notifyUser('Recorrência criada com sucesso.', 'success')
    await Promise.all([loadRecurringRules(), loadEntries()])
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar a recorrência.'), 'error')
  }
}

async function submitInstallmentPlan() {
  try {
    await createFinanceInstallmentPlan(props.request, {
      direction: installmentForm.direction,
      title: installmentForm.title,
      totalAmountBrl: Number(installmentForm.totalAmountBrl),
      downPaymentBrl: Number(installmentForm.downPaymentBrl || 0),
      installmentsCount: Number(installmentForm.installmentsCount),
      interestAmountBrl: Number(installmentForm.interestAmountBrl || 0),
      discountAmountBrl: Number(installmentForm.discountAmountBrl || 0),
      fineAmountBrl: Number(installmentForm.fineAmountBrl || 0),
      firstDueDate: installmentForm.firstDueDate || new Date().toISOString().slice(0, 10),
      categoryId: normalizeOptionalNumber(installmentForm.categoryId),
      defaultBankAccountId: normalizeOptionalNumber(installmentForm.defaultBankAccountId),
    })

    installmentForm.title = ''
    installmentForm.totalAmountBrl = ''

    notifyUser('Parcelamento criado com sucesso.', 'success')
    await Promise.all([loadInstallments(), loadEntries()])
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar o parcelamento.'), 'error')
  }
}

async function submitRenegotiation() {
  const planId = normalizeOptionalNumber(renegotiationForm.planId)
  if (!planId) {
    notifyUser('Selecione um plano para renegociar.', 'warning')
    return
  }

  try {
    await renegotiateFinanceInstallmentPlan(props.request, planId, {
      installmentsCount: Number(renegotiationForm.installmentsCount),
      reason: renegotiationForm.reason,
    })

    notifyUser('Plano renegociado com sucesso.', 'success')
    await Promise.all([loadInstallments(), loadEntries()])
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível renegociar o plano.'), 'error')
  }
}

async function submitSimulation() {
  try {
    const response = await createFinanceSimulation(props.request, {
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
    await convertFinanceSimulationToPlan(props.request, latestSimulation.value.id, {
      label: convertPlanForm.label,
      startDate: convertPlanForm.startDate || new Date().toISOString().slice(0, 10),
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
    await createFinanceExport(props.request, {
      exportType: exportForm.exportType,
      filters: {
        direction: entryFilters.direction || null,
        status: entryFilters.status || null,
      },
    })

    notifyUser('Exportação enfileirada.', 'success')
    await loadExports()
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível enfileirar a exportação.'), 'error')
  }
}

async function downloadExport(exportJob) {
  if (!exportJob?.id) {
    return
  }

  try {
    const response = await props.request({
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
    await createFinanceOpenFinanceConnection(props.request, {
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
    await syncFinanceOpenFinanceConnection(props.request, connectionId, {
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

function normalizeOptionalNumber(value) {
  const normalizedValue = Number(value)

  return Number.isFinite(normalizedValue) && normalizedValue > 0
    ? normalizedValue
    : null
}

function normalizeTextForComparison(rawValue) {
  return String(rawValue || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

function findCategoryByNormalizedName(targetName) {
  const normalizedTargetName = normalizeTextForComparison(targetName)
  return categories.value.find((categoryItem) => (
    normalizeTextForComparison(categoryItem?.name) === normalizedTargetName
  )) || null
}

function findRecurringTypeByNormalizedName(targetName) {
  const normalizedTargetName = normalizeTextForComparison(targetName)
  return recurringTypes.value.find((recurringTypeItem) => (
    normalizeTextForComparison(recurringTypeItem?.name) === normalizedTargetName
  )) || null
}

function resolveSalaryCategoryId() {
  const selectedCategoryId = normalizeOptionalNumber(entryForm.categoryId)
  if (selectedCategoryId) {
    return selectedCategoryId
  }

  return normalizeOptionalNumber(findCategoryByNormalizedName('Salario')?.id)
}

function resolveSalaryRecurringTypeId() {
  const salaryRecurringTypeId = normalizeOptionalNumber(findRecurringTypeByNormalizedName('Salario')?.id)
  if (salaryRecurringTypeId) {
    return salaryRecurringTypeId
  }

  const monthlyRecurringTypeId = normalizeOptionalNumber(findRecurringTypeByNormalizedName('Mensal')?.id)
  if (monthlyRecurringTypeId) {
    return monthlyRecurringTypeId
  }

  const firstActiveRecurringType = recurringTypes.value.find((recurringTypeItem) => Boolean(recurringTypeItem?.isActive))
  return normalizeOptionalNumber(firstActiveRecurringType?.id)
}

function shouldCreateSalaryRecurringRuleFromEntry() {
  if (entryForm.direction !== 'RECEIVABLE') {
    return false
  }

  const normalizedTitle = normalizeTextForComparison(entryForm.title)
  const selectedCategoryName = categories.value.find((categoryItem) => (
    String(categoryItem?.id) === String(entryForm.categoryId)
  ))?.name || ''
  const normalizedCategoryName = normalizeTextForComparison(selectedCategoryName)

  return normalizedTitle.includes('salario') || normalizedCategoryName === 'salario'
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

  if (entryForm.direction === 'RECEIVABLE' && entryForm.entryType === 'DEBT') {
    entryForm.entryType = 'ONE_OFF'
  }
}
</script>

<template>
  <section class="finance-screen">
    <section v-if="activeTab === 'reports'" class="finance-section">
      <div class="finance-kpi-grid">
        <RemoteFinanceKpiCard
          v-for="kpiCard in kpiCards"
          :key="kpiCard.key"
          :label="kpiCard.label"
          :value="kpiCard.value"
          :caption="kpiCard.caption"
          :tone="kpiCard.tone"
        />
      </div>

      <article class="finance-panel">
        <header>
          <h3>Painel de tendências - fluxo mensal</h3>
          <small v-if="loadingState.dashboard">Atualizando...</small>
        </header>

        <div v-if="dashboardCashflowChartRows.length" class="finance-dashboard-chart-stack">
          <div
            v-for="chartRow in dashboardCashflowChartRows"
            :key="chartRow.key"
            class="finance-dashboard-chart-row"
            :class="chartRow.columns === 1 ? 'finance-dashboard-chart-row-single' : 'finance-dashboard-chart-row-double'"
          >
            <article
              v-for="chartCard in chartRow.charts"
              :key="chartCard.key"
              class="finance-dashboard-chart-card"
            >
              <div class="finance-dashboard-chart-card-header">
                <h4>{{ chartCard.title }}</h4>
                <small>{{ chartCard.summaryLabel }}</small>
              </div>

              <RemoteFinanceTrendMiniChart
                :points="chartCard.points"
                :stroke-color="chartCard.strokeColor"
              />

              <p class="finance-dashboard-chart-caption">{{ chartCard.caption }}</p>
              <small class="finance-dashboard-chart-secondary">{{ chartCard.secondaryLabel }}</small>
            </article>
          </div>

          <small v-if="dashboardCashflowMonthsLegend" class="finance-dashboard-legend">
            {{ dashboardCashflowMonthsLegend }}
          </small>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem dados de fluxo"
          description="Cadastre lançamentos para preencher o histórico de fluxo mensal."
        />
      </article>

      <article class="finance-panel">
        <header>
          <h3>Painel de tendências por categoria</h3>
        </header>

        <div v-if="dashboardCategoryChartRows.length" class="finance-dashboard-chart-stack">
          <div
            v-for="chartRow in dashboardCategoryChartRows"
            :key="chartRow.key"
            class="finance-dashboard-chart-row"
            :class="chartRow.columns === 1 ? 'finance-dashboard-chart-row-single' : 'finance-dashboard-chart-row-double'"
          >
            <article
              v-for="chartCard in chartRow.charts"
              :key="chartCard.key"
              class="finance-dashboard-chart-card"
            >
              <div class="finance-dashboard-chart-card-header">
                <h4>{{ chartCard.title }}</h4>
                <small>{{ chartCard.summaryLabel }}</small>
              </div>

              <RemoteFinanceTrendMiniChart
                :points="chartCard.points"
                :stroke-color="chartCard.strokeColor"
              />

              <p class="finance-dashboard-chart-caption">{{ chartCard.caption }}</p>
              <small class="finance-dashboard-chart-secondary">{{ chartCard.secondaryLabel }}</small>
            </article>
          </div>

          <small v-if="dashboardCategoriesLegend" class="finance-dashboard-legend">
            {{ dashboardCategoriesLegend }}
          </small>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem categorias no período"
          description="Nenhum lançamento encontrado para montar análise por categoria."
        />
      </article>

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
                <td>{{ cashflowItem.competenceMonth }}</td>
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
        <RemoteFinanceEmptyState
          v-else
          title="Sem detalhamento de fluxo"
          description="Cadastre lançamentos para visualizar o histórico mensal completo."
        />
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
              <tr v-for="categoryItem in dashboardCategories" :key="`${categoryItem.categoryId || 'none'}-${categoryItem.categoryName}`">
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
        <RemoteFinanceEmptyState
          v-else
          title="Sem categorias no período"
          description="Nenhum lançamento encontrado para montar análise por categoria."
        />
      </article>
    </section>

    <section v-if="activeTab === 'accounts'" class="finance-section">
      <nav class="finance-subtabs" aria-label="Abas da tela de contas">
        <button
          v-for="accountsTabOption in accountsTabOptions"
          :key="accountsTabOption.key"
          type="button"
          class="finance-subtab-button"
          :class="{ active: activeAccountsTab === accountsTabOption.key }"
          @click="activeAccountsTab = accountsTabOption.key"
        >
          {{ accountsTabOption.label }}
        </button>
      </nav>

      <article v-if="activeAccountsTab === 'overview'" class="finance-panel">
        <header>
          <h3>Resumo rápido: pagar x receber</h3>
          <small>Análise simples para o dia a dia. Detalhes completos em Relatórios.</small>
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
              <tr v-for="comparisonRow in accountsOverviewComparisonRows" :key="comparisonRow.key">
                <td>{{ comparisonRow.label }}</td>
                <td>{{ formatCurrency(comparisonRow.expectedBrl) }}</td>
                <td>{{ formatCurrency(comparisonRow.realizedBrl) }}</td>
                <td>{{ formatCurrency(comparisonRow.remainingBrl) }}</td>
                <td>{{ formatPercent(comparisonRow.progressPercent) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem dados de análise"
          description="Cadastre lançamentos para visualizar o comparativo entre pagar e receber."
        />
      </article>

      <article v-if="activeAccountsTab !== 'debts'" class="finance-panel">
        <header>
          <h3>Novo lançamento</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitEntry">
          <label v-if="activeAccountsTab === 'overview'">
            <span>Direção</span>
            <select v-model="entryForm.direction">
              <option
                v-for="directionOption in directionOptions"
                :key="directionOption.value"
                :value="directionOption.value"
              >
                {{ directionOption.label }}
              </option>
            </select>
          </label>

          <label v-else>
            <span>Direção</span>
            <input :value="accountsCurrentDirectionLabel" type="text" readonly>
          </label>

          <label>
            <span>Título</span>
            <input v-model="entryForm.title" type="text" required>
          </label>

          <label>
            <span>Tipo de lançamento</span>
            <select v-model="entryForm.entryType">
              <option
                v-for="entryTypeOption in entryTypeOptionsForForm"
                :key="entryTypeOption.value"
                :value="entryTypeOption.value"
              >
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
            <input v-model="entryForm.dueDate" type="date">
          </label>

          <label>
            <span>Categoria</span>
            <select v-model="entryForm.categoryId">
              <option value="">Sem categoria</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
          </label>

          <label>
            <span>Conta bancária</span>
            <select v-model="entryForm.bankAccountId">
              <option value="">Sem conta</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">Salvar lançamento</button>
        </form>
      </article>

      <article v-if="activeAccountsTab !== 'debts'" class="finance-panel">
        <header>
          <h3>Baixa de lançamento</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitSettlement">
          <label>
            <span>Lançamento</span>
            <select v-model="settlementForm.entryId">
              <option value="">Selecione</option>
              <option v-for="entry in entriesState" :key="entry.id" :value="entry.id">{{ entry.title }} ({{ formatCurrency(entry.remainingAmountBrl) }})</option>
            </select>
          </label>

          <label>
            <span>Valor da baixa</span>
            <input v-model="settlementForm.amountBrl" type="number" step="0.01" min="0.01" required>
          </label>

          <label>
            <span>Data real</span>
            <input v-model="settlementForm.settledAt" type="datetime-local">
          </label>

          <label>
            <span>Conta bancária</span>
            <select v-model="settlementForm.bankAccountId">
              <option value="">Sem conta</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">Registrar baixa</button>
        </form>
      </article>

      <article v-if="activeAccountsTab !== 'debts'" class="finance-panel">
        <header>
          <h3>{{ accountsEntriesTitle }}</h3>
          <small>{{ totalsLabel }}</small>
        </header>

        <form class="finance-filter-grid" @submit.prevent="loadEntries">
          <label>
            <span>Busca</span>
            <input v-model="entryFilters.search" type="text" placeholder="Título ou descrição">
          </label>

          <label v-if="activeAccountsTab === 'overview'">
            <span>Direção</span>
            <select v-model="entryFilters.direction">
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

          <label v-else>
            <span>Direção atual</span>
            <input :value="accountsCurrentDirectionLabel" type="text" readonly>
          </label>

          <label>
            <span>Status</span>
            <select v-model="entryFilters.status">
              <option value="">Todos</option>
              <option
                v-for="statusOption in entryStatusOptions"
                :key="statusOption.value"
                :value="statusOption.value"
              >
                {{ statusOption.label }}
              </option>
            </select>
          </label>

          <label>
            <span>Início</span>
            <input v-model="entryFilters.startDate" type="date">
          </label>

          <label>
            <span>Fim</span>
            <input v-model="entryFilters.endDate" type="date">
          </label>

          <button class="finance-action-button" type="submit">Filtrar</button>
        </form>

        <div v-if="entriesState.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Título</th>
                <th>Tipo</th>
                <th>Status</th>
                <th>Vencimento</th>
                <th>Esperado</th>
                <th>Restante</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in entriesState" :key="entry.id">
                <td>
                  <strong>{{ entry.title }}</strong>
                  <small class="finance-muted-block">{{ entry.categoryName || 'Sem categoria' }}</small>
                </td>
                <td>{{ getFinanceLabel(entry.entryType, '-') }}</td>
                <td>
                  <RemoteFinanceStatusBadge
                    :status="entry.status"
                    :label="getFinanceLabel(entry.status)"
                  />
                </td>
                <td>{{ entry.dueDate || '-' }}</td>
                <td>{{ formatCurrency(entry.expectedAmountBrl) }}</td>
                <td>{{ formatCurrency(entry.remainingAmountBrl) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem lançamentos"
          description="Cadastre o primeiro lançamento para começar seu fluxo financeiro."
        />
      </article>
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
              <option
                v-for="bankAccountTypeOption in bankAccountTypeOptions"
                :key="bankAccountTypeOption.value"
                :value="bankAccountTypeOption.value"
              >
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
          <button
            v-if="bankAccountEditingId"
            type="button"
            class="finance-inline-action"
            @click="resetBankAccountForm"
          >
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
              <tr v-for="bankAccount in bankAccounts" :key="bankAccount.id">
                <td>{{ bankAccount.name }}</td>
                <td>{{ bankAccount.branch || '-' }}</td>
                <td>{{ bankAccount.accountNumber || '-' }}</td>
                <td>{{ getFinanceLabel(bankAccount.accountType, '-') }}</td>
                <td>{{ formatCurrency(bankAccount.currentBalanceBrl) }}</td>
                <td>
                  <RemoteFinanceStatusBadge
                    :status="bankAccount.isActive ? 'ACTIVE' : 'INACTIVE'"
                    :label="bankAccount.isActive ? 'Ativa' : 'Inativa'"
                  />
                </td>
                <td>
                  <button
                    type="button"
                    class="finance-inline-action"
                    @click="startEditingBankAccount(bankAccount)"
                  >
                    Editar
                  </button>
                  <button
                    type="button"
                    class="finance-inline-action"
                    :disabled="!bankAccount.isActive"
                    @click="deleteBankAccount(bankAccount)"
                  >
                    Deletar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <RemoteFinanceEmptyState
          v-else
          title="Sem contas bancárias"
          description="Cadastre contas para vincular lançamentos e controlar saldo."
        />
      </article>
    </section>

    <section v-if="activeTab === 'settings'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Nova categoria</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitCategory">
          <label>
            <span>Nome</span>
            <input v-model="categoryForm.name" type="text" required>
          </label>

          <label>
            <span>Aplicação</span>
            <select v-model="categoryForm.kind">
              <option
                v-for="categoryKindOption in categoryKindOptions"
                :key="categoryKindOption.value"
                :value="categoryKindOption.value"
              >
                {{ categoryKindOption.label }}
              </option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">Salvar categoria</button>
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
              <tr v-for="category in categories" :key="category.id">
                <td>{{ category.name }}</td>
                <td>{{ getFinanceLabel(category.kind, category.kind || '-') }}</td>
                <td>{{ category.isSystem ? 'Sistema' : 'Personalizada' }}</td>
                <td>
                  <RemoteFinanceStatusBadge
                    :status="category.isActive ? 'ACTIVE' : 'INACTIVE'"
                    :label="category.isActive ? 'Ativa' : 'Inativa'"
                  />
                </td>
                <td>
                  <button
                    type="button"
                    class="finance-inline-action"
                    @click="toggleCategoryStatus(category)"
                  >
                    {{ category.isActive ? 'Inativar' : 'Ativar' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem categorias"
          description="Cadastre categorias para padronizar análise financeira."
        />
      </article>

      <article class="finance-panel">
        <header>
          <h3>Novo tipo de recorrência</h3>
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

          <button class="finance-action-button" type="submit">Salvar tipo recorrente</button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Tipos de recorrência</h3>
        </header>

        <div v-if="recurringTypes.length" class="finance-inline-table-wrap">
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
              <tr v-for="recurringType in recurringTypes" :key="recurringType.id">
                <td>{{ recurringType.name }}</td>
                <td>{{ recurringType.description || '-' }}</td>
                <td>{{ recurringType.isSystem ? 'Sistema' : 'Personalizada' }}</td>
                <td>
                  <RemoteFinanceStatusBadge
                    :status="recurringType.isActive ? 'ACTIVE' : 'INACTIVE'"
                    :label="recurringType.isActive ? 'Ativo' : 'Inativo'"
                  />
                </td>
                <td>
                  <button
                    type="button"
                    class="finance-inline-action"
                    @click="toggleRecurringTypeStatus(recurringType)"
                  >
                    {{ recurringType.isActive ? 'Inativar' : 'Ativar' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem tipos recorrentes"
          description="Cadastre os tipos recorrentes para usar nas regras automáticas."
        />
      </article>
    </section>

    <section v-if="activeTab === 'accounts' && activeAccountsTab === 'overview'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Nova recorrência</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitRecurringRule">
          <label>
            <span>Direção</span>
            <select v-model="recurringForm.direction">
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
            <input v-model="recurringForm.startsAt" type="date">
          </label>

          <label>
            <span>Tipo recorrente</span>
            <select v-model="recurringForm.recurringTypeId" required>
              <option value="">Selecione</option>
              <option v-for="recurringType in recurringTypes" :key="recurringType.id" :value="recurringType.id">{{ recurringType.name }}</option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">Criar recorrência</button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Regras recorrentes</h3>
        </header>

        <div v-if="recurringRules.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Título</th>
                <th>Tipo</th>
                <th>Valor</th>
                <th>Próxima execução</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rule in recurringRules" :key="rule.id">
                <td>{{ rule.title }}</td>
                <td>{{ rule.recurringTypeName }}</td>
                <td>{{ formatCurrency(rule.amountBrl) }}</td>
                <td>{{ rule.nextRunDate || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem regras recorrentes"
          description="Cadastre regras para gerar lançamentos automaticamente mês a mês."
        />
      </article>
    </section>

    <section v-if="activeTab === 'accounts' && activeAccountsTab === 'overview'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Novo parcelamento</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitInstallmentPlan">
          <label>
            <span>Direção</span>
            <select v-model="installmentForm.direction">
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

          <button class="finance-action-button" type="submit">Criar parcelamento</button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Renegociar</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitRenegotiation">
          <label>
            <span>Plano</span>
            <select v-model="renegotiationForm.planId">
              <option value="">Selecione</option>
              <option v-for="plan in installmentPlans" :key="plan.id" :value="plan.id">{{ plan.title }}</option>
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

          <button class="finance-action-button" type="submit">Renegociar plano</button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Planos de parcelamento</h3>
        </header>

        <div v-if="installmentPlans.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Título</th>
                <th>Status</th>
                <th>Total</th>
                <th>Restante</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="plan in installmentPlans" :key="plan.id">
                <td>{{ plan.title }}</td>
                <td>
                  <RemoteFinanceStatusBadge
                    :status="plan.status"
                    :label="getFinanceLabel(plan.status)"
                  />
                </td>
                <td>{{ formatCurrency(plan.totalAmountBrl) }}</td>
                <td>{{ formatCurrency(plan.remainingAmountBrl) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem parcelamentos"
          description="Crie parcelamentos para gerar todas as parcelas automaticamente."
        />
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
              <option
                v-for="investmentTypeOption in investmentTypeOptions"
                :key="investmentTypeOption.value"
                :value="investmentTypeOption.value"
              >
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
          <RemoteFinanceKpiCard label="Patrimônio final" :value="investmentSummary?.finalAmount || '-'" tone="positive" />
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
              <option
                v-for="investmentYieldModeOption in investmentYieldModeOptions"
                :key="investmentYieldModeOption.value"
                :value="investmentYieldModeOption.value"
              >
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
              </tr>
            </thead>
            <tbody>
              <tr v-for="plan in investmentPlans" :key="plan.id">
                <td>{{ plan.label }}</td>
                <td>
                  <RemoteFinanceStatusBadge
                    :status="plan.status"
                    :label="getFinanceLabel(plan.status)"
                  />
                </td>
                <td>{{ formatCurrency(plan.monthlyContributionBrl) }}</td>
                <td>{{ Number(plan.effectiveMonthlyRate || 0).toFixed(6) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem planos de investimento"
          description="Gere uma simulação e converta para criar o primeiro plano real."
        />
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
            <span>Entrada (opcional)</span>
            <input v-model="debtForm.downPaymentBrl" type="number" step="0.01" min="0">
          </label>

          <label>
            <span>Renda mensal (opcional)</span>
            <input v-model="debtForm.monthlyIncomeBrl" type="number" step="0.01" min="0" placeholder="Se vazio, o sistema estima">
          </label>

          <label>
            <span>Limite da renda (%)</span>
            <input v-model="debtForm.maxCommitmentPercent" type="number" step="0.01" min="5" max="90">
          </label>

          <label>
            <span>Quantidade desejada de parcelas</span>
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
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
          </label>

          <label>
            <span>Conta bancária</span>
            <select v-model="debtForm.defaultBankAccountId">
              <option value="">Sem conta</option>
              <option v-for="bankAccount in bankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
            </select>
          </label>

          <label>
            <span>Modo de quitação</span>
            <select v-model="debtForm.settlementMode">
              <option value="INSTALLMENT">Parcelado</option>
              <option value="FULL">À vista</option>
            </select>
          </label>

          <label>
            <span>Parcelas escolhidas</span>
            <input v-model="debtForm.selectedInstallmentsCount" type="number" min="1" max="120">
          </label>

          <label>
            <span>Observação da negociação</span>
            <input v-model="debtForm.negotiationNote" type="text" placeholder="Descreva o acordo">
          </label>

          <label>
            <span>Observação da proposta</span>
            <input v-model="debtForm.proposalNote" type="text" placeholder="Descreva a proposta">
          </label>

          <label>
            <span>Observações gerais</span>
            <input v-model="debtForm.notes" type="text" placeholder="Notas adicionais">
          </label>

          <label class="finance-toggle-label">
            <input v-model="debtForm.allowExceedLimit" type="checkbox">
            <span>Permitir criar plano acima do limite de renda</span>
          </label>

          <button class="finance-action-button" type="submit">Simular plano</button>
          <button class="finance-action-button" type="button" @click="submitDebtPlan">Criar plano</button>
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
                <th>Base escolhida</th>
                <td>{{ getDebtReferenceTypeLabel(debtPreview.selectedReferenceType) }}</td>
              </tr>
              <tr>
                <th>Valor planejado</th>
                <td>{{ formatCurrency(debtPreview.plannedTotalAmountBrl) }}</td>
              </tr>
              <tr>
                <th>Renda mensal usada</th>
                <td>{{ formatCurrency(debtPreview.monthlyIncomeBrl) }} ({{ debtPreview.monthlyIncomeSource === 'INPUT' ? 'informada' : 'estimada' }})</td>
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
                <td>{{ formatCurrency(debtPreview.maxRecommendedPaymentBrl) }} ({{ formatPercent(debtPreview.maxCommitmentPercent) }})</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem simulação"
          description="Preencha o formulário e clique em simular para receber sugestões de pagamento."
        />

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
                  <RemoteFinanceStatusBadge
                    :status="suggestion.withinLimit ? 'PAID' : 'OVERDUE'"
                    :label="suggestion.withinLimit ? 'Sim' : 'Não'"
                  />
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
              </tr>
            </thead>
            <tbody>
              <tr v-for="debtPlan in debtPlans" :key="debtPlan.id">
                <td>{{ debtPlan.title }}</td>
                <td>{{ debtPlan.creditorName || '-' }}</td>
                <td>{{ getDebtSettlementModeLabel(debtPlan.settlementMode) }}</td>
                <td>{{ formatCurrency(debtPlan.plannedTotalAmountBrl) }}</td>
                <td>{{ formatCurrency(debtPlan.selectedMonthlyPaymentBrl) }}</td>
                <td>
                  <RemoteFinanceStatusBadge :status="debtPlan.status" :label="getFinanceLabel(debtPlan.status)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem planos de dívida"
          description="Crie seu primeiro plano de pagamento de dívidas para acompanhar negociações e parcelas."
        />
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

          <button class="finance-action-button" type="submit">Atualizar cotações</button>
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
              </tr>
            </thead>
            <tbody>
              <tr v-for="currencyRateItem in currencyRates" :key="currencyRateItem.code">
                <td>{{ currencyRateItem.code }} - {{ currencyRateItem.name }}</td>
                <td>{{ formatExchangeRate(currencyRateItem.buyRateBrl) }}</td>
                <td>{{ formatExchangeRate(currencyRateItem.sellRateBrl) }}</td>
                <td>{{ currencyRateItem.quoteDate || '-' }}</td>
                <td>{{ currencyRateItem.quoteDateTime || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem cotações"
          description="Selecione moedas e atualize para buscar cotações da API do Bacen."
        />
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
              <option
                v-for="exportTypeOption in exportTypeOptions"
                :key="exportTypeOption.value"
                :value="exportTypeOption.value"
              >
                {{ exportTypeOption.label }}
              </option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">Enfileirar exportação</button>
        </form>
      </article>

      <article class="finance-panel">
        <header>
          <h3>Histórico de exportações</h3>
        </header>

        <div v-if="exportJobs.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Status</th>
                <th>Solicitado em</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="exportJob in exportJobs" :key="exportJob.id">
                <td>{{ getFinanceExportTypeLabel(exportJob.exportType, '-') }}</td>
                <td>
                  <RemoteFinanceStatusBadge
                    :status="exportJob.status"
                    :label="getFinanceExportStatusLabel(exportJob.status)"
                  />
                </td>
                <td>{{ exportJob.requestedAt }}</td>
                <td>
                  <button
                    type="button"
                    class="finance-inline-action"
                    :disabled="exportJob.status !== 'DONE'"
                    @click="downloadExport(exportJob)"
                  >
                    Download
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem exportações"
          description="As exportações geradas aparecerão aqui para download."
        />
      </article>

      <article class="finance-panel">
        <header>
          <h3>Open Finance (base preparada)</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="createOpenFinanceConnection">
          <label>
            <span>Provider</span>
            <select v-model="openFinanceForm.providerId">
              <option value="">Selecione</option>
              <option v-for="provider in openFinanceProviders" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
            </select>
          </label>

          <label>
            <span>Status inicial</span>
            <select v-model="openFinanceForm.status">
              <option
                v-for="connectionStatusOption in openFinanceConnectionStatusOptions"
                :key="connectionStatusOption.value"
                :value="connectionStatusOption.value"
              >
                {{ connectionStatusOption.label }}
              </option>
            </select>
          </label>

          <label class="finance-toggle-label">
            <input v-model="openFinanceForm.createMockData" type="checkbox">
            <span>Gerar dados mock na sincronização</span>
          </label>

          <button class="finance-action-button" type="submit">Criar conexão</button>
        </form>

        <div v-if="openFinanceConnections.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Provider</th>
                <th>Status</th>
                <th>Última sync</th>
                <th>Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="connection in openFinanceConnections" :key="connection.id">
                <td>{{ connection.providerName }}</td>
                <td>
                  <RemoteFinanceStatusBadge
                    :status="connection.status"
                    :label="getFinanceLabel(connection.status)"
                  />
                </td>
                <td>{{ connection.lastSyncAt || '-' }}</td>
                <td>
                  <button type="button" class="finance-inline-action" @click="runOpenFinanceSync(connection.id)">Sincronizar</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem conexões Open Finance"
          description="Crie conexões para preparar o vínculo com dados bancários externos."
        />
      </article>
    </section>
  </section>
</template>

<style scoped>
.finance-screen {
  display: grid;
  gap: 18px;
}

.finance-screen-header {
  border: 1px solid var(--line, #cbd5e1);
  border-radius: 14px;
  padding: 18px;
  background: var(--soft-surface, linear-gradient(120deg, #ffffff 0%, #f8fafc 100%));
  overflow: hidden;
}

.finance-screen-header h2 {
  margin: 2px 0 6px;
  font-size: 1.24rem;
  color: var(--ink, #0f172a);
}

.finance-screen-header p {
  margin: 0;
  color: var(--muted, #475569);
}

.finance-eyebrow {
  margin: 0;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.72rem;
  color: var(--accent, #2563eb);
  font-weight: 700;
}

.finance-section {
  display: grid;
  gap: 16px;
}

.finance-subtabs {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.finance-subtab-button {
  border: 1px solid var(--line, #cbd5e1);
  border-radius: 10px;
  min-height: 38px;
  padding: 0 14px;
  background: var(--surface-strong, #ffffff);
  color: var(--muted, #475569);
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
}

.finance-subtab-button.active {
  border-color: var(--accent, #1d4ed8);
  background: color-mix(in srgb, var(--accent, #1d4ed8) 14%, #ffffff);
  color: var(--accent, #1d4ed8);
}

.finance-panel {
  border: 1px solid var(--line, #cbd5e1);
  border-radius: 14px;
  background: var(--surface-strong, #ffffff);
  padding: 16px;
  display: grid;
  gap: 14px;
  overflow: hidden;
}

.finance-panel header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
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

.finance-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.finance-dashboard-chart-stack {
  display: grid;
  gap: 12px;
}

.finance-dashboard-chart-row {
  display: grid;
  gap: 12px;
}

.finance-dashboard-chart-row-double {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.finance-dashboard-chart-row-single {
  grid-template-columns: 1fr;
}

.finance-dashboard-chart-card {
  border: 1px solid color-mix(in srgb, var(--accent, #1d4ed8) 24%, var(--line, #cbd5e1));
  border-radius: 12px;
  padding: 12px;
  background: var(--soft-surface, linear-gradient(120deg, #ffffff 0%, #f8fafc 100%));
  display: grid;
  gap: 10px;
}

.finance-dashboard-chart-card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.finance-dashboard-chart-card h4 {
  margin: 0;
  font-size: 0.88rem;
  color: var(--ink, #0f172a);
}

.finance-dashboard-chart-caption {
  margin: 0;
  color: var(--muted, #475569);
  font-size: 0.78rem;
  line-height: 1.4;
}

.finance-dashboard-chart-secondary {
  font-size: 0.72rem;
  color: var(--muted, #64748b);
}

.finance-dashboard-legend {
  display: block;
  font-size: 0.73rem;
  color: var(--muted, #64748b);
}

.finance-form-grid,
.finance-filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr));
  gap: 14px;
  align-items: end;
}

.finance-form-grid label,
.finance-filter-grid label {
  display: grid;
  gap: 8px;
  min-width: 0;
}

.finance-form-grid label span,
.finance-filter-grid label span {
  font-size: 0.78rem;
  color: var(--muted, #475569);
  font-weight: 700;
  text-transform: none;
  letter-spacing: 0.01em;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.finance-form-grid input,
.finance-filter-grid input,
.finance-form-grid select,
.finance-filter-grid select {
  border-radius: 10px;
  border: 1px solid var(--line, #cbd5e1);
  min-height: 42px;
  padding: 0 12px;
  width: 100%;
  max-width: 100%;
  min-width: 0;
  color: var(--ink, #0f172a);
  background: var(--app-field-bg, color-mix(in srgb, var(--surface-strong, #ffffff) 88%, transparent));
}

.finance-form-grid select,
.finance-filter-grid select {
  text-overflow: ellipsis;
}

.finance-multi-select-field {
  align-self: stretch;
}

.finance-multi-select {
  min-height: 132px;
  padding-top: 8px;
  padding-bottom: 8px;
}

.finance-field-hint {
  font-size: 0.73rem;
  color: var(--muted, #64748b);
}

.finance-action-button,
.finance-inline-action {
  border: 1px solid var(--accent, #1d4ed8);
  background: var(--accent, #1d4ed8);
  color: var(--button-primary-text, #ffffff);
  border-radius: 10px;
  min-height: 42px;
  padding: 0 12px;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  margin-left: 8px;
}

.finance-form-grid > .finance-action-button,
.finance-filter-grid > .finance-action-button {
  justify-self: start;
  width: auto;
  min-width: 168px;
  max-width: 100%;
}

.finance-inline-action:disabled {
  border-color: var(--line, #94a3b8);
  background: color-mix(in srgb, var(--muted, #94a3b8) 72%, transparent);
  color: var(--ink, #0f172a);
  cursor: not-allowed;
}

.finance-toggle-label {
  display: flex;
  align-items: center;
  gap: 8px;
}

.finance-toggle-label input {
  width: auto;
  min-height: auto;
}

.finance-inline-table-wrap {
  overflow-x: auto;
  max-width: 100%;
}

.finance-inline-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: auto;
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

.finance-muted-block {
  display: block;
  color: var(--muted, #64748b);
  font-size: 0.74rem;
}

@media (max-width: 768px) {
  .finance-screen-header {
    padding: 14px;
  }

  .finance-panel {
    padding: 12px;
  }

  .finance-dashboard-chart-row-double {
    grid-template-columns: 1fr;
  }

  .finance-form-grid,
  .finance-filter-grid {
    grid-template-columns: 1fr;
    gap: 12px;
  }

  .finance-form-grid > .finance-action-button,
  .finance-filter-grid > .finance-action-button {
    width: 100%;
  }
}
</style>
