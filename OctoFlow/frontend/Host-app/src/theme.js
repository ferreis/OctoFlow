export const DEFAULT_APP_THEME_KEY = 'original'
export const DEFAULT_COLOR_VISION_MODE = 'none'
export const DEFAULT_COLOR_VISION_INTENSITY = 100
export const DEFAULT_FONT_SCALE = 'default'
export const DEFAULT_LAYOUT_DENSITY_MODE = 'comfortable'
export const DEFAULT_LAYOUT_DENSITY_SCALE = 100
export const CUSTOM_THEME_KEY = 'personalizado'
export const CUSTOM_THEME_COLOR_KEYS = Object.freeze(['primary', 'secondary', 'accent', 'bg', 'text'])
export const DEFAULT_CUSTOM_THEME_PALETTE = Object.freeze({
  primary: '#4f46e5',
  secondary: '#06b6d4',
  accent: '#3b82f6',
  bg: '#0f172a',
  text: '#f8fafc',
})

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
  {
    key: CUSTOM_THEME_KEY,
    label: 'Personalizado',
    description: 'Tema customizado com 5 cores escolhidas por voce.',
    dark: true,
    colors: {
      ...DEFAULT_CUSTOM_THEME_PALETTE,
    },
  },
]

export const COLOR_VISION_MODE_OPTIONS = [
  {
    key: DEFAULT_COLOR_VISION_MODE,
    label: 'Padrao',
    description: 'Mantem a paleta original da interface.',
  },
  {
    key: 'protanopia',
    label: 'Protanopia',
    description: 'Redistribui tons de vermelho para ampliar separacao visual.',
  },
  {
    key: 'deuteranopia',
    label: 'Deuteranopia',
    description: 'Aumenta a diferenca entre verdes, cianos e azuis.',
  },
  {
    key: 'tritanopia',
    label: 'Tritanopia',
    description: 'Reforca contraste entre azuis, violetas e tons quentes.',
  },
  {
    key: 'acromatopsia',
    label: 'Acromatopsia',
    description: 'Prioriza luminancia e contraste acima de saturacao.',
  },
]

export const FONT_SCALE_OPTIONS = [
  {
    key: DEFAULT_FONT_SCALE,
    label: 'Padrao',
    factor: 1,
  },
  {
    key: 'medium',
    label: 'Medio',
    factor: 1.06,
  },
  {
    key: 'large',
    label: 'Grande',
    factor: 1.12,
  },
  {
    key: 'extra-large',
    label: 'Extra grande',
    factor: 1.18,
  },
]

export const LAYOUT_DENSITY_MODE_OPTIONS = [
  {
    key: DEFAULT_LAYOUT_DENSITY_MODE,
    label: 'Confortavel',
    description: 'Espacamento padrao da interface.',
  },
  {
    key: 'compact',
    label: 'Compacto',
    description: 'Reduz espacamento e tamanho visual para mostrar mais conteudo.',
  },
  {
    key: 'custom',
    label: 'Personalizado',
    description: 'Permite definir o nivel de compactacao manualmente.',
  },
]

export const DEFAULT_UI_SETTINGS = Object.freeze({
  themeKey: DEFAULT_APP_THEME_KEY,
  colorVisionMode: DEFAULT_COLOR_VISION_MODE,
  colorVisionIntensity: DEFAULT_COLOR_VISION_INTENSITY,
  highContrastEnabled: false,
  fontScale: DEFAULT_FONT_SCALE,
  layoutDensityMode: DEFAULT_LAYOUT_DENSITY_MODE,
  layoutDensityScale: DEFAULT_LAYOUT_DENSITY_SCALE,
  customThemePalette: { ...DEFAULT_CUSTOM_THEME_PALETTE },
})

const IDENTITY_COLOR_MATRIX = [
  1, 0, 0, 0, 0,
  0, 1, 0, 0, 0,
  0, 0, 1, 0, 0,
  0, 0, 0, 1, 0,
]

