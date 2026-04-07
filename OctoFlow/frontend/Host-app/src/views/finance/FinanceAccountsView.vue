<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useFinanceStore } from '../../stores/financeStore'
import { useSessionStore } from '../../stores/sessionStore'
import { useNotification } from '../../composables/useNotification'
import { RemoteFinanceEmptyState, RemoteFinanceStatusBadge } from '../../federation/remoteComponents'
import FinanceEntriesListPanel from '../../components/shared/FinanceEntriesListPanel.vue'
import AppConfirmDialog from '../../components/shared/AppConfirmDialog.vue'
import {
  createFinanceEntry,
  updateFinanceEntry,
  deleteFinanceEntry,
  createFinanceSettlement,
  fetchFinanceEntries,
  fetchFinanceDashboardSummary,
  fetchFinanceRecurringRules,
  fetchFinanceInstallmentPlans,
  createFinanceRecurringRule,
  updateFinanceRecurringRule,
  deleteFinanceRecurringRule,
  generateFinanceRecurringRuleManually,
  createFinanceInstallmentPlan,
  updateFinanceInstallmentPlan,
  renegotiateFinanceInstallmentPlan,
} from '../../services/finance'
import {
  FINANCE_DIRECTION_OPTIONS,
  FINANCE_ENTRY_TYPE_OPTIONS,
  FINANCE_ENTRY_STATUS_OPTIONS,
  translateFinanceTerm,
} from '../../constants/financeTerms'
import { buildCurrentMonthDateRange, formatDate } from '../../utils/date'
import { extractHttpMessage } from '../../utils/httpErrors'

const financeStore = useFinanceStore()
const sessionStore = useSessionStore()
const { categories, bankAccounts, recurringTypes } = storeToRefs(financeStore)
const { notifyUser } = useNotification()

const LIST_ITEMS_PER_PAGE = 10
const directionOptions = FINANCE_DIRECTION_OPTIONS
const manualEntryTypeOptions = FINANCE_ENTRY_TYPE_OPTIONS.filter((o) => ['ONE_OFF', 'ADJUSTMENT'].includes(o.value))
const entryStatusOptions = FINANCE_ENTRY_STATUS_OPTIONS

// ─── Sub-tabs ───
const accountsTabOptions = [
  { key: 'overview', label: 'Visão Geral' },
  { key: 'payable', label: 'Contas a Pagar' },
  { key: 'receivable', label: 'Contas a Receber' },
]
const activeAccountsTab = ref('overview')

// ─── State ───
const entriesState = ref([])
const entriesMeta = ref({ page: 1, itemsPerPage: LIST_ITEMS_PER_PAGE, total: 0 })
const recurringRules = ref([])
const installmentPlans = ref([])
const accountsOverviewSummary = ref(null)
const accountsOverviewMonth = ref(getNextMonthInputValue())

const loadingEntries = ref(false)
const loadingDashboard = ref(false)

const entryFilters = reactive({ direction: '', status: '', search: '', startDate: '', endDate: '', page: 1, itemsPerPage: LIST_ITEMS_PER_PAGE })

// ─── Forms ───
const entryForm = reactive({ direction: 'PAYABLE', entryType: 'ONE_OFF', title: '', expectedAmountBrl: '', dueDate: getCurrentDateInputValue(), categoryId: '', bankAccountId: '' })
const entryEditingId = ref(null)

const settlementForm = reactive({ entryId: '', amountBrl: '', settledAt: '', bankAccountId: '', useCreditCard: false, creditCardId: '', creditCardInterestRatePercent: '2.99', creditCardIofRatePercent: '0.38', creditCardDueDate: '' })

const recurringForm = reactive({ direction: 'PAYABLE', title: '', amountBrl: '', dayOfMonth: 5, startsAt: getCurrentDateInputValue(), recurringTypeId: '', categoryId: '', defaultBankAccountId: '' })
const recurringRuleEditingId = ref(null)

const installmentForm = reactive({ direction: 'PAYABLE', title: '', totalAmountBrl: '', downPaymentBrl: '0', installmentsCount: 12, interestAmountBrl: '0', discountAmountBrl: '0', fineAmountBrl: '0', firstDueDate: '', categoryId: '', defaultBankAccountId: '' })

const renegotiationForm = reactive({ planId: '', installmentsCount: 6, reason: 'Renegociação manual', categoryId: '', defaultBankAccountId: '' })

