# Diagrama de Sequencia do Sistema

Baseado no codigo real atual do projeto.

## Escopo confirmado no codigo

- Frontend principal: `frontend/Host-app`
- Componentes remotos via Module Federation: `frontend/Components-app`
- Backend: `www/src/Controller`
- Persistencia principal:
  - `app_user`
  - `refresh_token`
  - `local_task`
  - `github_account`
  - `github_repository`
  - `github_cached_issue`
  - `ui_settings`
- Integracao externa:
  - Google OAuth
  - GitHub GraphQL/API

## Observacoes importantes antes do diagrama

- O host controla autenticacao, token, CSRF, retries e orquestracao.
- O remote hoje fornece componentes de interface reutilizaveis.
- O fluxo real de tarefas usado no host passa por `TasksScreen` e pelos endpoints `/tasks/local-issues`.
- Existe um `TaskCrudPanel` remoto antigo com expectativa de endpoints genericos em `/tasks` e operacao `DELETE`, mas isso nao bate com o backend atual.
- No backend atual nao existe `DELETE /tasks/local-issues/{id}`.
- O delete real confirmado no sistema hoje existe para:
  - contas GitHub
  - repositorios GitHub

---

## 1. Visao geral do sistema

```mermaid
sequenceDiagram
    autonumber
    actor Usuario
    participant Browser as Browser
    participant Host as Host App Vue
    participant Remote as Components App Remote
    participant Gateway as Vite Proxy / Nginx
    participant Csrf as CsrfTokenManager + Sessao
    participant Guard as JWT Guard + CSRF Listener
    participant Auth as AuthController
    participant Task as TaskController
    participant Github as GithubController
    participant Services as Services de Dominio
    participant DB as PostgreSQL
    participant Google as Google OAuth
    participant GithubApi as GitHub API

    Usuario->>Browser: abre aplicacao
    Browser->>Host: carrega shell principal
    Host->>Remote: carrega componentes remotos sob demanda
    Note over Host,Remote: Host orquestra sessao, permissao e chamadas HTTP

    Host->>Gateway: GET /auth/session/restore-available
    Gateway->>Auth: sessionRestoreAvailable()
    Auth-->>Host: {restoreAvailable:true/false}

    alt sessao com refresh cookie disponivel
        Host->>Gateway: POST /auth/csrf/challenge
        Gateway->>Auth: gerar challenge publico
        Auth->>Csrf: issueChallenge()
        Csrf-->>Auth: csrfToken one-time
        Auth-->>Host: challenge

        Host->>Gateway: POST /auth/refresh + cookie + headers CSRF
        Gateway->>Guard: validar requisicao
        Guard->>Auth: seguir fluxo
        Auth->>Services: rotate refresh token + gerar novo JWT
        Services->>DB: SELECT/UPDATE/INSERT refresh_token
        Services-->>Auth: novo access token + novo refresh cookie
        Auth-->>Host: 200 sessao renovada

        Host->>Gateway: GET /auth/me + Authorization
        Gateway->>Guard: validar JWT
        Guard-->>Auth: acesso liberado
        Auth->>DB: SELECT app_user
        Auth-->>Host: dados do usuario
    else sem sessao valida
        Host-->>Browser: exibe tela de login
    end

    Usuario->>Host: navega em perfil, tarefas e GitHub
    alt leitura
        Host->>Gateway: GET protegido
        Gateway->>Guard: validar JWT
        Guard-->>Gateway: autorizado
        Gateway->>Task: TaskController ou GithubController
        Task->>Services: montar resposta
        Services->>DB: SELECT dados
        Services-->>Host: JSON
    else acao mutavel
        Host->>Gateway: POST /csrf/challenge
        Gateway->>Guard: validar sessao
        Guard-->>Gateway: autorizado
        Gateway->>Csrf: issueChallenge()
        Csrf-->>Host: token one-time

        Host->>Gateway: POST/PATCH/DELETE com JWT + CSRF
        Gateway->>Guard: validar JWT
        Gateway->>Csrf: validar token, actionId, method, path e subject
        Gateway->>Task: controller alvo
        Task->>Services: regra de negocio
        Services->>DB: INSERT/UPDATE/DELETE
        Services-->>Host: payload atualizado
    end

    opt operacoes externas
        Github->>GithubApi: sincronizar issue/repositorio/workspace
        GithubApi-->>Github: dados externos
        Github->>DB: persistir cache local
    end

    opt login Google
        Host->>Google: obter credential
        Host->>Gateway: POST /auth/google + CSRF
        Auth->>Services: validar token Google
        Services->>Google: verifyIdToken
        Google-->>Services: identidade confirmada
        Services->>DB: SELECT/INSERT app_user
        Services->>DB: INSERT refresh_token
        Auth-->>Host: JWT + cookie + user
    end
```

