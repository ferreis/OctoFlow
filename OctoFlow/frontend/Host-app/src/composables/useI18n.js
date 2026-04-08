import { computed, ref } from 'vue'
import enUS from '../locales/en-US.json'
import ptBR from '../locales/pt-BR.json'

const locales = {
  'pt-BR': ptBR,
  'en-US': enUS,
}

// Global state for simple translation across components without Pinia
const currentLocale = ref('pt-BR')

export function useI18n() {
  function getNestedValue(obj, path) {
    if (!obj || typeof path !== 'string') return null
    return path.split('.').reduce((acc, part) => (acc && acc[part] !== undefined ? acc[part] : null), obj)
  }

  function t(path, variables = {}) {
    const defaultDict = locales[currentLocale.value] || locales['pt-BR']
    let text = getNestedValue(defaultDict, path)

    // Fallback to en-US if missing, then to original key string
    if (!text) {
      text = getNestedValue(locales['en-US'], path) || path
    }

    if (typeof text !== 'string') {
      return path
    }

    // Process named variables {name}
    for (const [key, value] of Object.entries(variables)) {
      text = text.replace(new RegExp(`\\{${key}\\}`, 'g'), String(value))
    }

    return text
  }

  function setLocale(locale) {
    if (locales[locale]) {
      currentLocale.value = locale
      try {
        window.localStorage.setItem('octoflow.locale', locale)
      } catch {
        // Ignorar em caso de erro localstorage
      }
    }
  }

  // Auto-init only once on client-side
  if (typeof window !== 'undefined' && currentLocale.value === 'pt-BR') {
    try {
      const saved = window.localStorage.getItem('octoflow.locale')
      if (saved && locales[saved]) {
        currentLocale.value = saved
      } else {
        const navigatorLang = window.navigator?.language || ''
        if (navigatorLang.startsWith('en')) {
          currentLocale.value = 'en-US'
        }
      }
    } catch {
      // safe fallback
    }
  }

  return {
    t,
    setLocale,
    currentLocale: computed(() => currentLocale.value),
  }
}