const selectedAccountActionType = ref('ENTRY')
const accountActionModalState = reactive({ isOpen: false, actionType: 'ENTRY' })
const confirmDialogState = reactive({ isOpen: false, title: '', message: '', confirmLabel: 'Confirmar', confirmTone: 'danger', processing: false })
const confirmDialogAction = ref(null)

const localRecurringPage = ref(1)
const localInstallmentPage = ref(1)

// ─── Computed ───
const isOverview = computed(() => activeAccountsTab.value === 'overview')
const isPayable = computed(() => activeAccountsTab.value === 'payable')
const isReceivable = computed(() => activeAccountsTab.value === 'receivable')
const showEntryManagement = computed(() => isPayable.value || isReceivable.value)
const showRecurring = computed(() => isPayable.value || isReceivable.value)
const showInstallment = computed(() => isPayable.value)

const accountsDirectionByTab = computed(() => {
  if (isPayable.value) return 'PAYABLE'
  if (isReceivable.value) return 'RECEIVABLE'
  return ''
})

const accountsEntriesTitle = computed(() => {
  if (isPayable.value) return 'Lançamentos - Contas a pagar'
  if (isReceivable.value) return 'Lançamentos - Contas a receber'
  return 'Lançamentos unificados (pagar e receber)'
})

const totalsLabel = computed(() => {
  const total = Number(entriesMeta.value.total || 0)
  if (total <= 0) return '0 lançamentos'
  const first = (Number(entriesMeta.value.page || 1) - 1) * LIST_ITEMS_PER_PAGE + 1
  const last = Math.min(first + entriesState.value.length - 1, total)
  return `${first}-${last} de ${total} lançamentos`
})

const filteredRecurringRules = computed(() => {
  if (!showRecurring.value) return []
  const dir = accountsDirectionByTab.value
  return recurringRules.value.filter((r) => {
    const d = String(r?.direction || '').toUpperCase()
    return d === '' ? dir === 'PAYABLE' : d === dir
  })
})

const filteredInstallmentPlans = computed(() => {
  if (!showInstallment.value) return []
  return installmentPlans.value.filter((p) => {
    const d = String(p?.direction || '').toUpperCase()
    return d === '' ? true : d === 'PAYABLE'
  })
})

const paginatedRecurringRules = computed(() => {
  const start = (localRecurringPage.value - 1) * LIST_ITEMS_PER_PAGE
  return filteredRecurringRules.value.slice(start, start + LIST_ITEMS_PER_PAGE)
})
const paginatedInstallmentPlans = computed(() => {
  const start = (localInstallmentPage.value - 1) * LIST_ITEMS_PER_PAGE
  return filteredInstallmentPlans.value.slice(start, start + LIST_ITEMS_PER_PAGE)
})

const accountsOverviewSelectedMonthLabel = computed(() => formatYearMonthLabel(accountsOverviewMonth.value))

const accountsOverviewComparisonRows = computed(() => {
  const summary = accountsOverviewSummary.value
  if (!summary) return []
  const eI = Number(summary.expectedIncomeBrl || 0), rI = Number(summary.realizedIncomeBrl || 0)
  const eE = Number(summary.expectedExpenseBrl || 0), rE = Number(summary.realizedExpenseBrl || 0)
  const remI = Math.max(0, roundMoney(eI - rI)), remE = Math.max(0, roundMoney(eE - rE))
  return [
    { key: 'receivable', label: 'Contas a receber', expectedBrl: eI, realizedBrl: rI, remainingBrl: remI, progressPercent: eI > 0 ? roundMoney((rI / eI) * 100) : null },
    { key: 'payable', label: 'Contas a pagar', expectedBrl: eE, realizedBrl: rE, remainingBrl: remE, progressPercent: eE > 0 ? roundMoney((rE / eE) * 100) : null },
    { key: 'total', label: 'Total (Receber - Pagar)', expectedBrl: roundMoney(eI - eE), realizedBrl: roundMoney(rI - rE), remainingBrl: roundMoney(remI - remE), progressPercent: null },
  ]
})

const availableAccountActionOptions = computed(() => {
  const opts = []
  if (showEntryManagement.value) opts.push({ value: 'ENTRY', label: 'Lançamento avulso' }, { value: 'SETTLEMENT', label: 'Baixa de lançamento' })
  if (showRecurring.value) opts.push({ value: 'RECURRING_RULE', label: 'Regra recorrente' })
  if (showInstallment.value) opts.push({ value: 'INSTALLMENT_PLAN', label: 'Plano de parcelamento' }, { value: 'RENEGOTIATION', label: 'Renegociar plano' })
  return opts
})

