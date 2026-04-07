<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useFinanceStore } from '../../stores/financeStore'
import { useSessionStore } from '../../stores/sessionStore'
import { useNotification } from '../../composables/useNotification'
import {
  RemoteFinanceEmptyState,
  RemoteFinanceKpiCard,
  RemoteFinanceTrendMiniChart,
} from '../../federation/remoteComponents'
import {
  fetchFinanceDashboardSummary,
  fetchFinanceDashboardCashflow,
  fetchFinanceDashboardCategories,
  createFinanceExport,
  fetchFinanceMigrationSnapshot,
  importFinanceMigrationSnapshot,
} from '../../services/finance'
import {
  FINANCE_EXPORT_TYPE_OPTIONS,
  translateFinanceExportType,
} from '../../constants/financeTerms'
import { formatDate } from '../../utils/date'

const financeStore = useFinanceStore()
const sessionStore = useSessionStore()
const { notifyUser } = useNotification()

const { loading } = storeToRefs(financeStore)

const dashboardSummary = ref(null)
const dashboardCashflow = ref([])
const dashboardCategories = ref([])

const exportForm = reactive({ exportType: 'MONTHLY_SUMMARY' })
const exportTypeOptions = FINANCE_EXPORT_TYPE_OPTIONS

const migrationForm = reactive({ replaceExisting: true })
const migrationImportFile = ref(null)
const migrationLoading = ref(false)

function formatCurrency(rawValue) {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(Number(rawValue || 0))
}

function formatPercent(rawValue) {
  return `${Number(rawValue || 0).toFixed(1)}%`
}

function formatInteger(rawValue) {
  return String(Math.round(Number(rawValue || 0)))
}

function roundMoney(value) {
  return Math.round(Number(value || 0) * 100) / 100
}

function sumSeriesValues(series) {
  return series.reduce((acc, val) => acc + Number(val || 0), 0)
}

function averageSeriesValue(series) {
  if (series.length === 0) return 0
  return roundMoney(sumSeriesValues(series) / series.length)
}

function getLastSeriesValue(series) {
  return series.length > 0 ? Number(series[series.length - 1] || 0) : 0
}

function getMaxSeriesValue(series) {
  return series.length > 0 ? Math.max(...series.map((v) => Number(v || 0))) : 0
}

function getMaxAbsoluteSeriesValue(series) {
  return series.length > 0 ? Math.max(...series.map((v) => Math.abs(Number(v || 0)))) : 0
}

function sumCountValues(series) {
  return series.reduce((acc, val) => acc + Math.round(Number(val || 0)), 0)
}

