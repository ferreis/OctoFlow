export function fetchGithubProfile(request) {
  return request({
    url: '/github/profile',
    method: 'GET',
  })
}

export function fetchGithubWorkspace(request, params = {}) {
  return request({
    url: '/github/workspace',
    method: 'GET',
    params,
  })
}
