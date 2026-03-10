<template>
  <main class="remote-shell">
    <h1>Remote Task Module</h1>
    <p>Este projeto expoe apenas componentes de task para Module Federation.</p>
    <p>Use pelo host para acessar as acoes de lista, create, view e edit.</p>
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
