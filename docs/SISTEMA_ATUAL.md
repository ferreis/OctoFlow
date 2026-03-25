# SISTEMA_ATUAL.md - OctoFlow

Data de referencia: 25/03/2026  
Escopo: estado atual do sistema conforme codigo e documentacao do repositorio.

---

## 1. Resumo executivo

OctoFlow e um sistema web para operacao de trabalho com GitHub, com foco em:

- autenticacao segura (login local + Google),
- sessao com JWT + refresh token rotativo,
- tratamento de CSRF por acao,
- dashboard operacional de issues,
- gestao de tarefas locais sincronizaveis com GitHub,
- configuracao de perfil, contas GitHub, repositorios, emails e preferencias de interface.

Arquiteturalmente, o frontend e dividido em:

- `Host-app` (orquestrador da aplicacao),
- `Components-app` (remote com componentes compartilhados via Module Federation).

O backend e Symfony 7.3 com PostgreSQL, expondo endpoints REST para autenticacao, GitHub, tarefas e configuracoes de UI.

---

## 2. Objetivo do sistema

O sistema entrega um "shell operacional" para:

- centralizar login e sessao segura;
- consolidar leitura/atualizacao de issues GitHub;
- permitir criacao de tarefas locais (rascunho operacional) e sincronizacao posterior para GitHub;
- manter configuracoes de identidade e acessibilidade por usuario.

---

## 3. Arquitetura atual

## 3.1 Blocos principais

1. Frontend Host (`OctoFlow/frontend/Host-app`)
- Responsavel por autenticacao, sessao, navegacao, chamadas API, tratamento de erro e notificacao.
- Decide quando carregar componentes remotos e quais dados/callbacks enviar.

2. Frontend Remote (`OctoFlow/frontend/Components-app`)
- Responsavel por componentes reutilizaveis (forms, preview markdown, cards de workspace e painel de task).
- Nao controla autenticacao/sessao/orquestracao global.

3. Backend (`OctoFlow/www`)
- Responsavel por regras de negocio, seguranca, persistencia, integracao GitHub e Google OAuth.

4. Infra (`OctoFlow/docker-compose.yml`)
- Nginx (entrada HTTPS), PHP-FPM, Node, PostgreSQL, Mailcatcher.

## 3.2 Fluxo de requisicao

1. Usuario acessa `https://localhost:4481/OctoFlow`.
2. Nginx encaminha frontend host e remote no mesmo dominio.
3. Chamadas de API vao para `https://localhost:4481/OctoFlow/api/*`.
4. Nginx remove prefixo e encaminha para Symfony (`/auth`, `/github`, `/tasks`, `/ui`, `/api`).
5. Backend valida auth/CSRF/permissoes.
6. Backend consulta banco e/ou GitHub.
7. Frontend recebe resposta e atualiza estado da tela.

## 3.3 Acesso local (docker)

| Servico | URL/Porta |
|---|---|
| Aplicacao (Nginx HTTPS) | `https://localhost:4481/OctoFlow` |
| Remote MF (mesma origem) | `https://localhost:4481/OctoFlow-mf` |
| PostgreSQL | `localhost:14953` |
| Mailcatcher | `http://localhost:11081` |

---

## 4. Frontend - o que temos

## 4.1 Host-app (orquestrador)

Responsabilidades confirmadas no codigo:

- controle de sessao (`accessToken`, `currentUser`);
- refresh automatico em `401`;
- pipeline de request autenticada + CSRF por acao;
- navegacao entre telas (`dashboard`, `tasks`, `profile`);
- notificacoes globais;
- persistencia/sincronizacao de `uiSettings`;
- login local, cadastro e login Google.

Arquivos-chave:

- `OctoFlow/frontend/Host-app/src/App.vue`
- `OctoFlow/frontend/Host-app/src/services/tasks.js`
- `OctoFlow/frontend/Host-app/src/services/githubWorkspace.js`
- `OctoFlow/frontend/Host-app/src/components/GoogleLogin.vue`

## 4.2 Remote (Components-app)

Componentes expostos via federation:

- `./TaskCrudPanel`
- `./GithubWorkspacePanel`
- `./GithubWorkspaceSummaryCard`
- `./GithubIssueComposerPanel`
- `./GithubProjectsCard`
- `./MarkdownPreview`
- `./MultiSelect`
- `./IssueTemplateForm`
- `./IssueTemplatePreview`