---

## 2. Login local completo

```mermaid
sequenceDiagram
    autonumber
    actor Usuario
    participant Browser as Browser
    participant Host as Host App Vue
    participant Gateway as Vite Proxy / Nginx
    participant Auth as AuthController
    participant Csrf as CsrfTokenManager + Sessao
    participant Users as UserRepository
    participant JWT as Lexik JWT
    participant RTM as RefreshTokenManager
    participant DB as PostgreSQL

    Usuario->>Browser: preenche email e senha
    Browser->>Host: submit login

    Host->>Gateway: POST /auth/csrf/challenge
    Gateway->>Auth: csrfChallenge()
    Auth->>Csrf: issueChallenge(POST, /auth/login, auth.login)
    Csrf-->>Auth: csrfToken composto
    Auth-->>Host: csrfToken + headers esperados

    Host->>Gateway: POST /auth/login + X-CSRF-Token + X-CSRF-Action
    Gateway->>Csrf: isValidRequest()
    Csrf-->>Gateway: true
    Gateway->>Auth: login()

    Auth->>Users: findOneByEmail(email)
    Users->>DB: SELECT app_user by email
    DB-->>Users: usuario
    Users-->>Auth: entidade User

    Auth->>Auth: validar senha e isActive
    Auth->>JWT: create(user)
    JWT-->>Auth: access token JWT

    Auth->>RTM: issue(user, request)
    RTM->>RTM: gerar token opaco
    RTM->>RTM: gerar hash, fingerprint, familyId e expiresAt
    RTM->>DB: INSERT refresh_token
    DB-->>RTM: persisted
    RTM-->>Auth: plain refresh token + expiresAt

    Auth-->>Host: 200 {token, expires_in, user} + Set-Cookie refresh_token(HttpOnly, path dinamico /.../auth)
    Host->>Host: salva access token em memoria
    Host->>Gateway: GET /auth/me + Authorization
    Gateway->>DB: validar JWT e buscar app_user
    Gateway-->>Host: dados finais do usuario
    Host-->>Browser: dashboard autenticado
```

---

## 3. Renovacao automatica de sessao

```mermaid
sequenceDiagram
    autonumber
    participant Host as Host App Vue
    participant Gateway as Vite Proxy / Nginx
    participant Auth as AuthController
    participant Csrf as CsrfTokenManager + Sessao
    participant RTM as RefreshTokenManager
    participant DB as PostgreSQL

    Note over Host: fluxo executado no boot da app e em 401 de rotas protegidas

    Host->>Gateway: GET /auth/session/restore-available
    Gateway->>Auth: sessionRestoreAvailable()
    Auth-->>Host: {restoreAvailable:true/false}

    alt restoreAvailable=true
    Host->>Gateway: POST /auth/csrf/challenge
    Gateway->>Auth: csrfChallenge(auth.refresh)
    Auth->>Csrf: issueChallenge()
    Csrf-->>Auth: token one-time
    Auth-->>Host: challenge

    Host->>Gateway: POST /auth/refresh + refresh cookie + headers CSRF
    Gateway->>Csrf: validar challenge
    Csrf-->>Gateway: true
    Gateway->>Auth: refresh()

    Auth->>RTM: rotate(refresh_cookie, request)
    RTM->>DB: SELECT refresh_token por token_hash
    DB-->>RTM: token atual

    alt token valido e ativo
        RTM->>RTM: avaliar mudanca de contexto por fingerprint, UA, IP e localizacao
        alt token revogado reutilizado
            RTM->>DB: UPDATE reuse_detected_at no token atual
            RTM->>DB: UPDATE revoked_at em toda token_family
            RTM-->>Auth: null
            Auth-->>Host: 401 + limpar cookie
            Host->>Host: clearAuth()
        else rotacao normal
            RTM->>DB: UPDATE revoked_at no token atual
            RTM->>DB: INSERT novo refresh_token com parent_token_hash
            RTM-->>Auth: novo refresh plain + expiresAt
            Auth-->>Host: 200 novo JWT + novo Set-Cookie
            Host->>Host: substitui token em memoria
        end
    else token ausente, expirado ou invalido
        Auth-->>Host: 401 invalid or expired refresh token
        Host->>Host: limpar sessao local
    end
    else restoreAvailable=false
        Host->>Host: nao tenta refresh e segue para login
    end
```

