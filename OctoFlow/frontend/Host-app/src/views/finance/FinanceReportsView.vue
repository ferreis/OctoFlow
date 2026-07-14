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
} from 'vue'
import FinancePageHeader from '../../components/finance/FinancePageHeader.vue'
import { FINANCE_EXPORT_TYPE_OPTIONS } from '../../constants/financeTerms'
import { useFinancePermissions } from '../../composables/useFinancePermissions'
import { useNotification } from '../../composables/useNotification'
import { useScopedI18n } from '../../composables/useScopedI18n'
import {
  RemoteFinanceEmptyState,
  RemoteFinanceKpiCard,
  RemoteFinanceTrendMiniChart,
} from '../../federation/remoteComponents'
import {
  createFinanceExport,
  fetchFinanceDashboardCashflow,
  fetchFinanceDashboardCategories,
  fetchFinanceDashboardSummary,
  fetchFinanceEntries,
  fetchFinanceExports,
  fetchFinanceRecurringRules,
  fetchFinanceMigrationSnapshot,
  generateFinanceRecurringRuleManually,
  importFinanceMigrationSnapshot,
} from '../../services/finance'
import { useSessionStore } from '../../stores/sessionStore'
import { useFinanceStore } from '../../stores/financeStore'
import { formatDate } from '../../utils/date'
import { extractHttpMessage } from '../../utils/httpErrors'
import {
  sanitizeSingleLineText,
  sanitizeToggle,
} from '../../utils/financeInputSanitizers'

const MAX_MIGRATION_FILE_SIZE_BYTES = 5 * 1024 * 1024
const EXPORT_DOWNLOAD_POLL_ATTEMPTS = 10
const EXPORT_DOWNLOAD_POLL_INTERVAL_MS = 500
const MIGRATION_IMPORT_CONFIRMATION_PHRASE = 'IMPORTAR SNAPSHOT FINANCEIRO'

const financeStore = useFinanceStore()
const sessionStore = useSessionStore()
const { notifyUser } = useNotification()
const { translateScoped, currentLocale } = useScopedI18n('financeModule.reportsView')
const { canWriteFinance } = useFinancePermissions()

const dashboardSummary = ref(null)
const dashboardCashflow = ref([])
const dashboardCategories = ref([])
const loadingReports = ref(false)
const refreshingReports = ref(false)
const financeViewIsActive = ref(false)
const kpiCountBeforeDomUpdate = ref(0)

const exportTypeOptions = FINANCE_EXPORT_TYPE_OPTIONS
const exportingCsv = ref(false)
const exportForm = reactive({
  exportType: 'MONTHLY_SUMMARY',
})

const migrationForm = reactive({
  replaceExisting: true,
  confirmationPhrase: '',
})
const migrationImportFile = ref(null)
const migrationLoading = ref(false)
const migrationFileInputRef = ref(null)
const latestDownloadObjectUrl = ref('')
const localeForFormatting = computed(() => (
  String(currentLocale.value || '').toLowerCase() === 'en-us' ? 'en-US' : 'pt-BR'
))
const migrationImportRequiresConfirmation = computed(() => sanitizeToggle(migrationForm.replaceExisting))
const migrationConfirmationMatches = computed(() => {
  if (!migrationImportRequiresConfirmation.value) {
    return true
  }

  return String(migrationForm.confirmationPhrase || '').trim() === MIGRATION_IMPORT_CONFIRMATION_PHRASE
})
const migrationImportSubmitDisabled = computed(() => (
  migrationLoading.value
  || !migrationImportFile.value
  || !canWriteFinance.value
  || !migrationConfirmationMatches.value
))

