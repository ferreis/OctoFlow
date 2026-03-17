<template>
  <main class="remote-shell">
    <h1>OctoFlow Components</h1>
    <p>Este projeto expoe componentes remotos para o host do OctoFlow.</p>
    <p>O host monta apenas os blocos que a sessao atual pode carregar.</p>
  </main>
</template>

<style scoped>
.remote-shell {
  align-items: center;
  color: #0f172a;
  display: grid;
  gap: 8px;
  margin: 0 auto;
  max-width: 760px;
  min-height: 100vh;
  padding: 24px;
  place-content: center;
  text-align: center;
}

.remote-shell h1 {
  margin: 0;
}

.remote-shell p {
  color: #334155;
  margin: 0;
}
</style>
