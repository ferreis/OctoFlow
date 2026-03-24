export const PROJECT_STANDARD_LABELS = Object.freeze([
  Object.freeze({
    name: 'api',
    color: '1D4ED8',
    description: 'Integracoes com APIs, criacao/consumo de endpoints e comunicacao entre servicos.',
  }),
  Object.freeze({
    name: 'backend',
    color: '7C3AED',
    description: 'Alteracoes ou implementacoes no codigo do backend (regras de negocio, servicos, APIs).',
  }),
  Object.freeze({
    name: 'blocked',
    color: 'DC2626',
    description: 'Tarefa bloqueada por dependencia externa ou aguardando outra entrega.',
  }),
  Object.freeze({
    name: 'bug',
    color: 'EF4444',
    description: 'Erro ou comportamento inesperado no sistema.',
  }),
  Object.freeze({
    name: 'database',
    color: '0F766E',
    description: 'Alteracoes no banco de dados (migrations, queries, estrutura ou manutencao).',
  }),
  Object.freeze({
    name: 'feature',
    color: '16A34A',
    description: 'Nova funcionalidade ou recurso relevante para o sistema.',
  }),
  Object.freeze({
    name: 'frontend',
    color: '0891B2',
    description: 'Alteracoes na interface, componentes ou logica do frontend.',
  }),
  Object.freeze({
    name: 'improvement',
    color: '22C55E',
    description: 'Melhoria em funcionalidades existentes (performance, usabilidade, otimizacao).',
  }),
  Object.freeze({
    name: 'invalid',
    color: '64748B',
    description: 'Issue invalida, duplicada ou que nao sera tratada.',
  }),
  Object.freeze({
    name: 'refactor',
    color: '8B5CF6',
    description: 'Refatoracao de codigo sem alteracao de comportamento.',
  }),
  Object.freeze({
    name: 'security',
    color: 'EA580C',
    description: 'Questoes de seguranca (autenticacao, autorizacao, validacoes, protecao de dados).',
  }),
  Object.freeze({
    name: 'style',
    color: 'DB2777',
    description: 'Ajustes visuais ou de layout (CSS, UI, responsividade).',
  }),
  Object.freeze({
    name: 'doc',
    color: '15803D',
    description: 'Documentacao (README, guias, padroes, comentarios ou documentacao tecnica).',
  }),
  Object.freeze({
    name: 'test',
    color: '65A30D',
    description: 'Criacao ou ajuste de testes (unitarios, integracao, e2e).',
  }),
  Object.freeze({
    name: 'qa',
    color: '0D9488',
    description: 'Validacao, testes manuais ou reporte de qualidade.',
  }),
  Object.freeze({
    name: 'performance',
    color: 'D97706',
    description: 'Melhorias de desempenho (tempo de resposta, otimizacao, carga).',
  }),
  Object.freeze({
    name: 'ci/cd',
    color: '1E40AF',
    description: 'Configuracao ou ajustes de pipeline, build, deploy.',
  }),
  Object.freeze({
    name: 'deps',
    color: '475569',
    description: 'Atualizacao ou gerenciamento de dependencias.',
  }),
])

const DEFAULT_LABEL_COLOR = '94A3B8'

function normalizeLabelDescription(value) {
  if (typeof value !== 'string') {
    return null
  }

  const normalizedDescription = value.trim()
  return normalizedDescription === '' ? null : normalizedDescription
}

export function normalizeProjectLabelName(value) {
  return String(value || '')
    .trim()
    .replace(/\s+/g, ' ')
}

export function buildProjectLabelNameKey(value) {
  return normalizeProjectLabelName(value).toLocaleLowerCase()
}

