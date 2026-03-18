import { computed } from 'vue'

export function usePermissions(currentUserRef, contextRefs = {}) {
  const canLoadRemoteGithubPanels = computed(() => (
    Boolean(currentUserRef?.value?.id)
    && Boolean(contextRefs.profile?.value?.workspaceReady)
    && Boolean(contextRefs.workspace?.value?.repository)
  ))

  const canLoadRemoteIssueComposer = computed(() => (
    canLoadRemoteGithubPanels.value
    && Array.isArray(contextRefs.workspace?.value?.templates)
    && contextRefs.workspace.value.templates.length > 0
  ))

  return {
    canLoadRemoteGithubPanels,
    canLoadRemoteIssueComposer,
  }
}