const COLOR_VISION_TARGET_MATRICES = {
  protanopia: [
    0.58, 0.32, 0.10, 0, 0,
    0.22, 0.74, 0.04, 0, 0,
    0.00, 0.18, 0.82, 0, 0,
    0.00, 0.00, 0.00, 1, 0,
  ],
  deuteranopia: [
    0.62, 0.28, 0.10, 0, 0,
    0.24, 0.68, 0.08, 0, 0,
    0.00, 0.18, 0.82, 0, 0,
    0.00, 0.00, 0.00, 1, 0,
  ],
  tritanopia: [
    0.96, 0.04, 0.00, 0, 0,
    0.10, 0.78, 0.12, 0, 0,
    0.00, 0.22, 0.78, 0, 0,
    0.00, 0.00, 0.00, 1, 0,
  ],
  acromatopsia: [
    0.299, 0.587, 0.114, 0, 0,
    0.299, 0.587, 0.114, 0, 0,
    0.299, 0.587, 0.114, 0, 0,
    0.000, 0.000, 0.000, 1, 0,
  ],
}

const themeMap = new Map(APP_THEME_OPTIONS.map((theme) => [theme.key, theme]))
const colorVisionModeMap = new Map(COLOR_VISION_MODE_OPTIONS.map((option) => [option.key, option]))
const fontScaleMap = new Map(FONT_SCALE_OPTIONS.map((option) => [option.key, option]))
const layoutDensityModeMap = new Map(LAYOUT_DENSITY_MODE_OPTIONS.map((option) => [option.key, option]))
const UI_SETTINGS_STORAGE_PREFIX = 'octoflow.ui-settings.user.'
const UI_SETTINGS_SESSION_PREFIX = 'octoflow.ui-settings.session.user.'
const HEX_COLOR_PATTERN = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i
const CUSTOM_THEME_VARIABLE_MAP = Object.freeze({
  primary: '--custom-theme-primary',
  secondary: '--custom-theme-secondary',
  accent: '--custom-theme-accent',
  bg: '--custom-theme-bg',
  text: '--custom-theme-text',
})

function expandHexColorIfNeeded(colorValue) {
  if (typeof colorValue !== 'string' || colorValue.length !== 4) {
    return colorValue
  }

  return `#${colorValue[1]}${colorValue[1]}${colorValue[2]}${colorValue[2]}${colorValue[3]}${colorValue[3]}`
}

function normalizeHexColor(value, fallbackColor) {
  const normalizedValue = typeof value === 'string' ? value.trim() : ''
  if (HEX_COLOR_PATTERN.test(normalizedValue)) {
    return expandHexColorIfNeeded(normalizedValue).toLowerCase()
  }

  const normalizedFallback = typeof fallbackColor === 'string' ? fallbackColor.trim() : ''
  if (HEX_COLOR_PATTERN.test(normalizedFallback)) {
    return expandHexColorIfNeeded(normalizedFallback).toLowerCase()
  }

  return '#000000'
}

export function normalizeThemeKey(value) {
  const normalizedValue = typeof value === 'string' ? value.trim().toLowerCase() : ''
  return themeMap.has(normalizedValue) ? normalizedValue : DEFAULT_APP_THEME_KEY
}

export function normalizeColorVisionMode(value) {
  const normalizedValue = typeof value === 'string' ? value.trim().toLowerCase() : ''
  return colorVisionModeMap.has(normalizedValue) ? normalizedValue : DEFAULT_COLOR_VISION_MODE
}

export function normalizeColorVisionIntensity(value) {
  const numericValue = Number(value)
  if (!Number.isFinite(numericValue)) {
    return DEFAULT_COLOR_VISION_INTENSITY
  }

  return Math.min(100, Math.max(0, Math.round(numericValue)))
}

export function normalizeHighContrastEnabled(value) {
  if (typeof value === 'boolean') {
    return value
  }

  if (typeof value === 'number') {
    return value !== 0
  }

  if (typeof value === 'string') {
    const normalizedValue = value.trim().toLowerCase()

    if (['1', 'true', 'yes', 'on', 'enabled'].includes(normalizedValue)) {
      return true
    }

    if (['0', 'false', 'no', 'off', 'disabled', ''].includes(normalizedValue)) {
      return false
    }
  }

  return false
}

export function normalizeFontScale(value) {
  const normalizedValue = typeof value === 'string' ? value.trim().toLowerCase() : ''
  return fontScaleMap.has(normalizedValue) ? normalizedValue : DEFAULT_FONT_SCALE
}

export function normalizeLayoutDensityMode(value) {
  const normalizedValue = typeof value === 'string' ? value.trim().toLowerCase() : ''
  return layoutDensityModeMap.has(normalizedValue) ? normalizedValue : DEFAULT_LAYOUT_DENSITY_MODE
}

