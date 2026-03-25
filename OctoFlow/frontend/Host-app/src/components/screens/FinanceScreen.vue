<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  RemoteFinanceEmptyState,
  RemoteFinanceKpiCard,
  RemoteFinanceStatusBadge,
  RemoteFinanceTrendMiniChart,
} from '../../federation/remoteComponents'
import {
  createFinanceEntry,
  createFinanceExport,
  createFinanceInstallmentPlan,
  createFinanceOpenFinanceConnection,
  createFinanceRecurringRule,
  createFinanceSettlement,
  fetchFinanceBankAccounts,
  fetchFinanceCategories,
  fetchFinanceDashboardCashflow,
  fetchFinanceDashboardCategories,
  fetchFinanceDashboardSummary,
  fetchFinanceEntries,
  fetchFinanceExports,
  fetchFinanceInstallmentPlans,
  fetchFinanceOpenFinanceConnections,
  fetchFinanceOpenFinanceProviders,
  fetchFinanceRecurringRules,
  fetchFinanceRecurringTypes,
  renegotiateFinanceInstallmentPlan,
  syncFinanceOpenFinanceConnection,
} from '../../services/finance'
import {
  convertFinanceSimulationToPlan,
  createFinanceSimulation,
  fetchFinanceInvestmentPlans,
} from '../../services/financeInvestments'
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
})

const { notifyUser } = useNotification(props.notify)

const tabOptions = [
  { key: 'overview', label: 'Visão geral' },
  { key: 'entries', label: 'Lançamentos' },
  { key: 'recurrence', label: 'Recorrências' },
  { key: 'installments', label: 'Parcelamentos' },
  { key: 'investments', label: 'Investimentos' },
  { key: 'exports', label: 'Exportações' },
]

const activeTab = ref('overview')
const loadingState = reactive({
  dashboard: false,
  entries: false,
  recurring: false,
  installments: false,
  investments: false,
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
  title: '',
  expectedAmountBrl: '',
  dueDate: '',
  categoryId: '',
  bankAccountId: '',
})

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

const openFinanceForm = reactive({
  providerId: '',
  status: 'ACTIVE',
  createMockData: false,
})

