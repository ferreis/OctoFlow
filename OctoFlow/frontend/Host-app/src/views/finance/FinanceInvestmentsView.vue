<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useFinanceStore } from '../../stores/financeStore'
import { useNotification } from '../../composables/useNotification'
import { RemoteFinanceEmptyState } from '../../federation/remoteComponents'
import {
  createFinanceSimulation,
  convertFinanceSimulationToPlan,
  fetchFinanceInvestmentPlans,
  updateFinanceInvestmentPlan,
} from '../../services/financeInvestments'
import {
  FINANCE_INVESTMENT_TYPE_OPTIONS,
  FINANCE_INVESTMENT_YIELD_MODE_OPTIONS,
} from '../../constants/financeTerms'

const financeStore = useFinanceStore()
const { categories, bankAccounts } = storeToRefs(financeStore)
const { notifyUser } = useNotification()

const LIST_ITEMS_PER_PAGE = 10
const investmentPlans = ref([])
const latestSimulation = ref(null)
const loadingPlans = ref(false)
const currentPage = ref(1)

const investmentTypeOptions = FINANCE_INVESTMENT_TYPE_OPTIONS
const yieldModeOptions = FINANCE_INVESTMENT_YIELD_MODE_OPTIONS

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

const paginatedPlans = computed(() => {
  const start = (currentPage.value - 1) * LIST_ITEMS_PER_PAGE
  return investmentPlans.value.slice(start, start + LIST_ITEMS_PER_PAGE)
})
const totalPages = computed(() => Math.max(1, Math.ceil(investmentPlans.value.length / LIST_ITEMS_PER_PAGE)))

const investmentSummary = computed(() => {
  if (!latestSimulation.value) return null
  return {
    invested: formatCurrency(latestSimulation.value.totalInvestedBrl),
    yield: formatCurrency(latestSimulation.value.totalYieldBrl),
    finalAmount: formatCurrency(latestSimulation.value.finalAmountBrl),
  }
})

function formatCurrency(val) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2 }).format(Number(val || 0))
}

async function loadInvestmentPlans() {
  loadingPlans.value = true
  try {
    const res = await fetchFinanceInvestmentPlans()
    investmentPlans.value = res.data?.items || []
  } catch (err) {
    console.error('[FinanceInvestmentsView] loadPlans error:', err)
  } finally {
    loadingPlans.value = false
  }
}

async function submitSimulation() {
  try {
    const res = await createFinanceSimulation({
      investmentType: simulationForm.investmentType,
      label: simulationForm.label,
      initialAmountBrl: Number(simulationForm.initialAmountBrl || 0),
      monthlyContributionBrl: Number(simulationForm.monthlyContributionBrl || 0),
      periodMonths: Number(simulationForm.periodMonths || 1),
      rateInputType: simulationForm.rateInputType,
      rateValue: Number(simulationForm.rateValue || 0),
    })
    latestSimulation.value = res.data?.item || null
    notifyUser('Simulação gerada com sucesso.', 'success')
  } catch (err) {
    notifyUser('Erro ao gerar simulação.', 'error')
  }
}

async function convertSimulationToPlan() {
  if (!latestSimulation.value?.id) return
  try {
    await convertFinanceSimulationToPlan(latestSimulation.value.id, { ...convertPlanForm })
    notifyUser('Plano de investimento criado a partir da simulação.', 'success')
    latestSimulation.value = null
    await loadInvestmentPlans()
  } catch (err) {
    notifyUser('Erro ao converter simulação.', 'error')
  }
}

onMounted(() => {
  loadInvestmentPlans()
})
</script>

