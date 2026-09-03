import { test, expect } from '@playwright/test'

const apiBaseUrl = (process.env.OCTOFLOW_API_BASE_URL || '').replace(/\/$/, '')

const requireConfiguredBaseUrl = () => {
  test.skip(!apiBaseUrl, 'Defina OCTOFLOW_API_BASE_URL para executar os testes contra uma instância do OctoFlow.')
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
})
