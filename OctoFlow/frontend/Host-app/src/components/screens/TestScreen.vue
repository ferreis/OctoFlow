<script setup>
import { ref } from 'vue'
import { useNotification } from '../../composables/useNotification'
import { extractHttpMessage } from '../../utils/httpErrors'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  notify: {
    type: Function,
    default: null,
  },
})

const { notifyUser } = useNotification(props.notify)

const posting = ref(false)
const responsePayload = ref(null)
const requestPayloadText = ref(JSON.stringify({
  origem: 'tela-teste',
  descricao: 'Payload de teste para captura completa do backend',
  tipos: {
    texto: 'exemplo',
    numero: 123,
    booleano: true,
    nulo: null,
  },
  lista: [1, 'dois', { nivel: 3 }],
  objeto: {
    cliente: 'OctoFlow',
    contexto: 'teste-post',
    ambiente: 'frontend-host',
  },
  geradoEm: new Date().toISOString(),
}, null, 2))

async function sendTestPost() {
  if (posting.value) {
    return
  }

  let parsedPayload
  try {
    parsedPayload = JSON.parse(requestPayloadText.value)
  } catch (error) {
    notifyUser(`JSON inválido no payload de teste: ${error.message}`, 'error')
    return
  }

  posting.value = true

  try {
    const { data } = await props.request({
      url: '/tasks/test/post-capture',
      method: 'POST',
      csrfActionId: 'tasks.test.post-capture',
      data: parsedPayload,
    })

    responsePayload.value = data || null
    notifyUser('POST de teste enviado com sucesso.', 'success')
  } catch (error) {
    notifyUser(extractHttpMessage(error, 'Falha ao enviar o POST de teste.'), 'error')
  } finally {
    posting.value = false
  }
}

function resetPayloadToDefault() {
  requestPayloadText.value = JSON.stringify({
    origem: 'tela-teste',
    descricao: 'Payload de teste para captura completa do backend',
    tipos: {
      texto: 'exemplo',
      numero: 123,
      booleano: true,
      nulo: null,
    },
    lista: [1, 'dois', { nivel: 3 }],
    objeto: {
      cliente: 'OctoFlow',
      contexto: 'teste-post',
      ambiente: 'frontend-host',
    },
    geradoEm: new Date().toISOString(),
  }, null, 2)
}
</script>

<template>
  <section class="screen-grid">
    <article class="surface-card">
      <p class="section-kicker">Teste</p>
      <h2>POST de captura</h2>
      <p class="muted-copy">
        Clique no botão para enviar um POST. O backend devolve em JSON tudo o que recebeu no corpo da requisição.
      </p>

      <div class="field">
        <span>Payload (JSON)</span>
        <textarea
          v-model="requestPayloadText"
          rows="14"
          placeholder="Digite um JSON válido"
        />
      </div>

      <div class="form-actions">
        <button class="button-primary" type="button" :disabled="posting" @click="sendTestPost">
          {{ posting ? 'Enviando...' : 'Enviar POST de teste' }}
        </button>
        <button class="button-secondary" type="button" :disabled="posting" @click="resetPayloadToDefault">
          Restaurar payload
        </button>
      </div>
    </article>

    <article class="surface-card">
      <p class="section-kicker">Retorno</p>
      <h2>Resposta JSON</h2>

      <p v-if="!responsePayload" class="inline-note">
        Nenhuma resposta ainda. Envie o POST para visualizar o JSON retornado.
      </p>

      <pre v-else class="test-json-preview">{{ JSON.stringify(responsePayload, null, 2) }}</pre>
    </article>
  </section>
</template>

<style scoped>
.test-json-preview {
  background: var(--surface-muted);
  border: 1px solid var(--line);
  border-radius: 16px;
  font-size: 0.84rem;
  line-height: 1.5;
  margin: 0;
  max-height: 520px;
  overflow: auto;
  padding: 14px;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
