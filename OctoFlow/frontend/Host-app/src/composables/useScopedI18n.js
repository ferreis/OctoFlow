import { useI18n } from './useI18n'

export function useScopedI18n(scopeKey) {
  const { translate, currentLocale } = useI18n()

  function translateScoped(messagePath, fallbackMessage = '', variables = {}) {
    const normalizedScope = String(scopeKey || '').trim()
    const normalizedPath = String(messagePath || '').trim()

    if (normalizedScope === '' || normalizedPath === '') {
      return fallbackMessage || normalizedPath
    }

    const translationKey = `${normalizedScope}.${normalizedPath}`
    const translatedMessage = translate(translationKey, variables)

    if (translatedMessage === translationKey) {
      return fallbackMessage || normalizedPath
    }

    return translatedMessage
  }

  return {
    translateScoped,
    currentLocale,
  }
}
