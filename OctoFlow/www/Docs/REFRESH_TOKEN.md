# Refresh Token

Este documento descreve o fluxo e as regras do Refresh Token no backend `www`.

## 1. Resumo

O Refresh Token e uma string opaca, longa, usada para renovar o Access Token sem novo login.

Caracteristicas principais:
- formato: `bin2hex(random_bytes(64))` (128 hex chars)
- armazenamento no cliente: cookie `HttpOnly`
- armazenamento no servidor: apenas hash SHA-256 (`token_hash`)
- rotacao obrigatoria a cada uso
- deteccao de reuso com revogacao em cascata por familia

Fonte no codigo:
- `src/Security/RefreshTokenManager.php`
- `src/Entity/RefreshToken.php`
- `src/Repository/RefreshTokenRepository.php`
- `src/Controller/AuthController.php`

## 2. Cookie e Configuração

Arquivo: `www/.env`

```dotenv
AUTH_REFRESH_TOKEN_TTL=1209600
AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token
AUTH_REFRESH_COOKIE_SECURE=1
AUTH_REFRESH_COOKIE_SAMESITE=none
```

Resumo:
- TTL: 14 dias
- cookie name: `refresh_token`
- `HttpOnly=true` (definido em `AuthController`)
- `Secure=true`
- `SameSite=none` (necessario para cenario cross-origin)

## 3. Estrutura de Persistencia

Tabela: `refresh_token`

Campos chave:
- `token_hash`
- `token_family_id`
- `parent_token_hash`
- `fingerprint_hash`
- `user_agent_hash`
- `ip_hash`
- `revoked_at`
- `context_changed_at`
- `reuse_detected_at`
- `expires_at`

## 4. Diagrama de Sequencia (Refresh Normal com Rotacao)

```mermaid
sequenceDiagram
    participant Browser
    participant Auth as AuthController
    participant RTM as RefreshTokenManager
    participant Repo as RefreshTokenRepository

    Browser->>Auth: POST /auth/refresh\nCookie: refresh_token=<plain>
    Auth->>RTM: rotate(plainToken, request)
    RTM->>Repo: findByHash(sha256(plain))
    Repo-->>RTM: token existente e ativo
    RTM->>RTM: validar contexto (UA/IP)
    RTM->>RTM: revogar token atual (revoked_at)
    RTM->>RTM: issue(user, request, parentHash)
    RTM->>Repo: persist novo token
    RTM-->>Auth: novo plain refresh + expiracao
    Auth-->>Browser: 200 novo JWT + novo cookie refresh_token
```

## 5. Diagrama de Sequencia (Reuso Detectado)

```mermaid
sequenceDiagram
    participant Client
    participant Auth as AuthController
    participant RTM as RefreshTokenManager
    participant Repo as RefreshTokenRepository

    Client->>Auth: POST /auth/refresh com token ja revogado
    Auth->>RTM: rotate(plainToken, request)
    RTM->>Repo: findByHash(sha256(plain))
    Repo-->>RTM: token encontrado (revogado)
    RTM->>RTM: set reuse_detected_at = now
    RTM->>Repo: findByTokenFamilyId(familyId)
    RTM->>RTM: revoga todos da familia
    RTM-->>Auth: null
    Auth-->>Client: 401 Invalid or expired refresh token + clear cookie
```

## 6. Fluxo de Emissao

O Refresh Token e emitido em:
- `POST /auth/login`
- `POST /auth/google`
- `POST /auth/refresh` (rotacao)

Sempre via `Set-Cookie` no response, nunca no body.

## 7. Regras Importantes

- token plaintext nunca vai para banco
- token revogado nao pode ser usado novamente
- reuso detectado revoga toda a familia
- mudanca forte de contexto (UA/IP) marca `context_changed_at`
- logout revoga token atual e limpa cookie

## 8. Exemplo cURL

```bash
# Requer cookie refresh_token valido salvo em cookies.txt
curl -k -i -b cookies.txt -c cookies.txt \
  -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: <TOKEN_COMPOSTO>' \
  -H 'X-CSRF-Action: auth.refresh' \
  -X POST 'https://localhost/ModFederation/api/auth/refresh'
```

Observacao: `/auth/refresh` tambem exige challenge CSRF valido.
