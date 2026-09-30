import { createHash } from 'node:crypto'
import { expect, test } from '@playwright/test'

const API_BASE_URL = (process.env.PLAYWRIGHT_API_BASE_URL || 'https://localhost:4481/OctoFlow/api').replace(/\/$/, '')
const E2E_USER_EMAIL = String(process.env.E2E_USER_EMAIL || '').trim()
const E2E_USER_PASSWORD = String(process.env.E2E_USER_PASSWORD || '')
const REFRESH_COOKIE_NAME = String(process.env.PLAYWRIGHT_REFRESH_COOKIE_NAME || 'refresh_token').trim()
const LOGOUT_ACCESS_HASH_COOKIE_NAME = String(process.env.PLAYWRIGHT_LOGOUT_ACCESS_HASH_COOKIE_NAME || 'logout_access_token_hash').trim()

function apiUrl(path) {
  return `${API_BASE_URL}/${String(path || '').replace(/^\/+/, '')}`
}

function sha256(value) {
  return createHash('sha256').update(value).digest('hex')
}

async function publicCsrfHeaders(request, actionId, path) {
  const response = await request.post(apiUrl('/auth/csrf/challenge'), {
    data: {
      actionId,
      method: 'POST',
      path,
    },
  })

  expect(response.status()).toBe(200)
  const challenge = await response.json()
  expect(challenge.csrfToken).toMatch(/^[a-f0-9]{32}\.[a-f0-9]{64}$/)

  return {
    [challenge.headerName || 'X-CSRF-Token']: challenge.csrfToken,
    [challenge.actionHeaderName || 'X-CSRF-Action']: actionId,
  }
}

async function passwordLogin(request, email, password) {
  const headers = await publicCsrfHeaders(request, 'auth.login', '/auth/login')

  return request.post(apiUrl('/auth/login'), {
    headers,
    data: { email, password },
  })
}

async function currentCookies(request) {
  return (await request.storageState()).cookies
}

function findCookie(cookies, name) {
  return cookies.find((cookie) => cookie.name === name)
}

