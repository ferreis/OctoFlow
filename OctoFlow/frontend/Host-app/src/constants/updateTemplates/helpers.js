export function buildCurrentDateTimeLabel(value = new Date()) {
  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(value)
}

export function buildCurrentDateLabel() {
  return buildCurrentDateTimeLabel()
}