---

## 4. Regra de protecao para rotas autenticadas

```mermaid
sequenceDiagram
    autonumber
    participant Host as Host App Vue
    participant Gateway as Vite Proxy / Nginx
    participant Guard as JWT Guard
    participant Csrf as CsrfProtectionListener
    participant Controller as Controller alvo

    Host->>Gateway: GET/POST/PATCH com Authorization Bearer
    Gateway->>Guard: interceptar request protegida
    Guard->>Csrf: validar CSRF se metodo for mutavel
    alt CSRF invalido
        Csrf-->>Host: 403 Invalid or missing CSRF token
    else JWT valido e CSRF valido ou GET
        Gateway->>Controller: request liberada
        Controller-->>Host: resposta normal
    end
```

---

## 5. Carregamento da tela de tarefas

Fluxo real atual usado pelo host.

```mermaid
sequenceDiagram
    autonumber
    actor Usuario
    participant Browser as Browser
    participant Host as TasksScreen
    participant Remote as Remote Components opcionais
    participant Gateway as Vite Proxy / Nginx
    participant Guard as JWT Guard
    participant GithubCtrl as GithubController
    participant TaskCtrl as TaskController
    participant Assigned as GithubAssignedIssueService
    participant Cache as GithubIssueCacheService
    participant LocalTask as LocalTaskService
    participant DB as PostgreSQL
    participant GithubApi as GitHub API

    Usuario->>Browser: abre tela de tarefas
    Browser->>Host: montar TasksScreen
    Host->>Remote: carregar widgets remotos usados pela tela/modal

    par cache de issues
        Host->>Gateway: GET /github/issues/cache
        Gateway->>Guard: validar JWT
        Guard-->>GithubCtrl: autorizado
        GithubCtrl->>Assigned: fetchCachedIssues()
        Assigned->>Cache: buildCachedBoard()
        Cache->>DB: SELECT github_cached_issue por owner/scope/repo
        DB-->>Cache: issues em cache
        Cache-->>Host: board local + metadados de cache
    and tarefas locais
        Host->>Gateway: GET /tasks/local-issues
        Gateway->>Guard: validar JWT
        Guard-->>TaskCtrl: autorizado
        TaskCtrl->>LocalTask: buildBoard(user)
        LocalTask->>DB: SELECT local_task nao sincronizada
        DB-->>LocalTask: itens + stats
        LocalTask-->>Host: board local_task
    end

    alt cache indica needsRefresh ou usuario clica atualizar
        Host->>Gateway: GET /github/issues/assigned
        Gateway->>Guard: validar JWT
        Guard-->>GithubCtrl: autorizado
        GithubCtrl->>Assigned: fetchIssues()
        Assigned->>GithubApi: consultar issues no GitHub
        GithubApi-->>Assigned: issues atualizadas
        Assigned->>Cache: syncIssues()
        Cache->>DB: UPDATE/INSERT github_cached_issue
        Cache-->>Assigned: cache persistido
        Assigned-->>Host: board atualizado

        Host->>Gateway: GET /tasks/local-issues
        Gateway->>TaskCtrl: recarregar tarefas locais
        TaskCtrl->>DB: SELECT local_task
        DB-->>TaskCtrl: itens atuais
        TaskCtrl-->>Host: board local atualizado
    end
```

