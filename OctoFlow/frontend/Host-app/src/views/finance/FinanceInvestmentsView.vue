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
import {
  FINANCE_INVESTMENT_TYPE_OPTIONS,
  FINANCE_INVESTMENT_YIELD_MODE_OPTIONS,
} from '../../constants/financeTerms'
import FinancePageHeader from '../../components/finance/FinancePageHeader.vue'
import FinancePagination from '../../components/finance/FinancePagination.vue'
import { useFinancePermissions } from '../../composables/useFinancePermissions'
import { useNotification } from '../../composables/useNotification'
import { useScopedI18n } from '../../composables/useScopedI18n'
import { RemoteFinanceEmptyState } from '../../federation/remoteComponents'
import {
  convertFinanceSimulationToPlan,
  createFinanceSimulation,
  fetchFinanceInvestmentPlans,
  updateFinanceInvestmentPlan,
} from '../../services/financeInvestments'
import { useFinanceStore } from '../../stores/financeStore'
import { extractHttpMessage } from '../../utils/httpErrors'
import {
  sanitizeDateInput,
  sanitizeDecimal,
  sanitizeIdentifier,
  sanitizeInteger,
  sanitizeSingleLineText,
} from '../../utils/financeInputSanitizers'

const LIST_ITEMS_PER_PAGE = 10

const financeStore = useFinanceStore()
const { categories, bankAccounts } = storeToRefs(financeStore)
const { notifyUser } = useNotification()
const { translateScoped, currentLocale } = useScopedI18n('financeModule.investmentsView')
const { canWriteFinance } = useFinancePermissions()

const investmentPlans = ref([])
const latestSimulation = ref(null)
const loadingPlans = ref(false)
const runningSimulation = ref(false)
const convertingSimulationToPlan = ref(false)
const savingInvestmentPlan = ref(false)
const currentPage = ref(1)
const investmentPlanEditingId = ref('')
const financeViewIsActive = ref(false)
const investmentPlanCountBeforeDomUpdate = ref(0)

const investmentTypeOptions = FINANCE_INVESTMENT_TYPE_OPTIONS
const yieldModeOptions = FINANCE_INVESTMENT_YIELD_MODE_OPTIONS

const simulationForm = reactive({
  investmentType: 'SELIC',
  label: '',
  initialAmountBrl: '1000',
  monthlyContributionBrl: '300',
  periodMonths: 24,
  rateInputType: 'ANNUAL',
  rateValue: '12',
})

const convertPlanForm = reactive({
  label: '',
  startDate: '',
  contributionDay: 5,
  generateYieldEntries: false,
  yieldMode: 'NONE',
  categoryId: '',
  defaultBankAccountId: '',
})

const investmentPlanForm = reactive({
  label: '',
  contributionDay: 5,
  monthlyContributionBrl: '',
  generateYieldEntries: false,
  yieldMode: 'NONE',
  categoryId: '',
  defaultBankAccountId: '',
})

const paginatedPlans = computed(() => {
  const startIndex = (currentPage.value - 1) * LIST_ITEMS_PER_PAGE
  return investmentPlans.value.slice(startIndex, startIndex + LIST_ITEMS_PER_PAGE)
})

