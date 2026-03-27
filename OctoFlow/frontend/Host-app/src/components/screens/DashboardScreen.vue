<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import {
  RemoteFinanceEmptyState,
  RemoteFinanceKpiCard,
  RemoteFinanceTrendMiniChart,
} from '../../federation/remoteComponents'
import { useNotification } from '../../composables/useNotification'
import {
  fetchFinanceBankAccounts,
  fetchFinanceDashboardCashflow,
  fetchFinanceDashboardCategories,
  fetchFinanceDashboardSummary,
  fetchFinanceDebtPlans,
  fetchFinanceEntries,
  fetchFinanceInstallmentPlans,
  fetchFinanceRecurringRules,
} from '../../services/finance'
import { fetchFinanceInvestmentPlans } from '../../services/financeInvestments'
import { fetchGithubProfile, fetchGithubWorkspace } from '../../services/githubWorkspace'
import { fetchGithubIssuesCache, syncGithubIssues } from '../../services/tasks'
import { extractHttpMessage } from '../../utils/httpErrors'
import { formatDate } from '../../utils/date'

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
})

const profile = ref(null)
const workspace = ref(null)
const issueBoard = ref(null)
const loading = ref(false)
const syncing = ref(false)
const error = ref('')
const status = ref('')
const activeTab = ref('tasks')
const activeFinancialGroupKey = ref('accountsPayable')
const financeLoading = ref(false)
const financeLoaded = ref(false)
const financeErrorMessage = ref('')
const financeSummaryGlobal = ref(null)
const financeSummaryByGroup = ref(null)
const financeCashflowGlobal = ref([])
const financeCashflowByGroup = ref([])
const financeCategoriesGlobal = ref([])
const financeCategoriesByGroup = ref([])
const financeEntries = ref([])
const financeEntriesMeta = ref({ page: 1, itemsPerPage: 10, total: 0 })
const financeBankAccounts = ref([])
const financeRecurringRules = ref([])
const financeInstallmentPlans = ref([])
const financeDebtPlans = ref([])
const financeInvestmentPlans = ref([])
const dashboardChartContainerKeys = [
  'issueStatus',
  'closureWindow',
  'cycleTime',
  'updateFreshness',
  'mostOpenTypes',
  'responseByType',
]
const collapsedDashboardContainers = ref({
  issueStatus: false,
  closureWindow: false,
  cycleTime: false,
  updateFreshness: false,
  mostOpenTypes: false,
  responseByType: false,
})
const { notifyUser } = useNotification(props.notify)
const issueStatusDonutRadius = 68
const issueStatusDonutCircumference = 2 * Math.PI * issueStatusDonutRadius
const financialGroupOptions = [
  {
    key: 'accountsPayable',
    label: 'Contas a Pagar',
    description: 'Compromissos de saida e vencimentos',
  },
  {
    key: 'accountsReceivable',
    label: 'Contas a Receber',
    description: 'Entradas previstas e em aberto',
  },
]

