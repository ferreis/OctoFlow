# Autenticacao e Cadeia de Comunicacao

Documento consolidado do estado atual de autenticacao no OctoFlow.

## 1. Configuracao vigente

`www/.env`:

```dotenv
JWT_TOKEN_TTL=600
AUTH_REFRESH_TOKEN_TTL=1209600
AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token
AUTH_REFRESH_COOKIE_SECURE=1
AUTH_REFRESH_COOKIE_SAMESITE=none
AUTH_REFRESH_TOKEN_MAX_ACTIVE=10
```

`www/.env.local`:

```dotenv
AUTH_REFRESH_COOKIE_SECURE=1
AUTH_REFRESH_COOKIE_SAMESITE=lax
```

## 2. Endpoints de autenticacao

| Metodo | Path | Acesso | Descricao |
|---|---|---|---|
| POST | `/auth/csrf/challenge` | PUBLIC_ACCESS | Challenge CSRF para auth publica |
| POST | `/auth/login` | PUBLIC_ACCESS | Login email/senha |
| POST | `/auth/register` | PUBLIC_ACCESS | Registro |
| POST | `/auth/google` | PUBLIC_ACCESS | Login Google |
| POST | `/auth/refresh` | PUBLIC_ACCESS | Rotacao de refresh + novo access token |
| GET | `/auth/session/restore-available` | PUBLIC_ACCESS | Verifica se cookie de refresh esta presente |
| POST | `/auth/logout` | PUBLIC_ACCESS | Revoga refresh atual e limpa cookies |
| GET | `/auth/config` | PUBLIC_ACCESS | Config publica (Google Client ID) |
| GET | `/auth/me` | IS_AUTHENTICATED_FULLY | Usuario autenticado atual |
| POST | `/csrf/challenge` | IS_AUTHENTICATED_FULLY | Challenge CSRF de rotas protegidas |

## 3. Fluxo de login automatico no F5

1. Front faz `GET /auth/session/restore-available`.
2. Se vier `restoreAvailable=true`, front chama `POST /auth/refresh`.
3. Se vier `false`, nao tenta refresh.

Esse fluxo evita 401 por tentativa de refresh sem cookie.

## 4. Cookie refresh token

Configuracoes fixas:
- `HttpOnly=true`
- `Secure=true`
- `SameSite` por ambiente

`Path` do cookie:
1. Usa `X-Forwarded-Prefix` quando presente (proxy Nginx).
2. Senao usa `basePath`.
3. Senao fallback `/auth`.

No ambiente com `/OctoFlow/api`, o cookie fica em `/OctoFlow/api/auth`.

## 5. Rotacao e revogacao

Em `POST /auth/refresh`:

1. token atual e localizado por hash.
2. se token revogado for reutilizado: revoga familia inteira.
3. se token valido: revoga token atual e emite novo (rotacao).
4. access token (JWT) novo e retornado no body.
5. refresh token novo e retornado por cookie.

## 6. Validacao de contexto (anti abuso)

Sinais usados:
- `fingerprintHash`
- `userAgentHash`
- `ipHash`
- `locationHash`

Regra:
1. Mudou fingerprint confiavel => bloqueia.
2. Mudaram 2 ou mais sinais entre IP, User-Agent e Localizacao => bloqueia.
3. Em bloqueio, familia inteira e revogada.

## 7. Limite de sessoes simultaneas

- maximo 10 refresh tokens ativos por usuario.
- ao exceder, tokens ativos mais antigos sao revogados.

## 8. Componentes principais

- `www/src/Controller/AuthController.php`
- `www/src/Security/RefreshTokenManager.php`
- `www/src/Repository/RefreshTokenRepository.php`
- `www/src/Entity/RefreshToken.php`
- `frontend/Host-app/src/App.vue`
