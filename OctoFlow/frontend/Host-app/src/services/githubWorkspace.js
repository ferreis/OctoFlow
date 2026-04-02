import { useSessionStore } from '../stores/sessionStore'

function requestWithSession(config) {
  const sessionStore = useSessionStore()
  return sessionStore.authRequest(config)
}

export function fetchGithubProfile() {
  return requestWithSession({
    url: '/github/profile',
    method: 'GET',
  })
}

export function createGithubAccount(payload) {
  return requestWithSession({
    url: '/github/accounts',
    method: 'POST',
    csrfActionId: 'github.account.create',
    data: payload,
  })
}

export function updateGithubAccount(accountId, payload) {
  return requestWithSession({
    url: `/github/accounts/${accountId}`,
    method: 'PATCH',
    csrfActionId: 'github.account.update',
    data: payload,
  })
}

export function deleteGithubAccount(accountId) {
  return requestWithSession({
    url: `/github/accounts/${accountId}`,
    method: 'DELETE',
    csrfActionId: 'github.account.delete',
  })
}

export function createGithubRepository(payload) {
  return requestWithSession({
    url: '/github/repositories',
    method: 'POST',
    csrfActionId: 'github.repository.create',
    data: payload,
  })
}

export function updateGithubRepository(repositoryId, payload) {
  return requestWithSession({
    url: `/github/repositories/${repositoryId}`,
    method: 'PATCH',
    csrfActionId: 'github.repository.update',
    data: payload,
  })
}

export function deleteGithubRepository(repositoryId) {
  return requestWithSession({
    url: `/github/repositories/${repositoryId}`,
    method: 'DELETE',
    csrfActionId: 'github.repository.delete',
  })
}

export function fetchGithubWorkspace(params = {}) {
  return requestWithSession({
    url: '/github/workspace',
    method: 'GET',
    params,
  })
}
