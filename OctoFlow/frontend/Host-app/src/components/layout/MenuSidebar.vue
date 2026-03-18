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
const primaryItems = computed(() => props.items.filter((item) => item.key !== 'profile'))
const profileItem = computed(() => props.items.find((item) => item.key === 'profile') || {
  key: 'profile',
  label: 'Perfil',
  description: 'Configuracoes da conta',
})
const profileSelected = computed(() => props.activeKey === profileItem.value.key)

const userLabel = computed(() => {
  const email = typeof props.currentUser?.defaultEmail === 'string' && props.currentUser.defaultEmail.trim() !== ''
    ? props.currentUser.defaultEmail.trim()
    : typeof props.currentUser?.email === 'string' && props.currentUser.email.trim() !== ''
      ? props.currentUser.email.trim()
      : ''

  return email || 'Sessao nao autenticada'
})

const userInitial = computed(() => userLabel.value.slice(0, 1).toUpperCase() || 'U')

function navigate(key) {
  if (!props.authenticated) {
    return
  }

  emit('navigate', key)
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
    class="sticky top-0 flex h-screen max-h-screen flex-col gap-4 overflow-hidden border-r border-white/10 bg-slate-950/90 text-[var(--nav-text)] backdrop-blur transition-all duration-300"
    :class="sidebarExpanded ? 'w-[280px] px-3 py-4 lg:px-4' : 'w-[88px] px-2 py-4'"
    @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave">
    <div class="grid items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-3"
      :class="sidebarExpanded ? 'grid-cols-1' : 'grid-cols-1 justify-items-center'">
      <div class="flex min-w-0 items-center gap-3" :class="sidebarExpanded ? '' : 'justify-center'">
        <img src="/icon.ico" alt="OctoFlow"
          class="block h-10 w-10 min-h-10 min-w-10 shrink-0 rounded-xl border border-white/10 bg-white/10 object-cover p-1">

        <h1 v-if="sidebarExpanded" class="truncate text-xl font-semibold text-[var(--nav-text)]">
          OctoFlow
        </h1>
      </div>
    </div>

    <div
      class="min-h-0 flex-1 overflow-y-auto pr-1 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
      <nav class="grid gap-2" aria-label="Navegação principal">
        <button v-for="item in primaryItems" :key="item.key" type="button" :disabled="!props.authenticated" :title="item.label"
          class="group min-w-0 rounded-2xl border text-left transition duration-200 disabled:cursor-not-allowed disabled:opacity-45"
          :class="[
            sidebarExpanded
              ? 'grid w-full grid-cols-[40px_minmax(0,1fr)] gap-3 px-3 py-3'
              : 'grid w-full grid-cols-1 justify-items-center gap-2 px-2 py-3',
            item.key === props.activeKey
              ? 'border-cyan-400/30 bg-cyan-400/12 shadow-[0_12px_30px_rgba(34,211,238,0.12)]'
              : 'border-white/8 bg-white/5 hover:border-white/15 hover:bg-white/8',
          ]" @click="navigate(item.key)">
          <span
            class="inline-flex h-10 w-10 min-h-10 min-w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-xs font-black tracking-wide text-[var(--nav-text)]">
            {{ item.short }}
          </span>

          <span v-if="sidebarExpanded" class="grid min-w-0 gap-1">
            <strong class="truncate text-base font-semibold text-[var(--nav-text)]">
              {{ item.label }}
            </strong>
            <small class="line-clamp-2 text-xs leading-5 text-[var(--nav-muted)]">
              {{ item.description }}
            </small>
          </span>
        </button>
      </nav>

      <div class="mt-4 grid min-w-0 gap-3 rounded-2xl border border-white/10 bg-white/6 p-3"
        :class="[
          sidebarExpanded ? '' : 'justify-items-center',
          profileSelected ? 'border-cyan-400/30 bg-cyan-400/12 shadow-[0_12px_30px_rgba(34,211,238,0.12)]' : '',
        ]">
        <button type="button" :disabled="!props.authenticated" :title="profileItem.label"
          class="flex min-w-0 items-center gap-3 rounded-xl text-left transition disabled:cursor-not-allowed disabled:opacity-45"
          :class="sidebarExpanded ? 'w-full px-1 py-1' : 'justify-center'"
          @click="openProfile">
          <span
            class="inline-flex h-10 w-10 min-h-10 min-w-10 shrink-0 items-center justify-center rounded-xl bg-white/12 text-sm font-black text-[var(--nav-text)]">
            {{ userInitial }}
          </span>

          <div v-if="sidebarExpanded" class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-[var(--nav-muted)]">
              {{ profileItem.label }}
            </p>
            <strong class="block overflow-hidden text-ellipsis break-all text-sm font-semibold text-[var(--nav-text)]">
              {{ userLabel }}
            </strong>
            <small class="block truncate text-xs leading-5 text-[var(--nav-muted)]">
              {{ profileItem.description }}
            </small>
          </div>
        </button>


        <div class="grid w-full gap-2" :class="sidebarExpanded ? '' : 'justify-items-center'">
          <button type="button" :disabled="!props.authenticated" :title="!sidebarExpanded ? 'Sair' : ''"
            class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/90 text-sm font-semibold text-slate-900 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-50"
            :class="!sidebarExpanded ? 'h-10 w-10 min-h-10 min-w-10 px-0 py-0' : 'w-full px-4 py-2.5'"
            @click.stop="$emit('logout')">
            {{ !sidebarExpanded ? 'S' : 'Sair' }}
          </button>
        </div>
      </div>
    </div>
  </aside>
</template>
