# Seguranca de Autenticacao e Refresh Token (OctoFlow)

Atualizado em: 27/03/2026

## Objetivo
Documentar o comportamento real do login/sessao no sistema hoje.

## Resumo rapido

- O frontend usa `access_token` em memoria para rotas protegidas.
- O `refresh_token` fica em cookie `HttpOnly`.
- O refresh so e chamado quando:
1. o app inicia e detecta cookie de refresh disponivel; ou
2. uma rota protegida retorna `401`.
- Refresh token roda com rotacao obrigatoria a cada uso.
- Reuso de token revogado derruba a familia inteira de tokens.
- Rotas do host foram separadas em:
  - publicas: `/auth/*`
  - protegidas: `/app/*`
  - aliases legados com redirecionamento: `/dashboard`, `/tasks`, `/finance`, `/profile`
- Guardas de rota no frontend servem apenas para UX. Segurança/autorização final deve ser sempre do backend.

## Fluxo de auto-login no F5

1. Frontend chama `GET /auth/session/restore-available`.
2. Se `restoreAvailable=true`, chama `POST /auth/refresh`.
3. Recebe novo `access_token` + novo `refresh_token`.
4. Sessao e restaurada sem mostrar login de forma definitiva.

## Cookie de refresh (estado atual)

Variaveis:

```dotenv
AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token
AUTH_REFRESH_COOKIE_SECURE=1
AUTH_REFRESH_COOKIE_SAMESITE=none # .env
AUTH_REFRESH_COOKIE_SAMESITE=lax  # .env.local
```

Regras:
- `HttpOnly=true`
- `Secure=true`
- `SameSite` por ambiente
- `Path` calculado pelo backend com prioridade:
1. `X-Forwarded-Prefix` (ex.: `/OctoFlow/api` -> cookie em `/OctoFlow/api/auth`)
2. `basePath` do request
3. fallback `/auth`

## Validacao de contexto no refresh token

Campos usados:
- `fingerprintHash`
- `userAgentHash`
- `ipHash`
- `locationHash`

Origem:
- `fingerprintHash`: `X-Browser-Id` (preferencial) ou `X-Browser-Fingerprint`
- `userAgentHash`: `User-Agent`
- `ipHash`: IP de origem do request
- `locationHash`: `X-Client-Location`

Regra de bloqueio:
1. Se fingerprint confiavel mudou: bloqueia e revoga familia.
2. Caso contrario, se 2 ou mais sinais mudaram entre:
   - IP
   - User-Agent
   - Localizacao
   entao bloqueia e revoga familia.
3. Mudanca isolada de 1 sinal e tolerada, com auditoria (`contextChangedAt`).

## Limite de sessoes simultaneas

- `AUTH_REFRESH_TOKEN_MAX_ACTIVE=10`
- Se passar de 10, os tokens ativos mais antigos sao revogados.

## Resposta para duvidas frequentes

### Por que nao usamos identificador unico de placa-mae/PC no browser?
Porque navegador web nao expoe serial de hardware por privacidade e seguranca.

### Entao da para fingir outro dispositivo?
Parcialmente sim:
- IP pode ser alterado (VPN/proxy).
- User-Agent pode ser falsificado.
- Fingerprint pode ser imitado em ataque avancado.

Por isso usamos validacao combinada + rotacao + revogacao por familia.

### O que e `PHPSESSID`?
Cookie de sessao do PHP/Symfony, usado no fluxo de CSRF one-time challenge.

### O que e `g_state`?
Cookie do Google Identity Services. Nao e criado pelo backend do OctoFlow.

## Entidade RefreshToken (campos e uso)

| Campo | Funcao |
|---|---|
| `id` | ID interno |
| `user` | Dono do token |
| `tokenHash` | Hash do refresh token |
| `fingerprintHash` | Hash de identidade do navegador/dispositivo |
| `userAgentHash` | Hash do User-Agent |
| `ipHash` | Hash do IP |
| `locationHash` | Hash da localizacao enviada pelo cliente |
| `tokenFamilyId` | Familia de rotacao |
| `parentTokenHash` | Encadeamento de rotacao |
| `expiresAt` | Expiracao |
| `createdAt` | Criacao |
| `revokedAt` | Revogacao |
| `contextChangedAt` | Mudanca de contexto |
| `reuseDetectedAt` | Reuso detectado |

## Arquivos principais

- `www/src/Controller/AuthController.php`
- `www/src/Security/RefreshTokenManager.php`
- `www/src/Repository/RefreshTokenRepository.php`
- `www/src/Entity/RefreshToken.php`
- `www/src/Security/CsrfTokenManager.php`
- `frontend/Host-app/src/App.vue`
