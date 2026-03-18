export function formatDateTime(value, emptyLabel = 'sem data') {
  if (typeof value !== 'string' || value.trim() === '') {
    return emptyLabel
  }

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(value))
}
