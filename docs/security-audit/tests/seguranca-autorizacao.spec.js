import { test, expect } from '@playwright/test'

const apiBaseUrl = (process.env.OCTOFLOW_API_BASE_URL || '').replace(/\/$/, '')
const accessToken = (process.env.OCTOFLOW_ACCESS_TOKEN || '').trim()
const unregisteredIssueId = (process.env.OCTOFLOW_UNREGISTERED_ISSUE_ID || '').trim()

const requireConfiguredBaseUrl = () => {
  test.skip(!apiBaseUrl, 'Defina OCTOFLOW_API_BASE_URL para executar os testes contra uma instância do OctoFlow.')
}

const requireAuthenticatedIdorFixture = () => {
  requireConfiguredBaseUrl()
  test.skip(
    !accessToken || !unregisteredIssueId,
    'Defina OCTOFLOW_ACCESS_TOKEN e OCTOFLOW_UNREGISTERED_ISSUE_ID para validar a proteção IDOR autenticada.',
  )
}

test.describe('Auditoria de segurança — fronteiras de autenticação', () => {
  test('nega acesso anônimo à listagem financeira', async ({ request }) => {
    requireConfiguredBaseUrl()

    const response = await request.get(`${apiBaseUrl}/finance/entries`)

    expect([401, 403]).toContain(response.status())
  })

  test('nega acesso anônimo a issue GitHub por identificador', async ({ request }) => {
    requireConfiguredBaseUrl()

    const response = await request.get(`${apiBaseUrl}/github/issues/nao-autorizado`)

    expect([401, 403]).toContain(response.status())
  })

  test('nega mutação financeira anônima mesmo com payload válido', async ({ request }) => {
    requireConfiguredBaseUrl()

    const response = await request.post(`${apiBaseUrl}/finance/entries`, {
      data: {
        direction: 'PAYABLE',
        title: 'Teste de autorização',
        expectedAmountBrl: 1,
      },
    })

    expect([401, 403]).toContain(response.status())
  })

  test('bloqueia issue de repositório acessível no GitHub mas não cadastrado no OctoFlow', async ({ request }) => {
    requireAuthenticatedIdorFixture()

    const response = await request.get(`${apiBaseUrl}/github/issues/${encodeURIComponent(unregisteredIssueId)}`, {
      headers: {
        Authorization: `Bearer ${accessToken}`,
      },
    })

    expect(response.status()).toBe(403)
  })
})
