# Documentacao Completa de Autenticacao e Comunicacao

## 1. Objetivo
Este documento consolida, em um unico lugar, tudo que foi implementado no backend `www` para autenticacao com Access Token + Refresh Token, incluindo:

- arquitetura tecnica
- regras de seguranca
- estrutura de banco
- fluxo de login e refresh
- cadeia de comunicacao entre frontend, proxy, nginx, Symfony e PostgreSQL
- comandos de validacao

## 2. Stack e Componentes

### 2.1 Backend
- Framework: Symfony
- JWT: LexikJWTAuthenticationBundle
- Banco: PostgreSQL
- Persistencia: Doctrine ORM

### 2.2 Infra
- Nginx container com TLS local (self-signed)
- Docker Compose com servicos:
  - `nginx`
  - `backend` (php-fpm)
  - `database` (postgres)
  - `frontend` (host + remote)

### 2.3 Frontend
- Host app (Vite + Vue)
- Remote app (Module Federation)
- Fluxo autenticado consumindo `/ModFederation/api/*`

## 3. Configuracao Atual (Fonte de Verdade)

### 3.1 Arquivo `www/.env`
Contem apenas configuracao base e valores nao sensiveis do projeto.

- `APP_ENV=dev`
- `APP_DEBUG=1`
- `JWT_TOKEN_TTL=600` (Access Token = 10 minutos)
- `AUTH_REFRESH_TOKEN_TTL=1209600` (Refresh Token = 14 dias)
- `AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token`
- `AUTH_REFRESH_COOKIE_SECURE=1`
- `AUTH_REFRESH_COOKIE_SAMESITE=none`
- `CORS_ALLOW_ORIGIN='^https?://(localhost|127\\.0\\.0\\.1)(:[0-9]+)?$'`

### 3.2 Arquivo `www/.env.local`
Contem informacoes sensiveis e credenciais locais, sobrescrevendo o `.env`.

- `APP_SECRET`
- `DATABASE_URL`
- `JWT_PASSPHRASE`

Observacoes:
- `.env.local` ja esta ignorado pelo `.gitignore`.
- O `expires_in` retornado por `/auth/login` e `/auth/refresh` deve ser `600`.

## 4. Endpoints de Autenticacao
Base: `/auth`

- `POST /auth/login`
  - Entrada: `email`, `password`
  - Saida: `token`, `token_type`, `expires_in`, `user`
  - Efeito colateral: envia cookie `refresh_token`

- `POST /auth/refresh`
  - Entrada: cookie `refresh_token`
  - Saida: novo `token`, `token_type`, `expires_in`, `user`
  - Efeito colateral: rotaciona refresh token e envia novo cookie

- `POST /auth/logout`
  - Entrada: cookie `refresh_token`
  - Saida: mensagem de logout
  - Efeito colateral: revoga token atual e limpa cookie

- `GET /auth/me`
  - Entrada: `Authorization: Bearer <accessToken>`
  - Saida: dados do usuario autenticado

## 5. Modelo de Seguranca

### 5.1 Access Token
- formato JWT
- curta duracao (10 min)
- trafega no header `Authorization`

### 5.2 Refresh Token
- token opaco (nao JWT)
- armazenado no cliente como cookie HttpOnly
- armazenado no servidor somente como hash

### 5.3 Rotacao obrigatoria
Cada uso de refresh revoga o token anterior e emite um novo.

### 5.4 Deteccao de reuso
Se um refresh token ja revogado for reutilizado, o sistema marca incidente e pode revogar toda a familia.

### 5.5 Contexto/Fingerprint
O sistema registra contexto do cliente (User-Agent/IP em hash) e marca mudancas suspeitas.

## 6. Estrutura da Tabela `refresh_token`
Campos principais:

- `token_hash`: hash do token opaco
- `token_family_id`: identifica cadeia de rotacao
- `parent_token_hash`: hash do token anterior
- `fingerprint_hash`: hash combinado de contexto
- `user_agent_hash`: hash do User-Agent
- `ip_hash`: hash do IP
- `revoked_at`: data de revogacao
- `context_changed_at`: marca mudanca de contexto suspeita
- `reuse_detected_at`: marca tentativa de reuso

### 6.1 Significado dos campos criticos
- `parent_token_hash`
  - aponta para o token imediatamente anterior na cadeia de rotacao
  - permite auditoria de linhagem do token

