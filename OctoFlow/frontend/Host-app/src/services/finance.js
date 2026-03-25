function buildPaginationParams(options = {}) {
  const params = {
    page: options.page ?? 1,
    itemsPerPage: options.itemsPerPage ?? 20,
  }

  if (options.sort) {
    params.sort = options.sort
  }

  if (options.direction) {
    params.direction = options.direction
  }

  return params
}

export function fetchFinanceCategories(request, params = {}) {
  return request({
    url: '/finance/categories',
    method: 'GET',
    params,
  })
}

export function createFinanceCategory(request, payload) {
  return request({
    url: '/finance/categories',
    method: 'POST',
    csrfActionId: 'finance.categories.create',
    data: payload,
  })
}

export function updateFinanceCategory(request, categoryId, payload) {
  return request({
    url: `/finance/categories/${encodeURIComponent(categoryId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.categories.update',
    data: payload,
  })
}

export function fetchFinanceRecurringTypes(request) {
  return request({
    url: '/finance/recurring-types',
    method: 'GET',
  })
}

export function createFinanceRecurringType(request, payload) {
  return request({
    url: '/finance/recurring-types',
    method: 'POST',
    csrfActionId: 'finance.recurring-types.create',
    data: payload,
  })
}

export function updateFinanceRecurringType(request, recurringTypeId, payload) {
  return request({
    url: `/finance/recurring-types/${encodeURIComponent(recurringTypeId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.recurring-types.update',
    data: payload,
  })
}

export function fetchFinanceBankAccounts(request) {
  return request({
    url: '/finance/bank-accounts',
    method: 'GET',
  })
}

export function createFinanceBankAccount(request, payload) {
  return request({
    url: '/finance/bank-accounts',
    method: 'POST',
    csrfActionId: 'finance.bank-accounts.create',
    data: payload,
  })
}

export function updateFinanceBankAccount(request, bankAccountId, payload) {
  return request({
    url: `/finance/bank-accounts/${encodeURIComponent(bankAccountId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.bank-accounts.update',
    data: payload,
  })
}

export function updateFinanceBankAccountStatus(request, bankAccountId, payload) {
  return request({
    url: `/finance/bank-accounts/${encodeURIComponent(bankAccountId)}/status`,
    method: 'PATCH',
    csrfActionId: 'finance.bank-accounts.status',
    data: payload,
  })
}

export function fetchFinanceEntries(request, filters = {}, pagination = {}) {
  return request({
    url: '/finance/entries',
    method: 'GET',
    params: {
      ...filters,
      ...buildPaginationParams(pagination),
    },
  })
}

export function createFinanceEntry(request, payload) {
  return request({
    url: '/finance/entries',
    method: 'POST',
    csrfActionId: 'finance.entries.create',
    data: payload,
  })
}

export function updateFinanceEntry(request, entryId, payload) {
  return request({
    url: `/finance/entries/${encodeURIComponent(entryId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.entries.update',
    data: payload,
  })
}

export function createFinanceSettlement(request, entryId, payload) {
  return request({
    url: `/finance/entries/${encodeURIComponent(entryId)}/settlements`,
    method: 'POST',
    csrfActionId: 'finance.entries.settlements.create',
    data: payload,
  })
}

export function deleteFinanceSettlement(request, entryId, settlementId) {
  return request({
    url: `/finance/entries/${encodeURIComponent(entryId)}/settlements/${encodeURIComponent(settlementId)}`,
    method: 'DELETE',
    csrfActionId: 'finance.entries.settlements.delete',
  })
}

export function fetchFinanceRecurringRules(request) {
  return request({
    url: '/finance/recurring-rules',
    method: 'GET',
  })
}

export function createFinanceRecurringRule(request, payload) {
  return request({
    url: '/finance/recurring-rules',
    method: 'POST',
    csrfActionId: 'finance.recurring-rules.create',
    data: payload,
  })
}

export function updateFinanceRecurringRule(request, ruleId, payload) {
  return request({
    url: `/finance/recurring-rules/${encodeURIComponent(ruleId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.recurring-rules.update',
    data: payload,
  })
}

export function previewFinanceRecurringRule(request, ruleId, payload) {
  return request({
    url: `/finance/recurring-rules/${encodeURIComponent(ruleId)}/preview`,
    method: 'POST',
    csrfActionId: 'finance.recurring-rules.preview',
    data: payload,
  })
}

export function fetchFinanceInstallmentPlans(request) {
  return request({
    url: '/finance/installment-plans',
    method: 'GET',
  })
}

export function createFinanceInstallmentPlan(request, payload) {
  return request({
    url: '/finance/installment-plans',
    method: 'POST',
    csrfActionId: 'finance.installments.create',
    data: payload,
  })
}

export function renegotiateFinanceInstallmentPlan(request, planId, payload) {
  return request({
    url: `/finance/installment-plans/${encodeURIComponent(planId)}/renegotiate`,
    method: 'POST',
    csrfActionId: 'finance.installments.renegotiate',
    data: payload,
  })
}

export function fetchFinanceDashboardSummary(request, params = {}) {
  return request({
    url: '/finance/dashboard/summary',
    method: 'GET',
    params,
  })
}

export function fetchFinanceDashboardCashflow(request, params = {}) {
  return request({
    url: '/finance/dashboard/cashflow',
    method: 'GET',
    params,
  })
}

export function fetchFinanceDashboardCategories(request, params = {}) {
  return request({
    url: '/finance/dashboard/categories',
    method: 'GET',
    params,
  })
}

export function createFinanceExport(request, payload) {
  return request({
    url: '/finance/exports',
    method: 'POST',
    csrfActionId: 'finance.exports.create',
    data: payload,
  })
}

export function fetchFinanceExports(request, params = {}) {
  return request({
    url: '/finance/exports',
    method: 'GET',
    params,
  })
}

export function fetchFinanceExportById(request, exportJobId) {
  return request({
    url: `/finance/exports/${encodeURIComponent(exportJobId)}`,
    method: 'GET',
  })
}

export function fetchFinanceOpenFinanceProviders(request) {
  return request({
    url: '/finance/open-finance/providers',
    method: 'GET',
  })
}

export function fetchFinanceOpenFinanceConnections(request) {
  return request({
    url: '/finance/open-finance/connections',
    method: 'GET',
  })
}

export function createFinanceOpenFinanceConnection(request, payload) {
  return request({
    url: '/finance/open-finance/connections',
    method: 'POST',
    csrfActionId: 'finance.open-finance.connections.create',
    data: payload,
  })
}

export function syncFinanceOpenFinanceConnection(request, connectionId, payload = {}) {
  return request({
    url: `/finance/open-finance/connections/${encodeURIComponent(connectionId)}/sync`,
    method: 'POST',
    csrfActionId: 'finance.open-finance.connections.sync',
    data: payload,
  })
}
