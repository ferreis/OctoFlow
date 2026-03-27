# Refresh Token

Documento atualizado conforme codigo atual.

## 1. Resumo

O refresh token e uma credencial opaca, longa, usada para renovar o access token sem novo login.

Regras principais:
- gerado com alta entropia (`random_bytes`)
- cliente recebe apenas no cookie `HttpOnly`
- banco guarda apenas `token_hash` (SHA-256)
- rotacao obrigatoria a cada `POST /auth/refresh`
- deteccao de reuso com revogacao em cascata por `token_family_id`
- validacao de contexto por fingerprint, user-agent, IP e localizacao

## 2. Configuracao de cookie

Arquivo `www/.env`:

```dotenv
AUTH_REFRESH_TOKEN_TTL=1209600
AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token
AUTH_REFRESH_COOKIE_SECURE=1
AUTH_REFRESH_COOKIE_SAMESITE=none
```

Arquivo `www/.env.local`:

```dotenv
AUTH_REFRESH_COOKIE_SECURE=1
AUTH_REFRESH_COOKIE_SAMESITE=lax
```

Resumo:
- TTL: 14 dias
- nome do cookie: `refresh_token`
- `HttpOnly=true`
- `Secure=true`
- `SameSite`: `none` (base), `lax` (local)
- `Path`: calculado dinamicamente
1. `X-Forwarded-Prefix` (ex.: `/OctoFlow/api` => `/OctoFlow/api/auth`)
2. `basePath`
3. fallback `/auth`

## 3. Sessao automatica no bootstrap

Para evitar erro de cookie ausente no F5:

1. Front chama `GET /auth/session/restore-available`.
2. Se `restoreAvailable=true`, chama `POST /auth/refresh`.
3. Se `false`, nao tenta refresh.

## 4. Estrutura de persistencia

Tabela `refresh_token`:

- `token_hash`
- `fingerprint_hash`
- `user_agent_hash`
- `ip_hash`
- `location_hash`
- `token_family_id`
- `parent_token_hash`
- `expires_at`
- `created_at`
- `revoked_at`
- `context_changed_at`
- `reuse_detected_at`

## 5. Validacao de contexto

Origem dos sinais:
- `fingerprint_hash`: `X-Browser-Id` (preferencia) ou `X-Browser-Fingerprint`
- `user_agent_hash`: `User-Agent`
- `ip_hash`: `Request::getClientIp()`
- `location_hash`: `X-Client-Location`

Regra de bloqueio:
1. Se fingerprint confiavel mudou: bloqueia.
2. Se pelo menos 2 sinais mudaram entre `IP`, `User-Agent`, `Localizacao`: bloqueia.
3. Quando bloqueia:
   - marca `context_changed_at`
   - marca `reuse_detected_at`
   - revoga token atual
   - revoga toda a familia (`token_family_id`)

## 6. Reuso detectado

Se token ja revogado for usado:
- marca `reuse_detected_at`
- revoga familia inteira
- retorna `401` no refresh

## 7. Fluxo de emissao

Refresh token e emitido em:
- `POST /auth/login`
- `POST /auth/register`
- `POST /auth/google`
- `POST /auth/refresh` (rotacao)

Sempre em `Set-Cookie`, nunca no body.

## 8. Limite de tokens por usuario

- `AUTH_REFRESH_TOKEN_MAX_ACTIVE=10`
- excedeu: revoga os mais antigos ativos

## 9. Endpoints relevantes

- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/session/restore-available`

## 10. Fontes no codigo

- `www/src/Controller/AuthController.php`
- `www/src/Security/RefreshTokenManager.php`
- `www/src/Entity/RefreshToken.php`
- `www/src/Repository/RefreshTokenRepository.php`