const accountActionModalTitle = computed(() => {
  const t = accountActionModalState.actionType
  if (t === 'ENTRY') return entryEditingId.value ? 'Editar lançamento' : 'Novo lançamento'
  if (t === 'SETTLEMENT') return 'Baixa de lançamento'
  if (t === 'RECURRING_RULE') return recurringRuleEditingId.value ? 'Editar recorrência' : 'Nova recorrência'
  if (t === 'INSTALLMENT_PLAN') return 'Novo parcelamento'
  if (t === 'RENEGOTIATION') return 'Renegociar plano'
  return 'Gerenciar lançamento'
})

const entryTypeOptionsForForm = computed(() => entryForm.direction === 'RECEIVABLE' ? manualEntryTypeOptions.filter((o) => o.value !== 'DEBT') : manualEntryTypeOptions)

const availableSettlementEntries = computed(() => entriesState.value.filter((e) => {
  const rem = Number(e?.remainingAmountBrl || 0)
  return rem > 0 && !['PAID', 'RECEIVED', 'CANCELED'].includes(String(e?.status || '').toUpperCase())
}))
const selectedSettlementEntry = computed(() => availableSettlementEntries.value.find((e) => Number(e?.id) === Number(settlementForm.entryId)) || null)
const selectedSettlementRemainingAmountBrl = computed(() => Number(selectedSettlementEntry.value?.remainingAmountBrl || 0))

const availableRecurringTypes = computed(() => recurringTypes.value.filter((t) => String(t?.name || '').trim().toLowerCase() !== 'mensal'))

const availableCreditCardAccounts = computed(() => bankAccounts.value.filter((a) => String(a?.accountType || '').toUpperCase() === 'CREDIT' && Boolean(a?.isActive)))

// ─── Helpers ───
function getCurrentDateInputValue() { return new Date().toISOString().slice(0, 10) }
function getNextMonthInputValue() {
  const d = new Date()
  d.setMonth(d.getMonth() + 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
function formatYearMonthLabel(v) {
  const [y, m] = String(v || '').split('-')
  if (!y || !m) return ''
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })
}
function formatCurrency(val) { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2 }).format(Number(val || 0)) }
function formatPercent(val) { return `${Number(val || 0).toFixed(1)}%` }
function roundMoney(v) { return Math.round(Number(v || 0) * 100) / 100 }
function getFinanceLabel(term, fallback = '-') { return translateFinanceTerm(term, fallback) }

// ─── Data loading ───
async function loadEntries() {
  loadingEntries.value = true
  try {
    const filters = { ...entryFilters }
    if (accountsDirectionByTab.value) filters.direction = accountsDirectionByTab.value
    const res = await fetchFinanceEntries(filters, { page: entryFilters.page, itemsPerPage: LIST_ITEMS_PER_PAGE })
    entriesState.value = res.data?.items || []
    const meta = res.data?.item || {}
    entriesMeta.value = { page: Number(meta.page || 1), itemsPerPage: LIST_ITEMS_PER_PAGE, total: Number(meta.totalItems || meta.total || 0) }
  } catch (err) { console.error('[AccountsView] loadEntries:', err) }
  finally { loadingEntries.value = false }
}

async function loadOverviewSummary() {
  loadingDashboard.value = true
  try {
    const refDate = new Date()
    const [y, m] = String(accountsOverviewMonth.value || '').split('-')
    if (y && m) { refDate.setFullYear(Number(y)); refDate.setMonth(Number(m) - 1) }
    const dateRange = buildCurrentMonthDateRange(refDate)
    const res = await fetchFinanceDashboardSummary(dateRange)
    accountsOverviewSummary.value = res.data?.item || null
  } catch (err) { console.error('[AccountsView] loadOverviewSummary:', err) }
  finally { loadingDashboard.value = false }
}

async function loadRecurringRules() {
  try { const res = await fetchFinanceRecurringRules(); recurringRules.value = res.data?.items || [] }
  catch (err) { console.error('[AccountsView] loadRecurring:', err) }
}

async function loadInstallments() {
  try { const res = await fetchFinanceInstallmentPlans(); installmentPlans.value = res.data?.items || [] }
  catch (err) { console.error('[AccountsView] loadInstallments:', err) }
}

