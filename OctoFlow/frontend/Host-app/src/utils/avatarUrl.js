const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/OctoFlow/api').replace(/\/$/, '')

function normalizeOriginList(rawOrigins) {
  if (typeof rawOrigins !== 'string' || rawOrigins.trim() === '') {
    return []
  }

  return rawOrigins
    .split(',')
    .map((originValue) => originValue.trim())
    .filter((originValue) => originValue !== '')
}

function buildAllowedAvatarOrigins(apiBaseUrl) {
  const allowedOrigins = new Set()
  const defaultTrustedOrigins = [
    'https://avatars.githubusercontent.com',
    'https://lh3.googleusercontent.com',
    'https://secure.gravatar.com',
  ]

  if (typeof window !== 'undefined' && window.location?.origin) {
    allowedOrigins.add(window.location.origin)
  }

  try {
    const parsedApiBaseUrl = new URL(apiBaseUrl, typeof window !== 'undefined' ? window.location.origin : 'http://localhost')
    allowedOrigins.add(parsedApiBaseUrl.origin)
  } catch {
    // Se não for possível resolver a base da API, segue com os outros domínios.
  }

  for (const defaultTrustedOrigin of defaultTrustedOrigins) {
    allowedOrigins.add(defaultTrustedOrigin)
  }

  const configuredOrigins = normalizeOriginList(import.meta.env.VITE_ALLOWED_AVATAR_ORIGINS)
  for (const configuredOrigin of configuredOrigins) {
    try {
      const parsedConfiguredOrigin = new URL(configuredOrigin)
      if (parsedConfiguredOrigin.protocol === 'https:' || parsedConfiguredOrigin.protocol === 'http:') {
        allowedOrigins.add(parsedConfiguredOrigin.origin)
      }
    } catch {
      // Ignora origem malformada.
    }
  }

  return allowedOrigins
}

function normalizeRelativeAssetPath(assetPath) {
  return assetPath.replace(/^\/+/, '')
}

export function resolveSafeAvatarUrl(rawAvatarUrl, options = {}) {
  const candidateAvatarUrl = typeof rawAvatarUrl === 'string'
    ? rawAvatarUrl.trim()
    : ''

  if (candidateAvatarUrl === '') {
    return ''
  }

  const apiBaseUrl = typeof options.apiBaseUrl === 'string' && options.apiBaseUrl.trim() !== ''
    ? options.apiBaseUrl.trim().replace(/\/$/, '')
    : API_BASE_URL

  const allowedAvatarOrigins = buildAllowedAvatarOrigins(apiBaseUrl)

  if (candidateAvatarUrl.startsWith('/')) {
    return `${apiBaseUrl}${candidateAvatarUrl}`
  }

  const candidateIsAbsoluteHttpUrl = /^https?:\/\//i.test(candidateAvatarUrl)

  if (!candidateIsAbsoluteHttpUrl) {
    return `${apiBaseUrl}/${normalizeRelativeAssetPath(candidateAvatarUrl)}`
  }

  try {
    const parsedCandidateUrl = new URL(candidateAvatarUrl)
    const protocol = parsedCandidateUrl.protocol.toLowerCase()

    if (protocol !== 'https:' && protocol !== 'http:') {
      return ''
    }

    if (!allowedAvatarOrigins.has(parsedCandidateUrl.origin)) {
      return ''
    }

    return parsedCandidateUrl.toString()
  } catch {
    return ''
  }
}
