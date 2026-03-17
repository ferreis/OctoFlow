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
  collapsed: {
    type: Boolean,
    default: false,
  },
  collapsible: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['navigate', 'toggle-collapse', 'refresh', 'logout'])

const userLabel = computed(() => {
  const email = typeof props.currentUser?.defaultEmail === 'string' && props.currentUser.defaultEmail.trim() !== ''
    ? props.currentUser.defaultEmail.trim()
    : typeof props.currentUser?.email === 'string'
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
</script>

<template>
  <aside
    class="sticky top-0 flex h-screen max-h-screen min-w-0 flex-col gap-4 self-start overflow-hidden border-r border-white/10 bg-slate-950/90 text-slate-100 backdrop-blur"
    :class="props.collapsed ? 'px-2 py-4 lg:px-2.5' : 'px-3 py-4 lg:px-4'"
  >
    <div class="flex items-start justify-between gap-3 rounded-2xl border border-white/10 bg-white/5 p-3">
      <div class="min-w-0">
        <h1 class="truncate text-xl font-semibold text-white">
          {{ props.collapsed ? 'OF' : 'OctoFlow' }}
        </h1>
      </div>

      <button
        v-if="props.collapsible"
        type="button"
        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/10 text-xs font-black text-cyan-100 transition hover:bg-white/15"
        :title="props.collapsed ? 'Abrir menu' : 'Recolher menu'"
        @click="$emit('toggle-collapse')"
      >
        {{ props.collapsed ? '>>' : '<<' }}
      </button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto pr-1 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
      <nav
        class="grid min-w-0 gap-2"
        :class="props.collapsed ? 'grid-cols-1' : 'grid-cols-1'"
        aria-label="Navegacao principal"
      >
        <button
          v-for="item in items"
          :key="item.key"
          type="button"
          :disabled="!props.authenticated"
          :title="item.label"
          class="group grid min-w-0 gap-3 rounded-2xl border px-3 py-3 text-left transition duration-200 disabled:cursor-not-allowed disabled:opacity-45"
          :class="[
            props.collapsed ? 'grid-cols-1 justify-items-center px-2.5 py-3' : 'grid-cols-[auto,minmax(0,1fr)]',
            item.key === props.activeKey
              ? 'border-cyan-400/30 bg-cyan-400/12 shadow-[0_12px_30px_rgba(34,211,238,0.12)]'
              : 'border-white/8 bg-white/5 hover:border-white/15 hover:bg-white/8',
          ]"
          @click="navigate(item.key)"
        >
          <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-xs font-black tracking-wide text-slate-100">
            {{ item.short }}
          </span>

          <span v-if="!props.collapsed" class="grid min-w-0 gap-1">
            <strong class="truncate text-base font-semibold text-white">{{ item.label }}</strong>
            <small class="line-clamp-2 text-xs leading-5 text-slate-300">{{ item.description }}</small>
          </span>
        </button>
      </nav>

      <div
        class="mt-4 grid min-w-0 gap-3 rounded-2xl border border-white/10 bg-white/6 p-3"
        :class="props.collapsed ? 'justify-items-center' : ''"
      >
        <div class="flex min-w-0 items-center gap-3" :class="props.collapsed ? 'justify-center' : ''">
          <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/12 text-sm font-black text-cyan-50">
            {{ userInitial }}
          </span>

          <div v-if="!props.collapsed" class="min-w-0">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-cyan-200/70">Sessao</p>
            <strong class="block overflow-hidden text-ellipsis text-sm font-semibold text-white break-all">
              {{ userLabel }}
            </strong>
          </div>
        </div>

        <p v-if="!props.collapsed" class="text-sm leading-6 text-slate-300">
          {{ props.authenticated ? 'Use o menu para navegar entre Dashboard, Tarefas e Perfil.' : 'Faca login para liberar todas as areas.' }}
        </p>

        <div class="grid w-full gap-2" :class="props.collapsed ? 'justify-items-center' : ''">
          <button
            type="button"
            :disabled="!props.authenticated"
            :title="props.collapsed ? 'Renovar token' : ''"
            class="inline-flex max-w-full items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/20 transition hover:brightness-105 disabled:cursor-not-allowed disabled:opacity-50"
            :class="props.collapsed ? 'h-10 w-10 px-0 py-0' : 'w-full'"
            @click="$emit('refresh')"
          >
            {{ props.collapsed ? 'RT' : 'Renovar token' }}
          </button>
          <button
            type="button"
            :disabled="!props.authenticated"
            :title="props.collapsed ? 'Sair' : ''"
            class="inline-flex max-w-full items-center justify-center rounded-xl border border-white/10 bg-white/90 px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-50"
            :class="props.collapsed ? 'h-10 w-10 px-0 py-0' : 'w-full'"
            @click="$emit('logout')"
          >
            {{ props.collapsed ? 'S' : 'Sair' }}
          </button>
        </div>
      </div>
    </div>
  </aside>
</template>
