# Autenticacao e Cadeia de Comunicacao (Projeto `www`)

## Objetivo
Este documento consolida, em um unico lugar, como a autenticacao funciona no projeto e como a requisicao trafega entre frontend, proxy e backend.

## Configuracao Atual (fonte de verdade)
Arquivo: `www/.env`

- `JWT_TOKEN_TTL=600` (Access Token = 10 minutos)
- `AUTH_REFRESH_TOKEN_TTL=1209600` (Refresh Token = 14 dias)
- `AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token`
- `AUTH_REFRESH_COOKIE_SECURE=1`
- `AUTH_REFRESH_COOKIE_SAMESITE=none`

## Campos de Seguranca na Tabela `refresh_token`
Campos principais relacionados ao fluxo discutido:

- `token_hash`: hash SHA-256 do refresh token em texto puro. O token puro nunca e salvo no banco.
- `token_family_id`: identifica a familia de tokens da mesma sessao/dispositivo.
- `parent_token_hash`: hash do token anterior na cadeia de rotacao.
  - Uso: rastreabilidade da linhagem do token.
- `context_changed_at`: timestamp de mudanca forte de contexto (ex.: alteracao relevante de User-Agent/IP).
  - Uso: auditoria e alerta de risco.
- `reuse_detected_at`: timestamp de tentativa de uso de token ja revogado.
  - Uso: evidencia de comprometimento e gatilho para revogacao em cascata da familia.

## Cadeia de Comunicacao (visao geral)

### Fluxo 1: Desenvolvimento via Vite (porta 5173)
1. Browser chama `http://127.0.0.1:5173/ModFederation/api/auth/login`.
2. `Host-app` (Vite dev server) recebe a chamada e aplica proxy para `https://nginx:443`.
3. Nginx aplica regra `location /ModFederation/api` e redireciona para `http://127.0.0.1:85/` dentro do proprio container nginx.
4. PHP/Symfony processa `POST /auth/login`.
5. Symfony valida credenciais no PostgreSQL (`app_user`).
6. Symfony gera Access Token (JWT, 10 min) e Refresh Token (opaco, 14 dias).
7. Symfony grava hash do refresh token e metadados em `refresh_token`.
8. Resposta volta para o browser com:
   - JSON: `token`, `token_type`, `expires_in`, `user`
   - Cookie: `refresh_token` (`HttpOnly`, `Secure`, `SameSite`)

### Fluxo 2: Acesso HTTPS via Nginx (porta 4483)
1. Browser chama `https://localhost:4483/ModFederation/...`.
2. Nginx atende TLS e roteia frontend/backend pelos locations.
3. Para API (`/ModFederation/api/*`), segue para Symfony no mesmo caminho descrito acima.

## Cadeia de Comunicacao (refresh token)
1. Browser envia `POST /auth/refresh` com cookie `refresh_token`.
2. Symfony busca o token por `token_hash`.
3. Se token esta revogado:
   - marca `reuse_detected_at`
   - revoga familia (`token_family_id`)
   - retorna 401 e limpa cookie
4. Se contexto mudou de forma suspeita:
   - marca `context_changed_at`
   - segue regra definida no manager (alerta/revogacao conforme politica)
5. Em rotacao normal:
   - revoga token atual
   - emite novo token
   - grava `parent_token_hash` apontando para o token anterior
   - retorna novo JWT + novo cookie refresh

## Resumo Operacional
- Login funcionando com usuario existente: `admin@example.com`.
- Access Token atual: 10 minutos (`expires_in = 600`).
- Reuso de refresh token e rastreado e pode disparar revogacao em cascata.

## Comandos de verificacao rapida
```bash
# Validar login pela API HTTPS
curl -k -i 'https://localhost:4483/ModFederation/api/auth/login' \
  -H 'Content-Type: application/json' \
  --data-raw '{"email":"admin@example.com","password":"Senha@123"}'

# Validar login pelo fluxo de desenvolvimento (Vite proxy)
curl -i 'http://127.0.0.1:5173/ModFederation/api/auth/login' \
  -H 'Content-Type: application/json' \
  --data-raw '{"email":"admin@example.com","password":"Senha@123"}'
```
