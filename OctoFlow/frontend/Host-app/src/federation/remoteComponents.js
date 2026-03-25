import { defineAsyncComponent } from 'vue'

function createRemoteComponent(loader) {
  return defineAsyncComponent({
    loader,
    delay: 120,
    timeout: 20000,
  })
}

export const RemoteGithubWorkspaceSummaryCard = createRemoteComponent(() => import('remoteApp/GithubWorkspaceSummaryCard'))
export const RemoteGithubProjectsCard = createRemoteComponent(() => import('remoteApp/GithubProjectsCard'))
export const RemoteMarkdownPreview = createRemoteComponent(() => import('remoteApp/MarkdownPreview'))
export const RemoteMultiSelect = createRemoteComponent(() => import('remoteApp/MultiSelect'))
export const RemoteIssueTemplateForm = createRemoteComponent(() => import('remoteApp/IssueTemplateForm'))
export const RemoteIssueTemplatePreview = createRemoteComponent(() => import('remoteApp/IssueTemplatePreview'))
export const RemoteFinanceKpiCard = createRemoteComponent(() => import('remoteApp/FinanceKpiCard'))
export const RemoteFinanceStatusBadge = createRemoteComponent(() => import('remoteApp/FinanceStatusBadge'))
export const RemoteFinanceEmptyState = createRemoteComponent(() => import('remoteApp/FinanceEmptyState'))
export const RemoteFinanceTrendMiniChart = createRemoteComponent(() => import('remoteApp/FinanceTrendMiniChart'))
