const FINANCE_DIRECTION_LABEL_BY_CODE = Object.freeze({
  PAYABLE: 'Contas a pagar',
  RECEIVABLE: 'Contas a receber',
})

const FINANCE_PAYABLE_STATUS_LABEL_BY_CODE = Object.freeze({
  PENDING: 'Pendente',
  PAID: 'Pago',
  PARTIAL: 'Parcial',
  OVERDUE: 'Atrasado',
  SCHEDULED: 'Agendado',
  CANCELED: 'Cancelado',
  NEGOTIATED: 'Negociado',
})

const FINANCE_RECEIVABLE_STATUS_LABEL_BY_CODE = Object.freeze({
  PENDING: 'Pendente',
  FORECAST: 'Previsto',
  RECEIVED: 'Recebido',
  PARTIAL: 'Parcial',
  OVERDUE: 'Atrasado',
  SCHEDULED: 'Agendado',
  CANCELED: 'Cancelado',
  NEGOTIATED: 'Negociado',
})

const FINANCE_ENTRY_MACRO_STATUS_LABEL_BY_CODE = Object.freeze({
  OPEN: 'Em Aberto',
  FINISHED: 'Finalizado',
  PROJECTION: 'Projeção',
})

const FINANCE_ENTRY_TYPE_LABEL_BY_CODE = Object.freeze({
  ONE_OFF: 'Avulso',
  RECURRING: 'Recorrente',
  INSTALLMENT: 'Parcelamento',
  NEGOTIATION: 'Negociação',
  INVESTMENT_CONTRIBUTION: 'Aporte de investimento',
  INVESTMENT_YIELD: 'Rendimento de investimento',
  DEBT: 'Dívida',
  ADJUSTMENT: 'Ajuste',
})

const FINANCE_SOURCE_ORIGIN_LABEL_BY_CODE = Object.freeze({
  MANUAL: 'Manual',
  SYSTEM: 'Sistema',
  IMPORTED: 'Importado',
})

const FINANCE_INVESTMENT_TYPE_LABEL_BY_CODE = Object.freeze({
  SELIC: 'Selic',
  CDB: 'CDB',
  CDI: 'CDI',
  TESOURO: 'Tesouro',
  CUSTOM: 'Customizado',
})

const FINANCE_INVESTMENT_YIELD_MODE_LABEL_BY_CODE = Object.freeze({
  NONE: 'Não gerar',
  ESTIMATED: 'Estimado',
  MANUAL: 'Manual',
})

const FINANCE_EXPORT_TYPE_LABEL_BY_CODE = Object.freeze({
  PAYABLE: 'Contas a pagar',
  RECEIVABLE: 'Contas a receber',
  MONTHLY_SUMMARY: 'Resumo mensal',
  CATEGORY: 'Categorias',
  CASHFLOW: 'Fluxo financeiro',
  INVESTMENT: 'Investimentos',
})

const FINANCE_EXPORT_STATUS_LABEL_BY_CODE = Object.freeze({
  QUEUED: 'Na fila',
  PROCESSING: 'Processando',
  DONE: 'Concluído',
  FAILED: 'Falhou',
  EXPIRED: 'Expirado',
})

const FINANCE_ADDITIONAL_LABEL_BY_CODE = Object.freeze({
  ACTIVE: 'Ativo',
  INACTIVE: 'Inativo',
  BOTH: 'Pagar e receber',
  PLANNED: 'Planejado',
  DONE: 'Concluído',
  REVOKED: 'Revogado',
  CHECKING: 'Conta corrente',
  SAVINGS: 'Poupança',
  CREDIT: 'Cartão',
  CASH: 'Dinheiro',
  INVESTMENT: 'Investimento',
})

const FINANCE_ALIAS_LABEL_BY_CODE = Object.freeze({
  INSTALLMENT: 'Parcelamento',
  INSTALLMENTS: 'Parcelas',
  INSTALLMENT_PLAN: 'Plano de parcelamento',
  'INSTALLMENT PLAN': 'Plano de parcelamento',
})

const FINANCE_TERM_LABEL_BY_CODE = Object.freeze({
  ...FINANCE_DIRECTION_LABEL_BY_CODE,
  ...FINANCE_PAYABLE_STATUS_LABEL_BY_CODE,
  ...FINANCE_RECEIVABLE_STATUS_LABEL_BY_CODE,
  ...FINANCE_ENTRY_MACRO_STATUS_LABEL_BY_CODE,
  ...FINANCE_ENTRY_TYPE_LABEL_BY_CODE,
  ...FINANCE_SOURCE_ORIGIN_LABEL_BY_CODE,
  ...FINANCE_INVESTMENT_TYPE_LABEL_BY_CODE,
  ...FINANCE_INVESTMENT_YIELD_MODE_LABEL_BY_CODE,
  ...FINANCE_EXPORT_TYPE_LABEL_BY_CODE,
  ...FINANCE_EXPORT_STATUS_LABEL_BY_CODE,
  ...FINANCE_ADDITIONAL_LABEL_BY_CODE,
  ...FINANCE_ALIAS_LABEL_BY_CODE,
})