async function loadTabData() {
  const tab = activeAccountsTab.value
  if (tab === 'overview') { await Promise.all([loadEntries(), loadOverviewSummary()]) }
  else { await Promise.all([loadEntries(), loadRecurringRules(), loadInstallments()]) }
}

// ─── Entry CRUD ───
function resetEntryForm() { Object.assign(entryForm, { direction: accountsDirectionByTab.value || 'PAYABLE', entryType: 'ONE_OFF', title: '', expectedAmountBrl: '', dueDate: getCurrentDateInputValue(), categoryId: '', bankAccountId: '' }); entryEditingId.value = null }

function startEditingEntry(entry) {
  entryForm.direction = entry.direction || 'PAYABLE'
  entryForm.entryType = entry.entryType || 'ONE_OFF'
  entryForm.title = entry.title || ''
  entryForm.expectedAmountBrl = String(entry.expectedAmountBrl || '')
  entryForm.dueDate = entry.dueDate ? String(entry.dueDate).slice(0, 10) : getCurrentDateInputValue()
  entryForm.categoryId = String(entry.categoryId || '')
  entryForm.bankAccountId = String(entry.bankAccountId || '')
  entryEditingId.value = entry.id
  openAccountActionModal('ENTRY')
}

async function submitEntry() {
  try {
    const payload = { ...entryForm, expectedAmountBrl: Number(entryForm.expectedAmountBrl || 0) }
    if (entryEditingId.value) {
      await updateFinanceEntry(entryEditingId.value, payload)
      notifyUser('Lançamento atualizado.', 'success')
    } else {
      await createFinanceEntry(payload)
      notifyUser('Lançamento criado.', 'success')
    }
    resetEntryForm()
    closeAccountActionModal()
    await loadEntries()
  } catch (err) { notifyUser(extractHttpMessage(err, 'Erro ao salvar lançamento.'), 'error') }
}

function requestDeleteEntry(entry) {
  confirmDialogState.isOpen = true
  confirmDialogState.title = 'Excluir lançamento'
  confirmDialogState.message = `Tem certeza que deseja excluir "${entry.title}"?`
  confirmDialogAction.value = async () => {
    confirmDialogState.processing = true
    try { await deleteFinanceEntry(entry.id); notifyUser('Lançamento excluído.', 'success'); await loadEntries() }
    catch (err) { notifyUser('Erro ao excluir.', 'error') }
    finally { confirmDialogState.processing = false; closeConfirmDialog() }
  }
}

// ─── Settlement ───
async function submitSettlement() {
  try {
    const payload = { amountBrl: Number(settlementForm.amountBrl || 0) }
    if (settlementForm.settledAt) payload.settledAt = settlementForm.settledAt
    if (settlementForm.useCreditCard) {
      payload.creditCardId = settlementForm.creditCardId
      payload.creditCardInterestRatePercent = Number(settlementForm.creditCardInterestRatePercent || 0)
      payload.creditCardIofRatePercent = Number(settlementForm.creditCardIofRatePercent || 0)
      if (settlementForm.creditCardDueDate) payload.creditCardDueDate = settlementForm.creditCardDueDate
    } else {
      payload.bankAccountId = settlementForm.bankAccountId
    }
    await createFinanceSettlement(settlementForm.entryId, payload)
    notifyUser('Baixa registrada.', 'success')
    closeAccountActionModal()
    await loadEntries()
  } catch (err) { notifyUser(extractHttpMessage(err, 'Erro ao registrar baixa.'), 'error') }
}

// ─── Recurring ───
async function submitRecurringRule() {
  try {
    const payload = { ...recurringForm, amountBrl: Number(recurringForm.amountBrl || 0), dayOfMonth: Number(recurringForm.dayOfMonth || 5) }
    if (recurringRuleEditingId.value) {
      await updateFinanceRecurringRule(recurringRuleEditingId.value, payload)
      notifyUser('Recorrência atualizada.', 'success')
    } else {
      await createFinanceRecurringRule(payload)
      notifyUser('Recorrência criada.', 'success')
    }
    resetRecurringForm()
    closeAccountActionModal()
    await loadRecurringRules()
  } catch (err) { notifyUser(extractHttpMessage(err, 'Erro ao salvar recorrência.'), 'error') }
}

