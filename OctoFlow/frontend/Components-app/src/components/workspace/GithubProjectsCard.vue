<script setup>
import { computed } from 'vue'

const props = defineProps({
  workspace: {
    type: Object,
    required: true,
  },
})

const projects = computed(() => Array.isArray(props.workspace?.projects) ? props.workspace.projects : [])
const projectsMeta = computed(() => props.workspace?.projectsMeta || { available: true, message: null })

function projectStatusStyle(option) {
  const palette = {
    BLUE: '#2563eb',
    GRAY: '#475569',
    GREEN: '#15803d',
    ORANGE: '#ea580c',
    PINK: '#db2777',
    PURPLE: '#7c3aed',
    RED: '#dc2626',
    YELLOW: '#ca8a04',
  }

  const normalizedColor = typeof option?.color === 'string' ? option.color.toUpperCase() : ''
  const tone = palette[normalizedColor] || '#0f766e'

  return {
    borderColor: tone,
    background: `${tone}18`,
    color: tone,
  }
}
</script>

<template>
  <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
    <div>
      <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Projects</p>
      <h3 class="mt-1 text-2xl font-semibold text-slate-950">Painel do owner</h3>
      <p class="mt-3 text-sm leading-7 text-slate-600">
        O host monta este bloco apenas quando o workspace do usuario estiver pronto, sem puxar a tela remota inteira.
      </p>
    </div>

    <p
      v-if="!projectsMeta.available && projectsMeta.message"
      class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800"
    >
      {{ projectsMeta.message }}
    </p>

    <div v-if="projects.length" class="grid gap-3">
      <article
        v-for="project in projects"
        :key="project.id"
        class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 p-4"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <strong class="block break-words text-base font-semibold text-slate-950">{{ project.title }}</strong>
            <p class="mt-2 text-sm text-slate-600">{{ project.shortDescription || 'Sem Descriçao curta.' }}</p>
          </div>

          <a
            v-if="project.url"
            :href="project.url"
            target="_blank"
            rel="noreferrer noopener"
            class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            Abrir
          </a>
        </div>

        <div v-if="project.statusField?.options?.length" class="flex flex-wrap gap-2">
          <span
            v-for="statusOption in project.statusField.options"
            :key="statusOption.id"
            class="inline-flex max-w-full items-center justify-center rounded-full border px-3 py-1 text-xs font-semibold"
            :style="projectStatusStyle(statusOption)"
          >
            {{ statusOption.name }}
          </span>
        </div>
      </article>
    </div>

    <p
      v-else
      class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-6 text-sm text-slate-500"
    >
      Nenhum project disponivel para este owner ou o token nao possui o escopo necessario.
    </p>
  </article>
</template>