export function normalizeProjectLabelColor(value, fallbackColor = DEFAULT_LABEL_COLOR) {
  const normalizedValue = String(value || '')
    .trim()
    .replace(/^#/, '')

  if (/^[0-9a-fA-F]{6}$/.test(normalizedValue)) {
    return normalizedValue.toUpperCase()
  }

  const normalizedFallback = String(fallbackColor || '')
    .trim()
    .replace(/^#/, '')

  if (/^[0-9a-fA-F]{6}$/.test(normalizedFallback)) {
    return normalizedFallback.toUpperCase()
  }

  return DEFAULT_LABEL_COLOR
}

function buildPresetCatalog() {
  const presetCatalog = new Map()

  for (const presetLabel of PROJECT_STANDARD_LABELS) {
    const normalizedNameKey = buildProjectLabelNameKey(presetLabel.name)
    if (normalizedNameKey === '') {
      continue
    }

    presetCatalog.set(normalizedNameKey, {
      name: normalizeProjectLabelName(presetLabel.name),
      color: normalizeProjectLabelColor(presetLabel.color),
      description: normalizeLabelDescription(presetLabel.description),
    })
  }

  return presetCatalog
}

const PROJECT_STANDARD_LABEL_CATALOG = buildPresetCatalog()

export function normalizeProjectLabelOption(rawLabel = {}) {
  const normalizedName = normalizeProjectLabelName(rawLabel?.name)
  const normalizedNameKey = buildProjectLabelNameKey(normalizedName)
  const presetLabel = PROJECT_STANDARD_LABEL_CATALOG.get(normalizedNameKey) || null
  const normalizedId = String(rawLabel?.id || '').trim()

  return {
    id: normalizedId,
    name: normalizedName,
    color: normalizeProjectLabelColor(rawLabel?.color, presetLabel?.color || DEFAULT_LABEL_COLOR),
    description: normalizeLabelDescription(rawLabel?.description) || presetLabel?.description || null,
    isPreset: Boolean(presetLabel),
    isNew: normalizedId === '',
  }
}

export function buildMergedProjectLabelOptions(...rawLabelCollections) {
  const labelCatalog = new Map()

  for (const presetLabel of PROJECT_STANDARD_LABELS) {
    const normalizedPreset = normalizeProjectLabelOption({
      id: '',
      name: presetLabel.name,
      color: presetLabel.color,
      description: presetLabel.description,
    })
    const normalizedNameKey = buildProjectLabelNameKey(normalizedPreset.name)

    if (normalizedNameKey !== '') {
      labelCatalog.set(normalizedNameKey, normalizedPreset)
    }
  }

  for (const rawLabelCollection of rawLabelCollections) {
    if (!Array.isArray(rawLabelCollection)) {
      continue
    }

    for (const rawLabel of rawLabelCollection) {
      const normalizedLabel = normalizeProjectLabelOption(rawLabel)
      const normalizedNameKey = buildProjectLabelNameKey(normalizedLabel.name)
      if (normalizedNameKey === '') {
        continue
      }

      const existingLabel = labelCatalog.get(normalizedNameKey)
      if (!existingLabel) {
        labelCatalog.set(normalizedNameKey, normalizedLabel)
        continue
      }

      const mergedLabel = {
        ...existingLabel,
        ...normalizedLabel,
        id: normalizedLabel.id !== '' ? normalizedLabel.id : existingLabel.id,
        color: normalizedLabel.color || existingLabel.color,
        description: normalizedLabel.description || existingLabel.description,
      }

      mergedLabel.isNew = mergedLabel.id === ''
      mergedLabel.isPreset = Boolean(existingLabel.isPreset || normalizedLabel.isPreset)
      labelCatalog.set(normalizedNameKey, mergedLabel)
    }
  }

  return Array.from(labelCatalog.values())
    .filter((labelOption) => labelOption.name !== '')
    .sort((leftOption, rightOption) => leftOption.name.localeCompare(rightOption.name, 'pt-BR', { sensitivity: 'base' }))
}

export function buildLabelNamesFromSelection(selectedLabels = []) {
  if (!Array.isArray(selectedLabels)) {
    return []
  }

  const uniqueLabelNames = []
  const uniqueNameKeys = new Set()

  for (const selectedLabel of selectedLabels) {
    const normalizedLabelName = normalizeProjectLabelName(selectedLabel?.name)
    const normalizedNameKey = buildProjectLabelNameKey(normalizedLabelName)
    if (normalizedNameKey === '' || uniqueNameKeys.has(normalizedNameKey)) {
      continue
    }

    uniqueNameKeys.add(normalizedNameKey)
    uniqueLabelNames.push(normalizedLabelName)
  }

  return uniqueLabelNames
}

export function mapLabelNamesToSelectedOptions(labelNames = [], availableLabelOptions = []) {
  if (!Array.isArray(labelNames)) {
    return []
  }

  const optionCatalog = new Map()
  const mergedOptions = buildMergedProjectLabelOptions(availableLabelOptions)

  for (const mergedOption of mergedOptions) {
    optionCatalog.set(buildProjectLabelNameKey(mergedOption.name), mergedOption)
  }

  const mappedSelectedOptions = []
  const uniqueNameKeys = new Set()

  for (const labelName of labelNames) {
    const normalizedLabelName = normalizeProjectLabelName(labelName)
    const normalizedNameKey = buildProjectLabelNameKey(normalizedLabelName)
    if (normalizedNameKey === '' || uniqueNameKeys.has(normalizedNameKey)) {
      continue
    }

    uniqueNameKeys.add(normalizedNameKey)
    const existingOption = optionCatalog.get(normalizedNameKey)
    if (existingOption) {
      mappedSelectedOptions.push({
        ...existingOption,
      })
      continue
    }

    mappedSelectedOptions.push(normalizeProjectLabelOption({
      id: '',
      name: normalizedLabelName,
    }))
  }

  return mappedSelectedOptions
}
