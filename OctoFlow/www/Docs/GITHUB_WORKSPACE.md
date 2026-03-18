# GitHub Workspace

## Objetivo

Adicionar um workspace autenticado para:

- Ler o repositorio configurado no GitHub.
- Carregar labels disponiveis.
- Listar Projects do owner do repositorio.
- Criar issues a partir de templates estruturados.
- Adicionar a issue a um Project e definir um status inicial via GraphQL.

## Onde fica a configuração

Os dados do GitHub agora ficam por usuario no banco de dados:

- `github_repository_owner`
- `github_repository_name`
- `github_token_encrypted`

O token e criptografado no backend antes de ser salvo. O unico segredo de infraestrutura necessario continua sendo o `APP_SECRET` do Symfony, usado para derivar a chave de criptografia.

## Escopos recomendados para o token

- `repo` ou `public_repo` para criar issues.
- `read:project` para listar Projects.
- `project` para adicionar items ao Project e atualizar o status inicial.

## Endpoints

### `GET /github/profile`

Retorna o resumo da configuração GitHub do usuario autenticado:

- owner
- repository name
- se existe token salvo
- se o workspace ja esta pronto para uso

### `PATCH /github/profile`

Atualiza a configuração GitHub do usuario autenticado.

Payload esperado:

```json
{
  "repositoryOwner": "sua-organizacao-ou-usuario",
  "repositoryName": "seu-repositorio",
  "token": "ghp_xxxxxxxxxxxxxxxxxxxx",
  "clearToken": false
}
```

### `GET /github/workspace`

Retorna:

- Metadados do repositorio configurado no perfil do usuario.
- Labels disponiveis.
- Catalogo de templates da aplicacao.
- Projects do owner, quando o token permite acesso.

### `POST /github/issues`

Payload esperado:

```json
{
  "template": "feature-request",
  "title": "Integrar criacao de issues com Projects",
  "fields": {
    "description": "Hoje o fluxo esta manual.",
    "businessRule": "A funcionalidade precisa seguir o fluxo operacional definido pelo time.",
    "acceptanceCriteria": "Criar issue no repositorio correto\nAdicionar automaticamente ao backlog interno"
  },
  "labelIds": ["LA_kwDOAA..."],
  "projectId": "PVT_kwHOAA...",
  "statusOptionId": "47fc9ee4"
}
```

## Fluxo de criacao

1. O frontend autenticado carrega `/github/profile`.
2. Cada usuario salva owner, repositorio e token no proprio perfil.
3. O frontend carrega `/github/workspace` usando essa configuração.
4. O usuario escolhe um template, preenche o formulario e gera o preview.
5. O host pede um CSRF challenge autenticado.
6. O backend cria a issue via `createIssue`.
7. Se houver Project selecionado, o backend executa `addProjectV2ItemById`.
8. Se houver status inicial, o backend executa `updateProjectV2ItemFieldValue`.

## Observacoes

- O token do GitHub fica somente no backend e armazenado criptografado no banco.
- O app tolera falta de permissao em Projects: a criacao de issue continua disponivel.
- Os templates do app espelham os arquivos em `.github/ISSUE_TEMPLATE/`.
