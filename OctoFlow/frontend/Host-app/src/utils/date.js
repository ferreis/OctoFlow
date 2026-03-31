function parseDateValue(rawDateValue) {
  if (rawDateValue instanceof Date) {
    return Number.isNaN(rawDateValue.getTime()) ? null : rawDateValue
  }

  if (typeof rawDateValue === 'number' && Number.isFinite(rawDateValue)) {
    const parsedFromTimestamp = new Date(rawDateValue)
    return Number.isNaN(parsedFromTimestamp.getTime()) ? null : parsedFromTimestamp
  }

  if (typeof rawDateValue !== 'string') {
    return null
  }

  const normalizedDateValue = rawDateValue.trim()
  if (normalizedDateValue === '') {
    return null
  }

  const normalizedIsoValue = normalizedDateValue.includes(' ') && !normalizedDateValue.includes('T')
    ? normalizedDateValue.replace(' ', 'T')
    : normalizedDateValue

  const dateMatch = normalizedIsoValue.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (dateMatch) {
    const yearValue = Number(dateMatch[1])
    const monthValue = Number(dateMatch[2])
    const dayValue = Number(dateMatch[3])
    const parsedDate = new Date(yearValue, monthValue - 1, dayValue)
    return Number.isNaN(parsedDate.getTime()) ? null : parsedDate
  }

  const dateTimeMatch = normalizedIsoValue.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::\d{2}(?:\.\d{1,6})?)?$/)
  if (dateTimeMatch) {
    const yearValue = Number(dateTimeMatch[1])
    const monthValue = Number(dateTimeMatch[2])
    const dayValue = Number(dateTimeMatch[3])
    const hourValue = Number(dateTimeMatch[4])
    const minuteValue = Number(dateTimeMatch[5])
    const parsedDate = new Date(yearValue, monthValue - 1, dayValue, hourValue, minuteValue)
    return Number.isNaN(parsedDate.getTime()) ? null : parsedDate
  }

  const parsedDate = new Date(normalizedIsoValue)
  return Number.isNaN(parsedDate.getTime()) ? null : parsedDate
}

function padTwoDigits(rawValue) {
  return String(Math.trunc(Math.abs(Number(rawValue || 0)))).padStart(2, '0')
}

function buildDateLabel(parsedDate) {
  const dayLabel = padTwoDigits(parsedDate.getDate())
  const monthLabel = padTwoDigits(parsedDate.getMonth() + 1)
  const yearLabel = String(parsedDate.getFullYear())
  return `${dayLabel}/${monthLabel}/${yearLabel}`
}

function buildIsoDateLabel(parsedDate) {
  const yearLabel = String(parsedDate.getFullYear())
  const monthLabel = padTwoDigits(parsedDate.getMonth() + 1)
  const dayLabel = padTwoDigits(parsedDate.getDate())
  return `${yearLabel}-${monthLabel}-${dayLabel}`
}

function buildTimeLabel(parsedDate) {
  const hourLabel = padTwoDigits(parsedDate.getHours())
  const minuteLabel = padTwoDigits(parsedDate.getMinutes())
  return `${hourLabel}:${minuteLabel}`
}

export function formatDate(value, emptyLabel = '-') {
  const parsedDate = parseDateValue(value)
  if (!parsedDate) {
    return emptyLabel
  }

  return buildDateLabel(parsedDate)
}

export function formatDateTime(value, emptyLabel = 'sem data') {
  const parsedDate = parseDateValue(value)
  if (!parsedDate) {
    return emptyLabel
  }

  return `${buildDateLabel(parsedDate)} ${buildTimeLabel(parsedDate)}`
}

export function buildCurrentDateTimeLabel(value = new Date()) {
  return formatDateTime(value, '')
}

export function formatDateAsIsoInput(value = new Date(), emptyLabel = '') {
  const parsedDate = parseDateValue(value)
  if (!parsedDate) {
    return emptyLabel
  }

  return buildIsoDateLabel(parsedDate)
}

export function buildCurrentMonthDateRange(referenceDate = new Date()) {
  const parsedReferenceDate = parseDateValue(referenceDate) || new Date()
  const startDate = new Date(parsedReferenceDate.getFullYear(), parsedReferenceDate.getMonth(), 1)
  const endDate = new Date(parsedReferenceDate.getFullYear(), parsedReferenceDate.getMonth() + 1, 0)

  return {
    startDate: formatDateAsIsoInput(startDate, ''),
    endDate: formatDateAsIsoInput(endDate, ''),
  }
}
