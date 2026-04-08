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

export function sanitizeSingleLineSecurityText(rawValue, maxLength = 180) {
  return replaceControlCharactersWithSpaces(rawValue)
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, Math.max(1, Number(maxLength) || 1))
}

export function sanitizeAuthEmailInput(rawEmailAddress) {
  return sanitizeSingleLineSecurityText(rawEmailAddress, 254).toLowerCase()
}

export function sanitizeAuthPasswordInput(rawPassword, maxLength = 160) {
  const normalizedPassword = typeof rawPassword === 'string'
    ? rawPassword
    : String(rawPassword ?? '')

  return normalizedPassword.slice(0, Math.max(8, Number(maxLength) || 160))
}

export function sanitizeSecurityCodeInput(rawCode, maxLength = 16) {
  return replaceControlCharactersWithSpaces(rawCode)
    .replace(/\s+/g, '')
    .replace(/[^a-zA-Z0-9-]/g, '')
    .slice(0, Math.max(1, Number(maxLength) || 1))
}

export function sanitizeGoogleCredentialInput(rawCredential, maxLength = 4096) {
  return replaceControlCharactersWithSpaces(rawCredential)
    .replace(/\s+/g, '')
    .trim()
    .slice(0, Math.max(1, Number(maxLength) || 1))
}
