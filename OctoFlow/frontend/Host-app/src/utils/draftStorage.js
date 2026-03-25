export const TASK_DRAFT_MAX_AGE_MS = 60 * 60 * 1000
export const TASK_DRAFT_MAX_ITEMS = 5

const DEFAULT_TIMESTAMP_FALLBACK = 0

export function readDraft(storageKey, options = {}) {
  if (typeof window === 'undefined') {
    return null
  }

  const normalizedStorageKey = normalizeStorageKey(storageKey)
  if (normalizedStorageKey === '') {
    return null
  }

  pruneDraftCollection(options)

  const rawStoredValue = window.localStorage.getItem(normalizedStorageKey)
  if (typeof rawStoredValue !== 'string' || rawStoredValue.trim() === '') {
    return null
  }

  const parsedDraft = parseDraftPayload(rawStoredValue)
  if (!parsedDraft) {
    window.localStorage.removeItem(normalizedStorageKey)
    return null
  }

  const maxAgeMs = normalizeMaxAgeMs(options.maxAgeMs)
  if (isDraftExpired(parsedDraft, maxAgeMs)) {
    window.localStorage.removeItem(normalizedStorageKey)
    return null
  }

  return parsedDraft
}

export function writeDraft(storageKey, payload, options = {}) {
  if (typeof window === 'undefined') {
    return
  }

  const normalizedStorageKey = normalizeStorageKey(storageKey)
  if (normalizedStorageKey === '') {
    return
  }

  const normalizedPayload = payload && typeof payload === 'object' ? payload : {}
  const draftPayload = {
    ...normalizedPayload,
    savedAt: new Date().toISOString(),
  }

  try {
    window.localStorage.setItem(normalizedStorageKey, JSON.stringify(draftPayload))
  } catch {
    return
  }

  pruneDraftCollection(options)
}

export function removeDraft(storageKey) {
  if (typeof window === 'undefined') {
    return
  }

  const normalizedStorageKey = normalizeStorageKey(storageKey)
  if (normalizedStorageKey === '') {
    return
  }

  window.localStorage.removeItem(normalizedStorageKey)
}

function pruneDraftCollection(options = {}) {
  if (typeof window === 'undefined') {
    return
  }

  const scopePrefix = normalizeStorageKey(options.scopePrefix)
  if (scopePrefix === '') {
    return
  }

  const maxDraftItems = normalizeMaxDraftItems(options.maxDraftItems)
  const maxAgeMs = normalizeMaxAgeMs(options.maxAgeMs)
  const collectedDraftItems = []
  const matchingStorageKeys = []

  for (let storageIndex = 0; storageIndex < window.localStorage.length; storageIndex += 1) {
    const storageKey = window.localStorage.key(storageIndex)
    if (typeof storageKey !== 'string' || !storageKey.startsWith(scopePrefix)) {
      continue
    }

    matchingStorageKeys.push(storageKey)
  }

  for (const storageKey of matchingStorageKeys) {
    if (!storageKey.startsWith(scopePrefix)) {
      continue
    }

    const rawStoredValue = window.localStorage.getItem(storageKey)
    if (typeof rawStoredValue !== 'string' || rawStoredValue.trim() === '') {
      window.localStorage.removeItem(storageKey)
      continue
    }

    const parsedDraft = parseDraftPayload(rawStoredValue)
    if (!parsedDraft) {
      window.localStorage.removeItem(storageKey)
      continue
    }

    if (isDraftExpired(parsedDraft, maxAgeMs)) {
      window.localStorage.removeItem(storageKey)
      continue
    }

    collectedDraftItems.push({
      storageKey,
      timestampMs: resolveDraftTimestamp(parsedDraft),
    })
  }

  if (collectedDraftItems.length <= maxDraftItems) {
    return
  }

  collectedDraftItems
    .sort((leftDraft, rightDraft) => rightDraft.timestampMs - leftDraft.timestampMs)
    .slice(maxDraftItems)
    .forEach((draftItem) => {
      window.localStorage.removeItem(draftItem.storageKey)
    })
}

function parseDraftPayload(rawPayload) {
  try {
    const parsedPayload = JSON.parse(rawPayload)
    return parsedPayload && typeof parsedPayload === 'object' ? parsedPayload : null
  } catch {
    return null
  }
}

function isDraftExpired(draftPayload, maxAgeMs) {
  const draftTimestamp = resolveDraftTimestamp(draftPayload)
  if (draftTimestamp <= DEFAULT_TIMESTAMP_FALLBACK) {
    return true
  }

  return Date.now() - draftTimestamp > maxAgeMs
}

function resolveDraftTimestamp(draftPayload) {
  const rawTimestamp = draftPayload?.savedAt ?? draftPayload?.createdAt ?? draftPayload?.updatedAt ?? null
  if (typeof rawTimestamp !== 'string' || rawTimestamp.trim() === '') {
    return DEFAULT_TIMESTAMP_FALLBACK
  }

  const parsedTimestamp = Date.parse(rawTimestamp)
  return Number.isFinite(parsedTimestamp) ? parsedTimestamp : DEFAULT_TIMESTAMP_FALLBACK
}

function normalizeStorageKey(storageKey) {
  return String(storageKey || '').trim()
}

function normalizeMaxAgeMs(maxAgeMs) {
  const numericValue = Number(maxAgeMs)
  if (!Number.isFinite(numericValue) || numericValue <= 0) {
    return TASK_DRAFT_MAX_AGE_MS
  }

  return Math.round(numericValue)
}

function normalizeMaxDraftItems(maxDraftItems) {
  const numericValue = Number(maxDraftItems)
  if (!Number.isFinite(numericValue) || numericValue < 1) {
    return TASK_DRAFT_MAX_ITEMS
  }

  return Math.max(1, Math.round(numericValue))
}
