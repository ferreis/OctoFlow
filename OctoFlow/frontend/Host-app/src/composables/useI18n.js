import { computed, ref } from 'vue'
import enUSLocale from '../locales/en_US.json'
import ptBRLocale from '../locales/pt_BR.json'

const LOCALE_STORAGE_KEY = 'octoflow.locale'
const DEFAULT_LOCALE = 'pt-BR'

const localeDictionaryByCode = {
  'pt-BR': ptBRLocale,
  'en-US': enUSLocale,
}

const currentLocaleCode = ref(DEFAULT_LOCALE)
let localeWasInitialized = false

function normalizeLocaleCode(rawLocaleCode) {
  const normalizedLocaleCode = String(rawLocaleCode || '').trim().toLowerCase()

  if (normalizedLocaleCode.startsWith('en')) {
    return 'en-US'
  }

  if (normalizedLocaleCode.startsWith('pt')) {
    return 'pt-BR'
  }

  return DEFAULT_LOCALE
}

function getNestedTranslationValue(dictionaryObject, translationPath) {
  if (!dictionaryObject || typeof translationPath !== 'string') {
    return null
  }

  return translationPath
    .split('.')
    .reduce((resolvedValue, currentPathChunk) => {
      if (resolvedValue && resolvedValue[currentPathChunk] !== undefined) {
        return resolvedValue[currentPathChunk]
      }

      return null
    }, dictionaryObject)
}

function ensureLocaleInitialized() {
  if (localeWasInitialized || typeof window === 'undefined') {
    return
  }

  localeWasInitialized = true

  try {
    const savedLocaleCode = window.localStorage.getItem(LOCALE_STORAGE_KEY)
    const localeFromStorage = normalizeLocaleCode(savedLocaleCode)

    if (savedLocaleCode) {
      currentLocaleCode.value = localeFromStorage
      return
    }

    const navigatorLanguage = window.navigator?.language || ''
    currentLocaleCode.value = normalizeLocaleCode(navigatorLanguage)
  } catch {
    currentLocaleCode.value = DEFAULT_LOCALE
  }
}

export function useI18n() {
  ensureLocaleInitialized()

  function translate(translationPath, variables = {}) {
    const activeDictionary = localeDictionaryByCode[currentLocaleCode.value] || localeDictionaryByCode[DEFAULT_LOCALE]
    let resolvedText = getNestedTranslationValue(activeDictionary, translationPath)

    if (!resolvedText) {
      resolvedText = getNestedTranslationValue(localeDictionaryByCode['en-US'], translationPath) || translationPath
    }

    if (typeof resolvedText !== 'string') {
      return translationPath
    }

    let translatedText = resolvedText
    for (const [variableName, variableValue] of Object.entries(variables)) {
      translatedText = translatedText.replace(new RegExp(`\\{${variableName}\\}`, 'g'), String(variableValue))
    }

    return translatedText
  }

  function setLocale(rawLocaleCode) {
    const normalizedLocaleCode = normalizeLocaleCode(rawLocaleCode)
    currentLocaleCode.value = normalizedLocaleCode

    if (typeof window === 'undefined') {
      return
    }

    try {
      window.localStorage.setItem(LOCALE_STORAGE_KEY, normalizedLocaleCode)
    } catch {
      // Ignora indisponibilidade de storage sem quebrar fluxo.
    }
  }

  return {
    t: translate,
    translate,
    setLocale,
    currentLocale: computed(() => currentLocaleCode.value),
  }
}
