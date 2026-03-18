export function fetchGithubProfile(request) {
  return request({
    url: '/github/profile',
    method: 'GET',
  })
}

export function createGithubAccount(request, payload) {
  return request({
    url: '/github/accounts',
    method: 'POST',
    csrfActionId: 'github.account.create',
    data: payload,
  })
}

export function updateGithubAccount(request, accountId, payload) {
  return request({
    url: `/github/accounts/${accountId}`,
    method: 'PATCH',
    csrfActionId: 'github.account.update',
    data: payload,
  })
}

export function deleteGithubAccount(request, accountId) {
  return request({
    url: `/github/accounts/${accountId}`,
    method: 'DELETE',
    csrfActionId: 'github.account.delete',
  })
}

export function createGithubRepository(request, payload) {
  return request({
    url: '/github/repositories',
    method: 'POST',
    csrfActionId: 'github.repository.create',
    data: payload,
  })
}

export function updateGithubRepository(request, repositoryId, payload) {
  return request({
    url: `/github/repositories/${repositoryId}`,
    method: 'PATCH',
    csrfActionId: 'github.repository.update',
    data: payload,
  })
}

export function deleteGithubRepository(request, repositoryId) {
  return request({
    url: `/github/repositories/${repositoryId}`,
    method: 'DELETE',
    csrfActionId: 'github.repository.delete',
  })
}

export function fetchGithubWorkspace(request, params = {}) {
  return request({
    url: '/github/workspace',
    method: 'GET',
    params,
  })
}
