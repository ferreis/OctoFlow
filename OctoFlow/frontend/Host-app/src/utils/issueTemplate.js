export function buildInitialFieldState(template) {
  const state = {}

  for (const field of template?.fields || []) {
    const fieldKey = normalizeFieldKey(field?.key)
    if (fieldKey === '') {
      continue
    }

    state[fieldKey] = resolveFieldDefaultValue(field)
  }

  return state
}

export function snapshotFieldValues(template, fieldValues = {}) {
  const snapshot = {}

  for (const field of template?.fields || []) {
    const fieldKey = normalizeFieldKey(field?.key)
    if (fieldKey === '') {
      continue
    }

    snapshot[fieldKey] = typeof fieldValues[fieldKey] === 'string' ? fieldValues[fieldKey] : ''
  }

  return snapshot
}

export function splitMultilineItems(rawValue) {
  return String(rawValue || '')
    .split(/\r?\n/)
    .map((item) => item.trim())
    .filter(Boolean)
}

export function buildSubmissionFields(template, fieldValues = {}, options = {}) {
  const submission = {}
  const resolveSelectToLabel = options.resolveSelectToLabel === true

  for (const field of template?.fields || []) {
    const fieldKey = normalizeFieldKey(field?.key)
    if (fieldKey === '') {
      continue
    }

    const rawValue = typeof fieldValues[fieldKey] === 'string' ? fieldValues[fieldKey] : ''

    if (field.type === 'list') {
      submission[fieldKey] = splitMultilineItems(rawValue)
      continue
    }

    if (resolveSelectToLabel && field.type === 'select') {
      submission[fieldKey] = resolveSelectLabel(field, rawValue)
      continue
    }

    submission[fieldKey] = rawValue.trim()
  }

  return submission
}

export function resolveSelectLabel(field, value) {
  const normalizedValue = String(value || '').trim()
  const option = Array.isArray(field?.options)
    ? field.options.find((candidate) => candidate.value === normalizedValue)
    : null

  return option?.label || normalizedValue
}

export function formatTemplateTitle(template, rawTitle, emptyLabel = 'Titulo da issue') {
  const normalizedTitle = String(rawTitle || '').trim()
  if (normalizedTitle === '') {
    return emptyLabel
  }

  const prefix = typeof template?.titlePrefix === 'string' ? template.titlePrefix.trim() : ''
  if (prefix === '') {
    return normalizedTitle
  }

  const prefixPattern = new RegExp(`^\\[${escapeRegExp(prefix)}\\]\\s+`, 'i')
  if (prefixPattern.test(normalizedTitle)) {
    return normalizedTitle
  }

  return `[${prefix}] ${normalizedTitle}`
}

function resolveFieldDefaultValue(field) {
  if (typeof field?.defaultValue === 'function') {
    const computedValue = field.defaultValue()
    return typeof computedValue === 'string' ? computedValue : ''
  }

  return typeof field?.defaultValue === 'string' ? field.defaultValue : ''
}

function normalizeFieldKey(value) {
  return typeof value === 'string' ? value.trim() : ''
}

function escapeRegExp(value) {
  return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
