export const DEFAULT_APP_THEME_KEY = 'original'

export const APP_THEME_OPTIONS = [
  {
    key: 'original',
    label: 'Padrao Original',
    description: 'Base roxa com ciano e fundo escuro classico.',
    dark: true,
    colors: {
      primary: '#4F46E5',
      secondary: '#06B6D4',
      accent: '#3B82F6',
      bg: '#0F172A',
      text: '#F8FAFC',
    },
  },
  {
    key: 'neon-tech',
    label: 'Neon Tech',
    description: 'Tema escuro com contraste vivo e energia neon.',
    dark: true,
    colors: {
      primary: '#7C3AED',
      secondary: '#22D3EE',
      accent: '#0EA5E9',
      bg: '#020617',
      text: '#E0F2FE',
    },
  },
  {
    key: 'ocean-clean',
    label: 'Ocean Clean',
    description: 'Leve, claro e limpo para leituras longas.',
    dark: false,
    colors: {
      primary: '#2563EB',
      secondary: '#06B6D4',
      accent: '#6366F1',
      bg: '#F1F5F9',
      text: '#0F172A',
    },
  },
  {
    key: 'dark-premium',
    label: 'Dark Premium',
    description: 'Escuro refinado com tons frios e contraste suave.',
    dark: true,
    colors: {
      primary: '#4338CA',
      secondary: '#0891B2',
      accent: '#6366F1',
      bg: '#020617',
      text: '#CBD5F5',
    },
  },
  {
    key: 'futurista-contrast',
    label: 'Futurista Contrast',
    description: 'Mistura vibrante de roxo, verde-ciano e rosa.',
    dark: true,
    colors: {
      primary: '#A855F7',
      secondary: '#14B8A6',
      accent: '#F43F5E',
      bg: '#0F172A',
      text: '#FFFFFF',
    },
  },
]

const themeMap = new Map(APP_THEME_OPTIONS.map((theme) => [theme.key, theme]))
const UI_SETTINGS_STORAGE_PREFIX = 'octoflow.ui-settings.user.'
const UI_SETTINGS_SESSION_PREFIX = 'octoflow.ui-settings.session.user.'

export function normalizeThemeKey(value) {
  const normalizedValue = typeof value === 'string' ? value.trim().toLowerCase() : ''
  return themeMap.has(normalizedValue) ? normalizedValue : DEFAULT_APP_THEME_KEY
}

export function getThemeDefinition(themeKey) {
  return themeMap.get(normalizeThemeKey(themeKey)) || APP_THEME_OPTIONS[0]
}

function resolveUserScopeKey(user) {
  const userId = typeof user?.id === 'string' || typeof user?.id === 'number'
    ? String(user.id).trim()
    : ''

  if (userId !== '') {
    return userId
  }

  const email = typeof user?.defaultEmail === 'string' && user.defaultEmail.trim() !== ''
    ? user.defaultEmail.trim().toLowerCase()
    : typeof user?.email === 'string'
      ? user.email.trim().toLowerCase()
      : ''

  return email !== '' ? email : 'guest'
}

export function resolveThemeStorageKey(user) {
  return `${UI_SETTINGS_STORAGE_PREFIX}${resolveUserScopeKey(user)}`
}

export function resolveUiSettingsSessionKey(user) {
  return `${UI_SETTINGS_SESSION_PREFIX}${resolveUserScopeKey(user)}`
}

export function readStoredUiSettings(user) {
  if (typeof window === 'undefined') {
    return null
  }

  const rawValue = window.localStorage.getItem(resolveThemeStorageKey(user))
  if (typeof rawValue !== 'string' || rawValue.trim() === '') {
    return null
  }

  try {
    const parsed = JSON.parse(rawValue)
    return {
      themeKey: normalizeThemeKey(parsed?.themeKey),
    }
  } catch {
    return {
      themeKey: normalizeThemeKey(rawValue),
    }
  }
}

export function writeStoredUiSettings(user, settings = {}) {
  if (typeof window === 'undefined') {
    return
  }

  const payload = {
    themeKey: normalizeThemeKey(settings?.themeKey),
  }

  window.localStorage.setItem(resolveThemeStorageKey(user), JSON.stringify(payload))
}

export function hasUiSettingsLoadedInSession(user) {
  if (typeof window === 'undefined') {
    return false
  }

  return window.sessionStorage.getItem(resolveUiSettingsSessionKey(user)) === '1'
}

export function markUiSettingsLoadedInSession(user) {
  if (typeof window === 'undefined') {
    return
  }

  window.sessionStorage.setItem(resolveUiSettingsSessionKey(user), '1')
}

export function readStoredThemeKey(user) {
  return readStoredUiSettings(user)?.themeKey || DEFAULT_APP_THEME_KEY
}

export function writeStoredThemeKey(user, themeKey) {
  writeStoredUiSettings(user, { themeKey })
}

export function applyThemeToDocument(themeKey) {
  if (typeof document === 'undefined') {
    return
  }

  const theme = getThemeDefinition(themeKey)
  document.documentElement.dataset.theme = theme.key
  document.documentElement.style.colorScheme = theme.dark ? 'dark' : 'light'
}
