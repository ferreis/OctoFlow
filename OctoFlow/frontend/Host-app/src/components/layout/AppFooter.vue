<script setup>
const currentYear = new Date().getFullYear()
</script>

<template>
<footer class="mt-auto rounded-3xl border border-slate-200/80 bg-white/85 px-6 py-6 shadow-[0_16px_40px_rgba(15,23,42,0.06)] backdrop-blur">
  <div class="text-justify text-sm leading-relaxed text-slate-600">
    O OctoFlow é uma plataforma desenvolvida para centralizar a autenticação, organização e acompanhamento de tarefas integradas ao GitHub. O sistema permite visualizar issues, aplicar filtros inteligentes, analisar dados em dashboards e acompanhar o fluxo operacional em tempo real, facilitando a tomada de decisao e melhorando a produtividade no dia a dia.
  </div>

  <div class="mt-6 flex justify-center border-t border-slate-200 pt-4">
    <p class="text-center text-xs font-medium leading-relaxed text-slate-500">
      Copyright © {{ currentYear }} Todos os direitos reservados por Ferreis
      <br />
      contato por email:
      <a href="mailto:ferreis@univali.br" class="font-semibold text-cyan-700 hover:underline">
        ferreis@univali.br
      </a>
    </p>
  </div>
</footer>
</template>