function resetRecurringForm() {
  Object.assign(recurringForm, { direction: accountsDirectionByTab.value || 'PAYABLE', title: '', amountBrl: '', dayOfMonth: 5, startsAt: getCurrentDateInputValue(), recurringTypeId: '', categoryId: '', defaultBankAccountId: '' })
  recurringRuleEditingId.value = null
}

function startEditingRecurringRule(rule) {
  recurringForm.direction = rule.direction || 'PAYABLE'
  recurringForm.title = rule.title || ''
  recurringForm.amountBrl = String(rule.amountBrl || '')
  recurringForm.dayOfMonth = rule.dayOfMonth || 5
  recurringForm.startsAt = rule.startsAt ? String(rule.startsAt).slice(0, 10) : getCurrentDateInputValue()
  recurringForm.recurringTypeId = String(rule.recurringTypeId || '')
  recurringForm.categoryId = String(rule.categoryId || '')
  recurringForm.defaultBankAccountId = String(rule.defaultBankAccountId || '')
  recurringRuleEditingId.value = rule.id
  openAccountActionModal('RECURRING_RULE')
}

async function requestDeleteRecurringRule(rule) {
  confirmDialogState.isOpen = true
  confirmDialogState.title = 'Excluir recorrência'
  confirmDialogState.message = `Excluir "${rule.title}"?`
  confirmDialogAction.value = async () => {
    confirmDialogState.processing = true
    try { await deleteFinanceRecurringRule(rule.id); notifyUser('Recorrência excluída.', 'success'); await loadRecurringRules() }
    catch (err) { notifyUser('Erro ao excluir.', 'error') }
    finally { confirmDialogState.processing = false; closeConfirmDialog() }
  }
}

async function generateRecurringManually(rule) {
  try { await generateFinanceRecurringRuleManually(rule.id); notifyUser('Lançamento gerado.', 'success'); await loadEntries() }
  catch (err) { notifyUser('Erro ao gerar lançamento.', 'error') }
}

// ─── Installment ───
async function submitInstallmentPlan() {
  try {
    const payload = { ...installmentForm, totalAmountBrl: Number(installmentForm.totalAmountBrl || 0), downPaymentBrl: Number(installmentForm.downPaymentBrl || 0), installmentsCount: Number(installmentForm.installmentsCount || 1) }
    await createFinanceInstallmentPlan(payload)
    notifyUser('Parcelamento criado.', 'success')
    closeAccountActionModal()
    await loadInstallments()
    await loadEntries()
  } catch (err) { notifyUser(extractHttpMessage(err, 'Erro ao criar parcelamento.'), 'error') }
}

async function submitRenegotiation() {
  try {
    await renegotiateFinanceInstallmentPlan(renegotiationForm.planId, { installmentsCount: Number(renegotiationForm.installmentsCount), reason: renegotiationForm.reason, categoryId: renegotiationForm.categoryId || undefined, defaultBankAccountId: renegotiationForm.defaultBankAccountId || undefined })
    notifyUser('Plano renegociado.', 'success')
    closeAccountActionModal()
    await loadInstallments()
    await loadEntries()
  } catch (err) { notifyUser(extractHttpMessage(err, 'Erro ao renegociar.'), 'error') }
}

// ─── Modals / Dialogs ───
function openAccountActionModal(actionType) {
  accountActionModalState.actionType = actionType || selectedAccountActionType.value
  accountActionModalState.isOpen = true
  if (actionType === 'ENTRY' && !entryEditingId.value) resetEntryForm()
}

function closeAccountActionModal() { accountActionModalState.isOpen = false; resetEntryForm() }
function closeConfirmDialog() { confirmDialogState.isOpen = false; confirmDialogAction.value = null }
function handleConfirmDialogAction() { if (typeof confirmDialogAction.value === 'function') confirmDialogAction.value() }

function openSelectedAccountActionModal() { openAccountActionModal(selectedAccountActionType.value) }

// ─── Filter / Page handlers ───
function applyEntryFilters(filters) { Object.assign(entryFilters, filters, { page: 1 }); loadEntries() }
function setEntriesPage(page) { entryFilters.page = page; loadEntries() }

async function handleAccountsOverviewMonthChange() { await loadOverviewSummary() }
async function refreshAccountsOverviewValues() { await Promise.all([loadOverviewSummary(), loadEntries()]) }

