export function splitRepositoryKey(value) {
  const normalizedValue = String(value || '').trim()
  const separatorIndex = normalizedValue.indexOf('/')

  if (separatorIndex <= 0) {
    return { owner: '', name: '' }
  }

  return {
    owner: normalizedValue.slice(0, separatorIndex),
    name: normalizedValue.slice(separatorIndex + 1),
  }
}

export function parseGithubRepositoryUrl(value) {
  const rawValue = String(value || '').trim()
  if (rawValue === '') {
    return null
  }

  try {
    const parsedUrl = new URL(rawValue)
    if (!parsedUrl.hostname.includes('github.com')) {
      return null
    }

    const [ownerLogin, name] = parsedUrl.pathname
      .split('/')
      .filter(Boolean)
      .slice(0, 2)

    if (!ownerLogin || !name) {
      return null
    }

    return {
      ownerLogin,
      name: name.replace(/\.git$/i, ''),
    }
  } catch {
    return null
  }
}
