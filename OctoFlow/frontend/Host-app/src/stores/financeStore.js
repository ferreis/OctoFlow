import { defineStore } from 'pinia'
import {
  fetchFinanceBankAccounts,
  fetchFinanceCategories,
  fetchFinanceEntries,
  fetchFinanceRecurringRules,
  fetchFinanceRecurringTypes,
  fetchFinanceInstallmentPlans,
  fetchFinanceCurrencyRates,
  fetchFinanceCurrencies,
  fetchFinanceDashboardSummary,
  fetchFinanceDashboardCashflow,
  fetchFinanceDashboardCategories,
} from '../services/finance'

export const useFinanceStore = defineStore('finance', {
  state: () => ({
    // ─── Catálogos (carregados 1x, compartilhados entre todas as views) ───
    categories: [],
    bankAccounts: [],
    recurringTypes: [],
    recurringRules: [],
    installmentPlans: [],
    currencies: [],
    currencyRates: [],

    // ─── Dashboard / KPIs ───
    dashboard: {
      summary: null,
      cashflow: [],
      categories: [],
    },

    // ─── Lançamentos (entries) ───
    entries: [],
    entriesMeta: { page: 1, itemsPerPage: 10, total: 0 },
    entryFilters: {
      direction: '',
      status: '',
      search: '',
      startDate: '',
      endDate: '',
    },

    // ─── Loading states centralizados ───
    loading: {
      catalogs: false,
      bankAccounts: false,
      categories: false,
      entries: false,
      recurring: false,
      installmentPlans: false,
      currency: false,
      dashboard: false,
      investments: false,
      exports: false,
      migration: false,
    },

    // ─── Flags de inicialização ───
    catalogsLoaded: false,
    dashboardLoaded: false,
    error: null,
  }),

  getters: {
    activeBankAccounts: (state) => state.bankAccounts.filter((acc) => acc.isActive !== false),
    creditCardAccounts: (state) => state.bankAccounts.filter(
      (acc) => acc.isActive !== false && String(acc.accountType || '').toUpperCase() === 'CREDIT_CARD',
    ),
    isCatalogsReady: (state) => state.catalogsLoaded && !state.loading.catalogs,
  },

  actions: {
    // ─── Catálogos base (chamado uma vez no FinanceLayout) ───
    async loadCatalogs(force = false) {
      if (this.catalogsLoaded && !force) return true
      this.loading.catalogs = true
      this.error = null
      try {
        const [catRes, bankRes, typesRes] = await Promise.all([
          fetchFinanceCategories(),
          fetchFinanceBankAccounts(),
          fetchFinanceRecurringTypes(),
        ])
        this.categories = catRes.data?.items || []
        this.bankAccounts = bankRes.data?.items || []
        this.recurringTypes = typesRes.data?.items || []
        this.catalogsLoaded = true
        return true
      } catch (err) {
        this.error = 'Falha ao carregar catálogos financeiros.'
        console.error('[financeStore] loadCatalogs error:', err)
        return false
      } finally {
        this.loading.catalogs = false
      }
    },

    // ─── Reload individual de catálogos (após CRUD) ───
    async reloadCategories() {
      try {
        const res = await fetchFinanceCategories()
        this.categories = res.data?.items || []
        return true
      } catch (err) {
        console.error('[financeStore] reloadCategories error:', err)
        return false
      }
    },

    async reloadBankAccounts() {
      try {
        const res = await fetchFinanceBankAccounts()
        this.bankAccounts = res.data?.items || []
        return true
      } catch (err) {
        console.error('[financeStore] reloadBankAccounts error:', err)
        return false
      }
    },

    async reloadRecurringData() {
      this.loading.recurring = true
      try {
        const [typesRes, rulesRes] = await Promise.all([
          fetchFinanceRecurringTypes(),
          fetchFinanceRecurringRules(),
        ])
        this.recurringTypes = typesRes.data?.items || []
        this.recurringRules = rulesRes.data?.items || []
        return true
      } catch (err) {
        console.error('[financeStore] reloadRecurringData error:', err)
        return false
      } finally {
        this.loading.recurring = false
      }
    },

    async reloadInstallmentPlans() {
      this.loading.installmentPlans = true
      try {
        const res = await fetchFinanceInstallmentPlans()
        this.installmentPlans = res.data?.items || []
        return true
      } catch (err) {
        console.error('[financeStore] reloadInstallmentPlans error:', err)
        return false
      } finally {
        this.loading.installmentPlans = false
      }
    },

    // ─── Dashboard ───
    async loadDashboardData(force = false) {
      if (this.dashboardLoaded && !force) return true
      this.loading.dashboard = true
      try {
        const [sumRes, flowRes, catRes] = await Promise.all([
          fetchFinanceDashboardSummary(),
          fetchFinanceDashboardCashflow(),
          fetchFinanceDashboardCategories(),
        ])
        this.dashboard.summary = sumRes.data?.item || null
        this.dashboard.cashflow = flowRes.data?.items || []
        this.dashboard.categories = catRes.data?.items || []
        this.dashboardLoaded = true
        return true
      } catch (err) {
        this.error = 'Falha ao carregar dashboard.'
        console.error('[financeStore] loadDashboardData error:', err)
        return false
      } finally {
        this.loading.dashboard = false
      }
    },

    async loadDashboardSummary(params = {}) {
      this.loading.dashboard = true
      try {
        const res = await fetchFinanceDashboardSummary(params)
        return res.data?.item || null
      } catch (err) {
        console.error('[financeStore] loadDashboardSummary error:', err)
        return null
      } finally {
        this.loading.dashboard = false
      }
    },

    // ─── Entries ───
    async loadEntries(filters = {}, pagination = {}) {
      this.loading.entries = true
      try {
        const res = await fetchFinanceEntries(filters, pagination)
        this.entries = res.data?.items || []
        this.entriesMeta = {
          page: Number(res.data?.item?.page || pagination.page || 1),
          itemsPerPage: Number(res.data?.item?.itemsPerPage || pagination.itemsPerPage || 10),
          total: Number(res.data?.item?.totalItems || res.data?.item?.total || 0),
        }
      } catch (err) {
        console.error('[financeStore] loadEntries error:', err)
      } finally {
        this.loading.entries = false
      }
    },

    setEntryFilters(newFilters) {
      Object.assign(this.entryFilters, newFilters)
    },

    resetEntryFilters() {
      this.entryFilters = { direction: '', status: '', search: '', startDate: '', endDate: '' }
    },

    // ─── Currency ───
    async loadCurrencyData(force = false) {
      if (this.currencies.length > 0 && !force) return true
      this.loading.currency = true
      try {
        const [currRes, ratesRes] = await Promise.all([
          fetchFinanceCurrencies(),
          fetchFinanceCurrencyRates(),
        ])
        this.currencies = currRes.data?.items || []
        this.currencyRates = ratesRes.data?.items || []
        return true
      } catch (err) {
        console.error('[financeStore] loadCurrencyData error:', err)
        return false
      } finally {
        this.loading.currency = false
      }
    },

    async reloadCurrencyRates() {
      try {
        const res = await fetchFinanceCurrencyRates()
        this.currencyRates = res.data?.items || []
        return true
      } catch (err) {
        console.error('[financeStore] reloadCurrencyRates error:', err)
        return false
      }
    },

    // ─── Reset completo ───
    resetAll() {
      this.$reset()
    },
  },
})