const kpiCards = computed(() => {
  if (!dashboardSummary.value) return []

  const s = dashboardSummary.value
  const expectedIncomeBrl = Number(s.expectedIncomeBrl || 0)
  const expectedExpenseBrl = Number(s.expectedExpenseBrl || 0)
  const realizedIncomeBrl = Number(s.realizedIncomeBrl || 0)
  const realizedExpenseBrl = Number(s.realizedExpenseBrl || 0)
  const incomeSettlement = expectedIncomeBrl > 0 ? roundMoney((realizedIncomeBrl / expectedIncomeBrl) * 100) : 0
  const expenseExecution = expectedExpenseBrl > 0 ? roundMoney((realizedExpenseBrl / expectedExpenseBrl) * 100) : 0

  return [
    { key: 'expectedNet', label: 'Saldo previsto', value: formatCurrency(s.expectedNetBrl), caption: 'Entradas previstas - saídas previstas', tone: Number(s.expectedNetBrl) >= 0 ? 'positive' : 'negative' },
    { key: 'realizedNet', label: 'Saldo realizado', value: formatCurrency(s.realizedNetBrl), caption: 'Entradas recebidas - pagamentos', tone: Number(s.realizedNetBrl) >= 0 ? 'positive' : 'negative' },
    { key: 'remaining', label: 'Saldo pendente', value: formatCurrency(s.remainingTotalBrl), caption: 'Valor ainda aberto', tone: 'warning' },
    { key: 'overdue', label: 'Atrasos', value: String(s.overdueEntriesCount || 0), caption: 'Lançamentos vencidos', tone: Number(s.overdueEntriesCount || 0) > 0 ? 'negative' : 'neutral' },
    { key: 'expectedIncome', label: 'Receita prevista', value: formatCurrency(expectedIncomeBrl), caption: 'Total de entradas previstas', tone: 'positive' },
    { key: 'expectedExpense', label: 'Despesa prevista', value: formatCurrency(expectedExpenseBrl), caption: 'Total de saídas previstas', tone: 'warning' },
    { key: 'accountsBalance', label: 'Saldo em contas', value: formatCurrency(s.accountsBalanceBrl), caption: 'Soma das contas bancárias', tone: Number(s.accountsBalanceBrl) >= 0 ? 'positive' : 'negative' },
    { key: 'incomeSettlement', label: 'Execução de recebimentos', value: formatPercent(incomeSettlement), caption: 'Percentual realizado', tone: incomeSettlement >= 100 ? 'positive' : 'neutral' },
    { key: 'expenseExecution', label: 'Execução de pagamentos', value: formatPercent(expenseExecution), caption: 'Percentual pago', tone: expenseExecution > 100 ? 'negative' : 'neutral' },
  ]
})

const cashflowSeries = computed(() => {
  const items = Array.isArray(dashboardCashflow.value) ? dashboardCashflow.value : []
  return {
    monthLabels: items.map((i) => formatDate(i.competenceMonth, '-')),
    expectedNetSeries: items.map((i) => Number(i.expectedNetBrl || 0)),
    realizedNetSeries: items.map((i) => Number(i.realizedNetBrl || 0)),
    expectedIncomeSeries: items.map((i) => Number(i.expectedIncomeBrl || 0)),
    expectedExpenseSeries: items.map((i) => Number(i.expectedExpenseBrl || 0)),
    realizedIncomeSeries: items.map((i) => Number(i.realizedIncomeBrl || 0)),
    realizedExpenseSeries: items.map((i) => Number(i.realizedExpenseBrl || 0)),
    netGapSeries: items.map((i) => roundMoney(Number(i.expectedNetBrl || 0) - Number(i.realizedNetBrl || 0))),
  }
})