- `context_changed_at`
  - indica quando o sistema detectou mudanca forte de contexto
  - usado para monitoramento e resposta de risco

- `reuse_detected_at`
  - indica quando um token revogado foi reutilizado
  - sinal de possivel vazamento/comprometimento

## 7. Cadeia de Comunicacao

## 7.1 Fluxo de desenvolvimento (frontend em `127.0.0.1:5173`)
1. Browser chama `http://127.0.0.1:5173/ModFederation/api/auth/login`.
2. Host app (Vite) recebe a requisicao.
3. Proxy do Vite encaminha para `https://nginx:443` dentro da rede Docker.
4. Nginx recebe e aplica `location /ModFederation/api`.
5. Nginx encaminha para Symfony/PHP-FPM.
6. Symfony processa rota, consulta banco e monta resposta.
7. Resposta retorna ao browser com JSON + cookie.

## 7.2 Fluxo HTTPS via Nginx (porta 4483)
1. Browser chama `https://localhost:4483/ModFederation/api/...`.
2. Nginx termina TLS e roteia API para backend.
3. Backend responde com JWT e cookie refresh quando aplicavel.

## 7.3 Fluxo de refresh
1. Browser envia cookie `refresh_token` para `/auth/refresh`.
2. Backend calcula hash e busca token.
3. Se token revogado: marca `reuse_detected_at` e trata familia.
4. Se contexto suspeito: marca `context_changed_at`.
5. Revoga token atual.
6. Emite novo refresh token com `parent_token_hash`.
7. Emite novo access token.
8. Retorna novo cookie + novo JWT.

## 8. Diagrama Rapido (ASCII)
```text
Browser
  |
  | POST /ModFederation/api/auth/login
  v
Host App (Vite :5173)
  |
  | proxy /ModFederation/api -> https://nginx:443
  v
Nginx
  |
  | route /ModFederation/api/*
  v
Symfony (AuthController + RefreshTokenManager)
  |
  | SELECT/INSERT/UPDATE
  v
PostgreSQL (app_user, refresh_token)
```

## 9. Comandos de Validacao

### 9.1 Login via Nginx HTTPS
```bash
curl -k -i 'https://localhost:4483/ModFederation/api/auth/login' \
  -H 'Content-Type: application/json' \
  --data-raw '{"email":"admin@example.com","password":"Senha@123"}'
```

### 9.2 Login via fluxo dev (Vite proxy)
```bash
curl -i 'http://127.0.0.1:5173/ModFederation/api/auth/login' \
  -H 'Content-Type: application/json' \
  --data-raw '{"email":"admin@example.com","password":"Senha@123"}'
```

### 9.3 Verificar containers
```bash
docker compose ps backend nginx frontend database
```

## 10. Troubleshooting Rapido

- Erro `Network Error` no browser:
  - validar se `frontend` esta de pe (`docker compose ps`)
  - validar proxy do Vite para `https://nginx:443` (nao usar `localhost:4483` dentro do container)
  - em acesso HTTPS direto, aceitar certificado local self-signed no navegador

- `401 Invalid credentials`:
  - confirmar usuario
  - exemplo de teste: `admin@example.com`

- `expires_in` diferente de 600:
  - confirmar `JWT_TOKEN_TTL=600` em `www/.env`
  - reiniciar `backend` e `nginx`

## 11. Mapa dos Documentos em `Docs`
- `README_AUTENTICACAO.md`
- `IMPLEMENTATION_SUMMARY.md`
- `AUTHENTICATION_ARCHITECTURE.md`
- `PSEUDO_CODE_ENDPOINTS.md`
- `DATABASE_SCHEMA.md`
- `AUTH_COMMUNICATION_CHAIN.md`
- `DOCUMENTACAO_COMPLETA.md` (este arquivo)

## 12. Codigo-Fonte Relevante
- `www/src/Controller/AuthController.php`
- `www/src/Security/RefreshTokenManager.php`
- `www/src/Security/FingerprintService.php`
- `www/src/Entity/RefreshToken.php`
- `www/src/Repository/RefreshTokenRepository.php`
- `www/config/packages/lexik_jwt_authentication.yaml`
- `www/config/packages/nelmio_cors.yaml`
- `www/.env`
