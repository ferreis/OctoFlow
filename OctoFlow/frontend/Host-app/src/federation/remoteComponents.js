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