Observacao atual:

- O host usa componentes remotos pontuais (`MarkdownPreview`, `MultiSelect`, `IssueTemplateForm`, `IssueTemplatePreview`, etc.).
- `TaskCrudPanel` existe no remote, mas o fluxo principal de tarefas atual esta na tela local `TasksScreen.vue` do host.

Arquivos-chave:

- `OctoFlow/frontend/Components-app/vite.config.js`
- `OctoFlow/frontend/Host-app/src/federation/remoteComponents.js`

## 4.3 Telas atuais

### Tela: Autenticacao

Funcoes:

- login com email/senha (`/auth/login`);
- cadastro (`/auth/register`);
- login com Google (`/auth/google`);
- refresh de sessao (`/auth/refresh`);
- encerramento de sessao (`/auth/logout`).

Comportamento:

- sucesso abre dashboard;
- erro mostra notificacao com mensagem de API;
- toda acao mutavel usa challenge CSRF antes da chamada.

### Tela: Dashboard

Funcoes:

- carrega perfil GitHub e workspace;
- le cache de issues;
- dispara sincronizacao com GitHub quando necessario;
- mostra metricas operacionais (abertas/fechadas, ciclo, janela de fechamento, frescor de updates, tipo de ticket).

Observacao:

- bloco financeiro (`contas a pagar/receber`) esta em estado inicial de estrutura (cards com status "Planejado").

### Tela: Tarefas

Funcoes:

- lista consolidada de:
  - issues GitHub cacheadas/sincronizadas;
  - tarefas locais (`local_task`);
- filtros por origem, estado, repositorio, label, tipo e busca;
- paginacao;
- abertura de modal para criar issue;
- edicao de issue GitHub;
- edicao de tarefa local;
- sincronizacao da tarefa local para GitHub.

Recursos de UX:

- rascunho local (draft storage);
- fallback de cache;
- feedback por status/info/erro/sucesso.

### Tela: Perfil

Funcoes:

- leitura/edicao de perfil GitHub;
- criar/editar/remover conta GitHub;
- criar/editar/remover repositorio monitorado;
- gerenciar emails vinculados e email padrao;
- vincular conta Google;
- alterar tema e acessibilidade;
- salvar preferencias de UI no backend.

---

## 5. UX/UI - estilo atual

## 5.1 Direcao visual

- UI baseada em shell + sidebar.
- Uso forte de superfices em gradiente, cards, chips, feedback states.
- Tokens CSS centralizados (`shared/styles/app-visual-standard.css`).
- Tipografia padrao: `Avenir Next`, `Trebuchet MS`, `Segoe UI`, sans-serif.

## 5.2 Sistema de temas

Temas prontos:

- `original`
- `neon-tech`
- `ocean-clean`
- `dark-premium`
- `futurista-contrast`
- `personalizado`

Cores sao aplicadas por variaveis CSS de tema (`--theme-primary`, `--theme-secondary`, etc.).

## 5.3 Acessibilidade

O sistema permite:

- alto contraste;
- modo de visao de cor:
  - `none`, `protanopia`, `deuteranopia`, `tritanopia`, `acromatopsia`;
- escala de fonte:
  - `default`, `medium`, `large`, `extra-large`;
- densidade de layout:
  - `comfortable`, `compact`, `custom` (70 a 110).

As preferencias ficam em:

- frontend (cache local por usuario),
- backend (`ui_settings`) para persistencia.

---

## 6. Ferramentas e stack

## 6.1 Frontend

- Vue 3 (`^3.5.25`)
- Vite (`^7.3.1`)
- Tailwind CSS v4 (`@tailwindcss/vite`)
- Axios
- Module Federation (`@originjs/vite-plugin-federation`)
- DOMPurify + Marked (render seguro de Markdown)
- ESLint

## 6.2 Backend

- PHP `>=8.2`
- Symfony `7.3`
- Doctrine ORM + Migrations
- Lexik JWT Authentication Bundle
- API Platform
- Nelmio CORS
- Reset Password Bundle
- PHPUnit

## 6.3 Infra

- Docker Compose
- Nginx
- PHP-FPM
- PostgreSQL 16
- Mailcatcher

---

## 7. Tecnicas implementadas

