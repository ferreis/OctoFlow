<script setup>
import axios from 'axios'
import { computed, onMounted, ref, watch } from 'vue'

const props = defineProps({
  request: {
    type: Function,
    required: true,
  },
  currentUser: {
    type: Object,
    default: null,
  },
  summaryComponent: {
    type: [Object, Function],
    required: true,
  },
  composerComponent: {
    type: [Object, Function],
    required: true,
  },
  projectsComponent: {
    type: [Object, Function],
    required: true,
  },
  federationError: {
    type: String,
    default: '',
  },
})

const profile = ref(null)
const workspace = ref(null)
const loading = ref(false)
const error = ref('')
const status = ref('')
const composerMountKey = ref(0)

const displayEmail = computed(() => props.currentUser?.defaultEmail || props.currentUser?.email || 'usuario autenticado')
const workspaceReady = computed(() => Boolean(profile.value?.workspaceReady))
const activeRoles = computed(() => Array.isArray(props.currentUser?.roles) ? props.currentUser.roles : [])
const canRenderSummary = computed(() => workspaceReady.value && Boolean(workspace.value?.repository))
const canRenderComposer = computed(() => canRenderSummary.value && Array.isArray(workspace.value?.templates) && workspace.value.templates.length > 0)
const canRenderProjects = computed(() => workspaceReady.value && Boolean(workspace.value))

onMounted(async () => {
  await loadDashboardContext(false)
})

watch(
  () => props.currentUser?.id,
  async (userId, previousUserId) => {
    if (!userId) {
      profile.value = null
      workspace.value = null
      error.value = ''
      status.value = ''
      return
    }

    if (userId !== previousUserId) {
      await loadDashboardContext(false)
    }
  },
)

async function loadDashboardContext(showStatus = false) {
  if (!props.currentUser?.id) {
    return
  }

  loading.value = true
  error.value = ''

  if (showStatus) {
    status.value = ''
  }

  try {
    const profileResponse = await props.request({
      url: '/github/profile',
      method: 'GET',
    })

    profile.value = profileResponse.data?.profile || null

    if (!workspaceReady.value) {
      workspace.value = null

      if (showStatus) {
        status.value = 'Perfil GitHub carregado. Finalize a configuracao no Perfil para liberar os paineis do dashboard.'
      }

      return
    }

    const workspaceResponse = await props.request({
      url: '/github/workspace',
      method: 'GET',
    })

    workspace.value = workspaceResponse.data || null
    profile.value = workspaceResponse.data?.profile || profile.value
    composerMountKey.value += 1

    if (showStatus) {
      status.value = 'Dashboard do OctoFlow atualizado com sucesso.'
    }
  } catch (requestError) {
    workspace.value = null
    error.value = extractHttpMessage(requestError, 'Nao foi possivel carregar o dashboard do GitHub.')
  } finally {
    loading.value = false
  }
}

function handleIssueCreated(payload) {
  const issueNumber = payload?.issue?.number
  status.value = typeof issueNumber === 'number'
    ? `Issue #${issueNumber} criada com sucesso a partir do dashboard.`
    : 'Issue criada com sucesso a partir do dashboard.'
}

function extractHttpMessage(error, fallback) {
  if (axios.isAxiosError(error)) {
    const responseMessage = error.response?.data?.message
    if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
      return responseMessage
    }

    return error.message || fallback
  }

  if (error instanceof Error) {
    return error.message
  }

  return fallback
}
</script>

