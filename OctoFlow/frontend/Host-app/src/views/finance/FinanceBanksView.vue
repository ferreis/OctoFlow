<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useFinanceStore } from '../../stores/financeStore'
import { useNotification } from '../../composables/useNotification'
import { RemoteFinanceEmptyState } from '../../federation/remoteComponents'
import {
  createFinanceBankAccount,
  updateFinanceBankAccount,
  updateFinanceBankAccountStatus,
} from '../../services/finance'
import { FINANCE_BANK_ACCOUNT_TYPE_OPTIONS } from '../../constants/financeTerms'

const financeStore = useFinanceStore()
const { bankAccounts, loading } = storeToRefs(financeStore)
const { notifyUser } = useNotification()

const bankAccountTypeOptions = FINANCE_BANK_ACCOUNT_TYPE_OPTIONS
const LIST_ITEMS_PER_PAGE = 10
const currentPage = ref(1)
const editingId = ref(null)

const form = reactive({
  name: '',
  branch: '',
  accountNumber: '',
  accountType: 'CHECKING',
  currentBalanceBrl: '0',
})

const paginatedBankAccounts = computed(() => {
  const start = (currentPage.value - 1) * LIST_ITEMS_PER_PAGE
  return bankAccounts.value.slice(start, start + LIST_ITEMS_PER_PAGE)
})

const totalPages = computed(() => Math.max(1, Math.ceil(bankAccounts.value.length / LIST_ITEMS_PER_PAGE)))

function formatCurrency(rawValue) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2 }).format(Number(rawValue || 0))
}

function resetForm() {
  form.name = ''
  form.branch = ''
  form.accountNumber = ''
  form.accountType = 'CHECKING'
  form.currentBalanceBrl = '0'
  editingId.value = null
}

function startEditing(account) {
  form.name = account.name || ''
  form.branch = account.branch || ''
  form.accountNumber = account.accountNumber || ''
  form.accountType = account.accountType || 'CHECKING'
  form.currentBalanceBrl = String(account.currentBalanceBrl || '0')
  editingId.value = account.id
}

async function submitBankAccount() {
  try {
    if (editingId.value) {
      await updateFinanceBankAccount(editingId.value, { ...form })
      notifyUser('Conta bancária atualizada.', 'success')
    } else {
      await createFinanceBankAccount({ ...form })
      notifyUser('Conta bancária criada.', 'success')
    }
    resetForm()
    await financeStore.reloadBankAccounts()
  } catch (err) {
    notifyUser('Erro ao salvar conta bancária.', 'error')
  }
}

async function toggleAccountStatus(account) {
  try {
    const newStatus = account.isActive === false ? 'ACTIVE' : 'INACTIVE'
    await updateFinanceBankAccountStatus(account.id, { status: newStatus })
    notifyUser(`Conta ${newStatus === 'ACTIVE' ? 'ativada' : 'desativada'}.`, 'success')
    await financeStore.reloadBankAccounts()
  } catch (err) {
    notifyUser('Erro ao alterar status.', 'error')
  }
}

function resolveAccountTypeLabel(type) {
  const found = bankAccountTypeOptions.find((opt) => opt.value === type)
  return found ? found.label : type
}

onMounted(() => {
  financeStore.reloadBankAccounts()
})
</script>

<template>
  <section class="finance-section">
    <article class="finance-panel">
      <header>
        <h3>{{ editingId ? 'Editar conta bancária' : 'Nova conta bancária' }}</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitBankAccount">
        <label>
          <span>Nome da conta (banco)</span>
          <input v-model="form.name" type="text" required>
        </label>

        <label>
          <span>Agência</span>
          <input v-model="form.branch" type="text" placeholder="0001">
        </label>

        <label>
          <span>Número da conta</span>
          <input v-model="form.accountNumber" type="text" placeholder="12345-6">
        </label>

        <label>
          <span>Tipo</span>
          <select v-model="form.accountType">
            <option v-for="opt in bankAccountTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </label>

        <label>
          <span>Saldo inicial (R$)</span>
          <input v-model="form.currentBalanceBrl" type="number" step="0.01">
        </label>

        <div class="finance-form-actions">
          <button class="finance-action-button" type="submit">
            {{ editingId ? 'Salvar alterações' : 'Salvar conta' }}
          </button>
          <button v-if="editingId" type="button" class="finance-inline-action" @click="resetForm">Cancelar</button>
        </div>
      </form>
    </article>

    <article class="finance-panel">
      <header>
        <h3>Contas bancárias cadastradas</h3>
        <small>{{ bankAccounts.length }} conta{{ bankAccounts.length !== 1 ? 's' : '' }}</small>
      </header>

      <div v-if="paginatedBankAccounts.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Agência</th>
              <th>Conta</th>
              <th>Tipo</th>
              <th>Saldo (R$)</th>
              <th>Ação</th>
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
                <button type="button" class="finance-inline-action" @click="startEditing(account)">Editar</button>
                <button type="button" class="finance-inline-action finance-inline-action-danger" @click="toggleAccountStatus(account)">
                  {{ account.isActive === false ? 'Ativar' : 'Desativar' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <RemoteFinanceEmptyState v-else title="Sem contas bancárias" description="Cadastre sua primeira conta para começar." />

      <div v-if="totalPages > 1" class="finance-pagination">
        <span class="finance-pagination-summary">Página {{ currentPage }} de {{ totalPages }}</span>
        <div class="finance-pagination-actions">
          <button type="button" class="finance-inline-action" :disabled="currentPage <= 1" @click="currentPage--">Anterior</button>
          <button type="button" class="finance-inline-action" :disabled="currentPage >= totalPages" @click="currentPage++">Próxima</button>
        </div>
      </div>
    </article>
  </section>
</template>
