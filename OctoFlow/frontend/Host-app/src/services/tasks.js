import { useSessionStore } from '../stores/sessionStore'

function requestWithSession(config) {
  const sessionStore = useSessionStore()
  return sessionStore.authRequest(config)
}

export function fetchGithubIssuesCache(params = {}) {
  return requestWithSession({
    url: '/github/issues/cache',
    method: 'GET',
    params,
  })
}

export function syncGithubIssues(params = {}) {
  return requestWithSession({
    url: '/github/issues/assigned',
    method: 'GET',
    params,
  })
}

export function fetchGithubIssueDetails(issueId) {
  return requestWithSession({
    url: `/github/issues/${encodeURIComponent(issueId)}`,
    method: 'GET',
  })
}

export function createGithubIssue(payload) {
  return requestWithSession({
    url: '/github/issues',
    method: 'POST',
    csrfActionId: 'github.issue.create',
    data: payload,
  })
}

export function updateGithubIssue(issueId, payload) {
  return requestWithSession({
    url: `/github/issues/${encodeURIComponent(issueId)}`,
    method: 'PATCH',
    csrfActionId: 'github.issue.update',
    data: payload,
  })
}

export function createGithubSubIssues(issueId, payload) {
  return requestWithSession({
    url: `/github/issues/${encodeURIComponent(issueId)}/sub-issues`,
    method: 'POST',
    csrfActionId: 'github.issue.update',
    data: payload,
  })
}

export function fetchTaskTemplates() {
  return requestWithSession({
    url: '/tasks/templates',
    method: 'GET',
  })
}

export function fetchTaskUpdateTemplates() {
  return requestWithSession({
    url: '/tasks/update-templates',
    method: 'GET',
  })
}

export function fetchLocalTasks() {
  return requestWithSession({
    url: '/tasks/local-issues',
    method: 'GET',
  })
}

export function createLocalTask(payload) {
  return requestWithSession({
    url: '/tasks/local-issues',
    method: 'POST',
    csrfActionId: 'task.local.create',
    data: payload,
  })
}

export function fetchLocalTask(taskId) {
  return requestWithSession({
    url: `/tasks/local-issues/${encodeURIComponent(taskId)}`,
    method: 'GET',
  })
}

export function updateLocalTask(taskId, payload) {
  return requestWithSession({
    url: `/tasks/local-issues/${encodeURIComponent(taskId)}`,
    method: 'PATCH',
    csrfActionId: 'task.local.update',
    data: payload,
  })
}

export function syncLocalTaskToGithub(taskId, payload) {
  return requestWithSession({
    url: `/tasks/local-issues/${encodeURIComponent(taskId)}/sync`,
    method: 'POST',
    csrfActionId: 'task.local.sync',
    data: payload,
  })
}
