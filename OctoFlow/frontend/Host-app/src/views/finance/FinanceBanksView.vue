<script setup>
import {
  computed,
  onActivated,
  onBeforeMount,
  onBeforeUnmount,
  onBeforeUpdate,
  onDeactivated,
  onErrorCaptured,
  onMounted,
  onUnmounted,
  onUpdated,
  reactive,
  ref,
  watch,
} from 'vue'
import { storeToRefs } from 'pinia'
import { FINANCE_BANK_ACCOUNT_TYPE_OPTIONS } from '../../constants/financeTerms'
import { useFinancePermissions } from '../../composables/useFinancePermissions'
import { useNotification } from '../../composables/useNotification'
import { useScopedI18n } from '../../composables/useScopedI18n'
import { RemoteFinanceEmptyState } from '../../federation/remoteComponents'
import {
  createFinanceBankAccount,
  updateFinanceBankAccount,
  updateFinanceBankAccountStatus,
} from '../../services/finance'
import { useFinanceStore } from '../../stores/financeStore'
import { extractHttpMessage } from '../../utils/httpErrors'
import {
  sanitizeDecimal,
  sanitizeIdentifier,
  sanitizeSingleLineText,
} from '../../utils/financeInputSanitizers'

const LIST_ITEMS_PER_PAGE = 10

const financeStore = useFinanceStore()
const { bankAccounts, loading } = storeToRefs(financeStore)
const { notifyUser } = useNotification()
const { translateScoped, currentLocale } = useScopedI18n('financeModule.banksView')
const { canWriteFinance } = useFinancePermissions()

const currentPage = ref(1)
const editingId = ref('')
const isSavingBankAccount = ref(false)
const financeViewIsActive = ref(false)
const bankAccountsCountBeforeDomUpdate = ref(0)

const form = reactive({
  name: '',
  branch: '',
  accountNumber: '',
  accountType: 'CHECKING',
  currentBalanceBrl: '0',
})

const bankAccountTypeOptions = FINANCE_BANK_ACCOUNT_TYPE_OPTIONS

const isBankAccountsLoading = computed(() => loading.value.bankAccounts === true || loading.value.catalogs === true)
const localeForFormatting = computed(() => (
  String(currentLocale.value || '').toLowerCase() === 'en-us' ? 'en-US' : 'pt-BR'
))

const paginatedBankAccounts = computed(() => {
  const startIndex = (currentPage.value - 1) * LIST_ITEMS_PER_PAGE
  return bankAccounts.value.slice(startIndex, startIndex + LIST_ITEMS_PER_PAGE)
})

const totalPages = computed(() => Math.max(1, Math.ceil(bankAccounts.value.length / LIST_ITEMS_PER_PAGE)))

const bankAccountsSummaryLabel = computed(() => {
  return translateScoped('table.summary', '{count} conta(s)', { count: bankAccounts.value.length })
})

function formatCurrency(rawValue) {
  return new Intl.NumberFormat(localeForFormatting.value, {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
  }).format(Number(rawValue || 0))
}

function resetForm() {
  form.name = ''
  form.branch = ''
  form.accountNumber = ''
  form.accountType = 'CHECKING'
  form.currentBalanceBrl = '0'
  editingId.value = ''
}

function startEditing(account) {
  form.name = sanitizeSingleLineText(account?.name, 80)
  form.branch = sanitizeSingleLineText(account?.branch, 20)
  form.accountNumber = sanitizeSingleLineText(account?.accountNumber, 40)

  const normalizedAccountType = String(account?.accountType || '').trim().toUpperCase()
  form.accountType = bankAccountTypeOptions.some((option) => option.value === normalizedAccountType)
    ? normalizedAccountType
    : 'CHECKING'

  form.currentBalanceBrl = String(sanitizeDecimal(account?.currentBalanceBrl, {
    min: -999999999,
    max: 999999999,
    decimals: 2,
    defaultValue: 0,
  }))

  editingId.value = sanitizeIdentifier(account?.id)
}

function resolveAccountTypeLabel(type) {
  const accountTypeCode = String(type || '').trim().toUpperCase()
  const foundOption = bankAccountTypeOptions.find((option) => option.value === accountTypeCode)
  return foundOption ? foundOption.label : accountTypeCode || '-'
}

function buildBankAccountPayload() {
  const normalizedName = sanitizeSingleLineText(form.name, 80)
  const normalizedBranch = sanitizeSingleLineText(form.branch, 20)
  const normalizedAccountNumber = sanitizeSingleLineText(form.accountNumber, 40)
  const normalizedAccountType = String(form.accountType || '').trim().toUpperCase()

  return {
    name: normalizedName,
    branch: normalizedBranch,
    accountNumber: normalizedAccountNumber,
    accountType: bankAccountTypeOptions.some((option) => option.value === normalizedAccountType)
      ? normalizedAccountType
      : 'CHECKING',
    currentBalanceBrl: sanitizeDecimal(form.currentBalanceBrl, {
      min: -999999999,
      max: 999999999,
      decimals: 2,
      defaultValue: 0,
    }),
  }
}