const totalPages = computed(() => Math.max(1, Math.ceil(investmentPlans.value.length / LIST_ITEMS_PER_PAGE)))
const localeForFormatting = computed(() => (
  String(currentLocale.value || '').toLowerCase() === 'en-us' ? 'en-US' : 'pt-BR'
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

const plansSummaryLabel = computed(() => {
  return translateScoped('list.summary', '{count} plano(s)', { count: investmentPlans.value.length })
})

const selectableCategories = computed(() => (
  categories.value.filter((category) => category?.isActive !== false)
))

const selectableBankAccounts = computed(() => (
  bankAccounts.value.filter((bankAccount) => bankAccount?.isActive !== false)
))

function formatCurrency(rawValue) {
  return new Intl.NumberFormat(localeForFormatting.value, {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(Number(rawValue || 0))
}

function resetConvertPlanForm() {
  convertPlanForm.label = ''
  convertPlanForm.startDate = ''
  convertPlanForm.contributionDay = 5
  convertPlanForm.generateYieldEntries = false
  convertPlanForm.yieldMode = 'NONE'
  convertPlanForm.categoryId = ''
  convertPlanForm.defaultBankAccountId = ''
}

function resetInvestmentPlanForm() {
  investmentPlanEditingId.value = ''
  investmentPlanForm.label = ''
  investmentPlanForm.contributionDay = 5
  investmentPlanForm.monthlyContributionBrl = ''
  investmentPlanForm.generateYieldEntries = false
  investmentPlanForm.yieldMode = 'NONE'
  investmentPlanForm.categoryId = ''
  investmentPlanForm.defaultBankAccountId = ''
}

function buildSimulationPayload() {
  const normalizedInvestmentType = String(simulationForm.investmentType || '').trim().toUpperCase()
  const allowedInvestmentType = investmentTypeOptions.some((option) => option.value === normalizedInvestmentType)
    ? normalizedInvestmentType
    : 'SELIC'

  const normalizedRateInputType = String(simulationForm.rateInputType || '').trim().toUpperCase()
  const allowedRateInputType = ['ANNUAL', 'MONTHLY'].includes(normalizedRateInputType)
    ? normalizedRateInputType
    : 'ANNUAL'

  const normalizedLabel = sanitizeSingleLineText(
    simulationForm.label,
    100,
  ) || translateScoped('simulation.defaultLabel', 'Simulação padrão')

  return {
    investmentType: allowedInvestmentType,
    label: normalizedLabel,
    initialAmountBrl: sanitizeDecimal(simulationForm.initialAmountBrl, { min: 0, max: 999999999, decimals: 2, defaultValue: 0 }),
    monthlyContributionBrl: sanitizeDecimal(simulationForm.monthlyContributionBrl, { min: 0, max: 999999999, decimals: 2, defaultValue: 0 }),
    periodMonths: sanitizeInteger(simulationForm.periodMonths, { min: 1, max: 600, defaultValue: 1 }),
    rateInputType: allowedRateInputType,
    rateValue: sanitizeDecimal(simulationForm.rateValue, { min: 0, max: 1000, decimals: 4, defaultValue: 0 }),
  }
}

function buildConvertPlanPayload() {
  const normalizedYieldMode = String(convertPlanForm.yieldMode || '').trim().toUpperCase()
  const allowedYieldMode = yieldModeOptions.some((option) => option.value === normalizedYieldMode)
    ? normalizedYieldMode
    : 'NONE'

  return {
    label: sanitizeSingleLineText(convertPlanForm.label, 100)
      || translateScoped('convert.defaultPlanLabel', 'Plano de investimento'),
    startDate: sanitizeDateInput(convertPlanForm.startDate),
    contributionDay: sanitizeInteger(convertPlanForm.contributionDay, { min: 1, max: 31, defaultValue: 5 }),
    generateYieldEntries: convertPlanForm.generateYieldEntries === true,
    yieldMode: allowedYieldMode,
    categoryId: sanitizeIdentifier(convertPlanForm.categoryId),
    defaultBankAccountId: sanitizeIdentifier(convertPlanForm.defaultBankAccountId),
  }
}

function startEditingInvestmentPlan(investmentPlan) {
  investmentPlanEditingId.value = sanitizeIdentifier(investmentPlan?.id)
  investmentPlanForm.label = sanitizeSingleLineText(investmentPlan?.label, 100)
  investmentPlanForm.contributionDay = sanitizeInteger(investmentPlan?.contributionDay, { min: 1, max: 31, defaultValue: 5 })
  investmentPlanForm.monthlyContributionBrl = String(sanitizeDecimal(investmentPlan?.monthlyContributionBrl, {
    min: 0,
    max: 999999999,
    decimals: 2,
    defaultValue: 0,
  }))
  investmentPlanForm.generateYieldEntries = investmentPlan?.generateYieldEntries === true
    || investmentPlan?.generateYieldEntries === 1
    || investmentPlan?.generateYieldEntries === '1'
  investmentPlanForm.yieldMode = yieldModeOptions.some((option) => option.value === investmentPlan?.yieldMode)
    ? investmentPlan.yieldMode
    : 'NONE'
  investmentPlanForm.categoryId = sanitizeIdentifier(investmentPlan?.categoryId)
  investmentPlanForm.defaultBankAccountId = sanitizeIdentifier(investmentPlan?.bankAccountId)
}

function buildInvestmentPlanPayload() {
  const normalizedYieldMode = String(investmentPlanForm.yieldMode || '').trim().toUpperCase()

  return {
    label: sanitizeSingleLineText(investmentPlanForm.label, 100),
    contributionDay: sanitizeInteger(investmentPlanForm.contributionDay, { min: 1, max: 31, defaultValue: 5 }),
    monthlyContributionBrl: sanitizeDecimal(investmentPlanForm.monthlyContributionBrl, {
      min: 0,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
    generateYieldEntries: investmentPlanForm.generateYieldEntries === true,
    yieldMode: yieldModeOptions.some((option) => option.value === normalizedYieldMode)
      ? normalizedYieldMode
      : 'NONE',
    categoryId: sanitizeIdentifier(investmentPlanForm.categoryId),
    defaultBankAccountId: sanitizeIdentifier(investmentPlanForm.defaultBankAccountId),
  }
}

async function loadInvestmentPlans(showErrorNotification = false) {
  loadingPlans.value = true

  try {
    const response = await fetchFinanceInvestmentPlans()
    if (!financeViewIsActive.value) {
      return
    }

    investmentPlans.value = Array.isArray(response.data?.items) ? response.data.items : []
  } catch (error) {
    if (showErrorNotification && financeViewIsActive.value) {
      notifyUser(extractHttpMessage(error, translateScoped('notifications.loadPlansError', 'Não foi possível carregar os investimentos.')), 'error')
    }
  } finally {
    loadingPlans.value = false
  }
}

async function submitSimulation() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para simular investimentos.'), 'warning')
    return
  }

  if (runningSimulation.value) {
    return
  }

  const payload = buildSimulationPayload()

  runningSimulation.value = true

  try {
    const response = await createFinanceSimulation(payload)
    if (!financeViewIsActive.value) {
      return
    }

    latestSimulation.value = response.data?.item || null

    if (!convertPlanForm.label) {
      convertPlanForm.label = payload.label
    }

    notifyUser(translateScoped('notifications.simulationSuccess', 'Simulação gerada com sucesso.'), 'success')
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.simulationError', 'Erro ao gerar simulação.')), 'error')
  } finally {
    runningSimulation.value = false
  }
}

async function convertSimulationToPlan() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para criar planos de investimento.'), 'warning')
    return
  }

  if (convertingSimulationToPlan.value) {
    return
  }

  const simulationId = sanitizeIdentifier(latestSimulation.value?.id)
  if (simulationId === '') {
    notifyUser(translateScoped('notifications.simulationRequired', 'Gere uma simulação válida antes de criar o plano.'), 'warning')
    return
  }

  const payload = buildConvertPlanPayload()
  convertingSimulationToPlan.value = true

  try {
    await convertFinanceSimulationToPlan(simulationId, payload)
    if (!financeViewIsActive.value) {
      return
    }

    notifyUser(translateScoped('notifications.convertSuccess', 'Plano de investimento criado a partir da simulação.'), 'success')
    latestSimulation.value = null
    resetConvertPlanForm()
    await loadInvestmentPlans(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.convertError', 'Erro ao converter simulação.')), 'error')
  } finally {
    convertingSimulationToPlan.value = false
  }
}

async function submitInvestmentPlanEdit() {
  const investmentPlanId = sanitizeIdentifier(investmentPlanEditingId.value)
  const payload = buildInvestmentPlanPayload()
  if (investmentPlanId === '' || payload.label === '' || payload.monthlyContributionBrl < 0 || !canWriteFinance.value) {
    return
  }

  savingInvestmentPlan.value = true

  try {
    await updateFinanceInvestmentPlan(investmentPlanId, payload)
    notifyUser(translateScoped('notifications.planUpdated', 'Plano de investimento atualizado com sucesso.'), 'success')
    resetInvestmentPlanForm()
    await loadInvestmentPlans(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.planUpdateError', 'Erro ao atualizar o plano de investimento.')), 'error')
  } finally {
    savingInvestmentPlan.value = false
  }
}

async function updateInvestmentPlanStatus(investmentPlan, nextStatus) {
  const investmentPlanId = sanitizeIdentifier(investmentPlan?.id)
  if (investmentPlanId === '' || savingInvestmentPlan.value || !canWriteFinance.value) {
    return
  }

  savingInvestmentPlan.value = true

  try {
    await updateFinanceInvestmentPlan(investmentPlanId, { status: nextStatus })
    notifyUser(translateScoped('notifications.planStatusUpdated', 'Status do plano atualizado com sucesso.'), 'success')
    await loadInvestmentPlans(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.planStatusError', 'Erro ao alterar o status do plano.')), 'error')
  } finally {
    savingInvestmentPlan.value = false
  }
}

watch(totalPages, (nextTotalPages) => {
  if (currentPage.value > nextTotalPages) {
    currentPage.value = nextTotalPages
  }
})

onBeforeMount(() => {
  void financeStore.loadCatalogs(false)
})

onMounted(() => {
  financeViewIsActive.value = true

  if (!simulationForm.label) {
    simulationForm.label = translateScoped('simulation.defaultLabel', 'Simulação padrão')
  }
  if (!convertPlanForm.label) {
    convertPlanForm.label = translateScoped('convert.defaultPlanLabel', 'Plano de investimento')
  }

  void loadInvestmentPlans(true)
})

onBeforeUpdate(() => {
  investmentPlanCountBeforeDomUpdate.value = investmentPlans.value.length
})

onUpdated(() => {
  if (investmentPlanCountBeforeDomUpdate.value !== investmentPlans.value.length && currentPage.value > totalPages.value) {
    currentPage.value = totalPages.value
  }
})

onActivated(() => {
  financeViewIsActive.value = true
  void loadInvestmentPlans(false)
})

onDeactivated(() => {
  financeViewIsActive.value = false
  runningSimulation.value = false
  convertingSimulationToPlan.value = false
})

onBeforeUnmount(() => {
  financeViewIsActive.value = false
  runningSimulation.value = false
  convertingSimulationToPlan.value = false
  resetConvertPlanForm()
})

onUnmounted(() => {
  investmentPlanCountBeforeDomUpdate.value = 0
  currentPage.value = 1
  latestSimulation.value = null
})

onErrorCaptured((error) => {
  if (!financeViewIsActive.value) {
    return false
  }

  console.error('[FinanceInvestmentsView] child render error:', error)
  notifyUser(translateScoped('notifications.childRenderError', 'Erro inesperado ao renderizar investimentos.'), 'error')
  return false
})
</script>

<template>
  <section class="finance-section">
    <FinancePageHeader
      eyebrow="Planejamento"
      title="Investimentos"
      description="Simule cenários, compare rendimentos e transforme uma projeção em um plano acompanhável."
    />

    <article class="finance-panel">
      <header>
        <h3>{{ translateScoped('simulation.title', 'Simulação de investimento') }}</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitSimulation">
        <label>
          <span>{{ translateScoped('simulation.fields.investmentType', 'Tipo de investimento') }}</span>
          <select v-model="simulationForm.investmentType" :disabled="runningSimulation || !canWriteFinance">
            <option v-for="option in investmentTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('simulation.fields.label', 'Rótulo') }}</span>
          <input
            v-model="simulationForm.label"
            type="text"
            :placeholder="translateScoped('simulation.fields.labelPlaceholder', 'Simulação padrão')"
            :disabled="runningSimulation || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('simulation.fields.initialAmount', 'Aporte inicial (R$)') }}</span>
          <input
            v-model="simulationForm.initialAmountBrl"
            type="number"
            step="0.01"
            min="0"
            :disabled="runningSimulation || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('simulation.fields.monthlyContribution', 'Aporte mensal (R$)') }}</span>
          <input
            v-model="simulationForm.monthlyContributionBrl"
            type="number"
            step="0.01"
            min="0"
            :disabled="runningSimulation || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('simulation.fields.periodMonths', 'Período (meses)') }}</span>
          <input
            v-model="simulationForm.periodMonths"
            type="number"
            min="1"
            :disabled="runningSimulation || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('simulation.fields.rateValue', 'Taxa (%)') }}</span>
          <input
            v-model="simulationForm.rateValue"
            type="number"
            step="0.01"
            min="0"
            :disabled="runningSimulation || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('simulation.fields.rateInputType', 'Tipo de taxa') }}</span>
          <select v-model="simulationForm.rateInputType" :disabled="runningSimulation || !canWriteFinance">
            <option value="ANNUAL">{{ translateScoped('simulation.rateTypes.annual', 'Anual') }}</option>
            <option value="MONTHLY">{{ translateScoped('simulation.rateTypes.monthly', 'Mensal') }}</option>
          </select>
        </label>

        <button class="finance-action-button" type="submit" :disabled="runningSimulation || !canWriteFinance">
          {{ runningSimulation
            ? translateScoped('actions.simulating', 'Simulando...')
            : translateScoped('actions.simulate', 'Simular') }}
        </button>
      </form>

      <div v-if="investmentSummary" class="finance-kpi-grid finance-kpi-summary-grid">
        <div class="finance-panel finance-kpi-summary-card">
          <small>{{ translateScoped('summary.invested', 'Total investido') }}</small>
          <strong class="finance-kpi-summary-value">{{ investmentSummary.invested }}</strong>
        </div>
        <div class="finance-panel finance-kpi-summary-card">
          <small>{{ translateScoped('summary.yield', 'Rendimento') }}</small>
          <strong class="finance-kpi-summary-value">{{ investmentSummary.yield }}</strong>
        </div>
        <div class="finance-panel finance-kpi-summary-card">
          <small>{{ translateScoped('summary.finalAmount', 'Valor final') }}</small>
          <strong class="finance-kpi-summary-value">{{ investmentSummary.finalAmount }}</strong>
        </div>
      </div>

      <template v-if="latestSimulation">
        <hr class="finance-divider">
        <form class="finance-form-grid" @submit.prevent="convertSimulationToPlan">
          <label>
            <span>{{ translateScoped('convert.fields.label', 'Rótulo do plano') }}</span>
            <input
              v-model="convertPlanForm.label"
              type="text"
              :disabled="convertingSimulationToPlan || !canWriteFinance"
              required
            >
          </label>

          <label>
            <span>{{ translateScoped('convert.fields.startDate', 'Data de início') }}</span>
            <input
              v-model="convertPlanForm.startDate"
              type="date"
              :disabled="convertingSimulationToPlan || !canWriteFinance"
            >
          </label>

          <label>
            <span>{{ translateScoped('convert.fields.contributionDay', 'Dia do aporte') }}</span>
            <input
              v-model="convertPlanForm.contributionDay"
              type="number"
              min="1"
              max="31"
              :disabled="convertingSimulationToPlan || !canWriteFinance"
            >
          </label>

          <label>
            <span>{{ translateScoped('convert.fields.yieldMode', 'Modo de rendimento') }}</span>
            <select v-model="convertPlanForm.yieldMode" :disabled="convertingSimulationToPlan || !canWriteFinance">
              <option v-for="option in yieldModeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>

          <label>
            <span>{{ translateScoped('convert.fields.category', 'Categoria') }}</span>
            <select v-model="convertPlanForm.categoryId" :disabled="convertingSimulationToPlan || !canWriteFinance">
              <option value="">{{ translateScoped('convert.options.noCategory', 'Sem categoria') }}</option>
              <option v-for="category in selectableCategories" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
          </label>

          <label>
            <span>{{ translateScoped('convert.fields.bankAccount', 'Conta bancária') }}</span>
            <select v-model="convertPlanForm.defaultBankAccountId" :disabled="convertingSimulationToPlan || !canWriteFinance">
              <option value="">{{ translateScoped('convert.options.noBankAccount', 'Sem conta') }}</option>
              <option v-for="bankAccount in selectableBankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
            </select>
          </label>

          <button class="finance-action-button" type="submit" :disabled="convertingSimulationToPlan || !canWriteFinance">
            {{ convertingSimulationToPlan
              ? translateScoped('actions.converting', 'Convertendo...')
              : translateScoped('actions.convert', 'Criar plano a partir da simulação') }}
          </button>
        </form>
      </template>
    </article>

    <article v-if="investmentPlanEditingId" class="finance-panel">
      <header>
        <h3>Editar plano de investimento</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitInvestmentPlanEdit">
        <label>
          <span>Rótulo</span>
          <input v-model="investmentPlanForm.label" type="text" :disabled="savingInvestmentPlan || !canWriteFinance" required>
        </label>
        <label>
          <span>Dia do aporte</span>
          <input v-model="investmentPlanForm.contributionDay" type="number" min="1" max="31" :disabled="savingInvestmentPlan || !canWriteFinance">
        </label>
        <label>
          <span>Aporte mensal (R$)</span>
          <input v-model="investmentPlanForm.monthlyContributionBrl" type="number" min="0" step="0.01" :disabled="savingInvestmentPlan || !canWriteFinance">
        </label>
        <label>
          <span>Categoria</span>
          <select v-model="investmentPlanForm.categoryId" :disabled="savingInvestmentPlan || !canWriteFinance">
            <option value="">Sem categoria</option>
            <option v-for="category in selectableCategories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>
        <label>
          <span>Conta bancária</span>
          <select v-model="investmentPlanForm.defaultBankAccountId" :disabled="savingInvestmentPlan || !canWriteFinance">
            <option value="">Sem conta</option>
            <option v-for="bankAccount in selectableBankAccounts" :key="bankAccount.id" :value="bankAccount.id">{{ bankAccount.name }}</option>
          </select>
        </label>
        <label>
          <span>Modo de rendimento</span>
          <select v-model="investmentPlanForm.yieldMode" :disabled="savingInvestmentPlan || !canWriteFinance">
            <option v-for="option in yieldModeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>
        <label class="finance-modal-checkbox">
          <input v-model="investmentPlanForm.generateYieldEntries" type="checkbox" :disabled="savingInvestmentPlan || !canWriteFinance">
          <span>Gerar lançamentos de rendimento</span>
        </label>
        <div class="finance-form-actions">
          <button class="finance-action-button" type="submit" :disabled="savingInvestmentPlan || !canWriteFinance">Salvar alterações</button>
          <button class="finance-inline-action" type="button" :disabled="savingInvestmentPlan" @click="resetInvestmentPlanForm">Cancelar</button>
        </div>
      </form>
    </article>

    <article class="finance-panel">
      <header>
        <h3>{{ translateScoped('list.title', 'Investimentos cadastrados') }}</h3>
        <small>{{ plansSummaryLabel }}</small>
      </header>

      <div v-if="loadingPlans && !investmentPlans.length" class="finance-muted-block">
        {{ translateScoped('list.loading', 'Carregando investimentos...') }}
      </div>

      <div v-else-if="paginatedPlans.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('list.columns.label', 'Rótulo') }}</th>
              <th>{{ translateScoped('list.columns.type', 'Tipo') }}</th>
              <th>{{ translateScoped('list.columns.monthlyContribution', 'Aporte mensal') }}</th>
              <th>Status</th>
              <th>Conta</th>
              <th>Ação</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="plan in paginatedPlans" :key="plan.id">
              <td><strong>{{ plan.label || plan.title }}</strong></td>
              <td>{{ plan.investmentType }}</td>
              <td>{{ formatCurrency(plan.monthlyContributionBrl) }}</td>
              <td>{{ plan.status }}</td>
              <td>{{ plan.bankAccountName || '-' }}</td>
              <td class="finance-actions-cell">
                <button type="button" class="finance-inline-action" :disabled="savingInvestmentPlan || !canWriteFinance" @click="startEditingInvestmentPlan(plan)">Editar</button>
                <button v-if="plan.status === 'ACTIVE'" type="button" class="finance-inline-action" :disabled="savingInvestmentPlan || !canWriteFinance" @click="updateInvestmentPlanStatus(plan, 'PAUSED')">Pausar</button>
                <button v-if="plan.status === 'PAUSED'" type="button" class="finance-inline-action" :disabled="savingInvestmentPlan || !canWriteFinance" @click="updateInvestmentPlanStatus(plan, 'ACTIVE')">Retomar</button>
                <button v-if="['ACTIVE', 'PAUSED'].includes(plan.status)" type="button" class="finance-inline-action" :disabled="savingInvestmentPlan || !canWriteFinance" @click="updateInvestmentPlanStatus(plan, 'FINISHED')">Finalizar</button>
                <button v-if="['ACTIVE', 'PAUSED'].includes(plan.status)" type="button" class="finance-inline-action finance-inline-action-danger" :disabled="savingInvestmentPlan || !canWriteFinance" @click="updateInvestmentPlanStatus(plan, 'CANCELED')">Cancelar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <RemoteFinanceEmptyState
        v-else
        :title="translateScoped('list.empty.title', 'Sem investimentos')"
        :description="translateScoped('list.empty.description', 'Simule e crie seu primeiro plano de investimento.')"
      />

      <FinancePagination
        :current-page="currentPage"
        :total-pages="totalPages"
        :summary="translateScoped('pagination.summary', 'Página {page} de {totalPages}', { page: currentPage, totalPages })"
        :previous-label="translateScoped('pagination.previous', 'Anterior')"
        :next-label="translateScoped('pagination.next', 'Próxima')"
        @previous="currentPage--"
        @next="currentPage++"
      />
    </article>
  </section>
</template>
