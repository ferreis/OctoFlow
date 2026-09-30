# Testes Playwright de segurança

Suíte E2E/API para os controles de autenticação do OctoFlow.

## Instalação

```bash
cd OctoFlow/tests/playwright
npm install
```

Os testes desta suíte usam apenas o `APIRequestContext`, então não é necessário instalar os binários dos navegadores para `npm test` enquanto não forem adicionados testes com `page`.

## Execução pública

Com o ambiente do OctoFlow ativo no endereço padrão:

```bash
npm test
```

Para outro endpoint:

```bash
PLAYWRIGHT_API_BASE_URL="https://localhost:4481/OctoFlow/api" npm test
```

Os testes públicos validam:

- emissão de nonce Google aleatório e sem cache;
- resposta genérica para credenciais inválidas, evitando enumeração de contas.

## Execução autenticada

Use uma conta exclusiva de teste, sem privilégios administrativos:

```bash
E2E_USER_EMAIL="usuario-e2e@example.test" \
E2E_USER_PASSWORD="senha-exclusiva-de-teste" \
PLAYWRIGHT_API_BASE_URL="https://localhost:4481/OctoFlow/api" \
npm test
```

O teste autenticado valida:

- access token retornado somente no corpo da resposta;
- refresh token mantido em cookie `HttpOnly` + `Secure`;
- cookie auxiliar do access token restrito a `/auth/logout`;
- rotação do refresh token;
- emissão de novo access token após refresh;
- presença de `roles` e `permissions` no payload da sessão;
- remoção dos cookies no logout;
- invalidação imediata do access token usado antes do logout.

## Variáveis opcionais

- `PLAYWRIGHT_REFRESH_COOKIE_NAME`: padrão `refresh_token`.
- `PLAYWRIGHT_LOGOUT_ACCESS_COOKIE_NAME`: padrão `logout_access_token`.

Não use credenciais de produção na suíte E2E.
