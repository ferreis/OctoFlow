<script setup>
import { computed } from 'vue'

const props = defineProps({
  items: {
    type: Array,
    required: true,
  },
  activeKey: {
    type: String,
    required: true,
  },
  authenticated: {
    type: Boolean,
    default: false,
  },
  currentUser: {
    type: Object,
    default: null,
  },
  expanded: {
    type: Boolean,
    default: false,
  },
  compact: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['navigate', 'refresh', 'logout', 'expand', 'collapse'])

const sidebarExpanded = computed(() => props.compact || props.expanded)

const primaryItems = computed(() => {
  return props.items.filter((item) => item.key !== 'profile')
})

const profileItem = computed(() => {
  return props.items.find((item) => item.key === 'profile') || {
    key: 'profile',
    label: 'Perfil',
    description: 'Configuracoes da conta',
  }
})

const profileSelected = computed(() => props.activeKey === profileItem.value.key)

const userLabel = computed(() => {
  const defaultEmail = typeof props.currentUser?.defaultEmail === 'string'
    ? props.currentUser.defaultEmail.trim()
    : ''

  const email = typeof props.currentUser?.email === 'string'
    ? props.currentUser.email.trim()
    : ''

  return defaultEmail || email || 'Sessao nao autenticada'
})

const userInitial = computed(() => {
  return userLabel.value.slice(0, 1).toUpperCase() || 'U'
})

function navigate(menuKey) {
  if (!props.authenticated) {
    return
  }

  emit('navigate', menuKey)
}

function openProfile() {
  navigate(profileItem.value.key)
}

function handleMouseEnter() {
  if (props.compact) {
    return
  }

  emit('expand')
}

function handleMouseLeave() {
  if (props.compact) {
    return
  }

  emit('collapse')
}
</script>

<template>
  <aside
    class="menu-sidebar sticky top-0 flex h-screen max-h-screen flex-col backdrop-blur transition-[width,padding] duration-200"
    :class="sidebarExpanded ? 'w-[272px] px-3 py-4' : 'w-[84px] px-2 py-4'" @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave">

    <!-- topo -->
    <div class="menu-sidebar-panel rounded-2xl" :class="sidebarExpanded ? 'p-3' : 'p-2'">
      <div class="flex items-center" :class="sidebarExpanded ? 'gap-3' : 'justify-center'">
        <img src="/icon.ico" alt="OctoFlow"
          class="menu-logo h-11 w-11 shrink-0 rounded-xl object-cover p-1">

        <div class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
          :class="sidebarExpanded ? 'max-w-[160px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'">
          <h1 class="menu-title truncate whitespace-nowrap text-lg font-semibold">
            OctoFlow
          </h1>
          <p class="menu-subtitle truncate whitespace-nowrap text-xs">
            Painel interno
          </p>
        </div>
      </div>
    </div>

    <!-- menu -->
    <div
      class="min-h-0 flex-1 overflow-y-auto pt-4 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
      <nav class="flex flex-col gap-2" aria-label="Navegação principal">
        <button v-for="item in primaryItems" :key="item.key" type="button" :disabled="!props.authenticated"
          :title="item.label" @click="navigate(item.key)"
          class="menu-nav-item group rounded-2xl border transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-45"
          :class="[
            sidebarExpanded
              ? 'flex w-full items-center gap-3 px-3 py-3 text-left'
              : 'flex h-14 w-full items-center justify-center px-0 py-0',
            item.key === props.activeKey
              ? 'menu-nav-item-active'
              : 'menu-nav-item-idle',
          ]">
          <span
            class="menu-nav-icon inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl font-black"
            :class="item.key === props.activeKey ? 'menu-nav-icon-active' : 'menu-nav-icon-idle'">
            {{ item.short }}
          </span>

          <div class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
            :class="sidebarExpanded ? 'max-w-[170px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'">
            <strong class="menu-nav-label block truncate whitespace-nowrap text-sm font-semibold">
              {{ item.label }}
            </strong>
            <small class="menu-nav-description block truncate whitespace-nowrap text-xs">
              {{ item.description }}
            </small>
          </div>
        </button>
      </nav>
    </div>

    <!-- rodapé / perfil -->
    <div class="menu-sidebar-panel mt-4 rounded-2xl" :class="sidebarExpanded ? 'p-3' : 'p-2'">

      <button type="button" :disabled="!props.authenticated" :title="profileItem.label" @click="openProfile"
        class="menu-profile-button w-full rounded-2xl transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-45" :class="[
          sidebarExpanded
            ? 'flex items-center gap-3 px-2 py-2 text-left'
            : 'flex h-14 items-center justify-center',
          profileSelected
            ? 'menu-profile-button-active'
            : 'menu-profile-button-idle',
        ]">
        <span
          class="menu-profile-avatar inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm font-black">
          {{ userInitial }}
        </span>

        <div class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
          :class="sidebarExpanded ? 'max-w-[160px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'">
          <p class="menu-profile-kicker truncate whitespace-nowrap text-[11px] font-black uppercase tracking-[0.18em]">
            {{ profileItem.label }}
          </p>
          <strong class="menu-profile-user block truncate whitespace-nowrap text-sm font-semibold">
            {{ userLabel }}
          </strong>
        </div>
      </button>

      <button type="button" :disabled="!props.authenticated" :title="sidebarExpanded ? '' : 'Sair'"
        @click.stop="$emit('logout')"
        class="menu-logout mt-2 rounded-2xl border font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
        :class="sidebarExpanded ? 'h-11 w-full px-4' : 'h-12 w-full px-0'">
        {{ sidebarExpanded ? 'Sair' : 'S' }}
      </button>
    </div>
  </aside>
</template>

<style scoped>
.menu-sidebar {
  border-right: 1px solid var(--nav-border);
  background-color: var(--nav-bg);
  color: var(--nav-text);
}

.menu-sidebar-panel {
  border: 1px solid var(--nav-border);
  background: color-mix(in srgb, var(--nav-bg) 84%, var(--color-text) 16%);
}

.menu-logo {
  border: 1px solid color-mix(in srgb, var(--color-secondary) 34%, transparent);
  background: color-mix(in srgb, var(--color-secondary) 16%, transparent);
}

.menu-title,
.menu-nav-label,
.menu-profile-user,
.menu-nav-icon {
  color: var(--nav-text);
}

.menu-subtitle,
.menu-nav-description,
.menu-profile-kicker {
  color: var(--nav-muted);
}

.menu-nav-item {
  border-color: var(--nav-border);
}

.menu-nav-item-idle {
  background: var(--nav-card);
}

.menu-nav-item-idle:hover {
  background: var(--nav-card-hover);
  border-color: var(--nav-border-strong);
}

.menu-nav-item-active {
  background: var(--nav-active-bg);
  border-color: var(--nav-active-border);
  box-shadow: var(--nav-active-shadow);
}

.menu-nav-icon-idle,
.menu-profile-avatar {
  background: color-mix(in srgb, var(--nav-card) 70%, var(--color-text) 30%);
}

.menu-nav-icon-active {
  background: color-mix(in srgb, var(--color-secondary) 18%, transparent);
}

.menu-profile-button-active {
  background: var(--nav-active-bg);
  box-shadow: inset 0 0 0 1px var(--nav-active-border);
}

.menu-profile-button-idle:hover {
  background: var(--nav-card);
}

.menu-logout {
  border-color: var(--nav-border);
  background: var(--surface);
  color: var(--ink);
}

.menu-logout:hover:not(:disabled) {
  background: var(--app-field-bg-hover);
}
</style>