const kpiCards = computed(() => {
  if (!dashboardSummary.value) {
    return []
  }

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

watch(activeTab, async (nextTabKey) => {
  if (nextTabKey === 'overview') {
    await loadDashboard()
    return
  }

  if (nextTabKey === 'entries') {
    await loadEntries()
    return
  }

  if (nextTabKey === 'recurrence') {
    await loadRecurringRules()
    return
  }

  if (nextTabKey === 'installments') {
    await loadInstallments()
    return
  }

  if (nextTabKey === 'investments') {
    await loadInvestments()
    return
  }

  if (nextTabKey === 'exports') {
    await loadExports()
    await loadOpenFinance()
  }
})

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
    loadExports(),
    loadOpenFinance(),
  ])
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
    await createFinanceEntry(props.request, {
      direction: entryForm.direction,
      title: entryForm.title,
      expectedAmountBrl: Number(entryForm.expectedAmountBrl),
      dueDate: entryForm.dueDate || null,
      categoryId: normalizeOptionalNumber(entryForm.categoryId),
      bankAccountId: normalizeOptionalNumber(entryForm.bankAccountId),
    })

    entryForm.title = ''
    entryForm.expectedAmountBrl = ''
    entryForm.dueDate = ''

    notifyUser('Lançamento criado com sucesso.', 'success')
    await Promise.all([loadEntries(), loadDashboard()])
  } catch (requestError) {
    notifyUser(extractHttpMessage(requestError, 'Não foi possível criar o lançamento.'), 'error')
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

function formatCurrency(rawValue) {
  const numericValue = Number(rawValue || 0)

  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(numericValue)
}

function normalizeOptionalNumber(value) {
  const normalizedValue = Number(value)

  return Number.isFinite(normalizedValue) && normalizedValue > 0
    ? normalizedValue
    : null
}
</script>

<template>
  <section class="finance-screen">
    <header class="finance-screen-header">
      <div>
        <p class="finance-eyebrow">Módulo Financeiro</p>
        <h2>Controle financeiro pessoal</h2>
        <p>Gestão prática de contas, recorrência, parcelamentos, investimentos e exportação.</p>
      </div>
    </header>

    <nav class="finance-tabs" aria-label="Abas do financeiro">
      <button
        v-for="tabOption in tabOptions"
        :key="tabOption.key"
        type="button"
        class="finance-tab-button"
        :class="{ active: activeTab === tabOption.key }"
        @click="activeTab = tabOption.key"
      >
        {{ tabOption.label }}
      </button>
    </nav>

    <section v-if="activeTab === 'overview'" class="finance-section">
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
          <h3>Fluxo mensal</h3>
          <small v-if="loadingState.dashboard">Atualizando...</small>
        </header>

        <RemoteFinanceTrendMiniChart :points="dashboardCashflow" />

        <div v-if="dashboardCashflow.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Mês</th>
                <th>Previsto</th>
                <th>Realizado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="cashflowItem in dashboardCashflow" :key="cashflowItem.competenceMonth">
                <td>{{ cashflowItem.competenceMonth }}</td>
                <td>{{ formatCurrency(cashflowItem.expectedNetBrl) }}</td>
                <td>{{ formatCurrency(cashflowItem.realizedNetBrl) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <RemoteFinanceEmptyState
          v-else
          title="Sem dados de fluxo"
          description="Cadastre lançamentos para preencher o histórico de fluxo mensal."
        />
      </article>

      <article class="finance-panel">
        <header>
          <h3>Top categorias</h3>
        </header>

        <div v-if="dashboardCategories.length" class="finance-inline-table-wrap">
          <table class="finance-inline-table">
            <thead>
              <tr>
                <th>Categoria</th>
                <th>Previsto</th>
                <th>Realizado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="categoryItem in dashboardCategories" :key="`${categoryItem.categoryId || 'none'}-${categoryItem.categoryName}`">
                <td>{{ categoryItem.categoryName }}</td>
                <td>{{ formatCurrency(categoryItem.expectedNetBrl) }}</td>
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

    <section v-else-if="activeTab === 'entries'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Novo lançamento</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitEntry">
          <label>
            <span>Direção</span>
            <select v-model="entryForm.direction">
              <option value="PAYABLE">Contas a pagar</option>
              <option value="RECEIVABLE">Contas a receber</option>
            </select>
          </label>

          <label>
            <span>Título</span>
            <input v-model="entryForm.title" type="text" required>
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

      <article class="finance-panel">
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

      <article class="finance-panel">
        <header>
          <h3>Lançamentos</h3>
          <small>{{ totalsLabel }}</small>
        </header>

        <form class="finance-filter-grid" @submit.prevent="loadEntries">
          <label>
            <span>Busca</span>
            <input v-model="entryFilters.search" type="text" placeholder="Título ou descrição">
          </label>

          <label>
            <span>Direção</span>
            <select v-model="entryFilters.direction">
              <option value="">Todas</option>
              <option value="PAYABLE">Pagar</option>
              <option value="RECEIVABLE">Receber</option>
            </select>
          </label>

          <label>
            <span>Status</span>
            <select v-model="entryFilters.status">
              <option value="">Todos</option>
              <option value="PENDING">Pendente</option>
              <option value="FORECAST">Previsto</option>
              <option value="PARTIAL">Parcial</option>
              <option value="OVERDUE">Atrasado</option>
              <option value="PAID">Pago</option>
              <option value="RECEIVED">Recebido</option>
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
                <td>
                  <RemoteFinanceStatusBadge :status="entry.status" />
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

    <section v-else-if="activeTab === 'recurrence'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Nova recorrência</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitRecurringRule">
          <label>
            <span>Direção</span>
            <select v-model="recurringForm.direction">
              <option value="PAYABLE">Contas a pagar</option>
              <option value="RECEIVABLE">Contas a receber</option>
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

    <section v-else-if="activeTab === 'installments'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Novo parcelamento</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitInstallmentPlan">
          <label>
            <span>Direção</span>
            <select v-model="installmentForm.direction">
              <option value="PAYABLE">Contas a pagar</option>
              <option value="RECEIVABLE">Contas a receber</option>
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
                <td><RemoteFinanceStatusBadge :status="plan.status" /></td>
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

    <section v-else-if="activeTab === 'investments'" class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Simulador</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitSimulation">
          <label>
            <span>Tipo</span>
            <select v-model="simulationForm.investmentType">
              <option value="SELIC">Selic</option>
              <option value="CDB">CDB</option>
              <option value="CDI">CDI</option>
              <option value="TESOURO">Tesouro</option>
              <option value="CUSTOM">Customizado</option>
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
              <option value="NONE">Não gerar</option>
              <option value="ESTIMATED">Estimado</option>
              <option value="MANUAL">Manual</option>
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
                <td><RemoteFinanceStatusBadge :status="plan.status" /></td>
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

    <section v-else class="finance-section">
      <article class="finance-panel">
        <header>
          <h3>Nova exportação XLSX</h3>
        </header>

        <form class="finance-form-grid" @submit.prevent="submitExport">
          <label>
            <span>Tipo de exportação</span>
            <select v-model="exportForm.exportType">
              <option value="PAYABLE">Contas a pagar</option>
              <option value="RECEIVABLE">Contas a receber</option>
              <option value="MONTHLY_SUMMARY">Resumo mensal</option>
              <option value="CATEGORY">Categorias</option>
              <option value="CASHFLOW">Fluxo financeiro</option>
              <option value="INVESTMENT">Investimentos</option>
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
                <td>{{ exportJob.exportType }}</td>
                <td><RemoteFinanceStatusBadge :status="exportJob.status" /></td>
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
              <option value="ACTIVE">ACTIVE</option>
              <option value="REVOKED">REVOKED</option>
              <option value="EXPIRED">EXPIRED</option>
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
                <td><RemoteFinanceStatusBadge :status="connection.status" /></td>
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
  gap: 14px;
}

.finance-screen-header {
  border: 1px solid var(--app-border-color, #cbd5e1);
  border-radius: 14px;
  padding: 16px;
  background: linear-gradient(120deg, #ffffff 0%, #f8fafc 100%);
}

.finance-screen-header h2 {
  margin: 2px 0 6px;
  font-size: 1.24rem;
  color: var(--app-text-color, #0f172a);
}

.finance-screen-header p {
  margin: 0;
  color: var(--app-text-muted, #475569);
}

.finance-eyebrow {
  margin: 0;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.72rem;
  color: #2563eb;
  font-weight: 700;
}

.finance-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.finance-tab-button {
  border: 1px solid #cbd5e1;
  background: #ffffff;
  color: #334155;
  border-radius: 999px;
  padding: 0.4rem 0.8rem;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
}

.finance-tab-button.active {
  background: #1d4ed8;
  border-color: #1d4ed8;
  color: #ffffff;
}

.finance-section {
  display: grid;
  gap: 12px;
}

.finance-panel {
  border: 1px solid #cbd5e1;
  border-radius: 14px;
  background: #ffffff;
  padding: 14px;
  display: grid;
  gap: 12px;
}

.finance-panel header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.finance-panel h3 {
  margin: 0;
  font-size: 1rem;
  color: #0f172a;
}

.finance-panel small {
  color: #475569;
}

.finance-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 10px;
}

.finance-form-grid,
.finance-filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 10px;
}

.finance-form-grid label,
.finance-filter-grid label {
  display: grid;
  gap: 4px;
}

.finance-form-grid label span,
.finance-filter-grid label span {
  font-size: 0.74rem;
  color: #475569;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.finance-form-grid input,
.finance-filter-grid input,
.finance-form-grid select,
.finance-filter-grid select {
  border-radius: 10px;
  border: 1px solid #cbd5e1;
  min-height: 38px;
  padding: 0 10px;
  color: #0f172a;
  background: #f8fafc;
}

.finance-action-button,
.finance-inline-action {
  border: 1px solid #1d4ed8;
  background: #1d4ed8;
  color: #ffffff;
  border-radius: 10px;
  min-height: 38px;
  padding: 0 12px;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
}

.finance-inline-action:disabled {
  border-color: #94a3b8;
  background: #94a3b8;
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
}

.finance-inline-table {
  width: 100%;
  border-collapse: collapse;
}

.finance-inline-table th,
.finance-inline-table td {
  border-bottom: 1px solid #e2e8f0;
  padding: 10px 8px;
  text-align: left;
  vertical-align: middle;
  color: #0f172a;
}

.finance-inline-table th {
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #475569;
}

.finance-muted-block {
  display: block;
  color: #64748b;
  font-size: 0.74rem;
}

@media (max-width: 768px) {
  .finance-screen-header {
    padding: 14px;
  }

  .finance-panel {
    padding: 12px;
  }
}
</style>