---

## 6. Criacao de tarefa local

```mermaid
sequenceDiagram
    autonumber
    actor Usuario
    participant Host as IssueCreateModal
    participant Remote as RemoteIssueTemplateForm / Preview
    participant Gateway as Vite Proxy / Nginx
    participant Guard as JWT Guard + CSRF
    participant CsrfChallenge as /csrf/challenge
    participant TaskCtrl as TaskController
    participant LocalTask as LocalTaskService
    participant TemplateCatalog as GithubIssueTemplateCatalog
    participant DB as PostgreSQL

    Usuario->>Host: escolhe modo local e preenche template
    Host->>Remote: renderiza formulario e preview remoto
    Remote-->>Host: titulo/body final montado

    Host->>Gateway: POST /csrf/challenge para /tasks/local-issues
    Gateway->>Guard: validar sessao
    Guard-->>CsrfChallenge: autorizado
    CsrfChallenge-->>Host: csrfToken one-time

    Host->>Gateway: POST /tasks/local-issues + JWT + CSRF
    Gateway->>Guard: validar JWT
    Gateway->>Guard: validar CSRF
    Guard-->>TaskCtrl: autorizado

    TaskCtrl->>LocalTask: createTask(user, payload)
    LocalTask->>LocalTask: validar titulo, body e repositorio opcional
    LocalTask->>TemplateCatalog: validar templateKey
    TemplateCatalog-->>LocalTask: template ok
    LocalTask->>DB: INSERT local_task
    DB-->>LocalTask: id criado
    LocalTask->>DB: SELECT local_task nao sincronizadas do owner
    DB-->>LocalTask: board atualizado
    LocalTask-->>TaskCtrl: item + board
    TaskCtrl-->>Host: 201 created
    Host-->>Usuario: tarefa local aparece na lista
```

---

## 7. Edicao de tarefa local

```mermaid
sequenceDiagram
    autonumber
    actor Usuario
    participant Host as LocalTaskEditModal
    participant Remote as RemoteMarkdownPreview
    participant Gateway as Vite Proxy / Nginx
    participant Guard as JWT Guard + CSRF
    participant TaskCtrl as TaskController
    participant LocalTask as LocalTaskService
    participant Repo as LocalTaskRepository
    participant DB as PostgreSQL

    Usuario->>Host: abre modal de edicao
    Host->>Gateway: GET /tasks/local-issues/{taskId}
    Gateway->>Guard: validar JWT
    Guard-->>TaskCtrl: autorizado
    TaskCtrl->>LocalTask: getTask(user, taskId)
    LocalTask->>Repo: findOneByIdAndOwner()
    Repo->>DB: SELECT local_task por id e owner
    DB-->>Repo: tarefa
    Repo-->>LocalTask: entidade
    LocalTask-->>Host: item atual
    Host->>Remote: renderizar preview markdown

    Usuario->>Host: salva alteracoes
    Host->>Gateway: POST /csrf/challenge para PATCH /tasks/local-issues/{taskId}
    Gateway-->>Host: csrfToken

    Host->>Gateway: PATCH /tasks/local-issues/{taskId} + JWT + CSRF
    Gateway->>Guard: validar JWT
    Gateway->>Guard: validar CSRF
    Guard-->>TaskCtrl: autorizado

    TaskCtrl->>LocalTask: updateTask(user, taskId, payload)
    LocalTask->>Repo: findOneByIdAndOwner()
    Repo->>DB: SELECT local_task
    DB-->>Repo: tarefa
    Repo-->>LocalTask: entidade

    alt tarefa ja sincronizada com GitHub
        LocalTask-->>TaskCtrl: erro "already synchronized"
        TaskCtrl-->>Host: 400
    else tarefa local ainda editavel
        LocalTask->>LocalTask: atualizar title/body/template/repository
        LocalTask->>DB: UPDATE local_task
        DB-->>LocalTask: persisted
        LocalTask->>DB: SELECT board atualizado
        DB-->>LocalTask: itens atuais
        LocalTask-->>TaskCtrl: item + board
        TaskCtrl-->>Host: 200 updated
        Host-->>Usuario: lista e modal atualizados
    end
```