function createSelectOptions(codesList, labelsByCode) {
  return Object.freeze(
    codesList.map((codeValue) => Object.freeze({
      value: codeValue,
      label: labelsByCode[codeValue] || codeValue,
    })),
  )
}

export const FINANCE_DIRECTION_OPTIONS = createSelectOptions(
  ['PAYABLE', 'RECEIVABLE'],
  FINANCE_DIRECTION_LABEL_BY_CODE,
)

export const FINANCE_ENTRY_STATUS_OPTIONS = createSelectOptions(
  ['PENDING', 'FORECAST', 'PARTIAL', 'OVERDUE', 'PAID', 'RECEIVED', 'SCHEDULED', 'NEGOTIATED', 'CANCELED'],
  { ...FINANCE_PAYABLE_STATUS_LABEL_BY_CODE, ...FINANCE_RECEIVABLE_STATUS_LABEL_BY_CODE },
)

export const FINANCE_ENTRY_MACRO_STATUS_OPTIONS = createSelectOptions(
  ['OPEN', 'FINISHED', 'PROJECTION'],
  FINANCE_ENTRY_MACRO_STATUS_LABEL_BY_CODE,
)

export const FINANCE_ENTRY_TYPE_OPTIONS = createSelectOptions(
  ['ONE_OFF', 'RECURRING', 'INSTALLMENT', 'NEGOTIATION', 'INVESTMENT_CONTRIBUTION', 'INVESTMENT_YIELD', 'DEBT', 'ADJUSTMENT'],
  FINANCE_ENTRY_TYPE_LABEL_BY_CODE,
)

export const FINANCE_SOURCE_ORIGIN_OPTIONS = createSelectOptions(
  ['MANUAL', 'SYSTEM', 'IMPORTED'],
  FINANCE_SOURCE_ORIGIN_LABEL_BY_CODE,
)

export const FINANCE_INVESTMENT_TYPE_OPTIONS = createSelectOptions(
  ['SELIC', 'CDB', 'CDI', 'TESOURO', 'CUSTOM'],
  FINANCE_INVESTMENT_TYPE_LABEL_BY_CODE,
)

export const FINANCE_INVESTMENT_YIELD_MODE_OPTIONS = createSelectOptions(
  ['NONE', 'ESTIMATED', 'MANUAL'],
  FINANCE_INVESTMENT_YIELD_MODE_LABEL_BY_CODE,
)

export const FINANCE_EXPORT_TYPE_OPTIONS = createSelectOptions(
  ['PAYABLE', 'RECEIVABLE', 'MONTHLY_SUMMARY', 'CATEGORY', 'CASHFLOW', 'INVESTMENT'],
  FINANCE_EXPORT_TYPE_LABEL_BY_CODE,
)

export const FINANCE_EXPORT_STATUS_OPTIONS = createSelectOptions(
  ['QUEUED', 'PROCESSING', 'DONE', 'FAILED', 'EXPIRED'],
  FINANCE_EXPORT_STATUS_LABEL_BY_CODE,
)

export const FINANCE_BANK_ACCOUNT_TYPE_OPTIONS = createSelectOptions(
  ['CHECKING', 'SAVINGS', 'CREDIT', 'INVESTMENT', 'CASH'],
  FINANCE_ADDITIONAL_LABEL_BY_CODE,
)

export const OPEN_FINANCE_CONNECTION_STATUS_OPTIONS = createSelectOptions(
  ['ACTIVE', 'REVOKED', 'EXPIRED'],
  FINANCE_ADDITIONAL_LABEL_BY_CODE,
)

export function translateFinanceTerm(termCode, fallbackLabel = '-') {
  const normalizedTermCode = String(termCode || '').trim().toUpperCase()
  if (!normalizedTermCode) {
    return fallbackLabel
  }

  return FINANCE_TERM_LABEL_BY_CODE[normalizedTermCode] || normalizedTermCode
}

export function translateFinanceExportType(exportTypeCode, fallbackLabel = '-') {
  const normalizedExportType = String(exportTypeCode || '').trim().toUpperCase()
  if (!normalizedExportType) {
    return fallbackLabel
  }

  return FINANCE_EXPORT_TYPE_LABEL_BY_CODE[normalizedExportType] || translateFinanceTerm(normalizedExportType, fallbackLabel)
}

export function translateFinanceExportStatus(exportStatusCode, fallbackLabel = '-') {
  const normalizedExportStatus = String(exportStatusCode || '').trim().toUpperCase()
  if (!normalizedExportStatus) {
    return fallbackLabel
  }

  return FINANCE_EXPORT_STATUS_LABEL_BY_CODE[normalizedExportStatus] || translateFinanceTerm(normalizedExportStatus, fallbackLabel)
}