function formatSettlementEntryOptionLabel(entry) { return `${entry.title} — ${formatCurrency(entry.remainingAmountBrl)} restante` }
function formatCreditCardOptionLabel(card) { return `${card.name} (${card.accountNumber || 'sem número'})` }

// ─── Watchers ───
watch(activeAccountsTab, () => { entryFilters.page = 1; localRecurringPage.value = 1; localInstallmentPage.value = 1; loadTabData() })
watch(() => settlementForm.entryId, () => {
  const rem = Number(selectedSettlementEntry.value?.remainingAmountBrl || 0)
  if (rem > 0) settlementForm.amountBrl = rem.toFixed(2)
})

onMounted(() => { loadTabData() })
</script>

<template>
  <section class="finance-section">
    <!-- Sub-tabs -->
    <nav class="finance-subtabs" aria-label="Abas de contas">
      <button v-for="tab in accountsTabOptions" :key="tab.key" type="button" class="finance-subtab-button" :class="{ active: activeAccountsTab === tab.key }" @click="activeAccountsTab = tab.key">
        {{ tab.label }}
      </button>
    </nav>

    <!-- Overview: Resumo Rápido -->
    <article v-if="isOverview" class="finance-panel">
      <header>
        <div>
          <h3>Resumo rápido: pagar × receber</h3>
          <small>Mostrando o previsto de {{ accountsOverviewSelectedMonthLabel }}.</small>
        </div>
        <div class="finance-form-actions">
          <label class="finance-overview-month-field">
            <span>Mês</span>
            <input v-model="accountsOverviewMonth" type="month" @change="handleAccountsOverviewMonthChange">
          </label>
          <button type="button" class="finance-inline-action" :disabled="loadingDashboard" @click="refreshAccountsOverviewValues">
            {{ loadingDashboard ? 'Atualizando...' : 'Atualizar valores' }}
          </button>
        </div>
      </header>

      <div v-if="accountsOverviewComparisonRows.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr><th>Tipo</th><th>Previsto</th><th>Realizado</th><th>Restante</th><th>Execução</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in accountsOverviewComparisonRows" :key="row.key" :class="{ 'finance-total-summary-row': row.key === 'total' }">
              <td>{{ row.label }}</td>
              <td>{{ formatCurrency(row.expectedBrl) }}</td>
              <td>{{ formatCurrency(row.realizedBrl) }}</td>
              <td>{{ formatCurrency(row.remainingBrl) }}</td>
              <td>{{ row.progressPercent === null ? '-' : formatPercent(row.progressPercent) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <RemoteFinanceEmptyState v-else title="Sem dados" description="Cadastre lançamentos para visualizar o comparativo." />
    </article>

    <!-- Gerenciamento (Pagar / Receber) -->
    <article v-if="showEntryManagement" class="finance-panel">
      <header>
        <h3>Gerenciamento de lançamentos</h3>
      </header>
      <div class="finance-form-grid">
        <label>
          <span>O que deseja adicionar</span>
          <select v-model="selectedAccountActionType">
            <option v-for="opt in availableAccountActionOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </label>
        <button type="button" class="finance-action-button" @click="openSelectedAccountActionModal">Adicionar</button>
      </div>
    </article>

    <!-- Entries List -->
    <FinanceEntriesListPanel
      :panel-title="accountsEntriesTitle"
      :totals-label="totalsLabel"
      :entries-items="entriesState"
      :entries-meta="entriesMeta"
      :filters="entryFilters"
      :show-direction-filter="isOverview"
      :direction-options="directionOptions"
      :status-options="entryStatusOptions"
      :resolve-finance-label="getFinanceLabel"
      :loading="loadingEntries"
      @submit-filters="applyEntryFilters"
      @set-page="setEntriesPage"
      @edit-entry="startEditingEntry"
      @delete-entry="requestDeleteEntry"
    />

    <!-- Recurring Rules -->
    <article v-if="showRecurring && filteredRecurringRules.length > 0" class="finance-panel">
      <header>
        <h3>Regras recorrentes</h3>
        <small>{{ filteredRecurringRules.length }} regra{{ filteredRecurringRules.length !== 1 ? 's' : '' }}</small>
      </header>
      <div class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr><th>Título</th><th>Valor</th><th>Dia</th><th>Tipo</th><th>Ação</th></tr>
          </thead>
          <tbody>
            <tr v-for="rule in paginatedRecurringRules" :key="rule.id">
              <td><strong>{{ rule.title }}</strong></td>
              <td>{{ formatCurrency(rule.amountBrl) }}</td>
              <td>{{ rule.dayOfMonth }}</td>
              <td>{{ rule.recurringTypeName || '-' }}</td>
              <td class="finance-actions-cell">
                <button type="button" class="finance-inline-action" @click="startEditingRecurringRule(rule)">Editar</button>
                <button type="button" class="finance-inline-action" @click="generateRecurringManually(rule)">Gerar</button>
                <button type="button" class="finance-inline-action finance-inline-action-danger" @click="requestDeleteRecurringRule(rule)">Excluir</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <!-- Installment Plans -->
    <article v-if="showInstallment && filteredInstallmentPlans.length > 0" class="finance-panel">
      <header>
        <h3>Planos de parcelamento</h3>
        <small>{{ filteredInstallmentPlans.length }} plano{{ filteredInstallmentPlans.length !== 1 ? 's' : '' }}</small>
      </header>
      <div class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr><th>Título</th><th>Total</th><th>Parcelas</th><th>Restante</th></tr>
          </thead>
          <tbody>
            <tr v-for="plan in paginatedInstallmentPlans" :key="plan.id">
              <td><strong>{{ plan.title }}</strong></td>
              <td>{{ formatCurrency(plan.totalAmountBrl) }}</td>
              <td>{{ plan.installmentsCount }}</td>
              <td>{{ formatCurrency(plan.remainingAmountBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <!-- Modal de ações -->
  <div v-if="accountActionModalState.isOpen" class="app-modal-overlay" role="dialog" aria-modal="true" @click.self="closeAccountActionModal">
    <div class="app-modal-frame" style="max-width: 56rem; width: 100%; padding: 24px;">
      <header class="finance-modal-header">
        <h3>{{ accountActionModalTitle }}</h3>
        <p>Os dados serão aplicados nas listagens desta aba.</p>
      </header>

      <!-- Entry Form -->
      <form v-if="accountActionModalState.actionType === 'ENTRY'" class="finance-form-grid" @submit.prevent="submitEntry">
        <label><span>Título</span><input v-model="entryForm.title" type="text" required></label>
        <label><span>Tipo</span><select v-model="entryForm.entryType"><option v-for="o in entryTypeOptionsForForm" :key="o.value" :value="o.value">{{ o.label }}</option></select></label>
        <label><span>Valor (BRL)</span><input v-model="entryForm.expectedAmountBrl" type="number" step="0.01" min="0.01" required></label>
        <label><span>Vencimento</span><input v-model="entryForm.dueDate" type="date" required></label>
        <label><span>Categoria</span><select v-model="entryForm.categoryId"><option value="">Sem categoria</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
        <label><span>Conta bancária</span><select v-model="entryForm.bankAccountId"><option value="">Sem conta</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
        <div class="finance-modal-actions"><button type="button" class="finance-inline-action" @click="closeAccountActionModal">Cancelar</button><button class="finance-action-button" type="submit">{{ entryEditingId ? 'Salvar alterações' : 'Salvar lançamento' }}</button></div>
      </form>

      <!-- Settlement Form -->
      <form v-else-if="accountActionModalState.actionType === 'SETTLEMENT'" class="finance-form-grid" @submit.prevent="submitSettlement">
        <label><span>Lançamento</span><select v-model="settlementForm.entryId"><option value="">Selecione</option><option v-for="e in availableSettlementEntries" :key="e.id" :value="e.id">{{ formatSettlementEntryOptionLabel(e) }}</option></select></label>
        <label><span>Valor da baixa</span><input v-model="settlementForm.amountBrl" type="number" step="0.01" min="0.01" required></label>
        <label><span>Data/hora da baixa</span><input v-model="settlementForm.settledAt" type="datetime-local"></label>
        <label v-if="!settlementForm.useCreditCard"><span>Conta bancária</span><select v-model="settlementForm.bankAccountId"><option value="">Selecione</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
        <div class="finance-modal-actions"><button type="button" class="finance-inline-action" @click="closeAccountActionModal">Cancelar</button><button class="finance-action-button" type="submit">Registrar baixa</button></div>
      </form>

      <!-- Recurring Form -->
      <form v-else-if="accountActionModalState.actionType === 'RECURRING_RULE'" class="finance-form-grid" @submit.prevent="submitRecurringRule">
        <label><span>Título</span><input v-model="recurringForm.title" type="text" required></label>
        <label><span>Valor mensal</span><input v-model="recurringForm.amountBrl" type="number" step="0.01" min="0.01" required></label>
        <label><span>Dia do mês</span><input v-model="recurringForm.dayOfMonth" type="number" min="1" max="31" required></label>
        <label><span>Início</span><input v-model="recurringForm.startsAt" type="date" required></label>
        <label><span>Tipo recorrente</span><select v-model="recurringForm.recurringTypeId" required><option value="">Selecione</option><option v-for="t in availableRecurringTypes" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
        <label><span>Categoria</span><select v-model="recurringForm.categoryId"><option value="">Sem categoria</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
        <label><span>Conta bancária padrão</span><select v-model="recurringForm.defaultBankAccountId"><option value="">Sem conta</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
        <div class="finance-modal-actions"><button type="button" class="finance-inline-action" @click="closeAccountActionModal">Cancelar</button><button class="finance-action-button" type="submit">{{ recurringRuleEditingId ? 'Salvar' : 'Criar recorrência' }}</button></div>
      </form>

      <!-- Installment Form -->
      <form v-else-if="accountActionModalState.actionType === 'INSTALLMENT_PLAN'" class="finance-form-grid" @submit.prevent="submitInstallmentPlan">
        <label><span>Título</span><input v-model="installmentForm.title" type="text" required></label>
        <label><span>Valor total</span><input v-model="installmentForm.totalAmountBrl" type="number" step="0.01" min="0.01" required></label>
        <label><span>Entrada</span><input v-model="installmentForm.downPaymentBrl" type="number" step="0.01" min="0"></label>
        <label><span>Parcelas</span><input v-model="installmentForm.installmentsCount" type="number" min="1" required></label>
        <label><span>Primeiro vencimento</span><input v-model="installmentForm.firstDueDate" type="date"></label>
        <label><span>Categoria</span><select v-model="installmentForm.categoryId"><option value="">Sem categoria</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
        <label><span>Conta bancária padrão</span><select v-model="installmentForm.defaultBankAccountId"><option value="">Sem conta</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
        <div class="finance-modal-actions"><button type="button" class="finance-inline-action" @click="closeAccountActionModal">Cancelar</button><button class="finance-action-button" type="submit">Criar parcelamento</button></div>
      </form>

      <!-- Renegotiation Form -->
      <form v-else-if="accountActionModalState.actionType === 'RENEGOTIATION'" class="finance-form-grid" @submit.prevent="submitRenegotiation">
        <label><span>Plano</span><select v-model="renegotiationForm.planId"><option value="">Selecione</option><option v-for="p in filteredInstallmentPlans" :key="p.id" :value="p.id">{{ p.title }}</option></select></label>
        <label><span>Nova qtd de parcelas</span><input v-model="renegotiationForm.installmentsCount" type="number" min="1" required></label>
        <label><span>Motivo</span><input v-model="renegotiationForm.reason" type="text" required></label>
        <label><span>Categoria</span><select v-model="renegotiationForm.categoryId"><option value="">Manter atual</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
        <label><span>Conta bancária</span><select v-model="renegotiationForm.defaultBankAccountId"><option value="">Manter atual</option><option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
        <div class="finance-modal-actions"><button type="button" class="finance-inline-action" @click="closeAccountActionModal">Cancelar</button><button class="finance-action-button" type="submit">Renegociar plano</button></div>
      </form>
    </div>
  </div>

  <AppConfirmDialog
    :is-open="confirmDialogState.isOpen"
    :title="confirmDialogState.title"
    :message="confirmDialogState.message"
    :confirm-label="confirmDialogState.confirmLabel"
    :confirm-tone="confirmDialogState.confirmTone"
    :processing="confirmDialogState.processing"
    @cancel="closeConfirmDialog"
    @confirm="handleConfirmDialogAction"
  />
</template>

<style scoped>
.finance-overview-month-field {
  display: grid;
  gap: 4px;
}
.finance-overview-month-field span {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--muted, #475569);
}
.finance-overview-month-field input {
  border: 1px solid var(--line, #cbd5e1);
  border-radius: 10px;
  min-height: 38px;
  padding: 0 12px;
  color: var(--ink);
  background: var(--surface-strong);
}
.finance-form-actions {
  display: flex;
  gap: 10px;
  align-items: end;
  flex-wrap: wrap;
}
.finance-modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  grid-column: 1 / -1;
  padding-top: 8px;
}
</style>