function translateChart(key, fallbackMessage, variables = {}) {
  return translateScoped(`charts.${key}`, fallbackMessage, variables)
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

function formatInteger(rawValue) {
  return String(Math.round(Number(rawValue || 0)))
}

function roundMoney(rawValue) {
  return Math.round(Number(rawValue || 0) * 100) / 100
}

function sumSeriesValues(series = []) {
  return series.reduce((accumulator, value) => accumulator + Number(value || 0), 0)
}

function averageSeriesValue(series = []) {
  if (series.length === 0) {
    return 0
  }

  return roundMoney(sumSeriesValues(series) / series.length)
}

function getLastSeriesValue(series = []) {
  return series.length > 0 ? Number(series[series.length - 1] || 0) : 0
}

function getMaxAbsoluteSeriesValue(series = []) {
  return series.length > 0 ? Math.max(...series.map((value) => Math.abs(Number(value || 0)))) : 0
}

const kpiCards = computed(() => {
  if (!dashboardSummary.value) {
    return []
  }

  const summary = dashboardSummary.value
  const expectedIncomeBrl = Number(summary.expectedIncomeBrl || 0)
  const expectedExpenseBrl = Number(summary.expectedExpenseBrl || 0)
  const realizedIncomeBrl = Number(summary.realizedIncomeBrl || 0)
  const realizedExpenseBrl = Number(summary.realizedExpenseBrl || 0)

  const incomeSettlementPercent = expectedIncomeBrl > 0
    ? roundMoney((realizedIncomeBrl / expectedIncomeBrl) * 100)
    : 0

  const expenseExecutionPercent = expectedExpenseBrl > 0
    ? roundMoney((realizedExpenseBrl / expectedExpenseBrl) * 100)
    : 0

  return [
    {
      key: 'expected-net',
      label: translateScoped('kpis.expectedNet.label', 'Saldo previsto'),
      value: formatCurrency(summary.expectedNetBrl),
      caption: translateScoped('kpis.expectedNet.caption', 'Entradas previstas - saídas previstas'),
      tone: Number(summary.expectedNetBrl) >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'realized-net',
      label: translateScoped('kpis.realizedNet.label', 'Saldo realizado'),
      value: formatCurrency(summary.realizedNetBrl),
      caption: translateScoped('kpis.realizedNet.caption', 'Entradas recebidas - pagamentos'),
      tone: Number(summary.realizedNetBrl) >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'remaining',
      label: translateScoped('kpis.remaining.label', 'Saldo pendente'),
      value: formatCurrency(summary.remainingTotalBrl),
      caption: translateScoped('kpis.remaining.caption', 'Valor ainda aberto'),
      tone: 'warning',
    },
    {
      key: 'overdue',
      label: translateScoped('kpis.overdue.label', 'Atrasos'),
      value: String(summary.overdueEntriesCount || 0),
      caption: translateScoped('kpis.overdue.caption', 'Lançamentos vencidos'),
      tone: Number(summary.overdueEntriesCount || 0) > 0 ? 'negative' : 'neutral',
    },
    {
      key: 'expected-income',
      label: translateScoped('kpis.expectedIncome.label', 'Receita prevista'),
      value: formatCurrency(expectedIncomeBrl),
      caption: translateScoped('kpis.expectedIncome.caption', 'Total de entradas previstas'),
      tone: 'positive',
    },
    {
      key: 'expected-expense',
      label: translateScoped('kpis.expectedExpense.label', 'Despesa prevista'),
      value: formatCurrency(expectedExpenseBrl),
      caption: translateScoped('kpis.expectedExpense.caption', 'Total de saídas previstas'),
      tone: 'warning',
    },
    {
      key: 'accounts-balance',
      label: translateScoped('kpis.accountsBalance.label', 'Saldo em contas'),
      value: formatCurrency(summary.accountsBalanceBrl),
      caption: translateScoped('kpis.accountsBalance.caption', 'Soma das contas bancárias'),
      tone: Number(summary.accountsBalanceBrl) >= 0 ? 'positive' : 'negative',
    },
    {
      key: 'income-settlement',
      label: translateScoped('kpis.incomeSettlement.label', 'Execução de recebimentos'),
      value: formatPercent(incomeSettlementPercent),
      caption: translateScoped('kpis.incomeSettlement.caption', 'Percentual realizado'),
      tone: incomeSettlementPercent >= 100 ? 'positive' : 'neutral',
    },
    {
      key: 'expense-execution',
      label: translateScoped('kpis.expenseExecution.label', 'Execução de pagamentos'),
      value: formatPercent(expenseExecutionPercent),
      caption: translateScoped('kpis.expenseExecution.caption', 'Percentual pago'),
      tone: expenseExecutionPercent > 100 ? 'negative' : 'neutral',
    },
  ]
})

const cashflowSeries = computed(() => {
  const items = Array.isArray(dashboardCashflow.value) ? dashboardCashflow.value : []

  return {
    monthLabels: items.map((item) => formatDate(item.competenceMonth, '-')),
    expectedNetSeries: items.map((item) => Number(item.expectedNetBrl || 0)),
    realizedNetSeries: items.map((item) => Number(item.realizedNetBrl || 0)),
    expectedIncomeSeries: items.map((item) => Number(item.expectedIncomeBrl || 0)),
    expectedExpenseSeries: items.map((item) => Number(item.expectedExpenseBrl || 0)),
    realizedIncomeSeries: items.map((item) => Number(item.realizedIncomeBrl || 0)),
    realizedExpenseSeries: items.map((item) => Number(item.realizedExpenseBrl || 0)),
    netGapSeries: items.map((item) => roundMoney(Number(item.expectedNetBrl || 0) - Number(item.realizedNetBrl || 0))),
  }
})

const chartRows = computed(() => {
  const series = cashflowSeries.value
  if (series.monthLabels.length === 0) {
    return []
  }

  return [
    {
      key: 'net-series',
      columns: 2,
      charts: [
        {
          key: 'expected-net',
          title: translateChart('expectedNet.title', 'Saldo previsto por mês'),
          points: series.expectedNetSeries,
          strokeColor: '#2563eb',
          summaryLabel: translateChart('expectedNet.summary', 'Acumulado: {value}', {
            value: formatCurrency(sumSeriesValues(series.expectedNetSeries)),
          }),
          secondaryLabel: translateChart('expectedNet.secondary', 'Último mês: {value}', {
            value: formatCurrency(getLastSeriesValue(series.expectedNetSeries)),
          }),
        },
        {
          key: 'realized-net',
          title: translateChart('realizedNet.title', 'Saldo realizado por mês'),
          points: series.realizedNetSeries,
          strokeColor: '#16a34a',
          summaryLabel: translateChart('realizedNet.summary', 'Acumulado: {value}', {
            value: formatCurrency(sumSeriesValues(series.realizedNetSeries)),
          }),
          secondaryLabel: translateChart('realizedNet.secondary', 'Último mês: {value}', {
            value: formatCurrency(getLastSeriesValue(series.realizedNetSeries)),
          }),
        },
      ],
    },
    {
      key: 'expected-series',
      columns: 2,
      charts: [
        {
          key: 'expected-income',
          title: translateChart('expectedIncome.title', 'Receitas previstas'),
          points: series.expectedIncomeSeries,
          strokeColor: '#0284c7',
          summaryLabel: translateChart('expectedIncome.summary', 'Acumulado: {value}', {
            value: formatCurrency(sumSeriesValues(series.expectedIncomeSeries)),
          }),
          secondaryLabel: translateChart('expectedIncome.secondary', 'Média: {value}', {
            value: formatCurrency(averageSeriesValue(series.expectedIncomeSeries)),
          }),
        },
        {
          key: 'expected-expense',
          title: translateChart('expectedExpense.title', 'Despesas previstas'),
          points: series.expectedExpenseSeries,
          strokeColor: '#f97316',
          summaryLabel: translateChart('expectedExpense.summary', 'Acumulado: {value}', {
            value: formatCurrency(sumSeriesValues(series.expectedExpenseSeries)),
          }),
          secondaryLabel: translateChart('expectedExpense.secondary', 'Média: {value}', {
            value: formatCurrency(averageSeriesValue(series.expectedExpenseSeries)),
          }),
        },
      ],
    },
    {
      key: 'gap-series',
      columns: 1,
      charts: [
        {
          key: 'net-gap',
          title: translateChart('netGap.title', 'Gap previsto × realizado'),
          points: series.netGapSeries,
          strokeColor: '#7c3aed',
          summaryLabel: translateChart('netGap.summary', 'Média: {value}', {
            value: formatCurrency(averageSeriesValue(series.netGapSeries)),
          }),
          secondaryLabel: translateChart('netGap.secondary', 'Maior desvio: {value}', {
            value: formatCurrency(getMaxAbsoluteSeriesValue(series.netGapSeries)),
          }),
        },
      ],
    },
  ]
})

function resolveExportType() {
  const normalizedExportType = String(exportForm.exportType || '').trim().toUpperCase()
  if (exportTypeOptions.some((option) => option.value === normalizedExportType)) {
    return normalizedExportType
  }

  return 'MONTHLY_SUMMARY'
}

function resetMigrationImport() {
  migrationImportFile.value = null
  migrationForm.confirmationPhrase = ''
  if (migrationFileInputRef.value) {
    migrationFileInputRef.value.value = ''
  }
}

function revokeLatestDownloadUrl() {
  if (latestDownloadObjectUrl.value) {
    URL.revokeObjectURL(latestDownloadObjectUrl.value)
    latestDownloadObjectUrl.value = ''
  }
}

function waitFor(milliseconds) {
  return new Promise((resolve) => {
    setTimeout(resolve, Math.max(0, Number(milliseconds || 0)))
  })
}

function toMonthDateRange(monthOffset = 0) {
  const referenceDate = new Date()
  const monthStartDate = new Date(referenceDate.getFullYear(), referenceDate.getMonth() + monthOffset, 1)
  const monthEndDate = new Date(referenceDate.getFullYear(), referenceDate.getMonth() + monthOffset + 1, 0)

  const monthStartYear = monthStartDate.getFullYear()
  const monthStartMonth = String(monthStartDate.getMonth() + 1).padStart(2, '0')
  const monthStartDay = String(monthStartDate.getDate()).padStart(2, '0')

  const monthEndYear = monthEndDate.getFullYear()
  const monthEndMonth = String(monthEndDate.getMonth() + 1).padStart(2, '0')
  const monthEndDay = String(monthEndDate.getDate()).padStart(2, '0')

  return {
    startDate: `${monthStartYear}-${monthStartMonth}-${monthStartDay}`,
    endDate: `${monthEndYear}-${monthEndMonth}-${monthEndDay}`,
    competenceMonth: `${monthStartYear}-${monthStartMonth}-01`,
  }
}

async function countEntriesInMonthRange(monthDateRange) {
  const response = await fetchFinanceEntries({
    startDate: monthDateRange.startDate,
    endDate: monthDateRange.endDate,
  }, {
    page: 1,
    itemsPerPage: 1,
  })

  return Number(response.data?.meta?.total || 0)
}

async function generateRecurringEntriesForNextMonthIfNeeded() {
  const currentMonthRange = toMonthDateRange(0)
  const nextMonthRange = toMonthDateRange(1)

  const [currentMonthEntriesCount, nextMonthEntriesCount] = await Promise.all([
    countEntriesInMonthRange(currentMonthRange),
    countEntriesInMonthRange(nextMonthRange),
  ])

  if (currentMonthEntriesCount <= 0) {
    return { generatedEntriesCount: 0, reason: 'CURRENT_MONTH_EMPTY' }
  }

  if (nextMonthEntriesCount > 0) {
    return { generatedEntriesCount: 0, reason: 'NEXT_MONTH_ALREADY_HAS_ENTRIES' }
  }

  const recurringRulesResponse = await fetchFinanceRecurringRules()
  const recurringRules = Array.isArray(recurringRulesResponse.data?.items) ? recurringRulesResponse.data.items : []
  const activeRecurringRules = recurringRules.filter((recurringRule) => Boolean(recurringRule?.isActive))

  if (activeRecurringRules.length <= 0) {
    return { generatedEntriesCount: 0, reason: 'NO_ACTIVE_RULES' }
  }

  let generatedEntriesCount = 0

  for (const recurringRule of activeRecurringRules) {
    const recurringRuleId = Number(recurringRule?.id || 0)
    if (!Number.isFinite(recurringRuleId) || recurringRuleId <= 0) {
      continue
    }

    const response = await generateFinanceRecurringRuleManually(recurringRuleId, {
      competenceMonth: nextMonthRange.competenceMonth,
    })

    generatedEntriesCount += Number(response.data?.item?.generatedCount || 0)
  }

  return { generatedEntriesCount, reason: generatedEntriesCount > 0 ? 'GENERATED' : 'NO_GENERATION_NEEDED' }
}

async function refreshReportsAndGenerateRecurring() {
  if (refreshingReports.value) {
    return
  }

  refreshingReports.value = true

  try {
    let generationResult = { generatedEntriesCount: 0, reason: 'READ_ONLY' }

    if (canWriteFinance.value) {
      generationResult = await generateRecurringEntriesForNextMonthIfNeeded()
    }

    await loadReportsData(false)

    if (!financeViewIsActive.value) {
      return
    }

    if (!canWriteFinance.value) {
      notifyUser(translateScoped('notifications.refreshSuccess', 'Relatórios atualizados com sucesso.'), 'success')
      return
    }

    if (generationResult.generatedEntriesCount > 0) {
      notifyUser(translateScoped(
        'notifications.refreshGenerated',
        'Relatórios atualizados e {count} lançamento(s) recorrente(s) gerado(s) para o próximo mês.',
        { count: String(generationResult.generatedEntriesCount) },
      ), 'success')
      return
    }

    if (generationResult.reason === 'NEXT_MONTH_ALREADY_HAS_ENTRIES') {
      notifyUser(translateScoped(
        'notifications.refreshNoGenerationNeeded',
        'Relatórios atualizados. O próximo mês já possui lançamentos.',
      ), 'info')
      return
    }

    if (generationResult.reason === 'CURRENT_MONTH_EMPTY') {
      notifyUser(translateScoped(
        'notifications.refreshCurrentMonthEmpty',
        'Relatórios atualizados. Não há lançamentos no mês atual para comparar.',
      ), 'info')
      return
    }

    if (generationResult.reason === 'NO_GENERATION_NEEDED') {
      notifyUser(translateScoped(
        'notifications.refreshNoGenerationNeeded',
        'Relatórios atualizados. O próximo mês já possui lançamentos.',
      ), 'info')
      return
    }

    notifyUser(translateScoped(
      'notifications.refreshNoActiveRules',
      'Relatórios atualizados. Nenhuma regra recorrente ativa disponível para gerar lançamentos.',
    ), 'info')
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.refreshError', 'Erro ao atualizar relatórios.')), 'error')
  } finally {
    refreshingReports.value = false
  }
}

async function findExportJobById(exportJobId) {
  const normalizedExportJobId = Number(exportJobId || 0)
  if (!Number.isFinite(normalizedExportJobId) || normalizedExportJobId <= 0) {
    return null
  }

  const response = await fetchFinanceExports({
    page: 1,
    itemsPerPage: 20,
  })

  const exportItems = Array.isArray(response.data?.items) ? response.data.items : []
  return exportItems.find((exportItem) => Number(exportItem?.id || 0) === normalizedExportJobId) || null
}

async function downloadExportJobFile(exportJob) {
  const exportJobId = Number(exportJob?.id || 0)
  if (!Number.isFinite(exportJobId) || exportJobId <= 0) {
    return false
  }

  const response = await sessionStore.authRequest({
    url: `/finance/exports/${encodeURIComponent(exportJobId)}/download`,
    method: 'GET',
    responseType: 'blob',
  })

  if (typeof document === 'undefined') {
    return false
  }

  const downloadBlob = response.data instanceof Blob
    ? response.data
    : new Blob([response.data])

  const downloadUrl = URL.createObjectURL(downloadBlob)
  const downloadAnchor = document.createElement('a')
  downloadAnchor.href = downloadUrl
  downloadAnchor.download = String(exportJob?.fileName || `finance-export-${exportJobId}.xlsx`)
  document.body.appendChild(downloadAnchor)
  downloadAnchor.click()
  downloadAnchor.remove()
  URL.revokeObjectURL(downloadUrl)

  return true
}

async function tryAutoDownloadCreatedExport(exportJobId) {
  for (let attemptIndex = 0; attemptIndex < EXPORT_DOWNLOAD_POLL_ATTEMPTS; attemptIndex += 1) {
    const exportJob = await findExportJobById(exportJobId)
    const exportStatus = String(exportJob?.status || '').trim().toUpperCase()

    if (exportStatus === 'DONE') {
      const downloadStarted = await downloadExportJobFile(exportJob)
      return downloadStarted ? 'DOWNLOADED' : 'DONE'
    }

    if (exportStatus === 'FAILED') {
      const exportErrorMessage = String(exportJob?.errorMessage || '').trim()
      throw new Error(exportErrorMessage || translateScoped('notifications.exportError', 'Erro ao gerar relatório CSV.'))
    }

    if (attemptIndex < EXPORT_DOWNLOAD_POLL_ATTEMPTS - 1) {
      await waitFor(EXPORT_DOWNLOAD_POLL_INTERVAL_MS)
    }
  }

  return 'PENDING'
}

async function loadReportsData(showNotificationOnError = false) {
  loadingReports.value = true

  try {
    const [summaryResponse, cashflowResponse, categoryResponse] = await Promise.all([
      fetchFinanceDashboardSummary(),
      fetchFinanceDashboardCashflow(),
      fetchFinanceDashboardCategories(),
    ])

    if (!financeViewIsActive.value) {
      return
    }

    dashboardSummary.value = summaryResponse.data?.item || null
    dashboardCashflow.value = Array.isArray(cashflowResponse.data?.items) ? cashflowResponse.data.items : []
    dashboardCategories.value = Array.isArray(categoryResponse.data?.items) ? categoryResponse.data.items : []
  } catch (error) {
    if (showNotificationOnError && financeViewIsActive.value) {
      notifyUser(extractHttpMessage(error, translateScoped('notifications.loadError', 'Erro ao carregar relatórios financeiros.')), 'error')
    }
  } finally {
    loadingReports.value = false
  }
}

async function submitExport() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para exportar dados financeiros.'), 'warning')
    return
  }

  if (exportingCsv.value) {
    return
  }

  exportingCsv.value = true

  try {
    const response = await createFinanceExport({ exportType: resolveExportType() })
    const createdExportJobId = Number(response.data?.item?.id || 0)

    if (!Number.isFinite(createdExportJobId) || createdExportJobId <= 0) {
      notifyUser(translateScoped('notifications.exportSuccess', 'Relatório CSV solicitado com sucesso.'), 'success')
      return
    }

    const autoDownloadResult = await tryAutoDownloadCreatedExport(createdExportJobId)
    if (!financeViewIsActive.value) {
      return
    }

    if (autoDownloadResult === 'DOWNLOADED') {
      notifyUser(translateScoped('notifications.exportDownloadSuccess', 'Exportação pronta. Download iniciado com sucesso.'), 'success')
      return
    }

    notifyUser(translateScoped('notifications.exportPending', 'Exportação solicitada. O arquivo estará disponível assim que o processamento terminar.'), 'info')
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.exportError', 'Erro ao gerar relatório CSV.')), 'error')
  } finally {
    exportingCsv.value = false
  }
}