const chartRows = computed(() => {
  const s = cashflowSeries.value
  if (s.monthLabels.length === 0) return []

  return [
    {
      key: 'cashflow-net', columns: 2, charts: [
        { key: 'expected-net', title: 'Saldo previsto por mês', points: s.expectedNetSeries, strokeColor: '#2563eb', summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(s.expectedNetSeries))}`, secondaryLabel: `Último mês: ${formatCurrency(getLastSeriesValue(s.expectedNetSeries))}` },
        { key: 'realized-net', title: 'Saldo realizado por mês', points: s.realizedNetSeries, strokeColor: '#16a34a', summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(s.realizedNetSeries))}`, secondaryLabel: `Último mês: ${formatCurrency(getLastSeriesValue(s.realizedNetSeries))}` },
      ],
    },
    {
      key: 'cashflow-expected', columns: 2, charts: [
        { key: 'expected-income', title: 'Receitas previstas', points: s.expectedIncomeSeries, strokeColor: '#0284c7', summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(s.expectedIncomeSeries))}`, secondaryLabel: `Média: ${formatCurrency(averageSeriesValue(s.expectedIncomeSeries))}` },
        { key: 'expected-expense', title: 'Despesas previstas', points: s.expectedExpenseSeries, strokeColor: '#f97316', summaryLabel: `Acumulado: ${formatCurrency(sumSeriesValues(s.expectedExpenseSeries))}`, secondaryLabel: `Média: ${formatCurrency(averageSeriesValue(s.expectedExpenseSeries))}` },
      ],
    },
    {
      key: 'cashflow-gap', columns: 1, charts: [
        { key: 'gap-net', title: 'Gap previsto × realizado', points: s.netGapSeries, strokeColor: '#7c3aed', summaryLabel: `Média: ${formatCurrency(averageSeriesValue(s.netGapSeries))}`, secondaryLabel: `Maior desvio: ${formatCurrency(getMaxAbsoluteSeriesValue(s.netGapSeries))}` },
      ],
    },
  ]
})

async function loadReportsData() {
  try {
    const [sumRes, flowRes, catRes] = await Promise.all([
      fetchFinanceDashboardSummary(),
      fetchFinanceDashboardCashflow(),
      fetchFinanceDashboardCategories(),
    ])
    dashboardSummary.value = sumRes.data?.item || null
    dashboardCashflow.value = flowRes.data?.items || []
    dashboardCategories.value = catRes.data?.items || []
  } catch (err) {
    console.error('[FinanceReportsView] loadReportsData error:', err)
  }
}

async function submitExport() {
  try {
    await createFinanceExport({ exportType: exportForm.exportType })
    notifyUser('Relatório CSV gerado com sucesso.', 'success')
  } catch (err) {
    notifyUser('Erro ao gerar relatório.', 'error')
  }
}

function onMigrationImportFileChange(event) {
  const file = event.target?.files?.[0] || null
  migrationImportFile.value = file
}

async function downloadFinanceMigrationSnapshot() {
  migrationLoading.value = true
  try {
    const res = await fetchFinanceMigrationSnapshot()
    const snapshotData = res.data?.item || res.data || {}
    const blob = new Blob([JSON.stringify(snapshotData, null, 2)], { type: 'application/json;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `octoflow-finance-backup-${new Date().toISOString().slice(0, 10)}.json`
    anchor.click()
    URL.revokeObjectURL(url)
    notifyUser('Snapshot exportado com sucesso.', 'success')
  } catch (err) {
    notifyUser('Erro ao exportar snapshot.', 'error')
  } finally {
    migrationLoading.value = false
  }
}

async function submitFinanceMigrationImport() {
  if (!migrationImportFile.value) return
  migrationLoading.value = true
  try {
    const fileContent = await migrationImportFile.value.text()
    const parsedPayload = JSON.parse(fileContent)
    await importFinanceMigrationSnapshot({
      replaceExisting: migrationForm.replaceExisting,
      snapshot: parsedPayload,
    })
    notifyUser('Dados importados com sucesso!', 'success')
    migrationImportFile.value = null
    await loadReportsData()
  } catch (err) {
    notifyUser('Erro ao importar dados.', 'error')
  } finally {
    migrationLoading.value = false
  }
}

onMounted(() => {
  loadReportsData()
})
</script>

<template>
  <section class="finance-section finance-dashboard-section">
    <!-- KPI Cards -->
    <div class="finance-kpi-grid">
      <RemoteFinanceKpiCard
        v-for="card in kpiCards"
        :key="card.key"
        :label="card.label"
        :value="card.value"
        :caption="card.caption"
        :tone="card.tone"
      />
    </div>

    <!-- Fluxo Mensal -->
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
            <tr v-for="item in dashboardCashflow" :key="item.competenceMonth">
              <td>{{ formatDate(item.competenceMonth, '-') }}</td>
              <td>{{ formatCurrency(item.expectedIncomeBrl) }}</td>
              <td>{{ formatCurrency(item.expectedExpenseBrl) }}</td>
              <td>{{ formatCurrency(item.expectedNetBrl) }}</td>
              <td>{{ formatCurrency(item.realizedIncomeBrl) }}</td>
              <td>{{ formatCurrency(item.realizedExpenseBrl) }}</td>
              <td>{{ formatCurrency(item.realizedNetBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <RemoteFinanceEmptyState v-else title="Sem dados de fluxo" description="Cadastre lançamentos para visualizar o fluxo de caixa mensal." />
    </article>

    <!-- Análise por categoria -->
    <article class="finance-panel">
      <header>
        <h3>Análise por categoria</h3>
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
            <tr v-for="cat in dashboardCategories" :key="`${cat.categoryId || 'none'}-${cat.categoryName}`">
              <td>{{ cat.categoryName }}</td>
              <td>{{ formatInteger(cat.entriesCount) }}</td>
              <td>{{ formatCurrency(cat.expectedIncomeBrl) }}</td>
              <td>{{ formatCurrency(cat.expectedExpenseBrl) }}</td>
              <td>{{ formatCurrency(cat.expectedNetBrl) }}</td>
              <td>{{ formatCurrency(cat.realizedIncomeBrl) }}</td>
              <td>{{ formatCurrency(cat.realizedExpenseBrl) }}</td>
              <td>{{ formatCurrency(cat.realizedNetBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <RemoteFinanceEmptyState v-else title="Sem categorias no período" description="Nenhum lançamento encontrado para análise por categoria." />
    </article>

    <!-- Mini Charts de tendência -->
    <template v-for="row in chartRows" :key="row.key">
      <div class="finance-dashboard-chart-row" :class="{ 'finance-dashboard-chart-row-double': row.columns === 2 }">
        <article v-for="chart in row.charts" :key="chart.key" class="finance-panel">
          <header>
            <h3>{{ chart.title }}</h3>
            <small>{{ chart.summaryLabel }}</small>
          </header>
          <RemoteFinanceTrendMiniChart :points="chart.points" :stroke-color="chart.strokeColor" />
          <small>{{ chart.secondaryLabel }}</small>
        </article>
      </div>
    </template>
  </section>

  <!-- Exportação CSV -->
  <section class="finance-section">
    <article class="finance-panel">
      <header>
        <h3>Exportar relatório (CSV)</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitExport">
        <label>
          <span>Tipo de exportação</span>
          <select v-model="exportForm.exportType">
            <option v-for="opt in exportTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </label>
        <button class="finance-action-button" type="submit">Gerar e baixar CSV</button>
      </form>
    </article>

    <!-- Migração JSON -->
    <article class="finance-panel">
      <header>
        <h3>Migração completa (JSON)</h3>
        <small>Exporte ou importe todo o histórico financeiro.</small>
      </header>

      <div class="finance-migration-grid">
        <div class="finance-migration-card">
          <div>
            <h4 class="finance-migration-title">Exportar dados</h4>
            <p class="finance-migration-desc">Gere um snapshot para backup, análises externas ou migrações seguras.</p>
          </div>
          <button type="button" class="finance-action-button" :disabled="migrationLoading" @click="downloadFinanceMigrationSnapshot">
            {{ migrationLoading ? 'Gerando JSON...' : 'Exportar snapshot JSON' }}
          </button>
        </div>

        <form class="finance-migration-card" @submit.prevent="submitFinanceMigrationImport">
          <div>
            <h4 class="finance-migration-title">Importar dados</h4>
            <p class="finance-migration-desc">Restaure um histórico a partir de um arquivo JSON exportado.</p>
          </div>

          <div class="finance-migration-file-area">
            <label class="finance-action-button finance-migration-file-btn">
              <span>+ Escolher arquivo .json</span>
              <input type="file" accept=".json,application/json" class="hidden" @change="onMigrationImportFileChange">
            </label>
            <input v-if="migrationImportFile" type="text" :value="migrationImportFile.name" disabled class="finance-migration-filename">
          </div>

          <label class="finance-migration-toggle">
            <span>Substituir dados atuais da conta</span>
            <input v-model="migrationForm.replaceExisting" type="checkbox">
          </label>

          <button class="finance-action-button" type="submit" :disabled="migrationLoading || !migrationImportFile">
            {{ migrationLoading ? 'Importando...' : 'Importar snapshot JSON' }}
          </button>
        </form>
      </div>
    </article>
  </section>
</template>