<template>
  <section class="finance-section">
    <!-- Simulação -->
    <article class="finance-panel">
      <header>
        <h3>Simulação de investimento</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitSimulation">
        <label>
          <span>Tipo de investimento</span>
          <select v-model="simulationForm.investmentType">
            <option v-for="opt in investmentTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </label>

        <label>
          <span>Rótulo</span>
          <input v-model="simulationForm.label" type="text">
        </label>

        <label>
          <span>Aporte inicial (R$)</span>
          <input v-model="simulationForm.initialAmountBrl" type="number" step="0.01" min="0">
        </label>

        <label>
          <span>Aporte mensal (R$)</span>
          <input v-model="simulationForm.monthlyContributionBrl" type="number" step="0.01" min="0">
        </label>

        <label>
          <span>Período (meses)</span>
          <input v-model="simulationForm.periodMonths" type="number" min="1" required>
        </label>

        <label>
          <span>Taxa (%)</span>
          <input v-model="simulationForm.rateValue" type="number" step="0.01" min="0">
        </label>

        <label>
          <span>Tipo de taxa</span>
          <select v-model="simulationForm.rateInputType">
            <option value="ANNUAL">Anual</option>
            <option value="MONTHLY">Mensal</option>
          </select>
        </label>

        <button class="finance-action-button" type="submit">Simular</button>
      </form>

      <div v-if="investmentSummary" class="finance-kpi-grid" style="margin-top: 16px;">
        <div class="finance-panel" style="text-align: center;">
          <small>Total investido</small>
          <strong style="font-size: 1.2rem;">{{ investmentSummary.invested }}</strong>
        </div>
        <div class="finance-panel" style="text-align: center;">
          <small>Rendimento</small>
          <strong style="font-size: 1.2rem;">{{ investmentSummary.yield }}</strong>
        </div>
        <div class="finance-panel" style="text-align: center;">
          <small>Valor final</small>
          <strong style="font-size: 1.2rem;">{{ investmentSummary.finalAmount }}</strong>
        </div>
      </div>

      <template v-if="latestSimulation">
        <hr style="border: none; border-top: 1px solid var(--line); margin: 16px 0;">
        <form class="finance-form-grid" @submit.prevent="convertSimulationToPlan">
          <label>
            <span>Rótulo do plano</span>
            <input v-model="convertPlanForm.label" type="text" required>
          </label>

          <label>
            <span>Data de início</span>
            <input v-model="convertPlanForm.startDate" type="date">
          </label>

          <label>
            <span>Dia do aporte</span>
            <input v-model="convertPlanForm.contributionDay" type="number" min="1" max="31">
          </label>

          <label>
            <span>Modo de rendimento</span>
            <select v-model="convertPlanForm.yieldMode">
              <option v-for="opt in yieldModeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </label>

          <label>
            <span>Categoria</span>
            <select v-model="convertPlanForm.categoryId">
              <option value="">Sem categoria</option>
              <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
            </select>
          </label>

          <label>
            <span>Conta bancária</span>
            <select v-model="convertPlanForm.defaultBankAccountId">
              <option value="">Sem conta</option>
              <option v-for="bank in bankAccounts" :key="bank.id" :value="bank.id">{{ bank.name }}</option>
            </select>
          </label>

          <button class="finance-action-button" type="submit">Criar plano a partir da simulação</button>
        </form>
      </template>
    </article>

    <!-- Planos cadastrados -->
    <article class="finance-panel">
      <header>
        <h3>Investimentos cadastrados</h3>
        <small>{{ investmentPlans.length }} plano{{ investmentPlans.length !== 1 ? 's' : '' }}</small>
      </header>

      <div v-if="paginatedPlans.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>Rótulo</th>
              <th>Tipo</th>
              <th>Aporte inicial</th>
              <th>Aporte mensal</th>
              <th>Período</th>
              <th>Saldo estimado</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="plan in paginatedPlans" :key="plan.id">
              <td><strong>{{ plan.label || plan.title }}</strong></td>
              <td>{{ plan.investmentType }}</td>
              <td>{{ formatCurrency(plan.initialAmountBrl) }}</td>
              <td>{{ formatCurrency(plan.monthlyContributionBrl) }}</td>
              <td>{{ plan.periodMonths }} meses</td>
              <td>{{ formatCurrency(plan.estimatedFinalAmountBrl || plan.finalAmountBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <RemoteFinanceEmptyState v-else title="Sem investimentos" description="Simule e crie seu primeiro plano de investimento." />

      <div v-if="totalPages > 1" class="finance-pagination">
        <span class="finance-pagination-summary">Página {{ currentPage }} de {{ totalPages }}</span>
        <div class="finance-pagination-actions">
          <button type="button" class="finance-inline-action" :disabled="currentPage <= 1" @click="currentPage--">Anterior</button>
          <button type="button" class="finance-inline-action" :disabled="currentPage >= totalPages" @click="currentPage++">Próxima</button>
        </div>
      </div>
    </article>
  </section>
</template>
