# Segurança de Token no OctoFlow

## 1) Respostas diretas

### Por que o refresh token aparecia em todas as requisições?
Porque ele estava em cookie com `Path=/` e o frontend usa `withCredentials: true` no Axios.

Resultado: o navegador anexava esse cookie em praticamente toda chamada para a mesma origem.

### O que foi ajustado agora?
O cookie de refresh foi limitado para o escopo de autenticação (`/auth`, respeitando o base path da API).

Resultado: ele não vai mais em rotas como `tasks`, `finance`, `github`, `ui` e demais módulos.

### O refresh token deve ser enviado só quando o access token estiver inválido?
Sim. O fluxo atual foi ajustado para isso:

- O frontend envia sempre `Authorization: Bearer <access_token>` nas rotas protegidas.
- O backend valida esse access token.
- Se estiver inválido/expirado, responde `401`.
- Só nesse caso o frontend chama `/auth/refresh`, recebe novo access token e refaz a requisição uma vez.

Importante: o cookie pode acompanhar chamadas em `/auth/*`, mas só `/auth/refresh` consome o refresh token para rotação.

### De onde veio o `PHPSESSID`?
`PHPSESSID` é o cookie de sessão padrão do PHP/Symfony.

No seu sistema ele é usado para a camada de CSRF customizada (`CsrfTokenManager`), que guarda desafios one-time na sessão (`_custom_csrf_challenges`) e um identificador anônimo (`_custom_csrf_visitor`) quando não há usuário autenticado.

### De onde veio o `g_state`?
`g_state` vem do Google Identity Services (script `https://accounts.google.com/gsi/client`).

Ele é gerenciado pelo próprio Google para estado de login/seleção automática da conta Google. Não é criado nem lido pelo backend do OctoFlow.

## 2) Como está o fluxo de autenticação

1. Login (`/auth/login`, `/auth/register` ou `/auth/google`) retorna:
- `access_token` no corpo
- `refresh_token` em cookie HttpOnly

2. Requisições protegidas usam `Authorization: Bearer <access_token>`.

3. O backend valida o access token em cada chamada protegida:
- válido: segue normal
- inválido/expirado: responde `401`

4. Só após `401`, o frontend chama `/auth/refresh`:
- backend valida token atual
- revoga token antigo
- emite novo access token + novo refresh token

5. Em logout:
- backend tenta revogar refresh token recebido
- limpa cookie de refresh
- invalida sessão (quando existir)

## 3) Segurança de refresh token implementada

- Token aleatório forte: `random_bytes(64)` (hex).
- Banco não guarda token em texto puro: guarda só `sha256(token)`.
- Refresh token tem expiração (`AUTH_REFRESH_TOKEN_TTL`, padrão 14 dias).
- Rotação a cada refresh (token antigo é revogado).
- Frontend usa lock de refresh (1 refresh por vez) para evitar corrida e reuso acidental.
- Detecção de reuso de token revogado: se um token já revogado for usado de novo, a família inteira é revogada.
- Cookie com `HttpOnly` e `SameSite` configurável (`AUTH_REFRESH_COOKIE_SAMESITE`).
- `Secure` configurável por ambiente (`AUTH_REFRESH_COOKIE_SECURE`).

## 4) Entity `RefreshToken` (atributo por atributo)

| Atributo | Finalidade | Uso no sistema hoje |
|---|---|---|
| `id` | Identificador interno do registro | Chave primária no banco |
| `user` | Dono do refresh token | Definido em `issue()`, usado para emitir novo access token |
| `tokenHash` | Hash SHA-256 do refresh token real | Busca e validação (`findByHash`, `findValidByHash`) |
| `fingerprintHash` | Hash de fingerprint de dispositivo/navegador | Campo existente, hoje não preenchido no fluxo atual |
| `userAgentHash` | Hash do User-Agent para vínculo de contexto | Campo existente, hoje não preenchido no fluxo atual |
| `ipHash` | Hash de IP para vínculo de contexto | Campo existente, hoje não preenchido no fluxo atual |
| `tokenFamilyId` | Identificador da família de rotação | Usado para revogar toda a família em caso de reuso suspeito |
| `parentTokenHash` | Hash do token anterior da cadeia | Usado para encadear rotações e manter família |
| `expiresAt` | Data/hora de expiração | Validado em `rotate()` e `findValidByHash()` |
| `createdAt` | Data/hora de criação | Auditoria e rastreio |
| `revokedAt` | Marca de revogação | Impede uso futuro do token |
| `contextChangedAt` | Marca de mudança de contexto de segurança | Campo existente, hoje sem fluxo ativo de preenchimento |
| `reuseDetectedAt` | Marca de tentativa de reuso detectada | Preenchido quando token revogado é reutilizado |

## 5) Pontos de atenção

- A proteção por família de token e detecção de reuso está ativa.
- Os campos de contexto (`fingerprintHash`, `userAgentHash`, `ipHash`, `contextChangedAt`) ainda não estão sendo alimentados/validados no fluxo atual.
- Se quiser hardening adicional, o próximo passo é ativar validação de contexto nesses campos com política de tolerância (ex.: IP móvel) para evitar falso positivo.

## 6) Arquivos principais para consulta

- `www/src/Controller/AuthController.php`
- `www/src/Security/RefreshTokenManager.php`
- `www/src/Repository/RefreshTokenRepository.php`
- `www/src/Entity/RefreshToken.php`
- `www/src/Security/CsrfTokenManager.php`
- `frontend/Host-app/src/App.vue`
- `frontend/Host-app/src/components/GoogleLogin.vue`