async function loadBankAccounts(showNotificationOnError = false) {
  const loadedSuccessfully = await financeStore.reloadBankAccounts()
  if (!financeViewIsActive.value) {
    return
  }

  if (!loadedSuccessfully && showNotificationOnError) {
    notifyUser(translateScoped('notifications.loadError', 'Não foi possível carregar as contas bancárias.'), 'error')
  }
}

async function submitBankAccount() {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para alterar dados financeiros.'), 'warning')
    return
  }

  if (isSavingBankAccount.value) {
    return
  }

  const payload = buildBankAccountPayload()
  if (payload.name === '') {
    notifyUser(translateScoped('notifications.nameRequired', 'Informe o nome da conta para continuar.'), 'warning')
    return
  }

  isSavingBankAccount.value = true

  try {
    const normalizedEditingId = sanitizeIdentifier(editingId.value)
    if (normalizedEditingId !== '') {
      await updateFinanceBankAccount(normalizedEditingId, payload)
      notifyUser(translateScoped('notifications.updated', 'Conta bancária atualizada com sucesso.'), 'success')
    } else {
      await createFinanceBankAccount(payload)
      notifyUser(translateScoped('notifications.created', 'Conta bancária criada com sucesso.'), 'success')
    }

    resetForm()
    await loadBankAccounts(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.saveError', 'Erro ao salvar conta bancária.')), 'error')
  } finally {
    isSavingBankAccount.value = false
  }
}

async function toggleAccountStatus(account) {
  if (!canWriteFinance.value) {
    notifyUser(translateScoped('notifications.writeDenied', 'Você não possui permissão para alterar dados financeiros.'), 'warning')
    return
  }

  if (isSavingBankAccount.value) {
    return
  }

  const normalizedAccountId = sanitizeIdentifier(account?.id)
  if (normalizedAccountId === '') {
    notifyUser(translateScoped('notifications.invalidAccount', 'Conta bancária inválida para atualizar status.'), 'warning')
    return
  }

  const nextStatus = account?.isActive === false ? 'ACTIVE' : 'INACTIVE'

  isSavingBankAccount.value = true

  try {
    await updateFinanceBankAccountStatus(normalizedAccountId, { status: nextStatus })
    notifyUser(
      nextStatus === 'ACTIVE'
        ? translateScoped('notifications.activated', 'Conta ativada com sucesso.')
        : translateScoped('notifications.deactivated', 'Conta desativada com sucesso.'),
      'success',
    )
    await loadBankAccounts(false)
  } catch (error) {
    notifyUser(extractHttpMessage(error, translateScoped('notifications.statusError', 'Erro ao alterar o status da conta.')), 'error')
  } finally {
    isSavingBankAccount.value = false
  }
}

watch(totalPages, (nextTotalPages) => {
  if (currentPage.value > nextTotalPages) {
    currentPage.value = nextTotalPages
  }
})

onBeforeMount(() => {
  void financeStore.loadCatalogs(false)
})

onMounted(() => {
  financeViewIsActive.value = true
  void loadBankAccounts(true)
})

onBeforeUpdate(() => {
  bankAccountsCountBeforeDomUpdate.value = bankAccounts.value.length
})

onUpdated(() => {
  if (bankAccountsCountBeforeDomUpdate.value !== bankAccounts.value.length && currentPage.value > totalPages.value) {
    currentPage.value = totalPages.value
  }
})

onActivated(() => {
  financeViewIsActive.value = true
  void loadBankAccounts(false)
})

onDeactivated(() => {
  financeViewIsActive.value = false
  isSavingBankAccount.value = false
})

onBeforeUnmount(() => {
  financeViewIsActive.value = false
  isSavingBankAccount.value = false
  resetForm()
})

onUnmounted(() => {
  bankAccountsCountBeforeDomUpdate.value = 0
  currentPage.value = 1
  editingId.value = ''
})

onErrorCaptured((error) => {
  if (!financeViewIsActive.value) {
    return false
  }

  console.error('[FinanceBanksView] child render error:', error)
  notifyUser(translateScoped('notifications.childRenderError', 'Erro inesperado ao renderizar os dados bancários.'), 'error')
  return false
})
</script>