const workspaceReady = computed(() => Boolean(profile.value?.workspaceReady))
const allDashboardContainersCollapsed = computed(() => {
  return dashboardChartContainerKeys.every((containerKey) => Boolean(collapsedDashboardContainers.value[containerKey]))
})
const dashboardContainersToggleLabel = computed(() => {
  return allDashboardContainersCollapsed.value ? 'Expandir containers' : 'Recolher containers'
})
const activeFinancialDirection = computed(() => {
  return activeFinancialGroupKey.value === 'accountsPayable'
    ? 'PAYABLE'
    : 'RECEIVABLE'
})
const activeFinancialGroup = computed(() => {
  const selectedFinancialGroup = financialGroupOptions.find((financialGroupOption) => {
    return financialGroupOption.key === activeFinancialGroupKey.value
  })

  return selectedFinancialGroup || financialGroupOptions[0]
})
const financialGroupTitle = computed(() => {
  return activeFinancialDirection.value === 'PAYABLE'
    ? 'Controle de pagamentos do periodo'
    : 'Controle de recebimentos do periodo'
})
const financialGroupDescription = computed(() => {
  return activeFinancialDirection.value === 'PAYABLE'
    ? 'Organize titulos pendentes, vencimentos e previsao de saidas para nao misturar fluxo financeiro com a operacao de tarefas.'
    : 'Organize valores previstos, carteira em aberto e recebimentos liquidados para manter visibilidade de entrada de caixa.'
})
const financialSummaryCards = computed(() => {
  if (!financeSummaryByGroup.value || !financeSummaryGlobal.value) {
    return []
  }

  const groupSummary = financeSummaryByGroup.value
  const globalSummary = financeSummaryGlobal.value
  const isPayableGroup = activeFinancialDirection.value === 'PAYABLE'
  const groupExpectedMainAmount = isPayableGroup
    ? Number(groupSummary.expectedExpenseBrl || 0)
    : Number(groupSummary.expectedIncomeBrl || 0)
  const groupRealizedMainAmount = isPayableGroup
    ? Number(groupSummary.realizedExpenseBrl || 0)
    : Number(groupSummary.realizedIncomeBrl || 0)
  const groupExecutionPercent = groupExpectedMainAmount > 0
    ? roundMonetaryValue((groupRealizedMainAmount / groupExpectedMainAmount) * 100)
    : 0
  const groupExpectedNetAmount = Number(groupSummary.expectedNetBrl || 0)
  const groupRealizedNetAmount = Number(groupSummary.realizedNetBrl || 0)
  const globalExpectedNetAmount = Number(globalSummary.expectedNetBrl || 0)
  const globalRealizedNetAmount = Number(globalSummary.realizedNetBrl || 0)

  return [
    {
      key: 'group-expected-main',
      label: isPayableGroup ? 'Pagamento previsto' : 'Recebimento previsto',
      value: formatCurrency(groupExpectedMainAmount),
      caption: `${activeFinancialGroup.value.label} no periodo selecionado`,
      tone: isPayableGroup ? 'warning' : 'positive',
    },
    {
      key: 'group-realized-main',
      label: isPayableGroup ? 'Pagamento realizado' : 'Recebimento realizado',
      value: formatCurrency(groupRealizedMainAmount),
      caption: 'Valor efetivamente liquidado no grupo ativo',
      tone: isPayableGroup ? 'warning' : 'positive',
    },
    {
      key: 'group-remaining',
      label: 'Saldo pendente do grupo',
      value: formatCurrency(groupSummary.remainingTotalBrl),
      caption: 'Valor ainda aberto no grupo ativo',
      tone: Number(groupSummary.remainingTotalBrl || 0) > 0 ? 'warning' : 'neutral',
    },
    {
      key: 'group-overdue',
      label: 'Atrasos do grupo',
      value: formatInteger(groupSummary.overdueEntriesCount),
      caption: 'Titulos vencidos e nao liquidados',
      tone: Number(groupSummary.overdueEntriesCount || 0) > 0 ? 'negative' : 'neutral',
    },
    {
      key: 'group-expected-net',
      label: 'Saldo previsto do grupo',
      value: formatCurrency(groupExpectedNetAmount),
      caption: 'Entradas previstas menos saidas previstas',
      tone: groupExpectedNetAmount >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'group-realized-net',
      label: 'Saldo realizado do grupo',
      value: formatCurrency(groupRealizedNetAmount),
      caption: 'Entradas realizadas menos saidas realizadas',
      tone: groupRealizedNetAmount >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'global-net',
      label: 'Saldo geral consolidado',
      value: formatCurrency(globalExpectedNetAmount),
      caption: `Previsto: ${formatCurrency(globalRealizedNetAmount)} realizado`,
      tone: globalExpectedNetAmount >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'accounts-balance',
      label: 'Saldo em contas bancarias',
      value: formatCurrency(globalSummary.accountsBalanceBrl),
      caption: `Execucao do grupo: ${formatPercentage(groupExecutionPercent)}`,
      tone: Number(globalSummary.accountsBalanceBrl || 0) >= 0 ? 'positive' : 'negative',
    },
  ]
})
const refreshButtonDisabled = computed(() => {
  return activeTab.value === 'tasks'
    ? loading.value || syncing.value
    : financeLoading.value
})
const refreshButtonTitle = computed(() => {
  return activeTab.value === 'tasks'
    ? 'Atualizar analises'
    : 'Atualizar dashboard financeiro'
})
const financeBankAccountsList = computed(() => {
  return Array.isArray(financeBankAccounts.value) ? financeBankAccounts.value : []
})
const financeRecurringRulesList = computed(() => {
  return Array.isArray(financeRecurringRules.value) ? financeRecurringRules.value : []
})
const financeInstallmentPlansList = computed(() => {
  return Array.isArray(financeInstallmentPlans.value) ? financeInstallmentPlans.value : []
})
const financeDebtPlansList = computed(() => {
  return Array.isArray(financeDebtPlans.value) ? financeDebtPlans.value : []
})
const financeInvestmentPlansList = computed(() => {
  return Array.isArray(financeInvestmentPlans.value) ? financeInvestmentPlans.value : []
})
const financeEntriesByGroup = computed(() => {
  return Array.isArray(financeEntries.value) ? financeEntries.value : []
})
const financePendingEntries = computed(() => {
  return financeEntriesByGroup.value.filter((financeEntry) => Number(financeEntry.remainingAmountBrl || 0) > 0)
})
const financeOverdueEntries = computed(() => {
  return financePendingEntries.value.filter((financeEntry) => isFinanceEntryOverdue(financeEntry))
})
const financeUpcomingEntries = computed(() => {
  return financePendingEntries.value
    .map((financeEntry) => ({
      ...financeEntry,
      daysUntilDue: calculateDaysUntil(financeEntry.dueDate),
    }))
    .filter((financeEntry) => financeEntry.daysUntilDue !== null)
    .sort((leftFinanceEntry, rightFinanceEntry) => {
      const leftDays = Number(leftFinanceEntry.daysUntilDue)
      const rightDays = Number(rightFinanceEntry.daysUntilDue)
      return leftDays - rightDays
    })
    .slice(0, 10)
})
const financeTotalPendingAmount = computed(() => {
  return sumNumericValues(
    financePendingEntries.value.map((financeEntry) => Number(financeEntry.remainingAmountBrl || 0)),
  )
})
const financeCategoryDetailsRows = computed(() => {
  const categoryRows = Array.isArray(financeCategoriesGlobal.value) ? financeCategoriesGlobal.value : []

  return categoryRows.slice(0, 12)
})
const financeEntryStatusSeries = computed(() => {
  const statusCounters = new Map()

  for (const financeEntry of financeEntriesByGroup.value) {
    const financeStatusCode = String(financeEntry.status || 'UNKNOWN')
    statusCounters.set(financeStatusCode, (statusCounters.get(financeStatusCode) || 0) + 1)
  }

  return withPercent(
    Array.from(statusCounters.entries())
      .map(([statusCode, totalByStatus]) => ({
        key: statusCode,
        label: getFinanceEntryStatusLabel(statusCode),
        value: totalByStatus,
      }))
      .sort((leftStatus, rightStatus) => rightStatus.value - leftStatus.value),
  )
})
const financialOperationalCards = computed(() => {
  const activeBankAccountsTotal = financeBankAccountsList.value.filter((bankAccount) => bankAccount.isActive).length
  const openInstallmentsTotal = financeInstallmentPlansList.value.filter((installmentPlan) => {
    return !['PAID', 'CANCELED'].includes(String(installmentPlan.status || ''))
  }).length
  const openDebtsTotal = financeDebtPlansList.value.filter((debtPlan) => {
    return !['PAID', 'CANCELED'].includes(String(debtPlan.status || ''))
  }).length
  const activeInvestmentsTotal = financeInvestmentPlansList.value.filter((investmentPlan) => {
    return ['ACTIVE', 'RUNNING'].includes(String(investmentPlan.status || '').toUpperCase())
  }).length

  return [
    {
      key: 'open-entries',
      label: 'Lancamentos em aberto',
      value: formatInteger(financePendingEntries.value.length),
      note: `${formatCurrency(financeTotalPendingAmount.value)} ainda pendente no grupo ativo`,
    },
    {
      key: 'overdue-entries',
      label: 'Lancamentos atrasados',
      value: formatInteger(financeOverdueEntries.value.length),
      note: 'Titulos vencidos que ainda nao foram liquidados',
    },
    {
      key: 'active-bank-accounts',
      label: 'Contas bancarias ativas',
      value: formatInteger(activeBankAccountsTotal),
      note: `${formatInteger(financeBankAccountsList.value.length)} contas cadastradas no total`,
    },
    {
      key: 'recurrence-rules',
      label: 'Regras recorrentes',
      value: formatInteger(financeRecurringRulesList.value.length),
      note: 'Automacoes de fluxo mensal configuradas',
    },
    {
      key: 'installments-and-debts',
      label: 'Parcelamentos e dividas',
      value: `${formatInteger(openInstallmentsTotal)} / ${formatInteger(openDebtsTotal)}`,
      note: 'Parcelamentos abertos / planos de divida em aberto',
    },
    {
      key: 'investments',
      label: 'Planos de investimento ativos',
      value: formatInteger(activeInvestmentsTotal),
      note: `${formatInteger(financeInvestmentPlansList.value.length)} planos cadastrados no total`,
    },
  ]
})
const financeCashflowSeriesGlobal = computed(() => {
  return normalizeCashflowSeries(financeCashflowGlobal.value)
})
const financeCashflowSeriesByGroup = computed(() => {
  return normalizeCashflowSeries(financeCashflowByGroup.value)
})
const financeCashflowLegend = computed(() => {
  return buildCompactLegend(
    financeCashflowSeriesByGroup.value.monthLabels,
    'Periodo analisado',
  )
})
const financeCashflowChartRows = computed(() => {
  if (financeCashflowSeriesByGroup.value.monthLabels.length === 0 && financeCashflowSeriesGlobal.value.monthLabels.length === 0) {
    return []
  }

  const isPayableGroup = activeFinancialDirection.value === 'PAYABLE'
  const groupMainExpectedSeries = isPayableGroup
    ? financeCashflowSeriesByGroup.value.expectedExpenseSeries
    : financeCashflowSeriesByGroup.value.expectedIncomeSeries
  const groupMainRealizedSeries = isPayableGroup
    ? financeCashflowSeriesByGroup.value.realizedExpenseSeries
    : financeCashflowSeriesByGroup.value.realizedIncomeSeries
  const groupGapSeries = groupMainExpectedSeries.map((expectedValue, seriesIndex) => (
    roundMonetaryValue(expectedValue - Number(groupMainRealizedSeries[seriesIndex] || 0))
  ))

  return [
    {
      key: 'group-main-flow',
      columns: 2,
      charts: [
        {
          key: 'group-main-expected',
          title: isPayableGroup ? 'Pagamentos previstos por mes' : 'Recebimentos previstos por mes',
          caption: 'Planejamento mensal do grupo financeiro ativo.',
          points: groupMainExpectedSeries,
          strokeColor: isPayableGroup ? '#ea580c' : '#0284c7',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(groupMainExpectedSeries))}`,
          secondaryLabel: `Ultimo mes: ${formatCurrency(getLastNumericValue(groupMainExpectedSeries))}`,
        },
        {
          key: 'group-main-realized',
          title: isPayableGroup ? 'Pagamentos realizados por mes' : 'Recebimentos realizados por mes',
          caption: 'Liquidacao mensal efetiva do grupo ativo.',
          points: groupMainRealizedSeries,
          strokeColor: isPayableGroup ? '#f97316' : '#0369a1',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(groupMainRealizedSeries))}`,
          secondaryLabel: `Ultimo mes: ${formatCurrency(getLastNumericValue(groupMainRealizedSeries))}`,
        },
      ],
    },
    {
      key: 'global-net-flow',
      columns: 2,
      charts: [
        {
          key: 'global-expected-net',
          title: 'Saldo previsto consolidado por mes',
          caption: 'Entradas previstas menos saidas previstas, sem filtro de grupo.',
          points: financeCashflowSeriesGlobal.value.expectedNetSeries,
          strokeColor: '#2563eb',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(financeCashflowSeriesGlobal.value.expectedNetSeries))}`,
          secondaryLabel: `Ultimo mes: ${formatCurrency(getLastNumericValue(financeCashflowSeriesGlobal.value.expectedNetSeries))}`,
        },
        {
          key: 'global-realized-net',
          title: 'Saldo realizado consolidado por mes',
          caption: 'Entradas realizadas menos saidas realizadas, sem filtro de grupo.',
          points: financeCashflowSeriesGlobal.value.realizedNetSeries,
          strokeColor: '#16a34a',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(financeCashflowSeriesGlobal.value.realizedNetSeries))}`,
          secondaryLabel: `Ultimo mes: ${formatCurrency(getLastNumericValue(financeCashflowSeriesGlobal.value.realizedNetSeries))}`,
        },
      ],
    },
    {
      key: 'global-income-expense',
      columns: 2,
      charts: [
        {
          key: 'global-expected-income',
          title: 'Entradas previstas consolidadas',
          caption: 'Projecao de receitas mensais no total.',
          points: financeCashflowSeriesGlobal.value.expectedIncomeSeries,
          strokeColor: '#0ea5e9',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(financeCashflowSeriesGlobal.value.expectedIncomeSeries))}`,
          secondaryLabel: `Media mensal: ${formatCurrency(averageNumericValue(financeCashflowSeriesGlobal.value.expectedIncomeSeries))}`,
        },
        {
          key: 'global-expected-expense',
          title: 'Saidas previstas consolidadas',
          caption: 'Projecao de despesas mensais no total.',
          points: financeCashflowSeriesGlobal.value.expectedExpenseSeries,
          strokeColor: '#f97316',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(financeCashflowSeriesGlobal.value.expectedExpenseSeries))}`,
          secondaryLabel: `Media mensal: ${formatCurrency(averageNumericValue(financeCashflowSeriesGlobal.value.expectedExpenseSeries))}`,
        },
      ],
    },
    {
      key: 'group-gap-flow',
      columns: 1,
      charts: [
        {
          key: 'group-gap',
          title: isPayableGroup
            ? 'Gap entre pagamentos previstos e realizados'
            : 'Gap entre recebimentos previstos e realizados',
          caption: 'Valor positivo indica o quanto ainda falta liquidar no mes.',
          points: groupGapSeries,
          strokeColor: '#7c3aed',
          summaryLabel: `Media do gap: ${formatCurrency(averageNumericValue(groupGapSeries))}`,
          secondaryLabel: `Maior desvio: ${formatCurrency(getMaxAbsoluteNumericValue(groupGapSeries))}`,
        },
      ],
    },
  ]
})
const financeCategorySeriesGlobal = computed(() => {
  return normalizeCategorySeries(financeCategoriesGlobal.value)
})
const financeCategorySeriesByGroup = computed(() => {
  return normalizeCategorySeries(financeCategoriesByGroup.value)
})
const financeCategoriesLegend = computed(() => {
  return buildCompactLegend(
    financeCategorySeriesByGroup.value.categoryLabels,
    'Categorias analisadas',
  )
})
const financeCategoryChartRows = computed(() => {
  if (financeCategorySeriesByGroup.value.categoryLabels.length === 0 && financeCategorySeriesGlobal.value.categoryLabels.length === 0) {
    return []
  }

  const isPayableGroup = activeFinancialDirection.value === 'PAYABLE'
  const groupMainExpectedSeries = isPayableGroup
    ? financeCategorySeriesByGroup.value.expectedExpenseSeries
    : financeCategorySeriesByGroup.value.expectedIncomeSeries
  const groupMainRealizedSeries = isPayableGroup
    ? financeCategorySeriesByGroup.value.realizedExpenseSeries
    : financeCategorySeriesByGroup.value.realizedIncomeSeries

  return [
    {
      key: 'group-main-category',
      columns: 2,
      charts: [
        {
          key: 'group-category-expected',
          title: isPayableGroup ? 'Despesa prevista por categoria' : 'Receita prevista por categoria',
          caption: 'Distribuicao prevista por categoria no grupo ativo.',
          points: groupMainExpectedSeries,
          strokeColor: isPayableGroup ? '#f97316' : '#0284c7',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(groupMainExpectedSeries))}`,
          secondaryLabel: `Maior valor: ${formatCurrency(getMaxNumericValue(groupMainExpectedSeries))}`,
        },
        {
          key: 'group-category-realized',
          title: isPayableGroup ? 'Despesa realizada por categoria' : 'Receita realizada por categoria',
          caption: 'Distribuicao realizada por categoria no grupo ativo.',
          points: groupMainRealizedSeries,
          strokeColor: isPayableGroup ? '#ea580c' : '#0369a1',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(groupMainRealizedSeries))}`,
          secondaryLabel: `Maior valor: ${formatCurrency(getMaxNumericValue(groupMainRealizedSeries))}`,
        },
      ],
    },
    {
      key: 'global-net-category',
      columns: 2,
      charts: [
        {
          key: 'global-category-expected-net',
          title: 'Saldo previsto consolidado por categoria',
          caption: 'Entradas previstas menos saidas previstas em cada categoria.',
          points: financeCategorySeriesGlobal.value.expectedNetSeries,
          strokeColor: '#1d4ed8',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(financeCategorySeriesGlobal.value.expectedNetSeries))}`,
          secondaryLabel: `Maior valor: ${formatCurrency(getMaxNumericValue(financeCategorySeriesGlobal.value.expectedNetSeries))}`,
        },
        {
          key: 'global-category-realized-net',
          title: 'Saldo realizado consolidado por categoria',
          caption: 'Entradas realizadas menos saidas realizadas em cada categoria.',
          points: financeCategorySeriesGlobal.value.realizedNetSeries,
          strokeColor: '#16a34a',
          summaryLabel: `Acumulado: ${formatCurrency(sumNumericValues(financeCategorySeriesGlobal.value.realizedNetSeries))}`,
          secondaryLabel: `Maior valor: ${formatCurrency(getMaxNumericValue(financeCategorySeriesGlobal.value.realizedNetSeries))}`,
        },
      ],
    },
    {
      key: 'global-category-volume',
      columns: 1,
      charts: [
        {
          key: 'global-category-entries',
          title: 'Volume de lancamentos por categoria',
          caption: 'Quantidade de lancamentos movimentados por categoria.',
          points: financeCategorySeriesGlobal.value.entriesCountSeries,
          strokeColor: '#9333ea',
          summaryLabel: `Total: ${formatInteger(sumCountValues(financeCategorySeriesGlobal.value.entriesCountSeries))} lancamentos`,
          secondaryLabel: `Maior volume: ${formatInteger(getMaxNumericValue(financeCategorySeriesGlobal.value.entriesCountSeries))}`,
        },
      ],
    },
  ]
})
const repositoryLabel = computed(() => {
  const repositories = Array.isArray(profile.value?.repositories) ? profile.value.repositories : []
  const owner = String(profile.value?.repositoryOwner || '').trim()

  if (repositories.length > 0) {
    return `${repositories.length} repositorios cadastrados`
  }

  if (owner !== '') {
    return `Owner padrao: ${owner}`
  }

  return 'Nenhum repositorio cadastrado'
})
const issues = computed(() => Array.isArray(issueBoard.value?.items) ? issueBoard.value.items : [])
const repositories = computed(() => Array.isArray(issueBoard.value?.repositories) ? issueBoard.value.repositories : [])
const openIssues = computed(() => issues.value.filter((issue) => issue.state !== 'CLOSED'))
const closedIssues = computed(() => issues.value.filter((issue) => issue.state === 'CLOSED'))
const taskOverviewCards = computed(() => [
  {
    label: 'Issues totais',
    value: String(issues.value.length),
    note: 'volume atual analisado',
    cardClass: 'border-slate-200 bg-white/85',
    labelClass: 'text-slate-500',
    valueClass: 'text-slate-950',
  },
  {
    label: 'Abertas',
    value: String(openIssues.value.length),
    note: 'backlog em andamento',
    cardClass: 'border-emerald-200 bg-emerald-50/80',
    labelClass: 'text-emerald-700',
    valueClass: 'text-emerald-950',
  },
  {
    label: 'Fechadas',
    value: String(closedIssues.value.length),
    note: 'tarefas concluidas',
    cardClass: 'border-slate-200 bg-slate-100/80',
    labelClass: 'text-slate-500',
    valueClass: 'text-slate-950',
  },
  {
    label: 'Repositorios',
    value: String(repositories.value.length),
    note: 'fontes em observação',
    cardClass: 'border-cyan-200 bg-cyan-50/80',
    labelClass: 'text-cyan-700',
    valueClass: 'text-cyan-950',
  },
])
const issueStatusSeries = computed(() => {
  const total = issues.value.length || 1

  return [
    {
      label: 'Abertas',
      value: openIssues.value.length,
      share: (openIssues.value.length / total) * 100,
      cardClass: 'border-emerald-200 bg-emerald-50/80',
      labelClass: 'text-emerald-700',
      valueClass: 'text-emerald-950',
      barStartColor: 'var(--app-chart-open-start)',
      barEndColor: 'var(--app-chart-open-end)',
      strokeColor: 'var(--app-chart-open-start)',
    },
    {
      label: 'Fechadas',
      value: closedIssues.value.length,
      share: (closedIssues.value.length / total) * 100,
      cardClass: 'border-slate-200 bg-slate-100/80',
      labelClass: 'text-slate-500',
      valueClass: 'text-slate-950',
      barStartColor: 'var(--app-chart-closed-start)',
      barEndColor: 'var(--app-chart-closed-end)',
      strokeColor: 'var(--app-chart-closed-start)',
    },
  ]
})
const issueStatusDonutSegments = computed(() => {
  let accumulatedLength = 0

  return issueStatusSeries.value.map((segment) => {
    const segmentLength = (segment.share / 100) * issueStatusDonutCircumference
    const segmentStyle = {
      stroke: segment.strokeColor,
      strokeDasharray: `${segmentLength} ${issueStatusDonutCircumference}`,
      strokeDashoffset: `${-accumulatedLength}`,
    }

    accumulatedLength += segmentLength

    return {
      ...segment,
      segmentStyle,
    }
  })
})
const closureWindowSeries = computed(() => withPercent([
  {
    label: 'Semana',
    value: countClosedWithinDays(closedIssues.value, 7),
  },
  {
    label: 'Mes',
    value: countClosedWithinDays(closedIssues.value, 30),
  },
  {
    label: 'Ano',
    value: countClosedWithinDays(closedIssues.value, 365),
  },
]))
const averageResolutionHours = computed(() => averageDurationHours(closedIssues.value))
const averageOpenAgeHours = computed(() => averageAgeHours(openIssues.value))
const cycleTimeCards = computed(() => [
  {
    label: 'Media para finalizar',
    value: formatDuration(averageResolutionHours.value),
    note: closedIssues.value.length > 0
      ? `${closedIssues.value.length} issues fechadas analisadas`
      : 'sem base de issues fechadas ainda',
    cardClass: 'border-cyan-200 bg-cyan-50/80',
    valueClass: 'text-cyan-950',
  },
  {
    label: 'Tempo medio sem update',
    value: formatDuration(averageOpenAgeHours.value),
    note: openIssues.value.length > 0
      ? `${openIssues.value.length} issues abertas consideradas`
      : 'sem issues abertas no momento',
    cardClass: 'border-violet-200 bg-violet-50/80',
    valueClass: 'text-violet-950',
  },
])
const updateFreshnessSeries = computed(() => withPercent([
  {
    label: 'Ate 24h',
    value: countIssuesByUpdateAge(openIssues.value, 0, 24),
  },
  {
    label: '1-7 dias',
    value: countIssuesByUpdateAge(openIssues.value, 24, 24 * 7),
  },
  {
    label: '8-30 dias',
    value: countIssuesByUpdateAge(openIssues.value, 24 * 7, 24 * 30),
  },
  {
    label: 'Mais de 30 dias',
    value: countIssuesByUpdateAge(openIssues.value, 24 * 30, Number.POSITIVE_INFINITY),
  },
]))
const mostOpenTypesSeries = computed(() => {
  const counters = new Map()

  for (const issue of openIssues.value) {
    const label = detectTaskType(issue)
    counters.set(label, (counters.get(label) || 0) + 1)
  }

  return withPercent(
    Array.from(counters.entries())
      .map(([label, value]) => ({ label, value }))
      .sort((left, right) => right.value - left.value)
      .slice(0, 6)
  )
})
const responseByTypeSeries = computed(() => {
  const sourceIssues = closedIssues.value.length > 0 ? closedIssues.value : issues.value
  const grouped = new Map()

  for (const issue of sourceIssues) {
    const label = detectTaskType(issue)
    const hours = calculateLifecycleHours(issue)

    if (hours === null) {
      continue
    }

    if (!grouped.has(label)) {
      grouped.set(label, [])
    }

    grouped.get(label).push(hours)
  }

  return withPercent(
    Array.from(grouped.entries())
      .map(([label, hours]) => ({
        label,
        hours: average(hours),
        formatted: formatDuration(average(hours)),
      }))
      .filter((item) => item.hours !== null)
      .sort((left, right) => (right.hours || 0) - (left.hours || 0))
      .slice(0, 6),
    'hours',
    { includeShare: false }
  )
})
onMounted(async () => {
  await loadDashboardContext(false)

  if (activeTab.value === 'finance') {
    await loadFinanceDashboard(false)
  }
})

watch(
  () => props.currentUser?.id,
  async (userId, previousUserId) => {
    if (!userId) {
      profile.value = null
      workspace.value = null
      issueBoard.value = null
      resetFinanceState()
      error.value = ''
      status.value = ''
      return
    }

    if (userId !== previousUserId) {
      resetFinanceState()
      await loadDashboardContext(false)

      if (activeTab.value === 'finance') {
        await loadFinanceDashboard(false)
      }
    }
  },
)

watch(
  () => activeTab.value,
  async (nextTabKey, previousTabKey) => {
    if (nextTabKey === previousTabKey || nextTabKey !== 'finance') {
      return
    }

    if (!props.currentUser?.id) {
      return
    }

    if (!financeLoaded.value || financeErrorMessage.value !== '') {
      await loadFinanceDashboard(false)
    }
  },
)

watch(
  () => activeFinancialGroupKey.value,
  async (nextGroupKey, previousGroupKey) => {
    if (nextGroupKey === previousGroupKey) {
      return
    }

    if (activeTab.value !== 'finance' || !props.currentUser?.id) {
      return
    }

    await loadFinanceDashboard(false)
  },
)

watch(error, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'error')
  error.value = ''
})

watch(status, (message) => {
  if (!message) {
    return
  }

  notifyUser(message, 'info')
  status.value = ''
})

async function loadDashboardContext(showStatus = false) {
  if (!props.currentUser?.id) {
    return
  }

  loading.value = true
  error.value = ''

  if (showStatus) {
    status.value = ''
  }

  try {
    const profileResponse = await fetchGithubProfile(props.request)
    profile.value = profileResponse.data?.profile || null

    if (!workspaceReady.value) {
      workspace.value = null
      issueBoard.value = null

      if (showStatus) {
        status.value = 'Perfil GitHub carregado. Finalize a configuração no Perfil para liberar as analises.'
      }

      return
    }

    await loadWorkspaceContext()
    await loadIssueAnalytics(showStatus)
  } catch (requestError) {
    workspace.value = null
    issueBoard.value = null
    error.value = extractHttpMessage(requestError, 'Nao foi possivel carregar o dashboard analitico.')
  } finally {
    loading.value = false
  }
}

async function loadWorkspaceContext() {
  const { data } = await fetchGithubWorkspace(props.request)
  workspace.value = data || null
}

async function loadIssueAnalytics(showStatus = false) {
  const cacheResponse = await fetchGithubIssuesCache(props.request, { scope: 'all' })

  issueBoard.value = cacheResponse.data || null

  const hasItems = Array.isArray(cacheResponse.data?.items) && cacheResponse.data.items.length > 0
  const needsRefresh = Boolean(cacheResponse.data?.cache?.needsRefresh)

  if (!hasItems) {
    await syncIssueAnalytics(showStatus)
    return
  }

  if (showStatus) {
    status.value = 'Dashboard carregado do banco local.'
  }

  if (needsRefresh) {
    void syncIssueAnalytics(false)
  }
}

async function syncIssueAnalytics(showStatus = false) {
  syncing.value = true
  error.value = ''

  try {
    const response = await syncGithubIssues(props.request, { scope: 'all' })

    issueBoard.value = response.data || null

    if (showStatus || issues.value.length === 0) {
      status.value = 'Analises atualizadas com os dados mais recentes do GitHub.'
    }
  } catch (requestError) {
    if (issues.value.length > 0) {
      status.value = 'Mantendo as analises do banco local enquanto a sincronização do GitHub nao responde.'
      return
    }

    error.value = extractHttpMessage(requestError, 'Nao foi possivel sincronizar as analises do GitHub.')
  } finally {
    syncing.value = false
  }
}

function resetFinanceState() {
  financeSummaryGlobal.value = null
  financeSummaryByGroup.value = null
  financeCashflowGlobal.value = []
  financeCashflowByGroup.value = []
  financeCategoriesGlobal.value = []
  financeCategoriesByGroup.value = []
  financeEntries.value = []
  financeEntriesMeta.value = { page: 1, itemsPerPage: 10, total: 0 }
  financeBankAccounts.value = []
  financeRecurringRules.value = []
  financeInstallmentPlans.value = []
  financeDebtPlans.value = []
  financeInvestmentPlans.value = []
  financeLoading.value = false
  financeLoaded.value = false
  financeErrorMessage.value = ''
}

async function loadFinanceDashboard(showStatus = false) {
  if (!props.currentUser?.id) {
    return
  }

  financeLoading.value = true
  financeErrorMessage.value = ''

  try {
    const direction = activeFinancialDirection.value

    const [
      summaryGlobalResponse,
      summaryByGroupResponse,
      cashflowGlobalResponse,
      cashflowByGroupResponse,
      categoriesGlobalResponse,
      categoriesByGroupResponse,
      entriesResponse,
      bankAccountsResponse,
      recurringRulesResponse,
      installmentPlansResponse,
      debtPlansResponse,
      investmentPlansResponse,
    ] = await Promise.all([
      fetchFinanceDashboardSummary(props.request),
      fetchFinanceDashboardSummary(props.request, { direction }),
      fetchFinanceDashboardCashflow(props.request),
      fetchFinanceDashboardCashflow(props.request, { direction }),
      fetchFinanceDashboardCategories(props.request, { limit: 10 }),
      fetchFinanceDashboardCategories(props.request, { direction, limit: 10 }),
      fetchFinanceEntries(props.request, { direction }, { page: 1, itemsPerPage: 10, sort: 'dueDate:asc' }),
      fetchFinanceBankAccounts(props.request),
      fetchFinanceRecurringRules(props.request),
      fetchFinanceInstallmentPlans(props.request),
      fetchFinanceDebtPlans(props.request),
      fetchFinanceInvestmentPlans(props.request),
    ])

    financeSummaryGlobal.value = summaryGlobalResponse.data?.item || null
    financeSummaryByGroup.value = summaryByGroupResponse.data?.item || null
    financeCashflowGlobal.value = Array.isArray(cashflowGlobalResponse.data?.items) ? cashflowGlobalResponse.data.items : []
    financeCashflowByGroup.value = Array.isArray(cashflowByGroupResponse.data?.items) ? cashflowByGroupResponse.data.items : []
    financeCategoriesGlobal.value = Array.isArray(categoriesGlobalResponse.data?.items) ? categoriesGlobalResponse.data.items : []
    financeCategoriesByGroup.value = Array.isArray(categoriesByGroupResponse.data?.items) ? categoriesByGroupResponse.data.items : []
    financeEntries.value = Array.isArray(entriesResponse.data?.items) ? entriesResponse.data.items : []
    financeEntriesMeta.value = entriesResponse.data?.meta || { page: 1, itemsPerPage: 10, total: 0 }
    financeBankAccounts.value = Array.isArray(bankAccountsResponse.data?.items) ? bankAccountsResponse.data.items : []
    financeRecurringRules.value = Array.isArray(recurringRulesResponse.data?.items) ? recurringRulesResponse.data.items : []
    financeInstallmentPlans.value = Array.isArray(installmentPlansResponse.data?.items) ? installmentPlansResponse.data.items : []
    financeDebtPlans.value = Array.isArray(debtPlansResponse.data?.items) ? debtPlansResponse.data.items : []
    financeInvestmentPlans.value = Array.isArray(investmentPlansResponse.data?.items) ? investmentPlansResponse.data.items : []
    financeLoaded.value = true

    if (showStatus) {
      status.value = `Dashboard financeiro atualizado para ${activeFinancialGroup.value.label.toLowerCase()}.`
    }
  } catch (requestError) {
    financeErrorMessage.value = extractHttpMessage(requestError, 'Nao foi possivel carregar o dashboard financeiro.')

    if (showStatus) {
      notifyUser(financeErrorMessage.value, 'error')
    }
  } finally {
    financeLoading.value = false
  }
}

function refreshActiveDashboardTab() {
  if (activeTab.value === 'tasks') {
    void loadDashboardContext(true)
    return
  }

  void loadFinanceDashboard(true)
}

function detectTaskType(issue) {
  const match = String(issue?.title || '').match(/^\[([^\]]+)\]/)
  const rawKey = match?.[1]?.trim().toLowerCase()

  const catalog = {
    support: 'Suporte',
    incident: 'Incidente',
    service: 'Servico',
    feat: 'Melhoria',
    bug: 'Bug',
    chore: 'Tecnico',
  }

  return catalog[rawKey] || 'Outros'
}

function countClosedWithinDays(issueList, days) {
  const limitInHours = days * 24
  let total = 0

  for (const issue of issueList) {
    const updatedAt = parseTimestamp(issue?.updatedAt)
    if (!updatedAt) {
      continue
    }

    const hours = diffHours(updatedAt, new Date())
    if (hours !== null && hours <= limitInHours) {
      total += 1
    }
  }

  return total
}

function countIssuesByUpdateAge(issueList, minHours, maxHours) {
  let total = 0

  for (const issue of issueList) {
    const updatedAt = parseTimestamp(issue?.updatedAt)
    if (!updatedAt) {
      continue
    }

    const hours = diffHours(updatedAt, new Date())
    if (hours === null) {
      continue
    }

    if (hours >= minHours && hours < maxHours) {
      total += 1
    }
  }

  return total
}

function averageDurationHours(issueList) {
  const values = issueList
    .map((issue) => calculateLifecycleHours(issue))
    .filter((value) => value !== null)

  return average(values)
}

function averageAgeHours(issueList) {
  const values = issueList
    .map((issue) => {
      const updatedAt = parseTimestamp(issue?.updatedAt)
      return updatedAt ? diffHours(updatedAt, new Date()) : null
    })
    .filter((value) => value !== null)

  return average(values)
}

function calculateLifecycleHours(issue) {
  const createdAt = parseTimestamp(issue?.createdAt)
  const updatedAt = parseTimestamp(issue?.updatedAt)

  if (!createdAt || !updatedAt) {
    return null
  }

  return diffHours(createdAt, updatedAt)
}

function parseTimestamp(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return null
  }

  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime()) ? null : parsed
}

function diffHours(startDate, endDate) {
  const startTime = startDate instanceof Date ? startDate.getTime() : Number.NaN
  const endTime = endDate instanceof Date ? endDate.getTime() : Number.NaN

  if (!Number.isFinite(startTime) || !Number.isFinite(endTime) || endTime < startTime) {
    return null
  }

  return (endTime - startTime) / 3600000
}

function average(values) {
  if (!Array.isArray(values) || values.length === 0) {
    return null
  }

  return values.reduce((total, value) => total + value, 0) / values.length
}

function withPercent(items, valueKey = 'value', options = {}) {
  const includeShare = options.includeShare !== false
  const maxValue = items.reduce((highest, item) => Math.max(highest, Number(item?.[valueKey] || 0)), 0)
  const totalValue = items.reduce((total, item) => total + Number(item?.[valueKey] || 0), 0)

  return items.map((item) => {
    const numericValue = Number(item?.[valueKey] || 0)

    return {
      ...item,
      percentage: maxValue > 0 ? (numericValue / maxValue) * 100 : 0,
      share: includeShare && totalValue > 0 ? (numericValue / totalValue) * 100 : null,
    }
  })
}

function buildBarStyle(percentage) {
  if (!Number.isFinite(percentage) || percentage <= 0) {
    return {
      width: '0%',
      transition: 'width 480ms cubic-bezier(0.4, 0, 0.2, 1)',
    }
  }

  return {
    width: `${Math.max(percentage, 8)}%`,
    transition: 'width 480ms cubic-bezier(0.4, 0, 0.2, 1)',
  }
}

function buildBarFillStyle(percentage, startColor, endColor) {
  return {
    ...buildBarStyle(percentage),
    backgroundColor: startColor,
    backgroundImage: `linear-gradient(90deg, ${startColor} 0%, ${endColor} 100%)`,
    boxShadow: '0 0 0 1px color-mix(in srgb, var(--ink) 8%, transparent) inset',
  }
}

function buildBarTrackStyle() {
  return {
    backgroundColor: 'var(--app-chart-track-bg)',
  }
}

function formatPercentage(value, decimalPlaces = 1) {
  if (!Number.isFinite(value) || value === null) {
    return '0%'
  }

  return `${Number(value).toFixed(decimalPlaces)}%`
}

function formatDuration(hours) {
  if (!Number.isFinite(hours) || hours === null) {
    return 'sem base'
  }

  if (hours < 1) {
    return `${Math.max(1, Math.round(hours * 60))} min`
  }

  if (hours < 24) {
    return `${hours >= 10 ? Math.round(hours) : hours.toFixed(1)} h`
  }

  const days = hours / 24
  if (days < 30) {
    return `${days >= 10 ? Math.round(days) : days.toFixed(1)} d`
  }

  const months = days / 30
  return `${months.toFixed(1)} mes`
}

function formatCurrency(rawValue) {
  const numericValue = Number(rawValue || 0)

  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(numericValue)
}

function formatInteger(rawValue) {
  const numericValue = Number(rawValue || 0)

  return new Intl.NumberFormat('pt-BR', {
    maximumFractionDigits: 0,
  }).format(numericValue)
}

function roundMonetaryValue(rawValue) {
  return Math.round(Number(rawValue || 0) * 100) / 100
}

function sumNumericValues(rawValues = []) {
  if (!Array.isArray(rawValues)) {
    return 0
  }

  return roundMonetaryValue(rawValues.reduce((accumulatedValue, rawValue) => {
    return accumulatedValue + Number(rawValue || 0)
  }, 0))
}

function averageNumericValue(rawValues = []) {
  if (!Array.isArray(rawValues) || rawValues.length === 0) {
    return 0
  }

  return roundMonetaryValue(sumNumericValues(rawValues) / rawValues.length)
}

function getLastNumericValue(rawValues = []) {
  if (!Array.isArray(rawValues) || rawValues.length === 0) {
    return 0
  }

  return Number(rawValues[rawValues.length - 1] || 0)
}

function getMaxNumericValue(rawValues = []) {
  if (!Array.isArray(rawValues) || rawValues.length === 0) {
    return 0
  }

  return Math.max(...rawValues.map((rawValue) => Number(rawValue || 0)))
}

function getMaxAbsoluteNumericValue(rawValues = []) {
  if (!Array.isArray(rawValues) || rawValues.length === 0) {
    return 0
  }

  return Math.max(...rawValues.map((rawValue) => Math.abs(Number(rawValue || 0))))
}

function sumCountValues(rawValues = []) {
  if (!Array.isArray(rawValues)) {
    return 0
  }

  return rawValues.reduce((accumulatedValue, rawValue) => {
    return accumulatedValue + Math.max(0, Math.round(Number(rawValue || 0)))
  }, 0)
}

function parseDateOnly(rawDate) {
  if (typeof rawDate !== 'string' || rawDate.trim() === '') {
    return null
  }

  const [yearToken, monthToken, dayToken] = rawDate.split('-')
  const year = Number(yearToken)
  const month = Number(monthToken)
  const day = Number(dayToken)

  if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) {
    return null
  }

  const parsedDate = new Date(year, month - 1, day)
  return Number.isNaN(parsedDate.getTime()) ? null : parsedDate
}

function startOfToday() {
  const currentDate = new Date()
  return new Date(currentDate.getFullYear(), currentDate.getMonth(), currentDate.getDate())
}

function calculateDaysUntil(rawDate) {
  const parsedDate = parseDateOnly(rawDate)
  if (!parsedDate) {
    return null
  }

  const differenceInMilliseconds = parsedDate.getTime() - startOfToday().getTime()
  return Math.floor(differenceInMilliseconds / 86400000)
}

function formatDueStatus(daysUntilDue) {
  if (daysUntilDue === null) {
    return 'sem vencimento'
  }

  if (daysUntilDue < 0) {
    return `${Math.abs(daysUntilDue)}d atrasado`
  }

  if (daysUntilDue === 0) {
    return 'vence hoje'
  }

  return `vence em ${daysUntilDue}d`
}

function isFinanceEntryOverdue(financeEntry) {
  if (String(financeEntry?.status || '').toUpperCase() === 'OVERDUE') {
    return true
  }

  const daysUntilDue = calculateDaysUntil(financeEntry?.dueDate)
  return daysUntilDue !== null && daysUntilDue < 0 && Number(financeEntry?.remainingAmountBrl || 0) > 0
}

function getFinanceEntryStatusLabel(statusCode) {
  const statusCatalog = {
    PENDING: 'Pendente',
    PARTIALLY_SETTLED: 'Parcial',
    PAID: 'Pago',
    OVERDUE: 'Atrasado',
    CANCELED: 'Cancelado',
  }

  return statusCatalog[statusCode] || statusCode || 'Sem status'
}

function getFinanceStatusToneClasses(statusCode) {
  if (statusCode === 'OVERDUE') {
    return {
      wrapper: 'border-rose-200 bg-rose-50/80',
      badge: 'border-rose-200 bg-white text-rose-700',
      barStartColor: '#fb7185',
      barEndColor: '#e11d48',
    }
  }

  if (statusCode === 'PAID') {
    return {
      wrapper: 'border-emerald-200 bg-emerald-50/80',
      badge: 'border-emerald-200 bg-white text-emerald-700',
      barStartColor: '#34d399',
      barEndColor: '#059669',
    }
  }

  if (statusCode === 'PARTIALLY_SETTLED') {
    return {
      wrapper: 'border-amber-200 bg-amber-50/80',
      badge: 'border-amber-200 bg-white text-amber-700',
      barStartColor: '#fbbf24',
      barEndColor: '#d97706',
    }
  }

  if (statusCode === 'CANCELED') {
    return {
      wrapper: 'border-slate-300 bg-slate-100/80',
      badge: 'border-slate-300 bg-white text-slate-700',
      barStartColor: '#94a3b8',
      barEndColor: '#64748b',
    }
  }

  return {
    wrapper: 'border-sky-200 bg-sky-50/80',
    badge: 'border-sky-200 bg-white text-sky-700',
    barStartColor: '#38bdf8',
    barEndColor: '#0284c7',
  }
}

function normalizeCashflowSeries(rawCashflowItems = []) {
  const cashflowItems = Array.isArray(rawCashflowItems) ? rawCashflowItems : []

  return {
    monthLabels: cashflowItems.map((cashflowItem) => String(cashflowItem.competenceMonth || '-')),
    expectedIncomeSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.expectedIncomeBrl || 0)),
    expectedExpenseSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.expectedExpenseBrl || 0)),
    expectedNetSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.expectedNetBrl || 0)),
    realizedIncomeSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.realizedIncomeBrl || 0)),
    realizedExpenseSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.realizedExpenseBrl || 0)),
    realizedNetSeries: cashflowItems.map((cashflowItem) => Number(cashflowItem.realizedNetBrl || 0)),
  }
}

function normalizeCategorySeries(rawCategoryItems = []) {
  const categoryItems = Array.isArray(rawCategoryItems) ? rawCategoryItems : []

  return {
    categoryLabels: categoryItems.map((categoryItem) => String(categoryItem.categoryName || 'Sem categoria')),
    entriesCountSeries: categoryItems.map((categoryItem) => Number(categoryItem.entriesCount || 0)),
    expectedIncomeSeries: categoryItems.map((categoryItem) => Number(categoryItem.expectedIncomeBrl || 0)),
    expectedExpenseSeries: categoryItems.map((categoryItem) => Number(categoryItem.expectedExpenseBrl || 0)),
    expectedNetSeries: categoryItems.map((categoryItem) => Number(categoryItem.expectedNetBrl || 0)),
    realizedIncomeSeries: categoryItems.map((categoryItem) => Number(categoryItem.realizedIncomeBrl || 0)),
    realizedExpenseSeries: categoryItems.map((categoryItem) => Number(categoryItem.realizedExpenseBrl || 0)),
    realizedNetSeries: categoryItems.map((categoryItem) => Number(categoryItem.realizedNetBrl || 0)),
  }
}

function buildCompactLegend(rawLabels = [], prefix = 'Itens') {
  if (!Array.isArray(rawLabels) || rawLabels.length === 0) {
    return ''
  }

  const visibleLabels = rawLabels.slice(0, 8)
  const hiddenLabelsTotal = Math.max(0, rawLabels.length - visibleLabels.length)

  if (hiddenLabelsTotal > 0) {
    return `${prefix}: ${visibleLabels.join(' • ')} (+${hiddenLabelsTotal})`
  }

  return `${prefix}: ${visibleLabels.join(' • ')}`
}

function isDashboardContainerCollapsed(containerKey) {
  return Boolean(collapsedDashboardContainers.value[containerKey])
}

function toggleDashboardContainer(containerKey) {
  if (!dashboardChartContainerKeys.includes(containerKey)) {
    return
  }

  collapsedDashboardContainers.value[containerKey] = !collapsedDashboardContainers.value[containerKey]
}

function toggleAllDashboardContainers() {
  const shouldCollapseAll = !allDashboardContainersCollapsed.value

  for (const containerKey of dashboardChartContainerKeys) {
    collapsedDashboardContainers.value[containerKey] = shouldCollapseAll
  }
}

</script>

<template>
  <section class="grid gap-5">
    <article class="themed-hero-surface rounded-[28px] border border-white/60 p-5 app-depth-soft backdrop-blur">
      <div class="min-w-0">
        <h2 class="text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">Dashboard</h2>
      </div>
    </article>

    <article class="grid gap-6 rounded-[28px] border border-white/60 bg-white/80 p-5 md:p-6 app-depth-soft backdrop-blur">
      <div class="grid gap-3 md:grid-cols-[1fr_auto] md:items-center">
        <div class="min-w-0">
          <div
            class="inline-flex w-full max-w-full flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50/80 p-1.5 md:w-auto">
            <button type="button"
              class="app-btn min-w-[120px]"
              :class="activeTab === 'tasks' ? 'app-btn-tab-active' : 'app-btn-secondary'" @click="activeTab = 'tasks'">
              Tarefas
            </button>

            <button type="button"
              class="app-btn min-w-[120px]"
              :class="activeTab === 'finance' ? 'app-btn-tab-active' : 'app-btn-secondary'" @click="activeTab = 'finance'">
              Financeiro
            </button>
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-start gap-2 md:justify-end">
          <button
            v-if="activeTab === 'tasks' && issues.length > 0"
            type="button"
            class="app-btn app-btn-secondary"
            @click="toggleAllDashboardContainers"
          >
            {{ dashboardContainersToggleLabel }}
          </button>

          <button type="button"
            class="app-btn app-btn-primary app-btn-icon shrink-0"
            :disabled="refreshButtonDisabled" :title="refreshButtonTitle" @click="refreshActiveDashboardTab">
            <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-2.64-6.36" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 3v6h-6" />
            </svg>
          </button>
        </div>
      </div>

      <article
        v-if="activeTab === 'tasks' && loading"
        class="grid min-h-[220px] place-items-center rounded-[24px] border border-slate-200 bg-slate-50/80 p-5 text-sm text-slate-500"
      >
        Carregando dashboard analitico...
      </article>

      <article
        v-else-if="activeTab === 'tasks' && !workspaceReady"
        class="grid gap-4 rounded-[24px] border border-slate-200 bg-slate-50/80 p-5"
      >
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Perfil necessario</p>
            <h3 class="mt-1 text-2xl font-semibold text-slate-950">Configure o GitHub antes de liberar as analises</h3>
            <p class="mt-3 text-sm leading-7 text-slate-600">
            Salve o token do GitHub e cadastre ao menos um repositorio para liberar as analises do dashboard.
            </p>
          </div>

        <div class="grid gap-3 sm:grid-cols-3">
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repositorios</span>
            <strong class="mt-2 block break-all text-sm font-semibold text-slate-950">{{ repositoryLabel }}</strong>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Token</span>
            <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ profile?.tokenConfigured ? 'Salvo' : 'Ausente' }}</strong>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Workspace</span>
            <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ profile?.workspaceReady ? 'Pronto' : 'Pendente' }}</strong>
          </div>
        </div>
      </article>

      <div v-else-if="activeTab === 'tasks'" class="grid gap-5">
        <article
          v-if="issues.length === 0 && !syncing"
          class="grid gap-4 rounded-[24px] border border-dashed border-slate-300 bg-slate-50/80 p-5"
        >
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Sem base analitica</p>
            <h3 class="mt-1 text-2xl font-semibold text-slate-950">Ainda nao existem issues suficientes para montar os graficos</h3>
            <p class="mt-3 text-sm leading-7 text-slate-600">
              O dashboard usa a base cacheada das issues. Se for sua primeira entrada, atualize para preencher as analises.
            </p>
          </div>
        </article>

        <template v-else>
        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-4">
          <article
            v-for="card in taskOverviewCards"
            :key="card.label"
            class="rounded-[24px] border p-4 app-depth-soft"
            :class="card.cardClass"
          >
            <span class="text-xs font-semibold uppercase tracking-[0.18em]" :class="card.labelClass">{{ card.label }}</span>
            <strong class="mt-2 block break-words text-2xl font-semibold" :class="card.valueClass">{{ card.value }}</strong>
            <p class="mt-2 text-sm text-slate-500">{{ card.note }}</p>
          </article>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1.05fr),minmax(0,0.95fr)]">
          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 app-depth-soft backdrop-blur">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Status atual</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Abertas x fechadas</h3>
              </div>
              <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                  {{ issues.length }} issues analisadas
                </span>
                <button
                  type="button"
                  class="app-btn app-btn-secondary app-btn-sm"
                  @click="toggleDashboardContainer('issueStatus')"
                >
                  {{ isDashboardContainerCollapsed('issueStatus') ? 'Expandir' : 'Recolher' }}
                </button>
              </div>
            </div>

            <div v-if="!isDashboardContainerCollapsed('issueStatus')" class="grid gap-5 lg:grid-cols-[220px,minmax(0,1fr)] lg:items-center">
              <div class="relative mx-auto h-44 w-44">
                <svg
                  class="h-full w-full -rotate-90"
                  viewBox="0 0 180 180"
                  role="img"
                  aria-label="Distribuicao de issues abertas e fechadas"
                >
                  <circle
                    cx="90"
                    cy="90"
                    :r="issueStatusDonutRadius"
                    class="fill-none stroke-slate-200/90"
                    stroke-width="18"
                  />
                  <circle
                    v-for="segment in issueStatusDonutSegments"
                    :key="`status-donut-${segment.label}`"
                    cx="90"
                    cy="90"
                    :r="issueStatusDonutRadius"
                    class="fill-none"
                    stroke-linecap="round"
                    stroke-width="18"
                    :style="segment.segmentStyle"
                  />
                </svg>

                <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 text-center">
                  <strong class="block text-3xl font-semibold leading-none text-slate-950">{{ issues.length }}</strong>
                  <span class="mt-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Issues</span>
                </div>
              </div>

              <div class="grid gap-3 sm:grid-cols-2">
                <div
                  v-for="segment in issueStatusSeries"
                  :key="segment.label"
                  class="rounded-2xl border p-4"
                  :class="segment.cardClass"
                >
                  <div class="flex items-center justify-between gap-3">
                    <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em]" :class="segment.labelClass">
                      <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: segment.strokeColor }" />
                      {{ segment.label }}
                    </span>
                    <span class="rounded-full border border-white/80 bg-white/80 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                      {{ formatPercentage(segment.share) }}
                    </span>
                  </div>
                  <strong class="mt-2 block text-2xl font-semibold" :class="segment.valueClass">{{ segment.value }}</strong>
                  <p class="mt-2 text-sm text-slate-500">Participacao no universo atual</p>
                  <div class="mt-3 h-2.5 overflow-hidden rounded-full" :style="buildBarTrackStyle()">
                    <span
                      class="block h-full rounded-full"
                      :style="buildBarFillStyle(segment.share, segment.barStartColor, segment.barEndColor)"
                    />
                  </div>
                </div>
              </div>
            </div>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 app-depth-soft backdrop-blur">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Fechamento</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Finalizadas por periodo</h3>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                  Quantas issues fechadas tiveram ultimo movimento de encerramento na semana, no mes e no ano.
                </p>
              </div>
              <button
                type="button"
                class="app-btn app-btn-secondary app-btn-sm"
                @click="toggleDashboardContainer('closureWindow')"
              >
                {{ isDashboardContainerCollapsed('closureWindow') ? 'Expandir' : 'Recolher' }}
              </button>
            </div>

            <div v-if="!isDashboardContainerCollapsed('closureWindow')" class="grid gap-3">
              <div
                v-for="item in closureWindowSeries"
                :key="item.label"
                class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div class="flex items-center justify-between gap-3 text-sm">
                  <strong class="font-semibold text-slate-900">{{ item.label }}</strong>
                  <div class="flex items-center gap-2">
                    <span class="font-semibold text-slate-700">{{ item.value }}</span>
                    <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                      {{ formatPercentage(item.share) }}
                    </span>
                  </div>
                </div>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full" :style="buildBarTrackStyle()">
                  <span
                    class="block h-full rounded-full"
                    :style="buildBarFillStyle(item.percentage, 'var(--app-chart-closure-start)', 'var(--app-chart-closure-end)')"
                  />
                </div>
              </div>
            </div>
          </article>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,0.8fr),minmax(0,1.2fr)]">
          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 app-depth-soft backdrop-blur">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Tempo medio</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Ciclo das tarefas</h3>
              </div>
              <button
                type="button"
                class="app-btn app-btn-secondary app-btn-sm"
                @click="toggleDashboardContainer('cycleTime')"
              >
                {{ isDashboardContainerCollapsed('cycleTime') ? 'Expandir' : 'Recolher' }}
              </button>
            </div>

            <div v-if="!isDashboardContainerCollapsed('cycleTime')" class="grid gap-3">
              <article
                v-for="card in cycleTimeCards"
                :key="card.label"
                class="rounded-2xl border p-4"
                :class="card.cardClass"
              >
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ card.label }}</span>
                <strong class="mt-2 block text-3xl font-semibold" :class="card.valueClass">{{ card.value }}</strong>
                <p class="mt-2 text-sm text-slate-500">{{ card.note }}</p>
              </article>
            </div>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 app-depth-soft backdrop-blur">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Atualização</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Tempo sem mexer nas tarefas abertas</h3>
              </div>
              <button
                type="button"
                class="app-btn app-btn-secondary app-btn-sm"
                @click="toggleDashboardContainer('updateFreshness')"
              >
                {{ isDashboardContainerCollapsed('updateFreshness') ? 'Expandir' : 'Recolher' }}
              </button>
            </div>

            <div v-if="!isDashboardContainerCollapsed('updateFreshness')" class="grid gap-3">
              <div
                v-for="item in updateFreshnessSeries"
                :key="item.label"
                class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div class="flex items-center justify-between gap-3 text-sm">
                  <strong class="font-semibold text-slate-900">{{ item.label }}</strong>
                  <div class="flex items-center gap-2">
                    <span class="font-semibold text-slate-700">{{ item.value }}</span>
                    <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                      {{ formatPercentage(item.share) }}
                    </span>
                  </div>
                </div>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full" :style="buildBarTrackStyle()">
                  <span
                    class="block h-full rounded-full"
                    :style="buildBarFillStyle(item.percentage, 'var(--app-chart-freshness-start)', 'var(--app-chart-freshness-end)')"
                  />
                </div>
              </div>
            </div>
          </article>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,0.82fr),minmax(0,1.18fr)]">
          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 app-depth-soft backdrop-blur">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Tipos abertos</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Tipos de tarefa mais abertas</h3>
              </div>
              <button
                type="button"
                class="app-btn app-btn-secondary app-btn-sm"
                @click="toggleDashboardContainer('mostOpenTypes')"
              >
                {{ isDashboardContainerCollapsed('mostOpenTypes') ? 'Expandir' : 'Recolher' }}
              </button>
            </div>

            <div v-if="!isDashboardContainerCollapsed('mostOpenTypes') && mostOpenTypesSeries.length" class="grid gap-3">
              <div
                v-for="item in mostOpenTypesSeries"
                :key="item.label"
                class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div class="flex items-center justify-between gap-3 text-sm">
                  <strong class="font-semibold text-slate-900">{{ item.label }}</strong>
                  <div class="flex items-center gap-2">
                    <span class="font-semibold text-slate-700">{{ item.value }}</span>
                    <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                      {{ formatPercentage(item.share) }}
                    </span>
                  </div>
                </div>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full" :style="buildBarTrackStyle()">
                  <span
                    class="block h-full rounded-full"
                    :style="buildBarFillStyle(item.percentage, 'var(--app-chart-types-start)', 'var(--app-chart-types-end)')"
                  />
                </div>
              </div>
            </div>

            <p
              v-else-if="!isDashboardContainerCollapsed('mostOpenTypes')"
              class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
            >
              Nenhuma issue aberta o suficiente para montar este ranking agora.
            </p>
          </article>

          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 app-depth-soft backdrop-blur">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Resposta por tipo</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-950">Tempo medio por categoria</h3>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                  Leitura comparativa do tempo medio entre criação e ultimo movimento para cada tipo de tarefa.
                </p>
              </div>
              <button
                type="button"
                class="app-btn app-btn-secondary app-btn-sm"
                @click="toggleDashboardContainer('responseByType')"
              >
                {{ isDashboardContainerCollapsed('responseByType') ? 'Expandir' : 'Recolher' }}
              </button>
            </div>

            <div v-if="!isDashboardContainerCollapsed('responseByType') && responseByTypeSeries.length" class="grid gap-3">
              <div
                v-for="item in responseByTypeSeries"
                :key="item.label"
                class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div class="flex items-center justify-between gap-3">
                  <strong class="text-sm font-semibold text-slate-900">{{ item.label }}</strong>
                  <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-slate-700">{{ item.formatted }}</span>
                    <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                      Indice {{ formatPercentage(item.percentage, 0) }}
                    </span>
                  </div>
                </div>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full" :style="buildBarTrackStyle()">
                  <span
                    class="block h-full rounded-full"
                    :style="buildBarFillStyle(item.percentage, 'var(--app-chart-response-start)', 'var(--app-chart-response-end)')"
                  />
                </div>
              </div>
            </div>

            <p
              v-else-if="!isDashboardContainerCollapsed('responseByType')"
              class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
            >
              Ainda nao existe base temporal suficiente para comparar os tipos de tarefa.
            </p>
          </article>
        </div>

        </template>
      </div>

      <article
        v-else
        class="themed-soft-surface grid gap-5 rounded-[24px] border border-slate-200 p-6"
      >
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr),auto] lg:items-start">
          <div>
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Financeiro</p>
            <h3 class="mt-1 text-3xl font-semibold text-slate-950">Grupo Financeiro</h3>
            <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">
              Selecione o grupo financeiro para organizar o fluxo entre saidas e entradas sem misturar regras da operação de tarefas.
            </p>
          </div>

          <div
            class="inline-flex w-full max-w-full flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white/80 p-1.5 lg:w-auto"
          >
            <button
              v-for="financialGroupOption in financialGroupOptions"
              :key="financialGroupOption.key"
              type="button"
              class="app-btn min-w-[170px]"
              :class="activeFinancialGroupKey === financialGroupOption.key ? 'app-btn-tab-active' : 'app-btn-secondary'"
              @click="activeFinancialGroupKey = financialGroupOption.key"
            >
              {{ financialGroupOption.label }}
            </button>
          </div>
        </div>

        <article class="rounded-[22px] border border-white/80 bg-white/80 p-5 app-depth-soft">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-500">Grupo ativo</p>
              <h4 class="mt-1 text-2xl font-semibold text-slate-950">{{ financialGroupTitle }}</h4>
              <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">
                {{ financialGroupDescription }}
              </p>
            </div>

            <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
              {{ activeFinancialGroup.label }}
            </span>
          </div>
        </article>

        <article
          v-if="financeLoading && !financeLoaded"
          class="grid min-h-[200px] place-items-center rounded-[22px] border border-slate-200 bg-slate-50/80 p-5 text-sm text-slate-500"
        >
          Carregando dashboard financeiro...
        </article>

        <article
          v-else-if="financeErrorMessage && !financeLoaded"
          class="grid gap-3 rounded-[22px] border border-rose-200 bg-rose-50/70 p-5"
        >
          <p class="text-[11px] font-black uppercase tracking-[0.22em] text-rose-700">Falha no dashboard</p>
          <p class="text-sm leading-7 text-rose-700">{{ financeErrorMessage }}</p>
          <button
            type="button"
            class="app-btn app-btn-secondary max-w-[220px]"
            @click="loadFinanceDashboard(true)"
          >
            Tentar novamente
          </button>
        </article>

        <template v-else>
          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <RemoteFinanceKpiCard
              v-for="financialSummaryCard in financialSummaryCards"
              :key="financialSummaryCard.key"
              :label="financialSummaryCard.label"
              :value="financialSummaryCard.value"
              :caption="financialSummaryCard.caption"
              :tone="financialSummaryCard.tone"
            />
          </div>

          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <article
              v-for="financialOperationalCard in financialOperationalCards"
              :key="financialOperationalCard.key"
              class="rounded-2xl border border-slate-200 bg-white/85 p-4"
            >
              <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ financialOperationalCard.label }}</span>
              <strong class="mt-2 block text-xl font-semibold text-slate-950">{{ financialOperationalCard.value }}</strong>
              <p class="mt-2 text-sm text-slate-500">{{ financialOperationalCard.note }}</p>
            </article>
          </div>

          <article class="grid gap-4 rounded-[22px] border border-white/80 bg-white/80 p-5 app-depth-soft">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Fluxo mensal</p>
                <h4 class="mt-1 text-2xl font-semibold text-slate-950">Tendencias do caixa</h4>
                <p class="mt-2 text-sm leading-7 text-slate-600">
                  Graficos de previsto x realizado com comparativo consolidado e gap do grupo ativo.
                </p>
              </div>
              <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                {{ formatInteger(financeEntriesMeta.total) }} lancamentos analisados
              </span>
            </div>

            <div v-if="financeCashflowChartRows.length" class="grid gap-3">
              <div
                v-for="financeChartRow in financeCashflowChartRows"
                :key="financeChartRow.key"
                class="grid gap-3"
                :class="financeChartRow.columns === 2 ? 'xl:grid-cols-2' : 'grid-cols-1'"
              >
                <article
                  v-for="financeChartCard in financeChartRow.charts"
                  :key="financeChartCard.key"
                  class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"
                >
                  <div class="flex flex-wrap items-start justify-between gap-2">
                    <h5 class="text-sm font-semibold text-slate-950">{{ financeChartCard.title }}</h5>
                    <span class="text-xs font-semibold text-slate-600">{{ financeChartCard.summaryLabel }}</span>
                  </div>

                  <RemoteFinanceTrendMiniChart
                    class="mt-3"
                    :points="financeChartCard.points"
                    :stroke-color="financeChartCard.strokeColor"
                  />

                  <p class="mt-3 text-sm text-slate-600">{{ financeChartCard.caption }}</p>
                  <p class="mt-2 text-xs font-semibold text-slate-500">{{ financeChartCard.secondaryLabel }}</p>
                </article>
              </div>

              <p v-if="financeCashflowLegend" class="text-xs text-slate-500">{{ financeCashflowLegend }}</p>
            </div>

            <RemoteFinanceEmptyState
              v-else
              title="Sem historico de fluxo"
              description="Cadastre lancamentos para liberar os graficos mensais de tendencia."
            />
          </article>

          <article class="grid gap-4 rounded-[22px] border border-white/80 bg-white/80 p-5 app-depth-soft">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Categorias</p>
                <h4 class="mt-1 text-2xl font-semibold text-slate-950">Tendencias por categoria</h4>
                <p class="mt-2 text-sm leading-7 text-slate-600">
                  Leitura consolidada por categoria para identificar onde estao os maiores volumes financeiros.
                </p>
              </div>
            </div>

            <div v-if="financeCategoryChartRows.length" class="grid gap-3">
              <div
                v-for="financeCategoryChartRow in financeCategoryChartRows"
                :key="financeCategoryChartRow.key"
                class="grid gap-3"
                :class="financeCategoryChartRow.columns === 2 ? 'xl:grid-cols-2' : 'grid-cols-1'"
              >
                <article
                  v-for="financeCategoryChartCard in financeCategoryChartRow.charts"
                  :key="financeCategoryChartCard.key"
                  class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"
                >
                  <div class="flex flex-wrap items-start justify-between gap-2">
                    <h5 class="text-sm font-semibold text-slate-950">{{ financeCategoryChartCard.title }}</h5>
                    <span class="text-xs font-semibold text-slate-600">{{ financeCategoryChartCard.summaryLabel }}</span>
                  </div>

                  <RemoteFinanceTrendMiniChart
                    class="mt-3"
                    :points="financeCategoryChartCard.points"
                    :stroke-color="financeCategoryChartCard.strokeColor"
                  />

                  <p class="mt-3 text-sm text-slate-600">{{ financeCategoryChartCard.caption }}</p>
                  <p class="mt-2 text-xs font-semibold text-slate-500">{{ financeCategoryChartCard.secondaryLabel }}</p>
                </article>
              </div>

              <p v-if="financeCategoriesLegend" class="text-xs text-slate-500">{{ financeCategoriesLegend }}</p>
            </div>

            <RemoteFinanceEmptyState
              v-else
              title="Sem categorias para comparar"
              description="Registre lancamentos categorizados para habilitar a leitura por categoria."
            />
          </article>

          <div class="grid gap-5 xl:grid-cols-[minmax(0,1.2fr),minmax(0,0.8fr)]">
            <article class="grid gap-4 rounded-[22px] border border-white/80 bg-white/80 p-5 app-depth-soft">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Vencimentos</p>
                  <h4 class="mt-1 text-2xl font-semibold text-slate-950">Proximos titulos do grupo</h4>
                </div>
              </div>

              <div v-if="financeUpcomingEntries.length" class="overflow-x-auto">
                <table class="min-w-full border-collapse text-sm">
                  <thead>
                    <tr class="border-b border-slate-200 text-left">
                      <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Titulo</th>
                      <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Categoria</th>
                      <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Vencimento</th>
                      <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Restante</th>
                      <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Situacao</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="financeUpcomingEntry in financeUpcomingEntries"
                      :key="financeUpcomingEntry.id"
                      class="border-b border-slate-100"
                    >
                      <td class="px-3 py-2 align-top text-slate-900">
                        <strong class="block text-sm font-semibold">{{ financeUpcomingEntry.title }}</strong>
                      </td>
                      <td class="px-3 py-2 align-top text-slate-600">{{ financeUpcomingEntry.categoryName || 'Sem categoria' }}</td>
                      <td class="px-3 py-2 align-top text-slate-600">{{ formatDate(financeUpcomingEntry.dueDate) }}</td>
                      <td class="px-3 py-2 align-top font-semibold text-slate-900">{{ formatCurrency(financeUpcomingEntry.remainingAmountBrl) }}</td>
                      <td class="px-3 py-2 align-top">
                        <span
                          class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                          :class="Number(financeUpcomingEntry.daysUntilDue) < 0
                            ? 'border-rose-200 bg-rose-50 text-rose-700'
                            : Number(financeUpcomingEntry.daysUntilDue) <= 3
                              ? 'border-amber-200 bg-amber-50 text-amber-700'
                              : 'border-emerald-200 bg-emerald-50 text-emerald-700'"
                        >
                          {{ formatDueStatus(financeUpcomingEntry.daysUntilDue) }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <RemoteFinanceEmptyState
                v-else
                title="Sem vencimentos pendentes"
                description="Nao existem titulos em aberto com vencimento para exibir."
              />
            </article>

            <article class="grid gap-4 rounded-[22px] border border-white/80 bg-white/80 p-5 app-depth-soft">
              <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Status</p>
                <h4 class="mt-1 text-2xl font-semibold text-slate-950">Distribuicao dos lancamentos</h4>
              </div>

              <div v-if="financeEntryStatusSeries.length" class="grid gap-3">
                <div
                  v-for="financeStatusItem in financeEntryStatusSeries"
                  :key="financeStatusItem.key"
                  class="rounded-2xl border p-4"
                  :class="getFinanceStatusToneClasses(financeStatusItem.key).wrapper"
                >
                  <div class="flex items-center justify-between gap-3 text-sm">
                    <strong class="font-semibold text-slate-900">{{ financeStatusItem.label }}</strong>
                    <div class="flex items-center gap-2">
                      <span class="font-semibold text-slate-700">{{ formatInteger(financeStatusItem.value) }}</span>
                      <span
                        class="rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                        :class="getFinanceStatusToneClasses(financeStatusItem.key).badge"
                      >
                        {{ formatPercentage(financeStatusItem.share) }}
                      </span>
                    </div>
                  </div>
                  <div class="mt-3 h-2.5 overflow-hidden rounded-full" :style="buildBarTrackStyle()">
                    <span
                      class="block h-full rounded-full"
                      :style="buildBarFillStyle(
                        financeStatusItem.percentage,
                        getFinanceStatusToneClasses(financeStatusItem.key).barStartColor,
                        getFinanceStatusToneClasses(financeStatusItem.key).barEndColor
                      )"
                    />
                  </div>
                </div>
              </div>

              <RemoteFinanceEmptyState
                v-else
                title="Sem distribuicao por status"
                description="Cadastre lancamentos para visualizar a distribuicao por status."
              />
            </article>
          </div>

          <article class="grid gap-4 rounded-[22px] border border-white/80 bg-white/80 p-5 app-depth-soft">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Detalhamento</p>
              <h4 class="mt-1 text-2xl font-semibold text-slate-950">Top categorias consolidadas</h4>
            </div>

            <div v-if="financeCategoryDetailsRows.length" class="overflow-x-auto">
              <table class="min-w-full border-collapse text-sm">
                <thead>
                  <tr class="border-b border-slate-200 text-left">
                    <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Categoria</th>
                    <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Lancamentos</th>
                    <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Previsto</th>
                    <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Realizado</th>
                    <th class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Saldo</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="financeCategoryRow in financeCategoryDetailsRows"
                    :key="`${financeCategoryRow.categoryId || 'none'}-${financeCategoryRow.categoryName}`"
                    class="border-b border-slate-100"
                  >
                    <td class="px-3 py-2 text-slate-900">{{ financeCategoryRow.categoryName }}</td>
                    <td class="px-3 py-2 text-slate-600">{{ formatInteger(financeCategoryRow.entriesCount) }}</td>
                    <td class="px-3 py-2 text-slate-600">{{ formatCurrency(financeCategoryRow.expectedNetBrl) }}</td>
                    <td class="px-3 py-2 text-slate-600">{{ formatCurrency(financeCategoryRow.realizedNetBrl) }}</td>
                    <td
                      class="px-3 py-2 font-semibold"
                      :class="Number(financeCategoryRow.realizedNetBrl || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                    >
                      {{ formatCurrency(Number(financeCategoryRow.realizedNetBrl || 0) - Number(financeCategoryRow.expectedNetBrl || 0)) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <RemoteFinanceEmptyState
              v-else
              title="Sem detalhamento de categorias"
              description="As categorias com maior movimentacao aparecerao aqui."
            />
          </article>
        </template>
      </article>
    </article>
  </section>
</template>
