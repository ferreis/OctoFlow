# Autenticacao e Cadeia de Comunicacao (Projeto `www`)

## Objetivo
Este documento consolida, em um unico lugar, como a autenticacao funciona no projeto e como a requisicao trafega entre frontend, proxy e backend.

## Configuracao Atual (fonte de verdade)

Arquivo: `www/.env`

```dotenv
JWT_TOKEN_TTL=600
AUTH_REFRESH_TOKEN_TTL=1209600
AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token
AUTH_REFRESH_COOKIE_SECURE=1
AUTH_REFRESH_COOKIE_SAMESITE=none
```

Arquivo: `www/.env.local` (desenvolvimento)

```dotenv
AUTH_REFRESH_COOKIE_SECURE=0
AUTH_REFRESH_COOKIE_SAMESITE=lax
```

Resumo:
- Access Token (JWT): 10 minutos (`expires_in = 600`)
- Refresh Token: 14 dias (1209600 segundos)
- Cookie name: `refresh_token`
- Cookie path: `/` (global)
- Cookie `HttpOnly`: true (definido no codigo)
- Cookie `Secure`: true em producao, false em desenvolvimento
- Cookie `SameSite`: none em producao, lax em desenvolvimento

## Campos de Seguranca na Tabela `refresh_token`
Campos principais relacionados ao fluxo discutido:

- `token_hash`: hash SHA-256 do refresh token em texto puro. O token puro nunca e salvo no banco.
- `token_family_id`: identifica a familia de tokens da mesma sessao/dispositivo.
- `parent_token_hash`: hash do token anterior na cadeia de rotacao.
  - Uso: rastreabilidade da linhagem do token.
- `fingerprint_hash`: hash do fingerprint do dispositivo.
- `user_agent_hash`: hash do User-Agent do browser.
- `ip_hash`: hash do IP de origem da requisicao.
- `context_changed_at`: timestamp de mudanca forte de contexto (ex.: alteracao relevante de User-Agent/IP).
  - Uso: auditoria e alerta de risco.
- `reuse_detected_at`: timestamp de tentativa de uso de token ja revogado.
  - Uso: evidencia de comprometimento e gatilho para revogacao em cascata da familia.

## Endpoints de Autenticacao

| Metodo | Path | Acesso | Descricao |
|---|---|---|---|
| POST | `/auth/csrf/challenge` | PUBLIC_ACCESS | Challenge CSRF para acoes publicas |
| POST | `/auth/login` | PUBLIC_ACCESS | Login com email/senha |
| POST | `/auth/register` | PUBLIC_ACCESS | Registro de novo usuario |
| POST | `/auth/google` | PUBLIC_ACCESS | Login via Google OAuth |
| POST | `/auth/refresh` | PUBLIC_ACCESS | Renovacao de sessao |
| POST | `/auth/logout` | PUBLIC_ACCESS | Logout (revoga token) |
| GET | `/auth/config` | PUBLIC_ACCESS | Configuracao publica (Google Client ID) |
| GET | `/auth/me` | IS_AUTHENTICATED_FULLY | Dados do usuario logado |
| POST | `/csrf/challenge` | IS_AUTHENTICATED_FULLY | Challenge CSRF para acoes autenticadas |

## Cadeia de Comunicacao (visao geral)

### Fluxo 1: Desenvolvimento via Vite (porta 5173)
1. Browser chama `http://127.0.0.1:5173/OctoFlow/api/auth/login`.
2. `Host-app` (Vite dev server) recebe a chamada e aplica proxy para `https://nginx:443`.
3. Nginx aplica regra `location /OctoFlow/api` e redireciona para `http://127.0.0.1:85/` dentro do proprio container nginx.
4. PHP/Symfony processa `POST /auth/login`.
5. Symfony valida credenciais no PostgreSQL (tabela `app_user`).
6. Symfony gera Access Token (JWT, 10 min) e Refresh Token (opaco, 14 dias).
7. Symfony grava hash do refresh token e metadados em `refresh_token`.
8. Resposta volta para o browser com:
   - JSON: `token`, `token_type`, `expires_in`, `user`
   - Cookie: `refresh_token` (`HttpOnly`, `Secure`, `SameSite`)

### Fluxo 2: Acesso HTTPS via Nginx (porta 4481)
1. Browser chama `https://localhost:4481/OctoFlow/...`.
2. Nginx atende TLS e roteia frontend/backend pelos locations.
3. Para API (`/OctoFlow/api/*`), segue para Symfony no mesmo caminho descrito acima.

## Cadeia de Comunicacao (refresh token)
1. Browser envia `POST /auth/refresh` com cookie `refresh_token`.
2. Symfony busca o token por `token_hash` via `RefreshTokenManager::rotate(plainToken)`.
3. Se token esta revogado:
   - marca `reuse_detected_at`
   - revoga familia (`token_family_id`)
   - retorna 401 e limpa cookie
4. Se token esta expirado:
   - retorna null e AuthController retorna 401 + limpa cookie
5. Em rotacao normal:
   - revoga token atual (`revoked_at = now`)
   - emite novo token via `issue(user, parentHash)`
   - novo token herda `token_family_id` do pai
   - retorna novo JWT + novo cookie refresh

## Resumo Operacional
- Login funcionando com usuario existente: `admin@example.com`.
- Access Token atual: 10 minutos (`expires_in = 600`).
- Refresh Token: 14 dias, rotacao a cada uso.
- Reuso de refresh token e rastreado e dispara revogacao em cascata.

## Comandos de verificacao rapida
```bash
# Validar login pela API HTTPS (porta 4481)
curl -k -i 'https://localhost:4481/OctoFlow/api/auth/login' \
  -H 'Content-Type: application/json' \
  --data-raw '{"email":"admin@example.com","password":"Senha@123"}'

# Validar login pelo fluxo de desenvolvimento (Vite proxy)
curl -i 'http://127.0.0.1:5173/OctoFlow/api/auth/login' \
  -H 'Content-Type: application/json' \
  --data-raw '{"email":"admin@example.com","password":"Senha@123"}'
```
