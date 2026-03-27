<script setup>
import { computed, onMounted, ref } from 'vue'
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

const BROWSER_ID_STORAGE_KEY = 'octoflow.test.browser-id'
const TAB_ID_STORAGE_KEY = 'octoflow.test.tab-id'

const posting = ref(false)
const responsePayload = ref(null)
const browserId = ref('')
const tabId = ref('')
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

const browserFingerprint = computed(() => {
  if (typeof window === 'undefined') {
    return ''
  }

  console.log(window.navigator?.language);
  console.log(Intl.DateTimeFormat().resolvedOptions().timeZone);
  console.log(window.navigator?.platform);
  console.log(window.navigator?.userAgent);
  console.log(window.screen?.width);
  console.log(window.screen?.height);
  console.log(window.devicePixelRatio);
  console.log(window.browserFingerprint);
    

  const language = String(window.navigator?.language || '')
  const timezone = String(Intl.DateTimeFormat().resolvedOptions().timeZone || '')
  const platform = String(window.navigator?.platform || '')
  const userAgent = String(window.navigator?.userAgent || '')
  const screenWidth = Number(window.screen?.width || 0)
  const screenHeight = Number(window.screen?.height || 0)
  const pixelRatio = Number(window.devicePixelRatio || 1)

  return `${language}|${timezone}|${platform}|${screenWidth}x${screenHeight}|${pixelRatio}|${userAgent}`
})

const browserFingerprintHash = computed(() => {
  const rawFingerprint = browserFingerprint.value
  if (rawFingerprint === '') {
    return ''
  }

  let accumulatedHash = 0
  for (let characterIndex = 0; characterIndex < rawFingerprint.length; characterIndex += 1) {
    const currentCharacterCode = rawFingerprint.charCodeAt(characterIndex)
    accumulatedHash = ((accumulatedHash << 5) - accumulatedHash) + currentCharacterCode
    accumulatedHash |= 0
  }

  return `fp-${Math.abs(accumulatedHash)}`
})

onMounted(() => {
  initializeBrowserIdentity()
})

function buildRandomIdentifier(prefixLabel) {
  if (typeof window !== 'undefined' && window.crypto && typeof window.crypto.randomUUID === 'function') {
    return `${prefixLabel}-${window.crypto.randomUUID()}`
  }

  const randomSuffix = `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
  return `${prefixLabel}-${randomSuffix}`
}

function initializeBrowserIdentity() {
  if (typeof window === 'undefined') {
    return
  }

  let persistedBrowserId = String(window.localStorage.getItem(BROWSER_ID_STORAGE_KEY) || '').trim()
  if (persistedBrowserId === '') {
    persistedBrowserId = buildRandomIdentifier('browser')
    window.localStorage.setItem(BROWSER_ID_STORAGE_KEY, persistedBrowserId)
  }
  browserId.value = persistedBrowserId

  let currentTabId = String(window.sessionStorage.getItem(TAB_ID_STORAGE_KEY) || '').trim()
  if (currentTabId === '') {
    currentTabId = buildRandomIdentifier('tab')
    window.sessionStorage.setItem(TAB_ID_STORAGE_KEY, currentTabId)
  }
  tabId.value = currentTabId
}

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
      headers: {
        'X-Tab-Id': tabId.value,
        'X-Browser-Id': browserId.value,
        'X-Browser-Fingerprint': browserFingerprintHash.value,
      },
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

      <div class="identity-grid">
        <div class="identity-item">
          <span class="identity-label">tab_id</span>
          <code class="identity-value">{{ tabId || 'não definido' }}</code>
        </div>
        <div class="identity-item">
          <span class="identity-label">browser_id</span>
          <code class="identity-value">{{ browserId || 'não definido' }}</code>
        </div>
        <div class="identity-item">
          <span class="identity-label">fingerprint_hash</span>
          <code class="identity-value">{{ browserFingerprintHash || 'não definido' }}</code>
        </div>
      </div>

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

.identity-grid {
  display: grid;
  gap: 10px;
  margin-top: 10px;
}

.identity-item {
  background: var(--surface-muted);
  border: 1px solid var(--line);
  border-radius: 14px;
  display: grid;
  gap: 6px;
  padding: 10px 12px;
}

.identity-label {
  color: var(--muted);
  font-size: 0.76rem;
  font-weight: 700;
}

.identity-value {
  font-size: 0.8rem;
  overflow-wrap: anywhere;
}
</style>