function onMigrationImportFileChange(event) {
  const selectedFile = event.target?.files?.[0] || null
  if (!selectedFile) {
    migrationImportFile.value = null
    return
  }

  const normalizedFileName = sanitizeSingleLineText(selectedFile.name, 120).toLowerCase()
  if (!normalizedFileName.endsWith('.json')) {
    migrationImportFile.value = null
    notifyUser(translateScoped('notifications.invalidFileType', 'Selecione um arquivo JSON válido.'), 'warning')
    return
  }

  if (selectedFile.size > MAX_MIGRATION_FILE_SIZE_BYTES) {
    migrationImportFile.value = null
    notifyUser(translateScoped('notifications.fileTooLarge', 'Arquivo excede o limite de 5 MB para importação.'), 'warning')
    return
  }

  migrationImportFile.value = selectedFile
}

async function downloadFinanceMigrationSnapshot() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para exportar dados financeiros.'), 'warning')
    return
  }

  if (migrationLoading.value) {
    return
  }

  migrationLoading.value = true

  try {
    const response = await fetchFinanceMigrationSnapshot()
    if (!financeViewIsActive.value) {
      return
    }

    const snapshotPayload = response.data?.item || response.data || {}

    revokeLatestDownloadUrl()

    const snapshotBlob = new Blob([
      JSON.stringify(snapshotPayload, null, 2),
    ], { type: 'application/json;charset=utf-8' })

    latestDownloadObjectUrl.value = URL.createObjectURL(snapshotBlob)

    if (typeof document === 'undefined') {
      return
    }

    const downloadAnchor = document.createElement('a')
    downloadAnchor.href = latestDownloadObjectUrl.value
    downloadAnchor.download = `octoflow-finance-backup-${new Date().toISOString().slice(0, 10)}.json`
    document.body.appendChild(downloadAnchor)
    downloadAnchor.click()
    downloadAnchor.remove()

    notifyUser(translateScoped('notifications.snapshotExportSuccess', 'Snapshot exportado com sucesso.'), 'success')
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.snapshotExportError', 'Erro ao exportar snapshot.')), 'error')
  } finally {
    migrationLoading.value = false
  }
}

