import { ref } from 'vue'
import {
  fetchFinanceBankAccounts,
  fetchFinanceDashboardCashflow,
  fetchFinanceDashboardCategories,
  fetchFinanceDashboardSummary,
  fetchFinanceDebtPlans,
  fetchFinanceEntries,
  fetchFinanceInstallmentPlans,
  fetchFinanceRecurringRules,
} from '../services/finance'
import { fetchFinanceInvestmentPlans } from '../services/financeInvestments'
import { buildCurrentMonthDateRange } from '../utils/date'
import { extractHttpMessage } from '../utils/httpErrors'

function resolveMessage(translate, messageKey, fallbackMessage) {
  if (typeof translate !== 'function') {
    return fallbackMessage
  }

  const translatedMessage = translate(messageKey)
  return translatedMessage === messageKey ? fallbackMessage : translatedMessage
}

export function useDashboardFinanceData({
  currentUserRef,
  activeFinancialDirectionRef,
  activeFinancialGroupLabelRef,
  translate,
  handleInfoStatus,
}) {
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

  let financeRequestVersion = 0
  let financeComponentActive = true

  function isRequestStale(requestVersion) {
    return !financeComponentActive || requestVersion !== financeRequestVersion
  }

  function setFinanceComponentActive(isComponentActive) {
    financeComponentActive = Boolean(isComponentActive)

    if (!financeComponentActive) {
      financeRequestVersion += 1
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
    if (!currentUserRef?.value?.id) {
      return
    }

    const requestVersion = ++financeRequestVersion

    financeLoading.value = true
    financeErrorMessage.value = ''

    try {
      const financialDirection = activeFinancialDirectionRef.value
      const currentMonthDateRange = buildCurrentMonthDateRange()

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
        fetchFinanceDashboardSummary(currentMonthDateRange),
        fetchFinanceDashboardSummary({ ...currentMonthDateRange, direction: financialDirection }),
        fetchFinanceDashboardCashflow(),
        fetchFinanceDashboardCashflow({ direction: financialDirection }),
        fetchFinanceDashboardCategories({ limit: 10 }),
        fetchFinanceDashboardCategories({ direction: financialDirection, limit: 10 }),
        fetchFinanceEntries({ direction: financialDirection }, { page: 1, itemsPerPage: 10, sort: 'dueDate:asc' }),
        fetchFinanceBankAccounts(),
        fetchFinanceRecurringRules(),
        fetchFinanceInstallmentPlans(),
        fetchFinanceDebtPlans(),
        fetchFinanceInvestmentPlans(),
      ])

      if (isRequestStale(requestVersion)) {
        return
      }

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

      if (showStatus && typeof handleInfoStatus === 'function') {
        const activeGroupLabel = String(activeFinancialGroupLabelRef.value || '').toLowerCase()
        handleInfoStatus(
          resolveMessage(
            translate,
            'dashboard.finance.status.updatedByGroup',
            `Dashboard financeiro atualizado para ${activeGroupLabel}.`,
          ).replace('{groupLabel}', activeGroupLabel),
        )
      }
    } catch (requestError) {
      if (isRequestStale(requestVersion)) {
        return
      }

      financeErrorMessage.value = extractHttpMessage(
        requestError,
        resolveMessage(
          translate,
          'dashboard.finance.errors.loadDashboard',
          'Nao foi possivel carregar o dashboard financeiro.',
        ),
      )
    } finally {
      if (!isRequestStale(requestVersion)) {
        financeLoading.value = false
      }
    }
  }

  return {
    financeLoading,
    financeLoaded,
    financeErrorMessage,
    financeSummaryGlobal,
    financeSummaryByGroup,
    financeCashflowGlobal,
    financeCashflowByGroup,
    financeCategoriesGlobal,
    financeCategoriesByGroup,
    financeEntries,
    financeEntriesMeta,
    financeBankAccounts,
    financeRecurringRules,
    financeInstallmentPlans,
    financeDebtPlans,
    financeInvestmentPlans,
    loadFinanceDashboard,
    resetFinanceState,
    setFinanceComponentActive,
  }
}
