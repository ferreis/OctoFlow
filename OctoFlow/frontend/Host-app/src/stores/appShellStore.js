import { defineStore } from 'pinia'

export const useAppShellStore = defineStore('appShell', {
  state: () => ({
    sidebarExpanded: false,
    isCompactViewport: false,
  }),
  getters: {
    effectiveSidebarExpanded: (state) => !state.isCompactViewport && state.sidebarExpanded,
  },
  actions: {
    setCompactViewport(isCompactViewport) {
      this.isCompactViewport = Boolean(isCompactViewport)

      // Mantém comportamento atual da app: ao sair do modo compacto, recolhe a sidebar.
      if (!this.isCompactViewport) {
        this.sidebarExpanded = false
      }
    },
    expandSidebar() {
      if (this.isCompactViewport) {
        return
      }

      this.sidebarExpanded = true
    },
    collapseSidebar() {
      if (this.isCompactViewport) {
        return
      }

      this.sidebarExpanded = false
    },
  },
})