async function submitFinanceMigrationImport() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para importar dados financeiros.'), 'warning')
    return
  }

  if (migrationLoading.value) {
    return
  }

  if (!migrationImportFile.value) {
    notifyUser(translateScoped('notifications.selectFileFirst', 'Selecione um arquivo JSON antes de importar.'), 'warning')
    return
  }

  const replaceExisting = sanitizeToggle(migrationForm.replaceExisting)
  if (replaceExisting && !migrationConfirmationMatches.value) {
    notifyUser(translateScoped(
      'notifications.confirmationRequired',
      'Digite a frase de confirmação exatamente como exibida antes de substituir os dados financeiros.',
    ), 'warning')
    return
  }

  migrationLoading.value = true

  try {
    const fileContent = await migrationImportFile.value.text()
    const parsedPayload = JSON.parse(fileContent)

    if (!parsedPayload || typeof parsedPayload !== 'object' || Array.isArray(parsedPayload)) {
      throw new Error(translateScoped('notifications.invalidJsonContent', 'Conteúdo JSON inválido para importação.'))
    }

    await importFinanceMigrationSnapshot({
      replaceExisting,
      confirmationPhrase: replaceExisting ? String(migrationForm.confirmationPhrase || '').trim() : '',
      snapshot: parsedPayload,
    })

    if (!financeViewIsActive.value) {
      return
    }

    notifyUser(translateScoped('notifications.snapshotImportSuccess', 'Dados importados com sucesso.'), 'success')
    resetMigrationImport()
    await loadReportsData(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.snapshotImportError', 'Erro ao importar snapshot financeiro.')), 'error')
  } finally {
    migrationLoading.value = false
  }
}