1. Module Federation com host/remote em Vite.
2. Carregamento assincrono de componentes remotos.
3. Pipeline unico de request:
- injeta Bearer token,
- executa challenge CSRF para metodos mutaveis,
- faz retry de CSRF em erro 403,
- tenta refresh em 401.
4. Refresh token com rotacao e revogacao por familia.
5. Persistencia de drafts para formularios de issue/tarefa.
6. Cache local de issues GitHub com estrategia auto/force.
7. Sanitizacao de Markdown com `DOMPurify`.
8. Criptografia de token GitHub em repouso (AES-256-GCM com chave derivada de `APP_SECRET`).

---

## 8. Backend - endpoints e dominios

Referencia de acesso externo:

- URL base externa: `/OctoFlow/api`
- rota interna Symfony: sem prefixo (`/auth`, `/github`, `/tasks`, `/ui`, `/api`)

## 8.1 Auth

| Metodo | Rota interna | Auth | CSRF | Finalidade |
|---|---|---|---|---|
| POST | `/auth/csrf/challenge` | Publico | Nao (endpoint de challenge) | Challenge CSRF para acoes publicas de auth |
| POST | `/auth/login` | Publico | Sim | Login email/senha |
| POST | `/auth/register` | Publico | Sim | Cadastro e login automatico |
| POST | `/auth/google` | Publico | Sim | Login com credencial Google |
| POST | `/auth/refresh` | Publico | Sim | Rotacao de refresh e novo JWT |
| POST | `/auth/logout` | Publico | Sim | Revoga refresh atual e limpa cookies |
| GET | `/auth/me` | Autenticado | Nao | Retorna usuario da sessao |
| GET | `/auth/config` | Publico | Nao | Retorna `googleClientId` |

## 8.2 Emails e vinculacao Google (usuario autenticado)

| Metodo | Rota interna | Auth | CSRF | Finalidade |
|---|---|---|---|---|
| GET | `/auth/emails` | Sim | Nao | Retorna payload atualizado de usuario/emails |
| PATCH | `/auth/emails/default` | Sim | Sim | Define email padrao da conta |
| POST | `/auth/google/link` | Sim | Sim | Vincula Google ao usuario logado |

## 8.3 CSRF autenticado

| Metodo | Rota interna | Auth | CSRF | Finalidade |
|---|---|---|---|---|
| POST | `/csrf/challenge` | Sim | Nao (endpoint de challenge) | Challenge CSRF para acoes autenticadas |

## 8.4 GitHub

| Metodo | Rota interna | Auth | CSRF | Finalidade |
|---|---|---|---|---|
| GET | `/github/profile` | Sim | Nao | Perfil GitHub operacional |
| PATCH/PUT | `/github/profile` | Sim | Sim | Atualiza perfil GitHub |
| POST | `/github/accounts` | Sim | Sim | Cria conta GitHub |
| PATCH/PUT | `/github/accounts/{id}` | Sim | Sim | Atualiza conta GitHub |
| DELETE | `/github/accounts/{id}` | Sim | Sim | Remove conta GitHub |
| GET | `/github/repositories` | Sim | Nao | Lista catalogo de repositorios |
| POST | `/github/repositories` | Sim | Sim | Cria repositorio monitorado |
| PATCH/PUT | `/github/repositories/{id}` | Sim | Sim | Atualiza repositorio |
| DELETE | `/github/repositories/{id}` | Sim | Sim | Remove repositorio |
| GET | `/github/workspace` | Sim | Nao | Dados de workspace/repositorio |
| POST | `/github/issues` | Sim | Sim | Cria issue no GitHub |
| GET | `/github/issues/assigned` | Sim | Nao | Sincroniza/lista issues atribuidas |
| GET | `/github/issues/cache` | Sim | Nao | Lista issues cacheadas localmente |
| GET | `/github/issues/{issueId}` | Sim | Nao | Detalhes de issue |
| PATCH/PUT | `/github/issues/{issueId}` | Sim | Sim | Atualiza issue |
| POST | `/github/emails/link` | Sim | Sim | Importa e vincula emails verificados do GitHub |

## 8.5 Tasks

| Metodo | Rota interna | Auth | CSRF | Finalidade |
|---|---|---|---|---|
| GET | `/tasks/templates` | Sim | Nao | Templates de criacao |
| GET | `/tasks/update-templates` | Sim | Nao | Templates de atualizacao |
| GET | `/tasks/local-issues` | Sim | Nao | Board de tarefas locais |
| POST | `/tasks/local-issues` | Sim | Sim | Cria tarefa local |
| GET | `/tasks/local-issues/{taskId}` | Sim | Nao | Detalhes da tarefa local |
| PATCH | `/tasks/local-issues/{taskId}` | Sim | Sim | Atualiza tarefa local |
| POST | `/tasks/local-issues/{taskId}/sync` | Sim | Sim | Sincroniza tarefa local para GitHub |

