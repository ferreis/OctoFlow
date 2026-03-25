export function createFinanceSimulation(request, payload) {
  return request({
    url: '/finance/investment/simulations',
    method: 'POST',
    csrfActionId: 'finance.investments.simulations.create',
    data: payload,
  })
}

export function fetchFinanceSimulation(request, simulationId) {
  return request({
    url: `/finance/investment/simulations/${encodeURIComponent(simulationId)}`,
    method: 'GET',
  })
}

export function convertFinanceSimulationToPlan(request, simulationId, payload) {
  return request({
    url: `/finance/investment/simulations/${encodeURIComponent(simulationId)}/convert-to-plan`,
    method: 'POST',
    csrfActionId: 'finance.investments.simulations.convert',
    data: payload,
  })
}

export function fetchFinanceInvestmentPlans(request) {
  return request({
    url: '/finance/investment/plans',
    method: 'GET',
  })
}

export function createFinanceInvestmentPlan(request, payload) {
  return request({
    url: '/finance/investment/plans',
    method: 'POST',
    csrfActionId: 'finance.investments.plans.create',
    data: payload,
  })
}

export function updateFinanceInvestmentPlan(request, planId, payload) {
  return request({
    url: `/finance/investment/plans/${encodeURIComponent(planId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.investments.plans.update',
    data: payload,
  })
}
