import { computed, ref } from 'vue'
import { usePermissions } from './usePermissions'
import { fetchGithubProfile, fetchGithubWorkspace } from '../services/githubWorkspace'
import { fetchGithubIssuesCache, syncGithubIssues } from '../services/tasks'
import { extractHttpMessage } from '../utils/httpErrors'

function resolveMessage(translate, messageKey, fallbackMessage) {
  if (typeof translate !== 'function') {
    return fallbackMessage
  }

  const translatedMessage = translate(messageKey)
  return translatedMessage === messageKey ? fallbackMessage : translatedMessage
}

export function useDashboardTasksData({
  currentUserRef,
  translate,
}) {
  const profile = ref(null)
  const workspace = ref(null)
  const issueBoard = ref(null)
  const loading = ref(false)
  const syncing = ref(false)
  const errorMessage = ref('')
  const statusMessage = ref('')

  const workspaceReady = computed(() => Boolean(profile.value?.workspaceReady))
  const repositoryLabel = computed(() => {
    const repositories = Array.isArray(profile.value?.repositories) ? profile.value.repositories : []
    const repositoryOwner = String(profile.value?.repositoryOwner || '').trim()

    if (repositories.length > 0) {
      return resolveMessage(translate, 'dashboard.tasks.workspace.repositoriesConfigured', `${repositories.length} repositorios cadastrados`)
        .replace('{count}', String(repositories.length))
    }

    if (repositoryOwner !== '') {
      return resolveMessage(translate, 'dashboard.tasks.workspace.defaultOwner', `Owner padrao: ${repositoryOwner}`)
        .replace('{owner}', repositoryOwner)
    }

    return resolveMessage(translate, 'dashboard.tasks.workspace.repositoriesMissing', 'Nenhum repositorio cadastrado')
  })

  const issues = computed(() => Array.isArray(issueBoard.value?.items) ? issueBoard.value.items : [])
  const repositories = computed(() => Array.isArray(issueBoard.value?.repositories) ? issueBoard.value.repositories : [])
  const openIssues = computed(() => issues.value.filter((issue) => issue.state !== 'CLOSED'))
  const closedIssues = computed(() => issues.value.filter((issue) => issue.state === 'CLOSED'))

  const { canLoadRemoteGithubPanels } = usePermissions(currentUserRef, {
    profile,
    workspace,
  })

  let dashboardRequestVersion = 0
  let dashboardComponentActive = true

  function isRequestStale(requestVersion) {
    return !dashboardComponentActive || requestVersion !== dashboardRequestVersion
  }

  function setTasksComponentActive(isComponentActive) {
    dashboardComponentActive = Boolean(isComponentActive)

    if (!dashboardComponentActive) {
      dashboardRequestVersion += 1
    }
  }

  function clearTasksState() {
    profile.value = null
    workspace.value = null
    issueBoard.value = null
    loading.value = false
    syncing.value = false
    errorMessage.value = ''
    statusMessage.value = ''
  }

  async function loadDashboardContext(showStatus = false) {
    if (!currentUserRef?.value?.id) {
      return
    }

    const requestVersion = ++dashboardRequestVersion

    loading.value = true
    errorMessage.value = ''

    if (showStatus) {
      statusMessage.value = ''
    }

    try {
      const profileResponse = await fetchGithubProfile()
      if (isRequestStale(requestVersion)) {
        return
      }

      profile.value = profileResponse.data?.profile || null

      if (!workspaceReady.value) {
        workspace.value = null
        issueBoard.value = null

        if (showStatus) {
          statusMessage.value = resolveMessage(
            translate,
            'dashboard.tasks.status.profileLoadedNeedsSetup',
            'Perfil GitHub carregado. Finalize a configuração no Perfil para liberar as analises.',
          )
        }

        return
      }

      await loadWorkspaceContext(requestVersion)
      if (isRequestStale(requestVersion)) {
        return
      }

      if (!canLoadRemoteGithubPanels.value) {
        issueBoard.value = null

        if (showStatus) {
          statusMessage.value = resolveMessage(
            translate,
            'dashboard.tasks.status.workspaceMissingPermissions',
            'Workspace sem contexto suficiente para liberar analises.',
          )
        }

        return
      }

      await loadIssueAnalytics(showStatus, requestVersion)
    } catch (requestError) {
      if (isRequestStale(requestVersion)) {
        return
      }

      workspace.value = null
      issueBoard.value = null
      errorMessage.value = extractHttpMessage(
        requestError,
        resolveMessage(
          translate,
          'dashboard.tasks.errors.loadDashboard',
          'Nao foi possivel carregar o dashboard analitico.',
        ),
      )
    } finally {
      if (!isRequestStale(requestVersion)) {
        loading.value = false
      }
    }
  }

  async function loadWorkspaceContext(requestVersion) {
    const workspaceResponse = await fetchGithubWorkspace()

    if (isRequestStale(requestVersion)) {
      return
    }

    workspace.value = workspaceResponse.data || null
  }

  async function loadIssueAnalytics(showStatus = false, requestVersion = dashboardRequestVersion) {
    const cacheResponse = await fetchGithubIssuesCache({ scope: 'all' })

    if (isRequestStale(requestVersion)) {
      return
    }

    issueBoard.value = cacheResponse.data || null

    const hasItems = Array.isArray(cacheResponse.data?.items) && cacheResponse.data.items.length > 0
    const needsRefresh = Boolean(cacheResponse.data?.cache?.needsRefresh)

    if (!hasItems) {
      await syncIssueAnalytics(showStatus)
      return
    }

    if (showStatus) {
      statusMessage.value = resolveMessage(
        translate,
        'dashboard.tasks.status.loadedFromCache',
        'Dashboard carregado do banco local.',
      )
    }

    if (needsRefresh) {
      void syncIssueAnalytics(false)
    }
  }

  async function syncIssueAnalytics(showStatus = false) {
    const requestVersion = ++dashboardRequestVersion

    syncing.value = true
    errorMessage.value = ''

    try {
      const syncResponse = await syncGithubIssues({ scope: 'all' })
      if (isRequestStale(requestVersion)) {
        return
      }

      issueBoard.value = syncResponse.data || null

      if (showStatus || issues.value.length === 0) {
        statusMessage.value = resolveMessage(
          translate,
          'dashboard.tasks.status.syncedFromGithub',
          'Analises atualizadas com os dados mais recentes do GitHub.',
        )
      }
    } catch (requestError) {
      if (isRequestStale(requestVersion)) {
        return
      }

      if (issues.value.length > 0) {
        statusMessage.value = resolveMessage(
          translate,
          'dashboard.tasks.status.usingCacheFallback',
          'Mantendo as analises do banco local enquanto a sincronização do GitHub nao responde.',
        )
        return
      }

      errorMessage.value = extractHttpMessage(
        requestError,
        resolveMessage(
          translate,
          'dashboard.tasks.errors.syncGithub',
          'Nao foi possivel sincronizar as analises do GitHub.',
        ),
      )
    } finally {
      if (!isRequestStale(requestVersion)) {
        syncing.value = false
      }
    }
  }

  return {
    profile,
    workspace,
    issueBoard,
    loading,
    syncing,
    errorMessage,
    statusMessage,
    workspaceReady,
    repositoryLabel,
    issues,
    repositories,
    openIssues,
    closedIssues,
    loadDashboardContext,
    syncIssueAnalytics,
    clearTasksState,
    setTasksComponentActive,
  }
}
