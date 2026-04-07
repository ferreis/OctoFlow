export const FINANCE_ROUTE_NAMES = {
  accounts: 'finance-accounts',
  banks: 'finance-banks',
  investments: 'finance-investments',
  settings: 'finance-settings',
  reports: 'finance-reports',
}

const DEFAULT_VIEW_KEY = 'dashboard'
const ROOT_VIEW_KEYS = ['dashboard', 'tasks', 'test', 'profile']

export function normalizeViewKey(rawViewKey) {
  const normalizedViewKey = String(rawViewKey || '').trim()

  if (normalizedViewKey === 'finance') {
    return 'finance-accounts'
  }

  if (normalizedViewKey.startsWith('finance.')) {
    const sectionPart = normalizedViewKey.replace('finance.', '')
    return FINANCE_ROUTE_NAMES[sectionPart] || 'finance-accounts'
  }

  if (Object.values(FINANCE_ROUTE_NAMES).includes(normalizedViewKey)) {
    return normalizedViewKey
  }

  if (ROOT_VIEW_KEYS.includes(normalizedViewKey)) {
    return normalizedViewKey
  }

  return DEFAULT_VIEW_KEY
}

export function resolveViewKeyFromRoute(currentRoute) {
  const routeName = String(currentRoute?.name || '').trim()

  if (routeName.startsWith('finance')) {
    return routeName
  }

  if (ROOT_VIEW_KEYS.includes(routeName)) {
    return routeName
  }

  return DEFAULT_VIEW_KEY
}

export function resolveRouteLocationFromView(viewKey) {
  const normalizedViewKey = normalizeViewKey(viewKey)
  return { name: normalizedViewKey }
}
