import { defineStore } from 'pinia'
import {
  FINANCE_VIEW_TO_SECTION,
  normalizeViewKey,
  resolveRouteLocationFromView,
  resolveViewKeyFromRoute,
} from '../router/viewRouting'

export const useAppNavigationStore = defineStore('appNavigation', {
  state: () => ({
    activeViewKey: 'dashboard',
  }),
  getters: {
    isFinanceView: (state) => String(state.activeViewKey || '').startsWith('finance.'),
    financeSectionByView: (state) => FINANCE_VIEW_TO_SECTION[state.activeViewKey] || 'accounts',
  },
  actions: {
    normalizeViewKey(viewKey) {
      return normalizeViewKey(viewKey)
    },
    syncFromRoute(currentRoute) {
      this.activeViewKey = resolveViewKeyFromRoute(currentRoute)
      return this.activeViewKey
    },
    async navigateToView(routerInstance, viewKey, options = {}) {
      const normalizedViewKey = normalizeViewKey(viewKey)
      this.activeViewKey = normalizedViewKey

      const currentRouteViewKey = resolveViewKeyFromRoute(routerInstance.currentRoute.value)
      if (currentRouteViewKey === normalizedViewKey) {
        return true
      }

      const targetRouteLocation = resolveRouteLocationFromView(normalizedViewKey)

      try {
        if (options.replaceHistory === true) {
          await routerInstance.replace(targetRouteLocation)
        } else {
          await routerInstance.push(targetRouteLocation)
        }

        return true
      } catch {
        // Navegação pode falhar por duplicidade de rota ou bloqueio de guard.
        return false
      }
    },
  },
})

