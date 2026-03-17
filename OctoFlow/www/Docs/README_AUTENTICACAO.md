# Documentacao de Autenticacao

Este e o indice oficial da pasta `Docs` para autenticacao e tokens.

## 1. Documentos obrigatorios (fonte de verdade)

- `CSRF_TOKEN.md`
- `ACCESS TOKEN.md`
- `REFRESH_TOKEN.md`

## 2. Leitura recomendada por ordem

1. `CSRF_TOKEN.md`
2. `ACCESS TOKEN.md`
3. `REFRESH_TOKEN.md`
4. `AUTH_COMMUNICATION_CHAIN.md`
5. `DATABASE_SCHEMA.md`

## 3. Parametros atuais confirmados no codigo

- `JWT_TOKEN_TTL=600` (Access Token = 10 min)
- `AUTH_REFRESH_TOKEN_TTL=1209600` (Refresh Token = 14 dias)
- `AUTH_REFRESH_COOKIE_SAMESITE=none`
- `AUTH_CSRF_TOKEN_TTL=600`

## 4. Endpoints de autenticacao

- `POST /auth/login`
- `POST /auth/google`
- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/me`
- `POST /auth/csrf/challenge` (acoes publicas)
- `POST /csrf/challenge` (acoes autenticadas)

## 5. Status da arquitetura atual

- CSRF: challenge por acao, token one-time, validacao por sujeito/metodo/path.
- Access Token: JWT curto, validacao com regra JWT + cookie refresh.
- Refresh Token: token opaco com rotacao e deteccao de reuso por familia.

## 6. Sobre documentos legados

Alguns arquivos antigos mantem historico e podem conter exemplos de versoes anteriores.
Use os 3 documentos obrigatorios acima como referencia principal.
