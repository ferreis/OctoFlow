<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useFinanceStore } from '../../stores/financeStore'
import { useNotification } from '../../composables/useNotification'
import { RemoteFinanceEmptyState } from '../../federation/remoteComponents'
import {
  createFinanceCategory,
  updateFinanceCategory,
  createFinanceRecurringType,
  updateFinanceRecurringType,
  deleteFinanceRecurringType,
  fetchFinanceCurrencyRates,
  createFinanceCurrencyRateManual,
} from '../../services/finance'

const financeStore = useFinanceStore()
const { categories, recurringTypes, currencyRates, loading } = storeToRefs(financeStore)
const { notifyUser } = useNotification()

const LIST_ITEMS_PER_PAGE = 10

// ─── Categories ───
const categoryForm = reactive({ name: '', kind: 'BOTH' })
const categoryEditingId = ref(null)
const categoryPage = ref(1)

const categoryKindOptions = [
  { value: 'BOTH', label: 'Ambos (pagar e receber)' },
  { value: 'PAYABLE', label: 'Somente pagar' },
  { value: 'RECEIVABLE', label: 'Somente receber' },
  { value: 'INVESTMENT', label: 'Investimento' },
]

const paginatedCategories = computed(() => {
  const start = (categoryPage.value - 1) * LIST_ITEMS_PER_PAGE
  return categories.value.slice(start, start + LIST_ITEMS_PER_PAGE)
})
const categoryTotalPages = computed(() => Math.max(1, Math.ceil(categories.value.length / LIST_ITEMS_PER_PAGE)))

function resetCategoryForm() {
  categoryForm.name = ''
  categoryForm.kind = 'BOTH'
  categoryEditingId.value = null
}

function startEditingCategory(cat) {
  categoryForm.name = cat.name || ''
  categoryForm.kind = cat.kind || 'BOTH'
  categoryEditingId.value = cat.id
}

async function submitCategory() {
  try {
    if (categoryEditingId.value) {
      await updateFinanceCategory(categoryEditingId.value, { ...categoryForm })
      notifyUser('Categoria atualizada.', 'success')
    } else {
      await createFinanceCategory({ ...categoryForm })
      notifyUser('Categoria criada.', 'success')
    }
    resetCategoryForm()
    await financeStore.reloadCategories()
  } catch (err) {
    notifyUser('Erro ao salvar categoria.', 'error')
  }
}

// ─── Recurring Types ───
const recurringTypeForm = reactive({ name: '', description: '' })
const recurringTypeEditingId = ref(null)
const recurringTypePage = ref(1)

const filteredRecurringTypes = computed(() =>
  recurringTypes.value.filter((t) => String(t?.name || '').trim().toLowerCase() !== 'mensal')
)
const paginatedRecurringTypes = computed(() => {
  const start = (recurringTypePage.value - 1) * LIST_ITEMS_PER_PAGE
  return filteredRecurringTypes.value.slice(start, start + LIST_ITEMS_PER_PAGE)
})
const recurringTypeTotalPages = computed(() => Math.max(1, Math.ceil(filteredRecurringTypes.value.length / LIST_ITEMS_PER_PAGE)))

function resetRecurringTypeForm() {
  recurringTypeForm.name = ''
  recurringTypeForm.description = ''
  recurringTypeEditingId.value = null
}

function startEditingRecurringType(rt) {
  recurringTypeForm.name = rt.name || ''
  recurringTypeForm.description = rt.description || ''
  recurringTypeEditingId.value = rt.id
}

async function submitRecurringType() {
  try {
    if (recurringTypeEditingId.value) {
      await updateFinanceRecurringType(recurringTypeEditingId.value, { ...recurringTypeForm })
      notifyUser('Tipo recorrente atualizado.', 'success')
    } else {
      await createFinanceRecurringType({ ...recurringTypeForm })
      notifyUser('Tipo recorrente criado.', 'success')
    }
    resetRecurringTypeForm()
    await financeStore.reloadRecurringData()
  } catch (err) {
    notifyUser('Erro ao salvar tipo recorrente.', 'error')
  }
}

async function deleteRecurringType(rt) {
  if (!confirm(`Excluir tipo recorrente "${rt.name}"?`)) return
  try {
    await deleteFinanceRecurringType(rt.id)
    notifyUser('Tipo recorrente excluído.', 'success')
    await financeStore.reloadRecurringData()
  } catch (err) {
    notifyUser('Erro ao excluir.', 'error')
  }
}

// ─── Currency Rates ───
const currencyPage = ref(1)
const manualCurrencyRateForm = reactive({
  quoteDate: new Date().toISOString().slice(0, 10),
  currencyCode: 'USD',
  currencyName: '',
  rateBrl: '',
})

const paginatedCurrencyRates = computed(() => {
  const start = (currencyPage.value - 1) * LIST_ITEMS_PER_PAGE
  return currencyRates.value.slice(start, start + LIST_ITEMS_PER_PAGE)
})
const currencyTotalPages = computed(() => Math.max(1, Math.ceil(currencyRates.value.length / LIST_ITEMS_PER_PAGE)))

function formatCurrency(val) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 4 }).format(Number(val || 0))
}

async function submitManualCurrencyRate() {
  try {
    await createFinanceCurrencyRateManual({ ...manualCurrencyRateForm })
    notifyUser('Cotação registrada.', 'success')
    manualCurrencyRateForm.rateBrl = ''
    await financeStore.reloadCurrencyRates()
  } catch (err) {
    notifyUser('Erro ao registrar cotação.', 'error')
  }
}

