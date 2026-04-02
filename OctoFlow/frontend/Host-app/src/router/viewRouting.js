export const FINANCE_SECTION_TO_VIEW = {
  accounts: 'finance.accounts',
  banks: 'finance.banks',
  investments: 'finance.investments',
  debts: 'finance.debts',
  settings: 'finance.settings',
  reports: 'finance.reports',
}

export const FINANCE_VIEW_TO_SECTION = {
  'finance.accounts': 'accounts',
  'finance.banks': 'banks',
  'finance.investments': 'investments',
  'finance.debts': 'debts',
  'finance.settings': 'settings',
  'finance.currencies': 'settings',
  'finance.reports': 'reports',
}

const DEFAULT_VIEW_KEY = 'dashboard'
const ROOT_VIEW_KEYS = ['dashboard', 'tasks', 'test', 'profile']

export function normalizeFinanceSectionName(rawSectionName) {
  const normalizedSectionName = String(rawSectionName || '').trim().toLowerCase()
  if (Object.prototype.hasOwnProperty.call(FINANCE_SECTION_TO_VIEW, normalizedSectionName)) {
    return normalizedSectionName
  }

  return 'accounts'
}

export function normalizeViewKey(rawViewKey) {
  const normalizedViewKey = String(rawViewKey || '').trim()
  if (normalizedViewKey === 'finance') {
    return 'finance.accounts'
  }

  if (Object.prototype.hasOwnProperty.call(FINANCE_VIEW_TO_SECTION, normalizedViewKey)) {
    return normalizedViewKey
  }

  if (ROOT_VIEW_KEYS.includes(normalizedViewKey)) {
    return normalizedViewKey
  }

  return DEFAULT_VIEW_KEY
}

export function resolveViewKeyFromRoute(currentRoute) {
  const routeName = String(currentRoute?.name || '').trim()
  if (routeName === 'finance') {
    return 'finance.accounts'
  }

  if (routeName === 'finance-section') {
    const normalizedSectionName = normalizeFinanceSectionName(currentRoute?.params?.section)
    return FINANCE_SECTION_TO_VIEW[normalizedSectionName]
  }

  if (ROOT_VIEW_KEYS.includes(routeName)) {
    return routeName
  }

  return DEFAULT_VIEW_KEY
}

export function resolveRouteLocationFromView(viewKey) {
  const normalizedViewKey = normalizeViewKey(viewKey)
  if (Object.prototype.hasOwnProperty.call(FINANCE_VIEW_TO_SECTION, normalizedViewKey)) {
    const financeSectionName = FINANCE_VIEW_TO_SECTION[normalizedViewKey]
    if (financeSectionName === 'accounts') {
      return {
        name: 'finance',
      }
    }

    return {
      name: 'finance-section',
      params: {
        section: financeSectionName,
      },
    }
  }

  return {
    name: normalizedViewKey,
  }
}