export function normalizeLayoutDensityScale(value) {
  const numericValue = Number(value)
  if (!Number.isFinite(numericValue)) {
    return DEFAULT_LAYOUT_DENSITY_SCALE
  }

  return Math.min(110, Math.max(70, Math.round(numericValue)))
}

export function normalizeCustomThemePalette(value = {}, basePalette = DEFAULT_CUSTOM_THEME_PALETTE) {
  const nextPaletteCandidate = value && typeof value === 'object' ? value : {}
  const fallbackPalette = basePalette && typeof basePalette === 'object'
    ? basePalette
    : DEFAULT_CUSTOM_THEME_PALETTE
  const normalizedPalette = {}

  for (const colorKey of CUSTOM_THEME_COLOR_KEYS) {
    normalizedPalette[colorKey] = normalizeHexColor(
      nextPaletteCandidate?.[colorKey],
      fallbackPalette?.[colorKey] ?? DEFAULT_CUSTOM_THEME_PALETTE[colorKey],
    )
  }

  return normalizedPalette
}

export function normalizeUiSettingsPayload(settings = {}, baseSettings = DEFAULT_UI_SETTINGS) {
  const nextSettings = settings && typeof settings === 'object' ? settings : {}
  const fallbackSettings = baseSettings && typeof baseSettings === 'object'
    ? baseSettings
    : DEFAULT_UI_SETTINGS

  return {
    themeKey: normalizeThemeKey(nextSettings?.themeKey ?? fallbackSettings?.themeKey ?? DEFAULT_APP_THEME_KEY),
    colorVisionMode: normalizeColorVisionMode(nextSettings?.colorVisionMode ?? fallbackSettings?.colorVisionMode ?? DEFAULT_COLOR_VISION_MODE),
    colorVisionIntensity: normalizeColorVisionIntensity(nextSettings?.colorVisionIntensity ?? fallbackSettings?.colorVisionIntensity ?? DEFAULT_COLOR_VISION_INTENSITY),
    highContrastEnabled: normalizeHighContrastEnabled(nextSettings?.highContrastEnabled ?? fallbackSettings?.highContrastEnabled ?? false),
    fontScale: normalizeFontScale(nextSettings?.fontScale ?? fallbackSettings?.fontScale ?? DEFAULT_FONT_SCALE),
    layoutDensityMode: normalizeLayoutDensityMode(nextSettings?.layoutDensityMode ?? fallbackSettings?.layoutDensityMode ?? DEFAULT_LAYOUT_DENSITY_MODE),
    layoutDensityScale: normalizeLayoutDensityScale(nextSettings?.layoutDensityScale ?? fallbackSettings?.layoutDensityScale ?? DEFAULT_LAYOUT_DENSITY_SCALE),
    customThemePalette: normalizeCustomThemePalette(
      nextSettings?.customThemePalette,
      fallbackSettings?.customThemePalette ?? DEFAULT_CUSTOM_THEME_PALETTE,
    ),
  }
}

export function getThemeDefinition(themeKey) {
  return themeMap.get(normalizeThemeKey(themeKey)) || APP_THEME_OPTIONS[0]
}

export function getColorVisionModeDefinition(mode) {
  return colorVisionModeMap.get(normalizeColorVisionMode(mode)) || COLOR_VISION_MODE_OPTIONS[0]
}

export function getFontScaleDefinition(fontScale) {
  return fontScaleMap.get(normalizeFontScale(fontScale)) || FONT_SCALE_OPTIONS[0]
}

export function getLayoutDensityModeDefinition(layoutDensityMode) {
  return layoutDensityModeMap.get(normalizeLayoutDensityMode(layoutDensityMode)) || LAYOUT_DENSITY_MODE_OPTIONS[0]
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
    return normalizeUiSettingsPayload(parsed)
  } catch {
    return normalizeUiSettingsPayload({
      themeKey: rawValue,
    })
  }
}

