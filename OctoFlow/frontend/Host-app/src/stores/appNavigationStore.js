import { defineStore } from 'pinia'
import {
  FINANCE_ROUTE_NAMES,
  normalizeViewKey,
  resolveRouteLocationFromView,
  resolveViewKeyFromRoute,
} from '../router/viewRouting'
import { normalizeDashboardTabQuery } from '../utils/navigationSecurity'

export const DASHBOARD_TAB_OPTIONS = Object.freeze(['tasks', 'finance'])

export const useAppNavigationStore = defineStore('appNavigation', {
  state: () => ({
    activeViewKey: 'dashboard',
    dashboardTab: 'tasks',
  }),
  getters: {
    isFinanceView: (state) => String(state.activeViewKey || '').startsWith('finance'),
    financeSectionByView: (state) => {
      const viewKey = String(state.activeViewKey || '')
      const entry = Object.entries(FINANCE_ROUTE_NAMES).find(([, routeName]) => routeName === viewKey)
      return entry ? entry[0] : 'accounts'
    },
  },
  actions: {
    normalizeViewKey(viewKey) {
      return normalizeViewKey(viewKey)
    },
    normalizeDashboardTab(rawTab) {
      return normalizeDashboardTabQuery(rawTab)
    },
    syncFromRoute(currentRoute) {
      this.activeViewKey = resolveViewKeyFromRoute(currentRoute)
      this.syncDashboardTabFromRoute(currentRoute)
      return this.activeViewKey
    },
    syncDashboardTabFromRoute(currentRoute) {
      if (String(currentRoute?.name || '').trim() !== 'dashboard') {
        this.dashboardTab = 'tasks'
        return this.dashboardTab
      }

      this.dashboardTab = this.normalizeDashboardTab(currentRoute?.query?.tab)
      return this.dashboardTab
    },
    async navigateToView(routerInstance, viewKey, options = {}) {
      const normalizedViewKey = normalizeViewKey(viewKey)
      this.activeViewKey = normalizedViewKey

      const currentRouteViewKey = resolveViewKeyFromRoute(routerInstance.currentRoute.value)
      if (currentRouteViewKey === normalizedViewKey) {
        return true
      }

      const targetRouteLocation = normalizedViewKey === 'dashboard'
        ? {
            name: 'dashboard',
            query: {
              tab: this.normalizeDashboardTab(this.dashboardTab),
            },
          }
        : resolveRouteLocationFromView(normalizedViewKey)

      try {
        if (options.replaceHistory === true) {
          await routerInstance.replace(targetRouteLocation)
        } else {
          await routerInstance.push(targetRouteLocation)
        }

        return true
      } catch {
        return false
      }
    },
    async navigateDashboardTab(routerInstance, currentRoute, rawTab, options = {}) {
      const normalizedTab = this.normalizeDashboardTab(rawTab)
      this.dashboardTab = normalizedTab

      const routeSnapshot = currentRoute || routerInstance.currentRoute.value
      if (String(routeSnapshot?.name || '').trim() !== 'dashboard') {
        return false
      }

      const currentRouteTab = this.normalizeDashboardTab(routeSnapshot?.query?.tab)
      if (currentRouteTab === normalizedTab) {
        return true
      }

      const nextRouteQuery = {
        ...routeSnapshot.query,
        tab: normalizedTab,
      }

      try {
        if (options.replaceHistory === true) {
          await routerInstance.replace({
            name: 'dashboard',
            query: nextRouteQuery,
          })
        } else {
          await routerInstance.push({
            name: 'dashboard',
            query: nextRouteQuery,
          })
        }

        return true
      } catch {
        return false
      }
    },
  },
})