onBeforeMount(() => {
  void financeStore.loadCatalogs(false)
})

onMounted(() => {
  financeViewIsActive.value = true
  void loadReportsData(true)
})

onBeforeUpdate(() => {
  kpiCountBeforeDomUpdate.value = kpiCards.value.length
})

onUpdated(() => {
  if (kpiCountBeforeDomUpdate.value !== kpiCards.value.length) {
    exportForm.exportType = resolveExportType()
  }
})

onActivated(() => {
  financeViewIsActive.value = true
  void loadReportsData(false)
})

onDeactivated(() => {
  financeViewIsActive.value = false
  exportingCsv.value = false
  migrationLoading.value = false
  refreshingReports.value = false
})

onBeforeUnmount(() => {
  financeViewIsActive.value = false
  exportingCsv.value = false
  migrationLoading.value = false
  refreshingReports.value = false
  resetMigrationImport()
  revokeLatestDownloadUrl()
})

onUnmounted(() => {
  kpiCountBeforeDomUpdate.value = 0
  dashboardSummary.value = null
  dashboardCashflow.value = []
  dashboardCategories.value = []
})

onErrorCaptured((error) => {
  if (!financeViewIsActive.value) {
    return false
  }

  console.error('[FinanceReportsView] child render error:', error)
  notifyUser(translateScoped('notifications.childRenderError', 'Erro inesperado ao renderizar os relatórios.'), 'error')
  return false
})
</script>

