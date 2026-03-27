export function fetchGithubIssuesCache(request, params = {}) {
  return request({
    url: '/github/issues/cache',
    method: 'GET',
    params,
  })
}

export function syncGithubIssues(request, params = {}) {
  return request({
    url: '/github/issues/assigned',
    method: 'GET',
    params,
  })
}

export function fetchGithubIssueDetails(request, issueId) {
  return request({
    url: `/github/issues/${encodeURIComponent(issueId)}`,
    method: 'GET',
  })
}

export function createGithubIssue(request, payload) {
  return request({
    url: '/github/issues',
    method: 'POST',
    csrfActionId: 'github.issue.create',
    data: payload,
  })
}

export function updateGithubIssue(request, issueId, payload) {
  return request({
    url: `/github/issues/${encodeURIComponent(issueId)}`,
    method: 'PATCH',
    csrfActionId: 'github.issue.update',
    data: payload,
  })
}

export function createGithubSubIssues(request, issueId, payload) {
  return request({
    url: `/github/issues/${encodeURIComponent(issueId)}/sub-issues`,
    method: 'POST',
    csrfActionId: 'github.issue.update',
    data: payload,
  })
}

export function fetchTaskTemplates(request) {
  return request({
    url: '/tasks/templates',
    method: 'GET',
  })
}

export function fetchTaskUpdateTemplates(request) {
  return request({
    url: '/tasks/update-templates',
    method: 'GET',
  })
}

export function fetchLocalTasks(request) {
  return request({
    url: '/tasks/local-issues',
    method: 'GET',
  })
}

export function createLocalTask(request, payload) {
  return request({
    url: '/tasks/local-issues',
    method: 'POST',
    csrfActionId: 'task.local.create',
    data: payload,
  })
}

export function fetchLocalTask(request, taskId) {
  return request({
    url: `/tasks/local-issues/${encodeURIComponent(taskId)}`,
    method: 'GET',
  })
}

export function updateLocalTask(request, taskId, payload) {
  return request({
    url: `/tasks/local-issues/${encodeURIComponent(taskId)}`,
    method: 'PATCH',
    csrfActionId: 'task.local.update',
    data: payload,
  })
}

export function syncLocalTaskToGithub(request, taskId, payload) {
  return request({
    url: `/tasks/local-issues/${encodeURIComponent(taskId)}/sync`,
    method: 'POST',
    csrfActionId: 'task.local.sync',
    data: payload,
  })
}