---

## 8. Sincronizacao da tarefa local para o GitHub

```mermaid
sequenceDiagram
    autonumber
    actor Usuario
    participant Host as LocalTaskEditModal
    participant Gateway as Vite Proxy / Nginx
    participant Guard as JWT Guard + CSRF
    participant TaskCtrl as TaskController
    participant LocalTask as LocalTaskService
    participant Publisher as GithubIssuePublisherInterface
    participant GithubProfile as GithubProfileService
    participant GithubApi as GitHub API
    participant DB as PostgreSQL

    Usuario->>Host: escolhe repositorio e clica sincronizar
    Host->>Gateway: POST /csrf/challenge para /tasks/local-issues/{id}/sync
    Gateway-->>Host: csrfToken

    Host->>Gateway: POST /tasks/local-issues/{id}/sync + JWT + CSRF
    Gateway->>Guard: validar JWT
    Gateway->>Guard: validar CSRF
    Guard-->>TaskCtrl: autorizado

    TaskCtrl->>LocalTask: syncTaskToGithub(user, taskId, payload)
    LocalTask->>DB: SELECT local_task por id e owner
    DB-->>LocalTask: tarefa local
    LocalTask->>LocalTask: marcar como pendente e definir repositorio

    LocalTask->>Publisher: createDraftIssue(user, title, body, repo)
    Publisher->>GithubProfile: resolver token e repositorio do usuario
    GithubProfile->>DB: SELECT github_account/github_repository
    DB-->>GithubProfile: configuracao do usuario
    GithubProfile-->>Publisher: token + owner + name

    Publisher->>GithubApi: mutation para criar issue
    GithubApi-->>Publisher: issue criada
    Publisher-->>LocalTask: issue id, number, url

    alt publicacao com sucesso
        LocalTask->>DB: UPDATE local_task syncState=SYNCED, syncedAt, githubIssueId, githubIssueUrl
        DB-->>LocalTask: persisted
        LocalTask->>DB: SELECT board atualizado
        DB-->>LocalTask: itens atuais
        LocalTask-->>TaskCtrl: item + github + board
        TaskCtrl-->>Host: 200 sincronizado
        Host-->>Usuario: tarefa some da lista local pendente e passa a existir no fluxo GitHub
    else erro no GitHub
        LocalTask->>DB: UPDATE local_task syncState=FAILED, syncError
        DB-->>LocalTask: persisted
        LocalTask-->>TaskCtrl: erro
        TaskCtrl-->>Host: 400/erro
        Host-->>Usuario: tarefa continua local com mensagem de falha
    end
```

---

## 9. Atualizacao de issue GitHub

Esse fluxo existe no sistema e passa por GitHub + cache local.

```mermaid
sequenceDiagram
    autonumber
    actor Usuario
    participant Host as Host App Vue
    participant Gateway as Vite Proxy / Nginx
    participant Guard as JWT Guard + CSRF
    participant GithubCtrl as GithubController
    participant Assigned as GithubAssignedIssueService
    participant Cache as GithubIssueCacheService
    participant GithubProfile as GithubProfileService
    participant DB as PostgreSQL
    participant GithubApi as GitHub API

    Usuario->>Host: edita issue do GitHub
    Host->>Gateway: POST /csrf/challenge para PATCH /github/issues/{issueId}
    Gateway-->>Host: csrfToken

    Host->>Gateway: PATCH /github/issues/{issueId} + JWT + CSRF
    Gateway->>Guard: validar JWT
    Gateway->>Guard: validar CSRF
    Guard-->>GithubCtrl: autorizado

    GithubCtrl->>Assigned: updateIssue(user, issueId, payload)
    Assigned->>GithubProfile: resolver token/configuracao
    GithubProfile->>DB: SELECT github_account/github_repository
    DB-->>GithubProfile: dados do usuario
    GithubProfile-->>Assigned: token valido

    Assigned->>GithubApi: mutation updateIssue
    GithubApi-->>Assigned: issue atualizada
    Assigned->>Cache: upsertIssue()
    Cache->>DB: INSERT/UPDATE github_cached_issue
    DB-->>Cache: cache atualizado
    Cache-->>Assigned: issue normalizada
    Assigned-->>GithubCtrl: item atualizado
    GithubCtrl-->>Host: 200 item
    Host-->>Usuario: issue atualizada na tela e no cache local
```

