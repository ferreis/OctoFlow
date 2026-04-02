import { useSessionStore } from '../stores/sessionStore'

function requestWithSession(config) {
  const sessionStore = useSessionStore()
  return sessionStore.authRequest(config)
}

export function createFinanceSimulation(payload) {
  return requestWithSession({
    url: '/finance/investment/simulations',
    method: 'POST',
    csrfActionId: 'finance.investments.simulations.create',
    data: payload,
  })
}

export function fetchFinanceSimulation(simulationId) {
  return requestWithSession({
    url: `/finance/investment/simulations/${encodeURIComponent(simulationId)}`,
    method: 'GET',
  })
}

export function convertFinanceSimulationToPlan(simulationId, payload) {
  return requestWithSession({
    url: `/finance/investment/simulations/${encodeURIComponent(simulationId)}/convert-to-plan`,
    method: 'POST',
    csrfActionId: 'finance.investments.simulations.convert',
    data: payload,
  })
}

export function fetchFinanceInvestmentPlans() {
  return requestWithSession({
    url: '/finance/investment/plans',
    method: 'GET',
  })
}

export function createFinanceInvestmentPlan(payload) {
  return requestWithSession({
    url: '/finance/investment/plans',
    method: 'POST',
    csrfActionId: 'finance.investments.plans.create',
    data: payload,
  })
}

export function updateFinanceInvestmentPlan(planId, payload) {
  return requestWithSession({
    url: `/finance/investment/plans/${encodeURIComponent(planId)}`,
    method: 'PATCH',
    csrfActionId: 'finance.investments.plans.update',
    data: payload,
  })
}