<template>
  <section class="finance-section finance-dashboard-section">
    <FinancePageHeader
      eyebrow="Análise"
      title="Relatórios financeiros"
      description="Acompanhe o fluxo de caixa, as categorias e os indicadores que mostram a evolução do período."
    />

    <div class="finance-form-actions">
      <button type="button" class="finance-inline-action" :disabled="refreshingReports" @click="refreshReportsAndGenerateRecurring">
        {{ refreshingReports
          ? translateScoped('actions.refreshing', 'Atualizando...')
          : canWriteFinance
            ? translateScoped('actions.refreshAndGenerate', 'Atualizar e gerar recorrentes')
            : translateScoped('actions.refreshOnly', 'Atualizar relatórios') }}
      </button>
    </div>

    <div v-if="loadingReports && !dashboardCashflow.length && !dashboardCategories.length" class="finance-muted-block">
      {{ translateScoped('loading', 'Carregando relatórios financeiros...') }}
    </div>

    <div v-else class="finance-kpi-grid">
      <RemoteFinanceKpiCard
        v-for="card in kpiCards"
        :key="card.key"
        :label="card.label"
        :value="card.value"
        :caption="card.caption"
        :tone="card.tone"
      />
    </div>

    <article class="finance-panel">
      <header>
        <h3>{{ translateScoped('cashflow.title', 'Detalhamento do fluxo mensal') }}</h3>
      </header>

      <div v-if="dashboardCashflow.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('cashflow.columns.month', 'Mês') }}</th>
              <th>{{ translateScoped('cashflow.columns.expectedIncome', 'Receita prevista') }}</th>
              <th>{{ translateScoped('cashflow.columns.expectedExpense', 'Despesa prevista') }}</th>
              <th>{{ translateScoped('cashflow.columns.expectedNet', 'Saldo previsto') }}</th>
              <th>{{ translateScoped('cashflow.columns.realizedIncome', 'Receita realizada') }}</th>
              <th>{{ translateScoped('cashflow.columns.realizedExpense', 'Despesa realizada') }}</th>
              <th>{{ translateScoped('cashflow.columns.realizedNet', 'Saldo realizado') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in dashboardCashflow" :key="row.competenceMonth">
              <td>{{ formatDate(row.competenceMonth, '-') }}</td>
              <td>{{ formatCurrency(row.expectedIncomeBrl) }}</td>
              <td>{{ formatCurrency(row.expectedExpenseBrl) }}</td>
              <td>{{ formatCurrency(row.expectedNetBrl) }}</td>
              <td>{{ formatCurrency(row.realizedIncomeBrl) }}</td>
              <td>{{ formatCurrency(row.realizedExpenseBrl) }}</td>
              <td>{{ formatCurrency(row.realizedNetBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <RemoteFinanceEmptyState
        v-else
        :title="translateScoped('cashflow.empty.title', 'Sem dados de fluxo')"
        :description="translateScoped('cashflow.empty.description', 'Cadastre lançamentos para visualizar o fluxo de caixa mensal.')"
      />
    </article>

    <article class="finance-panel">
      <header>
        <h3>{{ translateScoped('categories.title', 'Análise por categoria') }}</h3>
      </header>

      <div v-if="dashboardCategories.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('categories.columns.name', 'Categoria') }}</th>
              <th>{{ translateScoped('categories.columns.entries', 'Lançamentos') }}</th>
              <th>{{ translateScoped('categories.columns.expectedIncome', 'Receita prevista') }}</th>
              <th>{{ translateScoped('categories.columns.expectedExpense', 'Despesa prevista') }}</th>
              <th>{{ translateScoped('categories.columns.expectedNet', 'Saldo previsto') }}</th>
              <th>{{ translateScoped('categories.columns.realizedIncome', 'Receita realizada') }}</th>
              <th>{{ translateScoped('categories.columns.realizedExpense', 'Despesa realizada') }}</th>
              <th>{{ translateScoped('categories.columns.realizedNet', 'Saldo realizado') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="category in dashboardCategories" :key="`${category.categoryId || 'none'}-${category.categoryName}`">
              <td>{{ category.categoryName }}</td>
              <td>{{ formatInteger(category.entriesCount) }}</td>
              <td>{{ formatCurrency(category.expectedIncomeBrl) }}</td>
              <td>{{ formatCurrency(category.expectedExpenseBrl) }}</td>
              <td>{{ formatCurrency(category.expectedNetBrl) }}</td>
              <td>{{ formatCurrency(category.realizedIncomeBrl) }}</td>
              <td>{{ formatCurrency(category.realizedExpenseBrl) }}</td>
              <td>{{ formatCurrency(category.realizedNetBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <RemoteFinanceEmptyState
        v-else
        :title="translateScoped('categories.empty.title', 'Sem categorias no período')"
        :description="translateScoped('categories.empty.description', 'Nenhum lançamento encontrado para análise por categoria.')"
      />
    </article>

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

  <section class="finance-section">
    <article class="finance-panel">
      <header>
        <h3>{{ translateScoped('exports.title', 'Exportar relatório (CSV)') }}</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitExport">
        <label>
          <span>{{ translateScoped('exports.fields.exportType', 'Tipo de exportação') }}</span>
          <select v-model="exportForm.exportType" :disabled="exportingCsv || !canWriteFinance">
            <option v-for="option in exportTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>
        <button class="finance-action-button" type="submit" :disabled="exportingCsv || !canWriteFinance">
          {{ exportingCsv
            ? translateScoped('exports.actions.generating', 'Gerando CSV...')
            : translateScoped('exports.actions.generate', 'Gerar e baixar CSV') }}
        </button>
      </form>
    </article>

    <article class="finance-panel">
      <header>
        <h3>{{ translateScoped('migration.title', 'Migração completa (JSON)') }}</h3>
        <small>{{ translateScoped('migration.subtitle', 'Exporte ou importe todo o histórico financeiro.') }}</small>
      </header>

      <div class="finance-migration-grid">
        <div class="finance-migration-card">
          <div>
            <h4 class="finance-migration-title">{{ translateScoped('migration.export.title', 'Exportar dados') }}</h4>
            <p class="finance-migration-desc">
              {{ translateScoped('migration.export.description', 'Gere um snapshot para backup, análises externas ou migrações seguras.') }}
            </p>
          </div>
          <button type="button" class="finance-action-button" :disabled="migrationLoading || !canWriteFinance" @click="downloadFinanceMigrationSnapshot">
            {{ migrationLoading
              ? translateScoped('migration.export.loading', 'Gerando JSON...')
              : translateScoped('migration.export.button', 'Exportar snapshot JSON') }}
          </button>
        </div>

        <form class="finance-migration-card" @submit.prevent="submitFinanceMigrationImport">
          <div>
            <h4 class="finance-migration-title">{{ translateScoped('migration.import.title', 'Importar dados') }}</h4>
            <p class="finance-migration-desc">
              {{ translateScoped('migration.import.description', 'Restaure um histórico a partir de um arquivo JSON exportado.') }}
            </p>
          </div>

          <div class="finance-migration-file-area">
            <label class="finance-action-button finance-migration-file-btn" :class="{ disabled: !canWriteFinance }">
              <span>{{ translateScoped('migration.import.chooseFile', '+ Escolher arquivo .json') }}</span>
              <input
                ref="migrationFileInputRef"
                type="file"
                accept=".json,application/json"
                class="hidden"
                :disabled="migrationLoading || !canWriteFinance"
                @change="onMigrationImportFileChange"
              >
            </label>
            <input
              v-if="migrationImportFile"
              type="text"
              :value="migrationImportFile.name"
              disabled
              class="finance-migration-filename"
            >
          </div>

          <label class="finance-migration-toggle">
            <span>{{ translateScoped('migration.import.replaceExisting', 'Substituir dados atuais da conta') }}</span>
            <input v-model="migrationForm.replaceExisting" type="checkbox" :disabled="migrationLoading || !canWriteFinance">
          </label>

          <label v-if="migrationImportRequiresConfirmation" class="finance-migration-confirmation">
            <span>{{ translateScoped('migration.import.confirmationLabel', 'Confirmação obrigatória') }}</span>
            <small>
              {{ translateScoped('migration.import.confirmationWarning', 'Esta importação substituirá todos os dados financeiros atuais da conta.') }}
            </small>
            <small>
              {{ translateScoped('migration.import.confirmationInstruction', 'Digite {phrase} para confirmar a substituição.', { phrase: MIGRATION_IMPORT_CONFIRMATION_PHRASE }) }}
            </small>
            <input
              v-model="migrationForm.confirmationPhrase"
              type="text"
              :placeholder="translateScoped('migration.import.confirmationPlaceholder', 'Digite a frase de confirmação')"
              autocomplete="off"
              :disabled="migrationLoading || !canWriteFinance"
              required
            >
          </label>

          <button class="finance-action-button" type="submit" :disabled="migrationImportSubmitDisabled">
            {{ migrationLoading
              ? translateScoped('migration.import.loading', 'Importando...')
              : translateScoped('migration.import.button', 'Importar snapshot JSON') }}
          </button>
        </form>
      </div>

      <p v-if="!canWriteFinance" class="finance-muted-block">
        {{ translateScoped('permissions.readOnlyHint', 'Sua conta está em modo de leitura para exportação e migração de dados.') }}
      </p>
    </article>
  </section>
</template>
