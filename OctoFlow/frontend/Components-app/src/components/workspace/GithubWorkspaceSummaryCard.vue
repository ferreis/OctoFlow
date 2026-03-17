<script setup>
import { computed } from 'vue'

const props = defineProps({
  workspace: {
    type: Object,
    required: true,
  },
  currentUser: {
    type: Object,
    default: null,
  },
})

const repository = computed(() => props.workspace?.repository || null)
const templatesCount = computed(() => Array.isArray(props.workspace?.templates) ? props.workspace.templates.length : 0)
const projectsCount = computed(() => Array.isArray(props.workspace?.projects) ? props.workspace.projects.length : 0)
const labelsCount = computed(() => Array.isArray(repository.value?.labels) ? repository.value.labels.length : 0)
const requesterEmail = computed(() => {
  const primaryEmail = typeof props.currentUser?.defaultEmail === 'string' ? props.currentUser.defaultEmail.trim() : ''
  const fallbackEmail = typeof props.currentUser?.email === 'string' ? props.currentUser.email.trim() : ''

  return primaryEmail || fallbackEmail || 'usuario autenticado'
})
</script>

<template>
  <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div class="min-w-0">
        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">OctoFlow Dashboard</p>
        <h3 class="mt-1 break-all text-2xl font-semibold text-slate-950">
          {{ repository?.nameWithOwner || 'Repositorio nao configurado' }}
        </h3>
        <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">
          {{ repository?.description || 'O host monta cada painel remoto de forma isolada para carregar apenas o que fizer sentido para a sessao atual.' }}
        </p>
      </div>

      <div class="grid gap-2 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 text-sm text-slate-600">
        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Solicitante</span>
        <strong class="break-all text-base font-semibold text-slate-950">{{ requesterEmail }}</strong>
      </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Templates</span>
        <strong class="mt-2 block text-2xl font-semibold text-slate-950">{{ templatesCount }}</strong>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Labels</span>
        <strong class="mt-2 block text-2xl font-semibold text-slate-950">{{ labelsCount }}</strong>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Projects</span>
        <strong class="mt-2 block text-2xl font-semibold text-slate-950">{{ projectsCount }}</strong>
      </div>
      <div class="rounded-2xl border border-cyan-200 bg-cyan-50/80 p-4">
        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-700">Origem</span>
        <strong class="mt-2 block text-base font-semibold text-cyan-950">Componentes federados</strong>
      </div>
    </div>

    <div class="flex flex-wrap gap-2">
      <a
        v-if="repository?.url"
        :href="repository.url"
        target="_blank"
        rel="noreferrer noopener"
        class="inline-flex max-w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
      >
        Abrir repositorio
      </a>
      <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
        Owner: {{ repository?.ownerLogin || 'nao definido' }}
      </span>
    </div>
  </article>
</template>
