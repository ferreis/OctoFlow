# Access Token (JWT)

## 1. Resumo

O Access Token e um JWT assinado (Lexik JWT) de curta duracao.

Regras:
- TTL: `JWT_TOKEN_TTL=600` (10 minutos)
- envio: `Authorization: Bearer <jwt>`
- nao e persistido como token em banco
- renovacao vem de `POST /auth/refresh` quando necessario

## 2. Emissao

O access token e emitido em:
- `POST /auth/login`
- `POST /auth/register`
- `POST /auth/google`
- `POST /auth/refresh`

Formato de resposta:

```json
{
  "token": "<JWT>",
  "token_type": "Bearer",
  "expires_in": 600,
  "user": {}
}
```

## 3. Uso no frontend

1. Front envia JWT nas rotas protegidas.
2. Se receber `401`, tenta `POST /auth/refresh`.
3. Se refresh funcionar, repete a requisicao original uma vez.

No bootstrap da app:
1. chama `GET /auth/session/restore-available`
2. se `true`, chama `POST /auth/refresh`

## 4. Refresh cookie (relacao com access token)

- refresh fica em cookie `HttpOnly`
- cookie `Secure=true`
- `Path` restrito a `/.../auth` (na pratica `/OctoFlow/api/auth` no proxy atual)
- front nao le o cookie (HttpOnly), apenas envia automaticamente com `withCredentials`

## 5. Configuracao

`www/.env`:

```dotenv
JWT_TOKEN_TTL=600
AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token
AUTH_REFRESH_COOKIE_SECURE=1
```

## 6. Fontes

- `www/src/Controller/AuthController.php`
- `www/config/packages/security.yaml`
- `www/config/packages/lexik_jwt_authentication.yaml`
- `frontend/Host-app/src/App.vue`