onMounted(() => {
  financeStore.loadCurrencyData()
})
</script>

<template>
  <section class="finance-section">
    <!-- Categorias -->
    <article class="finance-panel">
      <header>
        <h3>{{ categoryEditingId ? 'Editar categoria' : 'Nova categoria' }}</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitCategory">
        <label>
          <span>Nome da categoria</span>
          <input v-model="categoryForm.name" type="text" required>
        </label>
        <label>
          <span>Aplicação</span>
          <select v-model="categoryForm.kind">
            <option v-for="opt in categoryKindOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </label>
        <div class="finance-form-actions">
          <button class="finance-action-button" type="submit">{{ categoryEditingId ? 'Salvar' : 'Criar categoria' }}</button>
          <button v-if="categoryEditingId" type="button" class="finance-inline-action" @click="resetCategoryForm">Cancelar</button>
        </div>
      </form>

      <div v-if="paginatedCategories.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr><th>Nome</th><th>Tipo</th><th>Ação</th></tr>
          </thead>
          <tbody>
            <tr v-for="cat in paginatedCategories" :key="cat.id">
              <td><strong>{{ cat.name }}</strong></td>
              <td>{{ cat.kind || 'BOTH' }}</td>
              <td class="finance-actions-cell">
                <button type="button" class="finance-inline-action" @click="startEditingCategory(cat)">Editar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <RemoteFinanceEmptyState v-else title="Sem categorias" description="Crie sua primeira categoria." />

      <div v-if="categoryTotalPages > 1" class="finance-pagination">
        <span class="finance-pagination-summary">Página {{ categoryPage }} de {{ categoryTotalPages }}</span>
        <div class="finance-pagination-actions">
          <button type="button" class="finance-inline-action" :disabled="categoryPage <= 1" @click="categoryPage--">Anterior</button>
          <button type="button" class="finance-inline-action" :disabled="categoryPage >= categoryTotalPages" @click="categoryPage++">Próxima</button>
        </div>
      </div>
    </article>

    <!-- Tipos Recorrentes -->
    <article class="finance-panel">
      <header>
        <h3>{{ recurringTypeEditingId ? 'Editar tipo recorrente' : 'Novo tipo recorrente' }}</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitRecurringType">
        <label>
          <span>Nome</span>
          <input v-model="recurringTypeForm.name" type="text" required>
        </label>
        <label>
          <span>Descrição</span>
          <input v-model="recurringTypeForm.description" type="text">
        </label>
        <div class="finance-form-actions">
          <button class="finance-action-button" type="submit">{{ recurringTypeEditingId ? 'Salvar' : 'Criar tipo' }}</button>
          <button v-if="recurringTypeEditingId" type="button" class="finance-inline-action" @click="resetRecurringTypeForm">Cancelar</button>
        </div>
      </form>

      <div v-if="paginatedRecurringTypes.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr><th>Nome</th><th>Descrição</th><th>Ação</th></tr>
          </thead>
          <tbody>
            <tr v-for="rt in paginatedRecurringTypes" :key="rt.id">
              <td><strong>{{ rt.name }}</strong></td>
              <td>{{ rt.description || '-' }}</td>
              <td class="finance-actions-cell">
                <button type="button" class="finance-inline-action" @click="startEditingRecurringType(rt)">Editar</button>
                <button type="button" class="finance-inline-action finance-inline-action-danger" @click="deleteRecurringType(rt)">Excluir</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <RemoteFinanceEmptyState v-else title="Sem tipos recorrentes" description="Crie tipos para organizar suas recorrências." />
    </article>

    <!-- Cotações de Moeda -->
    <article class="finance-panel">
      <header>
        <h3>Cotações de moeda</h3>
      </header>

      <form class="finance-form-grid" @submit.prevent="submitManualCurrencyRate">
        <label>
          <span>Data</span>
          <input v-model="manualCurrencyRateForm.quoteDate" type="date" required>
        </label>
        <label>
          <span>Moeda (código)</span>
          <input v-model="manualCurrencyRateForm.currencyCode" type="text" placeholder="USD" required>
        </label>
        <label>
          <span>Cotação (R$)</span>
          <input v-model="manualCurrencyRateForm.rateBrl" type="number" step="0.0001" min="0" required>
        </label>
        <button class="finance-action-button" type="submit">Registrar cotação</button>
      </form>

      <div v-if="paginatedCurrencyRates.length" class="finance-inline-table-wrap">
        <table class="finance-inline-table">
          <thead>
            <tr><th>Data</th><th>Moeda</th><th>Cotação (R$)</th></tr>
          </thead>
          <tbody>
            <tr v-for="rate in paginatedCurrencyRates" :key="`${rate.currencyCode}-${rate.quoteDate}`">
              <td>{{ rate.quoteDate }}</td>
              <td><strong>{{ rate.currencyCode }}</strong></td>
              <td>{{ formatCurrency(rate.rateBrl) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <RemoteFinanceEmptyState v-else title="Sem cotações" description="Registre cotações manualmente ou atualize via API do Bacen." />

      <div v-if="currencyTotalPages > 1" class="finance-pagination">
        <span class="finance-pagination-summary">Página {{ currencyPage }} de {{ currencyTotalPages }}</span>
        <div class="finance-pagination-actions">
          <button type="button" class="finance-inline-action" :disabled="currencyPage <= 1" @click="currencyPage--">Anterior</button>
          <button type="button" class="finance-inline-action" :disabled="currencyPage >= currencyTotalPages" @click="currencyPage++">Próxima</button>
        </div>
      </div>
    </article>
  </section>
</template>
