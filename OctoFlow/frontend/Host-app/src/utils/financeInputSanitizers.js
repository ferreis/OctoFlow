function replaceControlCharactersWithSpaces(rawValue) {
  const rawText = String(rawValue || '')
  let sanitizedText = ''

  for (const currentCharacter of rawText) {
    const characterCode = currentCharacter.charCodeAt(0)
    const isControlCharacter = characterCode <= 31 || characterCode === 127
    sanitizedText += isControlCharacter ? ' ' : currentCharacter
  }

  return sanitizedText
}

export function sanitizeSingleLineText(rawValue, maxLength = 140) {
  return replaceControlCharactersWithSpaces(rawValue)
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, Math.max(1, Number(maxLength) || 1))
}

export function sanitizeSearchText(rawValue, maxLength = 180) {
  return sanitizeSingleLineText(rawValue, maxLength)
}

export function sanitizeIdentifier(rawValue) {
  const normalizedIdentifier = String(rawValue || '').trim()
  if (normalizedIdentifier === '') {
    return ''
  }

  if (/^\d+$/.test(normalizedIdentifier)) {
    return normalizedIdentifier
  }

  return ''
}

export function sanitizeDateInput(rawValue) {
  const normalizedDate = String(rawValue || '').trim()
  if (normalizedDate === '') {
    return ''
  }

  return /^\d{4}-\d{2}-\d{2}$/.test(normalizedDate) ? normalizedDate : ''
}

export function sanitizeMonthInput(rawValue) {
  const normalizedMonth = String(rawValue || '').trim()
  if (normalizedMonth === '') {
    return ''
  }

  return /^\d{4}-\d{2}$/.test(normalizedMonth) ? normalizedMonth : ''
}

export function sanitizeDateTimeLocalInput(rawValue) {
  const normalizedDateTime = String(rawValue || '').trim()
  if (normalizedDateTime === '') {
    return ''
  }

  return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(normalizedDateTime) ? normalizedDateTime : ''
}

export function sanitizeCurrencyCode(rawValue) {
  const normalizedCode = sanitizeSingleLineText(rawValue, 3).toUpperCase()
  return /^[A-Z]{3}$/.test(normalizedCode) ? normalizedCode : ''
}

export function sanitizeDecimal(rawValue, options = {}) {
  const minimumValue = Number.isFinite(options.min) ? options.min : Number.NEGATIVE_INFINITY
  const maximumValue = Number.isFinite(options.max) ? options.max : Number.POSITIVE_INFINITY
  const defaultValue = Number.isFinite(options.defaultValue) ? options.defaultValue : 0
  const decimalPlaces = Number.isInteger(options.decimals) && options.decimals >= 0 ? options.decimals : null

  const parsedValue = Number(rawValue)
  if (!Number.isFinite(parsedValue)) {
    return defaultValue
  }

  const clampedValue = Math.min(maximumValue, Math.max(minimumValue, parsedValue))
  if (decimalPlaces === null) {
    return clampedValue
  }

  const decimalFactor = 10 ** decimalPlaces
  return Math.round(clampedValue * decimalFactor) / decimalFactor
}

export function sanitizeInteger(rawValue, options = {}) {
  const parsedValue = Number.parseInt(String(rawValue || ''), 10)
  if (!Number.isFinite(parsedValue)) {
    return Number.isInteger(options.defaultValue) ? options.defaultValue : 0
  }

  const minimumValue = Number.isInteger(options.min) ? options.min : Number.MIN_SAFE_INTEGER
  const maximumValue = Number.isInteger(options.max) ? options.max : Number.MAX_SAFE_INTEGER
  return Math.min(maximumValue, Math.max(minimumValue, parsedValue))
}

export function sanitizeToggle(rawValue) {
  return rawValue === true
}
