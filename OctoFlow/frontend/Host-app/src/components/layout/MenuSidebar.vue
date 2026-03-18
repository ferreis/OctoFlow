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
    class="sticky top-0 flex h-screen max-h-screen flex-col border-r border-cyan-500/10 bg-slate-950/95 text-[var(--nav-text)] backdrop-blur transition-[width,padding] duration-200"
    :class="sidebarExpanded ? 'w-[272px] px-3 py-4' : 'w-[84px] px-2 py-4'" @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave">

    <!-- topo -->
    <div class="rounded-2xl border border-cyan-500/15 bg-slate-900/80" :class="sidebarExpanded ? 'p-3' : 'p-2'">
      <div class="flex items-center" :class="sidebarExpanded ? 'gap-3' : 'justify-center'">
        <img src="/icon.ico" alt="OctoFlow"
          class="h-11 w-11 shrink-0 rounded-xl border border-cyan-400/20 bg-cyan-500/10 object-cover p-1">

        <div class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
          :class="sidebarExpanded ? 'max-w-[160px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'">
          <h1 class="truncate whitespace-nowrap text-lg font-semibold text-white">
            OctoFlow
          </h1>
          <p class="truncate whitespace-nowrap text-xs text-slate-400">
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
          class="group rounded-2xl border transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-45"
          :class="[
            sidebarExpanded
              ? 'flex w-full items-center gap-3 px-3 py-3 text-left'
              : 'flex h-14 w-full items-center justify-center px-0 py-0',
            item.key === props.activeKey
              ? 'border-cyan-400/50 bg-cyan-500/15 shadow-[0_0_0_1px_rgba(34,211,238,0.08)]'
              : 'border-white/8 bg-slate-900/70 hover:border-white/15 hover:bg-slate-900',
          ]">
          <span
            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl font-black text-[var(--nav-text)]"
            :class="item.key === props.activeKey ? 'bg-cyan-400/15' : 'bg-white/5'">
            {{ item.short }}
          </span>

          <div class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
            :class="sidebarExpanded ? 'max-w-[170px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'">
            <strong class="block truncate whitespace-nowrap text-sm font-semibold text-white">
              {{ item.label }}
            </strong>
            <small class="block truncate whitespace-nowrap text-xs text-slate-400">
              {{ item.description }}
            </small>
          </div>
        </button>
      </nav>
    </div>

    <!-- rodapé / perfil -->
    <div class="mt-4 rounded-2xl border border-cyan-500/15 bg-slate-900/80" :class="sidebarExpanded ? 'p-3' : 'p-2'">

      <button type="button" :disabled="!props.authenticated" :title="profileItem.label" @click="openProfile"
        class="w-full rounded-2xl transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-45" :class="[
          sidebarExpanded
            ? 'flex items-center gap-3 px-2 py-2 text-left'
            : 'flex h-14 items-center justify-center',
          profileSelected
            ? 'bg-cyan-500/15 ring-1 ring-cyan-400/40'
            : 'hover:bg-white/5',
        ]">
        <span
          class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/5 text-sm font-black text-white">
          {{ userInitial }}
        </span>

        <div class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
          :class="sidebarExpanded ? 'max-w-[160px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'">
          <p class="truncate whitespace-nowrap text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">
            {{ profileItem.label }}
          </p>
          <strong class="block truncate whitespace-nowrap text-sm font-semibold text-white">
            {{ userLabel }}
          </strong>
        </div>
      </button>

      <button type="button" :disabled="!props.authenticated" :title="sidebarExpanded ? '' : 'Sair'"
        @click.stop="$emit('logout')"
        class="mt-2 rounded-2xl border border-white/10 font-semibold text-slate-900 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-50"
        :class="sidebarExpanded ? 'h-11 w-full px-4' : 'h-12 w-full px-0'">
        {{ sidebarExpanded ? 'Sair' : 'S' }}
      </button>
    </div>
  </aside>
</template>