<template>
  <section class="finance-section">
    <article class="finance-panel">
      <header>
        <h3>
          {{ editingId
            ? translateScoped('form.titleEdit', 'Editar conta bancária')
            : translateScoped('form.titleCreate', 'Nova conta bancária') }}
        </h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitBankAccount">
        <label>
          <span>{{ translateScoped('form.fields.name.label', 'Nome da conta') }}</span>
          <input
            v-model="form.name"
            type="text"
            :placeholder="translateScoped('form.fields.name.placeholder', 'Ex.: Conta principal')"
            :disabled="isSavingBankAccount || !canWriteFinance"
            required
          >
        </label>

        <label>
          <span>{{ translateScoped('form.fields.branch.label', 'Agência') }}</span>
          <input
            v-model="form.branch"
            type="text"
            :placeholder="translateScoped('form.fields.branch.placeholder', '0001')"
            :disabled="isSavingBankAccount || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('form.fields.accountNumber.label', 'Número da conta') }}</span>
          <input
            v-model="form.accountNumber"
            type="text"
            :placeholder="translateScoped('form.fields.accountNumber.placeholder', '12345-6')"
            :disabled="isSavingBankAccount || !canWriteFinance"
          >
        </label>

        <label>
          <span>{{ translateScoped('form.fields.accountType.label', 'Tipo') }}</span>
          <select v-model="form.accountType" :disabled="isSavingBankAccount || !canWriteFinance">
            <option v-for="option in bankAccountTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>

        <label>
          <span>{{ translateScoped('form.fields.initialBalance.label', 'Saldo atual (R$)') }}</span>
          <input
            v-model="form.currentBalanceBrl"
            type="number"
            step="0.01"
            :disabled="isSavingBankAccount || !canWriteFinance"
          >
        </label>

        <div class="finance-form-actions">
          <button class="finance-action-button" type="submit" :disabled="isSavingBankAccount || !canWriteFinance">
            {{ isSavingBankAccount
              ? translateScoped('actions.saving', 'Salvando...')
              : editingId
                ? translateScoped('actions.saveChanges', 'Salvar alterações')
                : translateScoped('actions.save', 'Salvar conta') }}
          </button>
          <button
            v-if="editingId"
            type="button"
            class="finance-inline-action"
            :disabled="isSavingBankAccount"
            @click="resetForm"
          >
            {{ translateScoped('actions.cancel', 'Cancelar') }}
          </button>
        </div>
      </form>

      <p v-if="!canWriteFinance" class="finance-muted-block">
        {{ translateScoped('permissions.readOnlyHint', 'Sua conta está em modo de leitura para dados bancários.') }}
      </p>
    </article>

    <article class="finance-panel">
      <header>
        <h3>{{ translateScoped('table.title', 'Contas bancárias cadastradas') }}</h3>
        <small>{{ bankAccountsSummaryLabel }}</small>
      </header>

      <div v-if="isBankAccountsLoading && !bankAccounts.length" class="finance-muted-block">
        {{ translateScoped('table.loading', 'Carregando contas bancárias...') }}
      </div>

      <div v-else-if="paginatedBankAccounts.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>{{ translateScoped('table.columns.name', 'Nome') }}</th>
              <th>{{ translateScoped('table.columns.branch', 'Agência') }}</th>
              <th>{{ translateScoped('table.columns.accountNumber', 'Conta') }}</th>
              <th>{{ translateScoped('table.columns.type', 'Tipo') }}</th>
              <th>{{ translateScoped('table.columns.balance', 'Saldo') }}</th>
              <th>{{ translateScoped('table.columns.actions', 'Ação') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="account in paginatedBankAccounts" :key="account.id">
              <td><strong>{{ account.name }}</strong></td>
              <td>{{ account.branch || '-' }}</td>
              <td>{{ account.accountNumber || '-' }}</td>
              <td>{{ resolveAccountTypeLabel(account.accountType) }}</td>
              <td>{{ formatCurrency(account.currentBalanceBrl) }}</td>
              <td class="finance-actions-cell">
                <button
                  type="button"
                  class="finance-inline-action"
                  :disabled="isSavingBankAccount || !canWriteFinance"
                  @click="startEditing(account)"
                >
                  {{ translateScoped('actions.edit', 'Editar') }}
                </button>
                <button
                  type="button"
                  class="finance-inline-action finance-inline-action-danger"
                  :disabled="isSavingBankAccount || !canWriteFinance"
                  @click="toggleAccountStatus(account)"
                >
                  {{ account.isActive === false
                    ? translateScoped('actions.activate', 'Ativar')
                    : translateScoped('actions.deactivate', 'Desativar') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <RemoteFinanceEmptyState
        v-else
        :title="translateScoped('table.empty.title', 'Sem contas bancárias')"
        :description="translateScoped('table.empty.description', 'Cadastre sua primeira conta para começar.')"
      />

      <div v-if="totalPages > 1" class="finance-pagination">
        <span class="finance-pagination-summary">
          {{ translateScoped('pagination.summary', 'Página {page} de {totalPages}', { page: currentPage, totalPages }) }}
        </span>
        <div class="finance-pagination-actions">
          <button
            type="button"
            class="finance-inline-action"
            :disabled="currentPage <= 1"
            @click="currentPage--"
          >
            {{ translateScoped('pagination.previous', 'Anterior') }}
          </button>
          <button
            type="button"
            class="finance-inline-action"
            :disabled="currentPage >= totalPages"
            @click="currentPage++"
          >
            {{ translateScoped('pagination.next', 'Próxima') }}
          </button>
        </div>
      </div>
    </article>
  </section>
</template>