test.describe('segurança de autenticação', () => {
  test('nonce do Google é aleatório, efêmero e não deve ser cacheado', async ({ request }) => {
    const firstResponse = await request.get(apiUrl('/auth/google/nonce'))
    expect(firstResponse.status()).toBe(200)
    expect(firstResponse.headers()['cache-control']).toContain('no-store')
    const first = await firstResponse.json()

    const secondResponse = await request.get(apiUrl('/auth/google/nonce'))
    expect(secondResponse.status()).toBe(200)
    const second = await secondResponse.json()

    expect(first.nonce).toMatch(/^[A-Za-z0-9_-]{32,128}$/)
    expect(second.nonce).toMatch(/^[A-Za-z0-9_-]{32,128}$/)
    expect(first.nonce).not.toBe(second.nonce)
    expect(first.expiresIn).toBeGreaterThan(0)
    expect(first.expiresIn).toBeLessThanOrEqual(600)
  })

  test('login inválido não revela se a conta existe', async ({ request }) => {
    const randomEmail = `missing-${Date.now()}-${Math.random().toString(36).slice(2)}@example.invalid`
    const response = await passwordLogin(request, randomEmail, 'invalid-password-for-security-test')

    expect(response.status()).toBe(401)
    expect(await response.json()).toEqual({ message: 'Invalid credentials.' })
    expect(response.headers()['cache-control']).toContain('no-store')
  })

  test('access token e refresh token rotacionam e logout invalida a sessão', async ({ playwright }) => {
    test.skip(!E2E_USER_EMAIL || !E2E_USER_PASSWORD, 'Defina E2E_USER_EMAIL e E2E_USER_PASSWORD para o teste autenticado.')

    const request = await playwright.request.newContext({
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: {
        'User-Agent': 'OctoFlow-Playwright-Security-Test/1.0',
        'X-Browser-Id': 'playwright-security-browser',
        'X-Browser-Fingerprint': 'playwright-security-fingerprint',
        'X-Client-Location': 'test-environment',
      },
    })

    try {
      const loginResponse = await passwordLogin(request, E2E_USER_EMAIL, E2E_USER_PASSWORD)
      expect(loginResponse.status()).toBe(200)
      const loginPayload = await loginResponse.json()

      expect(loginPayload.token_type).toBe('Bearer')
      expect(loginPayload.token).toEqual(expect.any(String))
      expect(loginPayload.token.length).toBeGreaterThan(40)
      expect(loginPayload).not.toHaveProperty('refresh_token')
      expect(loginPayload).not.toHaveProperty('refreshToken')
      expect(loginPayload.user?.roles).toEqual(expect.any(Array))
      expect(loginPayload.user?.permissions).toEqual(expect.any(Array))

      const cookiesAfterLogin = await currentCookies(request)
      const refreshAfterLogin = findCookie(cookiesAfterLogin, REFRESH_COOKIE_NAME)
      const logoutHashAfterLogin = findCookie(cookiesAfterLogin, LOGOUT_ACCESS_HASH_COOKIE_NAME)

      expect(refreshAfterLogin).toBeTruthy()
      expect(refreshAfterLogin.httpOnly).toBe(true)
      expect(refreshAfterLogin.secure).toBe(true)
      expect(refreshAfterLogin.path.endsWith('/auth')).toBe(true)
      expect(logoutHashAfterLogin).toBeTruthy()
      expect(logoutHashAfterLogin.httpOnly).toBe(true)
      expect(logoutHashAfterLogin.secure).toBe(true)
      expect(logoutHashAfterLogin.path.endsWith('/auth/logout')).toBe(true)
      expect(logoutHashAfterLogin.value).toBe(sha256(loginPayload.token))
      expect(logoutHashAfterLogin.value).not.toBe(loginPayload.token)

      const refreshHeaders = await publicCsrfHeaders(request, 'auth.refresh', '/auth/refresh')
      const refreshResponse = await request.post(apiUrl('/auth/refresh'), { headers: refreshHeaders })
      expect(refreshResponse.status()).toBe(200)
      const refreshPayload = await refreshResponse.json()

      expect(refreshPayload.token).toEqual(expect.any(String))
      expect(refreshPayload.token).not.toBe(loginPayload.token)
      expect(refreshPayload).not.toHaveProperty('refresh_token')
      expect(refreshPayload).not.toHaveProperty('refreshToken')

      const cookiesAfterRefresh = await currentCookies(request)
      const refreshAfterRotation = findCookie(cookiesAfterRefresh, REFRESH_COOKIE_NAME)
      const logoutHashAfterRefresh = findCookie(cookiesAfterRefresh, LOGOUT_ACCESS_HASH_COOKIE_NAME)
      expect(refreshAfterRotation).toBeTruthy()
      expect(refreshAfterRotation.value).not.toBe(refreshAfterLogin.value)
      expect(logoutHashAfterRefresh?.value).toBe(sha256(refreshPayload.token))

      const meResponse = await request.get(apiUrl('/auth/me'), {
        headers: { Authorization: `Bearer ${refreshPayload.token}` },
      })
      expect(meResponse.status()).toBe(200)

      const logoutHeaders = await publicCsrfHeaders(request, 'auth.logout', '/auth/logout')
      const logoutResponse = await request.post(apiUrl('/auth/logout'), { headers: logoutHeaders })
      expect(logoutResponse.status()).toBe(200)

      const cookiesAfterLogout = await currentCookies(request)
      expect(findCookie(cookiesAfterLogout, REFRESH_COOKIE_NAME)).toBeFalsy()
      expect(findCookie(cookiesAfterLogout, LOGOUT_ACCESS_HASH_COOKIE_NAME)).toBeFalsy()

      const replayAccessResponse = await request.get(apiUrl('/auth/me'), {
        headers: { Authorization: `Bearer ${refreshPayload.token}` },
      })
      expect(replayAccessResponse.status()).toBe(401)
    } finally {
      await request.dispose()
    }
  })
})