<template>
  <section class="grid gap-5">
    <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur lg:grid-cols-[minmax(0,1.45fr),minmax(250px,0.75fr)]">
      <div class="min-w-0">
        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">OctoFlow Dashboard</p>
        <h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">Painel central do GitHub por acesso</h2>
        <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600 sm:text-base">
          O dashboard centraliza o estado do repositorio, a criacao de chamados e a leitura dos Projects, com o host decidindo quais componentes remotos podem ser montados.
        </p>
      </div>

      <div class="grid gap-3">
        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Solicitante atual</span>
          <strong class="mt-2 block break-all text-base font-semibold text-slate-950">{{ displayEmail }}</strong>
        </div>
        <div class="rounded-2xl border border-cyan-200/60 bg-cyan-50/70 p-4">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-700">Modo</span>
          <strong class="mt-2 block text-base font-semibold text-cyan-950">Federated dashboard</strong>
        </div>
        <button
          type="button"
          class="inline-flex max-w-full items-center justify-center rounded-xl bg-gradient-to-r from-teal-600 via-cyan-500 to-sky-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-950/15 transition hover:brightness-105"
          :disabled="loading"
          @click="loadDashboardContext(true)"
        >
          {{ loading ? 'Atualizando...' : 'Atualizar dashboard' }}
        </button>
      </div>
    </article>

    <p
      v-if="status"
      class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
    >
      {{ status }}
    </p>

    <p
      v-if="federationError"
      class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
    >
      {{ federationError }}
    </p>

    <article
      v-if="loading"
      class="grid min-h-[220px] place-items-center rounded-[28px] border border-white/60 bg-white/80 p-5 text-sm text-slate-500 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur"
    >
      Carregando configuracao e dashboard do GitHub...
    </article>

    <article
      v-else-if="error"
      class="grid gap-4 rounded-[28px] border border-red-200 bg-red-50/90 p-5 shadow-[0_18px_48px_rgba(239,68,68,0.08)]"
    >
      <div>
        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-red-600">Erro</p>
        <h3 class="mt-1 text-2xl font-semibold text-red-950">Nao foi possivel montar o dashboard</h3>
      </div>

      <p class="text-sm leading-7 text-red-800">{{ error }}</p>

      <button
        type="button"
        class="inline-flex max-w-full items-center justify-center rounded-xl border border-red-200 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-50"
        @click="loadDashboardContext(true)"
      >
        Tentar novamente
      </button>
    </article>

    <article
      v-else-if="!workspaceReady"
      class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur"
    >
      <div>
        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Perfil necessario</p>
        <h3 class="mt-1 text-2xl font-semibold text-slate-950">Configure o GitHub antes de liberar o dashboard</h3>
        <p class="mt-3 text-sm leading-7 text-slate-600">
          O host so instancia os componentes remotos do OctoFlow quando o perfil GitHub estiver pronto. Assim o dashboard nao carrega paineis sem owner, repositorio e token validos.
        </p>
      </div>

      <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Repositorio</span>
          <strong class="mt-2 block break-all text-sm font-semibold text-slate-950">
            {{ profile?.repositoryOwner || 'nao configurado' }}/{{ profile?.repositoryName || 'nao configurado' }}
          </strong>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Token</span>
          <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ profile?.tokenConfigured ? 'Salvo' : 'Ausente' }}</strong>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
          <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Roles</span>
          <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ activeRoles.join(', ') || 'ROLE_USER' }}</strong>
        </div>
      </div>
    </article>

    <div v-else class="grid gap-5">
      <Suspense v-if="canRenderSummary">
        <template #default>
          <component :is="summaryComponent" :workspace="workspace" :current-user="currentUser" />
        </template>
        <template #fallback>
          <article class="rounded-[28px] border border-white/60 bg-white/80 p-5 text-sm text-slate-500 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
            Carregando resumo remoto...
          </article>
        </template>
      </Suspense>

      <div class="grid gap-5 xl:grid-cols-[minmax(0,1.18fr),minmax(320px,0.82fr)]">
        <Suspense v-if="canRenderComposer">
          <template #default>
            <component
              :is="composerComponent"
              :key="composerMountKey"
              :request="request"
              :current-user="currentUser"
              :workspace="workspace"
              @issue-created="handleIssueCreated"
            />
          </template>
          <template #fallback>
            <article class="rounded-[28px] border border-white/60 bg-white/80 p-5 text-sm text-slate-500 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
              Carregando composer remoto...
            </article>
          </template>
        </Suspense>

        <div class="grid gap-5">
          <article class="grid gap-4 rounded-[28px] border border-white/60 bg-white/80 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
            <div>
              <p class="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Politica de montagem</p>
              <h3 class="mt-1 text-2xl font-semibold text-slate-950">Host no controle</h3>
              <p class="mt-3 text-sm leading-7 text-slate-600">
                O host do OctoFlow consulta o perfil, valida se o dashboard GitHub esta pronto e so entao monta os componentes federados necessarios.
              </p>
            </div>

            <div class="grid gap-3">
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Resumo remoto</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ canRenderSummary ? 'Montado' : 'Bloqueado' }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Composer remoto</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ canRenderComposer ? 'Montado' : 'Bloqueado' }}</strong>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Projects remoto</span>
                <strong class="mt-2 block text-sm font-semibold text-slate-950">{{ canRenderProjects ? 'Montado' : 'Bloqueado' }}</strong>
              </div>
            </div>
          </article>

          <Suspense v-if="canRenderProjects">
            <template #default>
              <component :is="projectsComponent" :workspace="workspace" />
            </template>
            <template #fallback>
              <article class="rounded-[28px] border border-white/60 bg-white/80 p-5 text-sm text-slate-500 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur">
                Carregando painel remoto de projects...
              </article>
            </template>
          </Suspense>
        </div>
      </div>
    </div>
  </section>
</template>
