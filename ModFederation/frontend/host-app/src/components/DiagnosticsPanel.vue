<template>
  <div class="diagnostics">
    <h3>🔍 Diagnóstico Module Federation</h3>
    <button @click="testConnection">Testar Conexão com Remote</button>
    <div v-if="status" class="status" :class="statusClass">
      <pre>{{ status }}</pre>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const status = ref('')
const statusClass = ref('')

async function testConnection() {
  status.value = 'Testando...'
  statusClass.value = 'testing'
  
  try {
    // Testar fetch do remoteEntry.js
    const response = await fetch('http://localhost:5175/assets/remoteEntry.js')
    
    if (response.ok) {
      const text = await response.text()
      const contentType = response.headers.get('content-type')
      
      status.value = `🟢 remoteEntry.js encontrado!\n`
      status.value += `Status: ${response.status}\n`
      status.value += `Tamanho: ${text.length} bytes\n`
      status.value += `Content-Type: ${contentType}\n\n`
      
      if (contentType && contentType.includes('text/html')) {
        status.value += `⚠️ PROBLEMA DETECTADO!\n`
        status.value += `O arquivo está retornando HTML ao invés de JavaScript.\n\n`
        status.value += `SOLUÇÃO:\n`
        status.value += `1. Pare o remote (Ctrl+C)\n`
        status.value += `2. cd my-vue-mf\n`
        status.value += `3. npm run build\n`
        status.value += `4. npm run preview\n`
        statusClass.value = 'warning'
      } else {
        status.value += `🟢 Content-Type correto! Os componentes devem carregar.`
        statusClass.value = 'success'
      }
    } else {
      status.value = `🔴 Erro ${response.status}: ${response.statusText}`
      statusClass.value = 'error'
    }
  } catch (error) {
    status.value = `🔴 Erro: ${error.message}\n\n`
    status.value += 'Verifique se o remote está rodando em http://localhost:5175'
    statusClass.value = 'error'
  }
}
</script>

<style scoped>
.diagnostics {
  background: #f0f0f0;
  padding: 20px;
  border-radius: 8px;
  margin: 20px 0;
}

.diagnostics h3 {
  margin: 0 0 10px 0;
}

.diagnostics button {
  background: #667eea;
  color: white;
  border: none;
  padding: 10px 20px;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 600;
}

.status {
  margin-top: 15px;
  padding: 15px;
  border-radius: 6px;
  font-family: monospace;
  font-size: 0.9rem;
}

.status pre {
  margin: 0;
  white-space: pre-wrap;
}

.status.testing {
  background: #fff3cd;
  border: 1px solid #ffc107;
}

.status.success {
  background: #d4edda;
  border: 1px solid #28a745;
}

.status.warning {
  background: #fff3cd;
  border: 1px solid #ffc107;
  color: #856404;
}

.status.error {
  background: #f8d7da;
  border: 1px solid #dc3545;
}
</style>
