# Segurança de Autenticação e Refresh Token (OctoFlow)

## Objetivo
Documentar o fluxo atual de autenticação, rotação de token e validação de contexto para reduzir risco de uso indevido de sessão.

## Respostas rápidas

### Por que o refresh token aparecia em várias requisições?
Porque o cookie estava com `Path=/` e o frontend usa `withCredentials: true`.

### O que foi ajustado?
O cookie de refresh passou para escopo de autenticação (`/auth`, respeitando o base path da API).

### Quando o refresh é usado?
Somente quando uma rota protegida retorna `401`.

Fluxo:
1. Frontend envia requisição com `Authorization: Bearer <access_token>`.
2. Backend valida.
3. Se der `401`, frontend chama `/auth/refresh`.
4. Recebe novo `access_token` + novo `refresh_token`.
5. Refaz a requisição original uma única vez.

### De onde vem `PHPSESSID`?
É cookie de sessão padrão do PHP/Symfony.
No projeto, ele participa do fluxo de CSRF customizado (`CsrfTokenManager`).

### De onde vem `g_state`?
É cookie do Google Identity Services (`https://accounts.google.com/gsi/client`).
Não é criado nem controlado pelo backend do OctoFlow.

## Regras de segurança ativas

- Refresh token gerado com alta entropia (`random_bytes(64)`).
- Banco guarda só hash do refresh (`sha256`), nunca o valor puro.
- Rotação a cada refresh.
- Reuso de token revogado derruba a família inteira (`tokenFamilyId`).
- Lock no frontend para evitar refresh paralelo.
- Limite de tokens ativos por usuário: `AUTH_REFRESH_TOKEN_MAX_ACTIVE=10`.
- Ao exceder o limite, tokens ativos mais antigos são revogados.

## Validação de contexto no refresh

No `issue` e no `rotate`, o backend salva e compara:

- `fingerprintHash` (origem: `X-Browser-Id`, fallback `X-Browser-Fingerprint`)
- `userAgentHash` (`User-Agent`)
- `ipHash` (IP do request)

Política atual:

- Se fingerprint confiável mudar: bloqueia refresh e revoga família.
- Se `User-Agent` e IP mudarem juntos: bloqueia refresh e revoga família.
- Se apenas IP mudar: tolera e marca `contextChangedAt`.

## Entity `RefreshToken` (uso real)

| Campo | Para que serve | Uso no sistema |
|---|---|---|
| `id` | Identificador interno | PK |
| `user` | Dono do token | vínculo com usuário |
| `tokenHash` | Hash do refresh token | busca/validação |
| `fingerprintHash` | Hash de identidade de navegador/dispositivo | preenchido e validado |
| `userAgentHash` | Hash de user-agent | preenchido e validado |
| `ipHash` | Hash de IP | preenchido e validado |
| `tokenFamilyId` | Família de rotação | revogação em cascata |
| `parentTokenHash` | Encadeamento da rotação | histórico de token |
| `expiresAt` | Expiração | validação de validade |
| `createdAt` | Criação | auditoria |
| `revokedAt` | Revogação | bloqueio de uso |
| `contextChangedAt` | Mudança de contexto | auditoria de risco |
| `reuseDetectedAt` | Reuso detectado | sinal de tentativa indevida |

## Arquivos principais

- `www/src/Controller/AuthController.php`
- `www/src/Security/RefreshTokenManager.php`
- `www/src/Repository/RefreshTokenRepository.php`
- `www/src/Entity/RefreshToken.php`
- `www/src/Security/CsrfTokenManager.php`
- `frontend/Host-app/src/App.vue`