## 8.6 UI settings

| Metodo | Rota interna | Auth | CSRF | Finalidade |
|---|---|---|---|---|
| GET | `/ui/settings` | Sim | Nao | Le configuracoes de UI |
| PATCH | `/ui/settings` | Sim | **Frontend envia CSRF; listener global nao protege `/ui` hoje** | Atualiza configuracoes de UI |

## 8.7 API Platform

| Metodo | Rota interna | Auth | CSRF | Finalidade |
|---|---|---|---|---|
| GET | `/api/users/{id}` | ROLE_ADMIN | Nao | Leitura de usuario (admin) |
| GET | `/api/users` | ROLE_ADMIN | Nao | Lista usuarios (admin) |

---

## 9. Modelo de dados principal

| Entidade | Papel no sistema |
|---|---|
| `User` | Conta principal, roles, estado, relacoes de auth/GitHub/UI |
| `UserEmail` | Emails vinculados e email primario |
| `RefreshToken` | Sessao renovavel (hash, familia, auditoria de reuso) |
| `UISettings` | Tema e acessibilidade por usuario |
| `GithubAccount` | Conta GitHub vinculada ao usuario |
| `Github` | Repositorios monitorados/configurados |
| `GithubCachedIssue` | Cache local de issues vindas do GitHub |
| `GithubIssueSyncState` | Estado da sincronizacao de issues |
| `LocalTask` | Tarefa local com historico e sync state |
| `ResetPasswordRequest` | Fluxo de recuperacao de senha |

---

## 10. Seguranca - estado atual

## 10.1 JWT access token

- Emissao em login, registro, google e refresh.
- Transporte em `Authorization: Bearer`.
- TTL configuravel por `JWT_TOKEN_TTL` (documentado em 600s).

## 10.2 Refresh token

- Gerado com alta entropia (`random_bytes`), armazenado no cliente como cookie HttpOnly.
- No banco: apenas hash SHA-256.
- Rotacao obrigatoria em `/auth/refresh`.
- Reuso de token revogado marca evento e revoga toda familia.
- Logout revoga token e limpa cookie.

## 10.3 Cookies

- Refresh cookie com:
  - `HttpOnly=true`,
  - `path=/`,
  - `Secure` e `SameSite` configurados por ambiente.

## 10.4 CSRF

Modelo em uso:

- token one-time por acao (`action-scoped one-time token`);
- challenge separado para publico (`/auth/csrf/challenge`) e autenticado (`/csrf/challenge`);
- validacao inclui:
  - token composto,
  - actionId,
  - metodo,
  - path,
  - subject binding (`user`, `refresh`, `anon`).

## 10.5 Controle de acesso

- `security.yaml` separa rotas publicas e protegidas.
- Controllers sensiveis usam `#[IsGranted('IS_AUTHENTICATED_FULLY')]`.
- API admin exige `ROLE_ADMIN`.

## 10.6 Google OAuth

- Frontend obtem `googleClientId` de `/auth/config`.
- Credential Google e enviada ao backend.
- Backend valida:
  - `aud`,
  - `iss`,
  - `sub`,
  - `email_verified`,
  - `exp`,
  - `hd` (opcional).
- Somente depois disso emite token interno da aplicacao.

## 10.7 Protecao de dados sensiveis

- Token GitHub de perfil e criptografado com AES-256-GCM.
- Chave derivada de `APP_SECRET` via HKDF.

---

## 11. Qualidade e testes

Suite de testes no backend (`OctoFlow/www/tests`) inclui:

- unitarios de seguranca (`RefreshTokenManager`, `CsrfChallengeController`);
- unitarios de dominio (`UserEmailManager`, `LocalTaskService`, `UISettingsManager`);
- unitarios de GitHub services;
- integracao de repositories.

Nao ha, neste escopo, suite equivalente de testes frontend automatizados.

---

## 12. Pontos de atencao (confirmados nesta revisao)