export function writeStoredUiSettings(user, settings = {}) {
  if (typeof window === 'undefined') {
    return
  }

  window.localStorage.setItem(
    resolveThemeStorageKey(user),
    JSON.stringify(normalizeUiSettingsPayload(settings))
  )
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

function blendColorMatrix(targetMatrix, intensity) {
  const mixRatio = normalizeColorVisionIntensity(intensity) / 100

  return IDENTITY_COLOR_MATRIX.map((identityValue, index) => (
    identityValue + ((targetMatrix[index] ?? identityValue) - identityValue) * mixRatio
  ))
}

function formatColorMatrix(matrix) {
  return matrix
    .map((value) => Number(value.toFixed(4)))
    .join(' ')
}

export function buildColorVisionFilterMatrix(mode, intensity = DEFAULT_COLOR_VISION_INTENSITY) {
  const normalizedMode = normalizeColorVisionMode(mode)
  if (normalizedMode === DEFAULT_COLOR_VISION_MODE || normalizeColorVisionIntensity(intensity) === 0) {
    return formatColorMatrix(IDENTITY_COLOR_MATRIX)
  }

  return formatColorMatrix(
    blendColorMatrix(COLOR_VISION_TARGET_MATRICES[normalizedMode] || IDENTITY_COLOR_MATRIX, intensity)
  )
}

function applyCustomThemePaletteToDocument(rootNode, customThemePalette) {
  for (const colorKey of CUSTOM_THEME_COLOR_KEYS) {
    rootNode.style.setProperty(CUSTOM_THEME_VARIABLE_MAP[colorKey], customThemePalette[colorKey])
  }
}

function clearCustomThemePaletteFromDocument(rootNode) {
  for (const cssVariableName of Object.values(CUSTOM_THEME_VARIABLE_MAP)) {
    rootNode.style.removeProperty(cssVariableName)
  }
}

function isColorDark(colorValue) {
  const normalizedColor = normalizeHexColor(colorValue, '#000000')
  const redChannel = Number.parseInt(normalizedColor.slice(1, 3), 16) / 255
  const greenChannel = Number.parseInt(normalizedColor.slice(3, 5), 16) / 255
  const blueChannel = Number.parseInt(normalizedColor.slice(5, 7), 16) / 255
  const luminance = (0.2126 * redChannel) + (0.7152 * greenChannel) + (0.0722 * blueChannel)

  return luminance < 0.52
}

export function applyThemeToDocument(themeKey, customThemePalette = DEFAULT_CUSTOM_THEME_PALETTE) {
  if (typeof document === 'undefined') {
    return
  }

  const theme = getThemeDefinition(themeKey)
  const root = document.documentElement

  root.dataset.theme = theme.key

  if (theme.key === CUSTOM_THEME_KEY) {
    const normalizedPalette = normalizeCustomThemePalette(customThemePalette)
    applyCustomThemePaletteToDocument(root, normalizedPalette)
    root.style.colorScheme = isColorDark(normalizedPalette.bg) ? 'dark' : 'light'
    return
  }

  clearCustomThemePaletteFromDocument(root)
  root.style.colorScheme = theme.dark ? 'dark' : 'light'
}

export function applyAccessibilityToDocument(settings = DEFAULT_UI_SETTINGS) {
  if (typeof document === 'undefined') {
    return
  }

  const nextSettings = normalizeUiSettingsPayload(settings)
  const root = document.documentElement
  const fontScale = getFontScaleDefinition(nextSettings.fontScale).factor
  const layoutDensityScale = resolveLayoutDensityScale(nextSettings.layoutDensityMode, nextSettings.layoutDensityScale)
  const layoutDensityFactor = layoutDensityScale / 100
  const layoutDensityFontFactor = Math.min(1.06, Math.max(0.84, layoutDensityFactor))

  root.dataset.colorVisionMode = nextSettings.colorVisionMode
  root.dataset.highContrast = nextSettings.highContrastEnabled ? 'true' : 'false'
  root.dataset.fontScale = nextSettings.fontScale
  root.dataset.layoutDensityMode = nextSettings.layoutDensityMode
  root.style.setProperty('--app-font-scale', String(fontScale))
  root.style.setProperty('--app-font-px-scale', String(fontScale))
  root.style.setProperty('--app-color-vision-mix', `${nextSettings.colorVisionIntensity}%`)
  root.style.setProperty('--app-layout-density-scale', String(layoutDensityScale))
  root.style.setProperty('--app-layout-density-factor', String(layoutDensityFactor))
  root.style.setProperty('--app-layout-density-font-factor', String(layoutDensityFontFactor))
}

function resolveLayoutDensityScale(layoutDensityMode, layoutDensityScale) {
  const normalizedLayoutDensityMode = normalizeLayoutDensityMode(layoutDensityMode)

  if (normalizedLayoutDensityMode === 'compact') {
    return 88
  }

  if (normalizedLayoutDensityMode === DEFAULT_LAYOUT_DENSITY_MODE) {
    return DEFAULT_LAYOUT_DENSITY_SCALE
  }

  return normalizeLayoutDensityScale(layoutDensityScale)
}