---

## 10. Delete real existente no sistema

O delete confirmado hoje e de conta GitHub e repositorio GitHub.

```mermaid
sequenceDiagram
    autonumber
    actor Usuario
    participant Host as ProfileScreen
    participant Gateway as Vite Proxy / Nginx
    participant Guard as JWT Guard + CSRF
    participant GithubCtrl as GithubController
    participant Profile as GithubProfileService
    participant Registry as GithubRegistryService
    participant DB as PostgreSQL

    alt remover conta GitHub
        Usuario->>Host: confirma exclusao da conta
        Host->>Gateway: POST /csrf/challenge para DELETE /github/accounts/{id}
        Gateway-->>Host: csrfToken
        Host->>Gateway: DELETE /github/accounts/{id} + JWT + CSRF
        Gateway->>Guard: validar JWT
        Gateway->>Guard: validar CSRF
        Guard-->>GithubCtrl: autorizado
        GithubCtrl->>Profile: deleteAccount(user, account)
        Profile->>DB: DELETE github_account
        Note over DB: repositorios vinculados caem em cascata
        Profile->>DB: UPDATE app_user configuracao legada do GitHub
        GithubCtrl-->>Host: 204 no content
        Host->>Gateway: GET /github/profile
        Gateway->>DB: SELECT github_account + github_repository
        Gateway-->>Host: perfil recarregado
    else remover repositorio GitHub
        Usuario->>Host: confirma exclusao do repositorio
        Host->>Gateway: POST /csrf/challenge para DELETE /github/repositories/{id}
        Gateway-->>Host: csrfToken
        Host->>Gateway: DELETE /github/repositories/{id} + JWT + CSRF
        Gateway->>Guard: validar JWT
        Gateway->>Guard: validar CSRF
        Guard-->>GithubCtrl: autorizado
        GithubCtrl->>Registry: deleteRepository(user, repository)
        Registry->>DB: DELETE github_repository
        DB-->>Registry: removido
        GithubCtrl-->>Host: 204 no content
        Host->>Gateway: GET /github/profile
        Gateway->>DB: SELECT perfil atualizado
        Gateway-->>Host: repositorio removido da tela
    end
```

---

## 11. Gap atual do projeto

Esse gap precisa ficar explicito para o diagrama nao mentir.

```mermaid
sequenceDiagram
    autonumber
    participant Remote as TaskCrudPanel legado
    participant Host as request()
    participant Gateway as API atual
    participant Backend as TaskController atual

    Remote->>Host: DELETE /tasks/{id}
    Host->>Gateway: tenta chamada com csrfActionId dinamico
    Gateway->>Backend: procura rota DELETE /tasks/{id}
    Backend-->>Gateway: rota nao existe no backend atual
    Gateway-->>Host: erro
    Note over Remote,Backend: O CRUD remoto generico nao representa o fluxo real atual de tarefas
```

## Resumo pratico

- Login local e Google criam JWT em memoria no host e refresh token em cookie HttpOnly.
- Toda rota protegida depende de JWT valido.
- O refresh token fica restrito aos endpoints de auth e renova a sessao quando o JWT expira.
- Toda rota mutavel depende tambem de challenge CSRF one-time.
- Tarefa local cria e edita em `local_task`.
- Sincronizacao com GitHub move o estado da tarefa local e registra metadados da issue criada.
- Issues do GitHub sao lidas da API externa e persistidas em `github_cached_issue`.
- Deletes reais hoje sao de configuracao GitHub, nao de tarefa local.
