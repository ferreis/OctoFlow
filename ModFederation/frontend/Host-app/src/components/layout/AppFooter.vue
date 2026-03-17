<script setup>
const props = defineProps({
  currentUser: {
    type: Object,
    default: null,
  },
  activeLabel: {
    type: String,
    required: true,
  },
  apiBase: {
    type: String,
    required: true,
  },
})

const currentYear = new Date().getFullYear()
</script>

<template>
  <footer class="flex flex-col gap-4 rounded-[24px] border border-slate-200/80 bg-white/80 px-5 py-4 shadow-[0_16px_40px_rgba(15,23,42,0.06)] backdrop-blur sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0">
      <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Rodape</p>
      <strong class="block overflow-hidden text-ellipsis text-base font-semibold text-slate-950 break-all">
        {{ props.currentUser?.defaultEmail || props.currentUser?.email || 'Acesso publico' }}
      </strong>
      <p class="mt-1 text-sm text-slate-500">Area atual: {{ activeLabel }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-3 text-sm text-slate-500">
      <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1">API: {{ apiBase }}</span>
      <span>{{ currentYear }}</span>
    </div>
  </footer>
</template>
