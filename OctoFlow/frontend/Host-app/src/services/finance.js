import { useSessionStore } from '../stores/sessionStore'

function requestWithSession(config) {
  const sessionStore = useSessionStore()
  return sessionStore.authRequest(config)
}

function buildPaginationParams(options = {}) {
  const params = {
    page: options.page ?? 1,
    itemsPerPage: options.itemsPerPage ?? 10,
  }

  if (options.sort) {
    params.sort = options.sort
  }

  if (options.direction) {
    params.direction = options.direction
  }

  return params
}

export function fetchFinanceCategories(params = {}) {
  return requestWithSession({
    url: '/finance/categories',
    method: 'GET',
    params,
  })
}

export function createFinanceCategory(payload) {
  return requestWithSession({
    url: '/finance/categories',
    method: 'POST',
    csrfActionId: 'finance.categories.create',
    data: payload,
  })
}

export function updateFinanceCategory(categoryId, payload) {
  return requestWithSession({
    url: `/finance/categories/${encodeURIComponent(categoryId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.categories.update',
    data: payload,
  })
}

export function fetchFinanceRecurringTypes() {
  return requestWithSession({
    url: '/finance/recurring-types',
    method: 'GET',
  })
}

export function createFinanceRecurringType(payload) {
  return requestWithSession({
    url: '/finance/recurring-types',
    method: 'POST',
    csrfActionId: 'finance.recurring-types.create',
    data: payload,
  })
}

export function updateFinanceRecurringType(recurringTypeId, payload) {
  return requestWithSession({
    url: `/finance/recurring-types/${encodeURIComponent(recurringTypeId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.recurring-types.update',
    data: payload,
  })
}

export function deleteFinanceRecurringType(recurringTypeId) {
  return requestWithSession({
    url: `/finance/recurring-types/${encodeURIComponent(recurringTypeId)}`,
    method: 'DELETE',
    csrfActionId: 'finance.recurring-types.delete',
  })
}

export function fetchFinanceBankAccounts() {
  return requestWithSession({
    url: '/finance/bank-accounts',
    method: 'GET',
  })
}

export function createFinanceBankAccount(payload) {
  return requestWithSession({
    url: '/finance/bank-accounts',
    method: 'POST',
    csrfActionId: 'finance.bank-accounts.create',
    data: payload,
  })
}

export function updateFinanceBankAccount(bankAccountId, payload) {
  return requestWithSession({
    url: `/finance/bank-accounts/${encodeURIComponent(bankAccountId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.bank-accounts.update',
    data: payload,
  })
}

export function updateFinanceBankAccountStatus(bankAccountId, payload) {
  return requestWithSession({
    url: `/finance/bank-accounts/${encodeURIComponent(bankAccountId)}/status`,
    method: 'PATCH',
    csrfActionId: 'finance.bank-accounts.status',
    data: payload,
  })
}

export function fetchFinanceEntries(filters = {}, pagination = {}) {
  return requestWithSession({
    url: '/finance/entries',
    method: 'GET',
    params: {
      ...filters,
      ...buildPaginationParams(pagination),
    },
  })
}

export function createFinanceEntry(payload) {
  return requestWithSession({
    url: '/finance/entries',
    method: 'POST',
    csrfActionId: 'finance.entries.create',
    data: payload,
  })
}

export function updateFinanceEntry(entryId, payload) {
  return requestWithSession({
    url: `/finance/entries/${encodeURIComponent(entryId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.entries.update',
    data: payload,
  })
}

export function deleteFinanceEntry(entryId) {
  return requestWithSession({
    url: `/finance/entries/${encodeURIComponent(entryId)}`,
    method: 'DELETE',
    csrfActionId: 'finance.entries.delete',
  })
}

export function createFinanceSettlement(entryId, payload) {
  return requestWithSession({
    url: `/finance/entries/${encodeURIComponent(entryId)}/settlements`,
    method: 'POST',
    csrfActionId: 'finance.entries.settlements.create',
    data: payload,
  })
}

export function fetchFinanceRecurringRules() {
  return requestWithSession({
    url: '/finance/recurring-rules',
    method: 'GET',
  })
}

export function createFinanceRecurringRule(payload) {
  return requestWithSession({
    url: '/finance/recurring-rules',
    method: 'POST',
    csrfActionId: 'finance.recurring-rules.create',
    data: payload,
  })
}

export function updateFinanceRecurringRule(ruleId, payload) {
  return requestWithSession({
    url: `/finance/recurring-rules/${encodeURIComponent(ruleId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.recurring-rules.update',
    data: payload,
  })
}

export function deleteFinanceRecurringRule(ruleId) {
  return requestWithSession({
    url: `/finance/recurring-rules/${encodeURIComponent(ruleId)}`,
    method: 'DELETE',
    csrfActionId: 'finance.recurring-rules.delete',
  })
}

export function generateFinanceRecurringRuleManually(ruleId, payload = {}) {
  return requestWithSession({
    url: `/finance/recurring-rules/${encodeURIComponent(ruleId)}/generate-manual`,
    method: 'POST',
    csrfActionId: 'finance.recurring-rules.generate-manual',
    data: payload,
  })
}

export function fetchFinanceInstallmentPlans() {
  return requestWithSession({
    url: '/finance/installment-plans',
    method: 'GET',
  })
}

export function createFinanceInstallmentPlan(payload) {
  return requestWithSession({
    url: '/finance/installment-plans',
    method: 'POST',
    csrfActionId: 'finance.installments.create',
    data: payload,
  })
}

export function updateFinanceInstallmentPlan(planId, payload) {
  return requestWithSession({
    url: `/finance/installment-plans/${encodeURIComponent(planId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.installments.update',
    data: payload,
  })
}

export function applyFinanceInstallmentPlanAdjustment(planId, payload) {
  return requestWithSession({
    url: `/finance/installment-plans/${encodeURIComponent(planId)}/adjustment`,
    method: 'POST',
    csrfActionId: 'finance.installments.adjustment',
    data: payload,
  })
}

export function fetchFinanceDebtPlans() {
  return requestWithSession({
    url: '/finance/debt-plans',
    method: 'GET',
  })
}

export function previewFinanceDebtPlan(payload) {
  return requestWithSession({
    url: '/finance/debt-plans/preview',
    method: 'POST',
    csrfActionId: 'finance.debt-plans.preview',
    data: payload,
  })
}

export function createFinanceDebtPlan(payload) {
  return requestWithSession({
    url: '/finance/debt-plans',
    method: 'POST',
    csrfActionId: 'finance.debt-plans.create',
    data: payload,
  })
}

export function deleteFinanceDebtPlan(debtPlanId) {
  return requestWithSession({
    url: `/finance/debt-plans/${encodeURIComponent(debtPlanId)}`,
    method: 'DELETE',
    csrfActionId: 'finance.debt-plans.delete',
  })
}

export function fetchFinanceDashboardSummary(params = {}) {
  return requestWithSession({
    url: '/finance/dashboard/summary',
    method: 'GET',
    params,
  })
}

export function fetchFinanceDashboardCashflow(params = {}) {
  return requestWithSession({
    url: '/finance/dashboard/cashflow',
    method: 'GET',
    params,
  })
}

export function fetchFinanceDashboardCategories(params = {}) {
  return requestWithSession({
    url: '/finance/dashboard/categories',
    method: 'GET',
    params,
  })
}

export function fetchFinanceCurrencies(params = {}) {
  return requestWithSession({
    url: '/finance/currencies',
    method: 'GET',
    params,
  })
}

export function fetchFinanceCurrencyRates(params = {}) {
  return requestWithSession({
    url: '/finance/currencies/rates',
    method: 'GET',
    params,
  })
}

export function createFinanceCurrencyRateManual(payload) {
  return requestWithSession({
    url: '/finance/currencies/rates/manual',
    method: 'POST',
    csrfActionId: 'finance.currencies.rates.manual.create',
    data: payload,
  })
}

export function createFinanceExport(payload) {
  return requestWithSession({
    url: '/finance/exports',
    method: 'POST',
    csrfActionId: 'finance.exports.create',
    data: payload,
  })
}

export function fetchFinanceExports(params = {}) {
  return requestWithSession({
    url: '/finance/exports',
    method: 'GET',
    params,
  })
}

export function deleteFinanceExport(exportJobId) {
  return requestWithSession({
    url: `/finance/exports/${encodeURIComponent(exportJobId)}`,
    method: 'DELETE',
    csrfActionId: 'finance.exports.delete',
  })
}

export function fetchFinanceMigrationSnapshot() {
  return requestWithSession({
    url: '/finance/migration/export',
    method: 'GET',
  })
}

export function importFinanceMigrationSnapshot(payload) {
  return requestWithSession({
    url: '/finance/migration/import',
    method: 'POST',
    csrfActionId: 'finance.migration.import',
    data: payload,
  })
}

export function fetchFinanceOpenFinanceProviders() {
  return requestWithSession({
    url: '/finance/open-finance/providers',
    method: 'GET',
  })
}

export function fetchFinanceOpenFinanceConnections() {
  return requestWithSession({
    url: '/finance/open-finance/connections',
    method: 'GET',
  })
}

export function createFinanceOpenFinanceConnection(payload) {
  return requestWithSession({
    url: '/finance/open-finance/connections',
    method: 'POST',
    csrfActionId: 'finance.open-finance.connections.create',
    data: payload,
  })
}

export function syncFinanceOpenFinanceConnection(connectionId, payload = {}) {
  return requestWithSession({
    url: `/finance/open-finance/connections/${encodeURIComponent(connectionId)}/sync`,
    method: 'POST',
    csrfActionId: 'finance.open-finance.connections.sync',
    data: payload,
  })
}

export function deleteFinanceOpenFinanceConnection(connectionId) {
  return requestWithSession({
    url: `/finance/open-finance/connections/${encodeURIComponent(connectionId)}`,
    method: 'DELETE',
    csrfActionId: 'finance.open-finance.connections.delete',
  })
}