1. CSRF em `/ui`
- `CsrfChallengeController` aceita `/ui/*` como acao protegida.
- O frontend envia CSRF para `PATCH /ui/settings`.
- O `CsrfProtectionListener` atual protege `^/(auth|tasks|api|github)` e nao inclui `ui`.
- Resultado: ha inconsistência de politica CSRF para `ui`.

2. Campos de contexto em `refresh_token`
- Entidade tem `fingerprintHash`, `userAgentHash`, `ipHash`, `contextChangedAt`.
- No fluxo atual de emissao/rotacao do `RefreshTokenManager`, esses campos nao sao populados com contexto real.
- Resultado: auditoria de contexto fica incompleta em comparacao ao schema/documentacao.

3. Divergencias de documentacao legada
- Algumas docs antigas citam portas/fluxos antigos (ex.: manual com referencia a `localhost:3000`).
- Operacao atual esta alinhada a `5173` (dev) e `4481` (Nginx HTTPS).

---

## 13. Fontes usadas (arquivos)

- `README.md`
- `docs/Acesso.md`
- `docs/Security_Autenticacao.md`
- `docs/Security_Token_CSRF.md`
- `docs/Security_Frontend_Config.md`
- `docs/Security_Google_OAuth2.md`
- `docs/Token_Access.md`
- `docs/Token_Refresh.md`
- `OctoFlow/docker-compose.yml`
- `OctoFlow/docker/nginx/conf.d/app.vhost`
- `OctoFlow/frontend/Host-app/package.json`
- `OctoFlow/frontend/Components-app/package.json`
- `OctoFlow/frontend/Host-app/src/App.vue`
- `OctoFlow/frontend/Host-app/src/components/screens/DashboardScreen.vue`
- `OctoFlow/frontend/Host-app/src/components/screens/TasksScreen.vue`
- `OctoFlow/frontend/Host-app/src/components/screens/ProfileScreen.vue`
- `OctoFlow/frontend/Host-app/src/components/GoogleLogin.vue`
- `OctoFlow/frontend/Host-app/src/components/tasks/IssueCreateModal.vue`
- `OctoFlow/frontend/Host-app/src/components/tasks/WorkItemEditModal.vue`
- `OctoFlow/frontend/Host-app/src/services/tasks.js`
- `OctoFlow/frontend/Host-app/src/services/githubWorkspace.js`
- `OctoFlow/frontend/Host-app/src/theme.js`
- `OctoFlow/frontend/Host-app/src/style.css`
- `OctoFlow/frontend/Host-app/src/federation/remoteComponents.js`
- `OctoFlow/frontend/Host-app/vite.config.js`
- `OctoFlow/frontend/Components-app/vite.config.js`
- `OctoFlow/frontend/Components-app/src/components/content/MarkdownPreview.vue`
- `OctoFlow/frontend/shared/styles/app-visual-standard.css`
- `OctoFlow/www/composer.json`
- `OctoFlow/www/config/packages/security.yaml`
- `OctoFlow/www/src/Controller/AuthController.php`
- `OctoFlow/www/src/Controller/AuthConfigController.php`
- `OctoFlow/www/src/Controller/AccountEmailController.php`
- `OctoFlow/www/src/Controller/CsrfChallengeController.php`
- `OctoFlow/www/src/Controller/GithubController.php`
- `OctoFlow/www/src/Controller/TaskController.php`
- `OctoFlow/www/src/Controller/UISettingsController.php`
- `OctoFlow/www/src/EventListener/CsrfProtectionListener.php`
- `OctoFlow/www/src/Security/CsrfTokenManager.php`
- `OctoFlow/www/src/Security/RefreshTokenManager.php`
- `OctoFlow/www/src/Security/Google/GoogleIdentityVerifier.php`
- `OctoFlow/www/src/Github/GithubTokenCipher.php`
- `OctoFlow/www/src/Entity/User.php`
- `OctoFlow/www/src/Entity/UserEmail.php`
- `OctoFlow/www/src/Entity/RefreshToken.php`
- `OctoFlow/www/src/Entity/UISettings.php`
- `OctoFlow/www/src/Entity/Github.php`
- `OctoFlow/www/src/Entity/GithubAccount.php`
- `OctoFlow/www/src/Entity/GithubCachedIssue.php`
- `OctoFlow/www/src/Entity/GithubIssueSyncState.php`
- `OctoFlow/www/src/Entity/LocalTask.php`
- `OctoFlow/www/src/Entity/ResetPasswordRequest.php`
- `OctoFlow/www/tests/*`

