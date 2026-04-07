import { defineStore } from 'pinia'
import {
  fetchFinanceBankAccounts,
  fetchFinanceCategories,
  fetchFinanceEntries,
  fetchFinanceRecurringRules,
  fetchFinanceRecurringTypes,
  fetchFinanceInstallmentPlans,
  fetchFinanceDebtPlans,
  fetchFinanceCurrencyRates,
  fetchFinanceCurrencies,
  fetchFinanceDashboardSummary,
  fetchFinanceDashboardCashflow,
  fetchFinanceDashboardCategories
} from '../services/finance'

export const useFinanceStore = defineStore('finance', {
  state: () => ({
    bankAccounts: [],
    categories: [],
    entries: [],
    entriesMeta: { totalPages: 1, totalItems: 0 },
    recurringTypes: [],
    recurringRules: [],
    installmentPlans: [],
    debtPlans: [],
    currencyRates: [],
    currencies: [],
    dashboard: {
      summary: null,
      cashflow: [],
      categories: []
    },
    loading: {
      bankAccounts: false,
      categories: false,
      entries: false,
      recurring: false,
      installmentPlans: false,
      debtPlans: false,
      currency: false,
      dashboard: false,
    },
    error: null,
  }),

  getters: {
    activeBankAccounts: (state) => state.bankAccounts.filter(acc => acc.isActive),
    activeCategories: (state) => state.categories.filter(cat => cat.isActive),
  },

  actions: {
    async loadBankAccounts(force = false) {
      if (this.bankAccounts.length > 0 && !force) return
      this.loading.bankAccounts = true
      try {
        const res = await fetchFinanceBankAccounts()
        this.bankAccounts = res.data?.items || []
      } catch (err) {
        this.error = 'Falha ao carregar contas bancárias.'
        console.error(err)
      } finally {
        this.loading.bankAccounts = false
      }
    },

    async loadCategories(force = false) {
      if (this.categories.length > 0 && !force) return
      this.loading.categories = true
      try {
        const res = await fetchFinanceCategories()
        this.categories = res.data?.items || []
      } catch (err) {
        this.error = 'Falha ao carregar categorias.'
        console.error(err)
      } finally {
        this.loading.categories = false
      }
    },

    async loadEntries(filters = {}, pagination = { page: 1 }) {
      this.loading.entries = true
      try {
        const res = await fetchFinanceEntries(filters, pagination)
        this.entries = res.data?.items || []
        this.entriesMeta = {
          totalPages: res.data?.item?.totalPages || 1,
          totalItems: res.data?.item?.totalItems || 0,
        }
      } catch (err) {
        this.error = 'Falha ao carregar lançamentos.'
        console.error(err)
      } finally {
        this.loading.entries = false
      }
    },

    async loadRecurringData(force = false) {
      if (this.recurringRules.length > 0 && !force) return
      this.loading.recurring = true
      try {
        const [typesRes, rulesRes] = await Promise.all([
          fetchFinanceRecurringTypes(),
          fetchFinanceRecurringRules()
        ])
        this.recurringTypes = typesRes.data?.items || []
        this.recurringRules = rulesRes.data?.items || []
      } catch (err) {
        this.error = 'Falha ao carregar dados recorrentes.'
        console.error(err)
      } finally {
        this.loading.recurring = false
      }
    },

    async loadInstallmentPlans(force = false) {
      if (this.installmentPlans.length > 0 && !force) return
      this.loading.installmentPlans = true
      try {
        const res = await fetchFinanceInstallmentPlans()
        this.installmentPlans = res.data?.items || []
      } catch (err) {
        this.error = 'Falha ao carregar planos de parcelamento.'
        console.error(err)
      } finally {
        this.loading.installmentPlans = false
      }
    },

    async loadDebtPlans(force = false) {
      if (this.debtPlans.length > 0 && !force) return
      this.loading.debtPlans = true
      try {
        const res = await fetchFinanceDebtPlans()
        this.debtPlans = res.data?.items || []
      } catch (err) {
        this.error = 'Falha ao carregar planos de dívidas.'
        console.error(err)
      } finally {
        this.loading.debtPlans = false
      }
    },

    async loadCurrencyData(force = false) {
      if (this.currencies.length > 0 && !force) return
      this.loading.currency = true
      try {
        const [currRes, ratesRes] = await Promise.all([
          fetchFinanceCurrencies(),
          fetchFinanceCurrencyRates()
        ])
        this.currencies = currRes.data?.items || []
        this.currencyRates = ratesRes.data?.items || []
      } catch (err) {
        this.error = 'Falha ao carregar dados de moedas.'
        console.error(err)
      } finally {
        this.loading.currency = false
      }
    },

    async loadAllBaseData(force = false) {
      await Promise.all([
        this.loadBankAccounts(force),
        this.loadCategories(force),
        this.loadRecurringData(force),
        this.loadInstallmentPlans(force),
        this.loadDebtPlans(force),
        this.loadCurrencyData(force),
        this.loadDashboardData(force)
      ])
    },

    async loadDashboardData(force = false) {
      if (this.dashboard.summary && !force) return
      this.loading.dashboard = true
      try {
        const [sumRes, flowRes, catRes] = await Promise.all([
          fetchFinanceDashboardSummary(),
          fetchFinanceDashboardCashflow(),
          fetchFinanceDashboardCategories()
        ])
        this.dashboard.summary = sumRes.data?.item || null
        this.dashboard.cashflow = sumRes.data?.items || flowRes.data?.items || []
        this.dashboard.categories = catRes.data?.items || []
      } catch (err) {
        this.error = 'Falha ao carregar dashboard.'
        console.error(err)
      } finally {
        this.loading.dashboard = false
      }
    }
  }
})
