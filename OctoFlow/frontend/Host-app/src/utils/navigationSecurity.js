const DEFAULT_AUTH_ROUTE_FALLBACK = '/app/dashboard?tab=tasks'
const ALLOWED_REDIRECT_PREFIXES = [
  '/app',
  '/dashboard',
  '/tasks',
  '/test',
  '/finance',
  '/profile',
  '/auth/google-password-setup',
]

function normalizeSearchParams(searchParams) {
  if (!(searchParams instanceof URLSearchParams)) {
    return ''
  }

  const serialized = searchParams.toString().trim()
  return serialized === '' ? '' : `?${serialized}`
}

export function normalizeDashboardTabQuery(rawTab) {
  const normalizedTab = typeof rawTab === 'string'
    ? rawTab.trim().toLowerCase()
    : Array.isArray(rawTab)
      ? String(rawTab[0] || '').trim().toLowerCase()
      : ''

  return normalizedTab === 'finance' ? 'finance' : 'tasks'
}

export function sanitizeInternalRedirectPath(rawRedirectPath, options = {}) {
  const fallbackPathWasProvided = Object.prototype.hasOwnProperty.call(options, 'fallbackPath')
  const fallbackPath = fallbackPathWasProvided
    ? String(options.fallbackPath || '').trim()
    : DEFAULT_AUTH_ROUTE_FALLBACK
  const sanitizedFallbackPath = fallbackPath

  const candidateRedirectPath = typeof rawRedirectPath === 'string'
    ? rawRedirectPath.trim()
    : ''

  if (candidateRedirectPath === '') {
    return sanitizedFallbackPath
  }

  const lowerCaseCandidate = candidateRedirectPath.toLowerCase()
  if (lowerCaseCandidate.startsWith('javascript:') || lowerCaseCandidate.startsWith('data:')) {
    return sanitizedFallbackPath
  }

  try {
    const baseOrigin = typeof window !== 'undefined' && window.location?.origin
      ? window.location.origin
      : 'http://localhost'
    const parsedRedirectUrl = new URL(candidateRedirectPath, baseOrigin)

    if (parsedRedirectUrl.origin !== baseOrigin) {
      return sanitizedFallbackPath
    }

    const normalizedPathname = String(parsedRedirectUrl.pathname || '').trim()

    if (normalizedPathname === '' || !normalizedPathname.startsWith('/') || normalizedPathname.startsWith('//')) {
      return sanitizedFallbackPath
    }

    const blockedAuthPaths = new Set(['/auth/login', '/auth/register'])
    if (blockedAuthPaths.has(normalizedPathname)) {
      return sanitizedFallbackPath
    }

    const redirectIsAllowed = ALLOWED_REDIRECT_PREFIXES.some((pathPrefix) => {
      return normalizedPathname === pathPrefix || normalizedPathname.startsWith(`${pathPrefix}/`)
    })

    if (!redirectIsAllowed) {
      return sanitizedFallbackPath
    }

    const normalizedSearch = normalizeSearchParams(parsedRedirectUrl.searchParams)
    return `${normalizedPathname}${normalizedSearch}${parsedRedirectUrl.hash || ''}`
  } catch {
    return sanitizedFallbackPath
  }
}
