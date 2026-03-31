# Templates de Tasks e Issues (Backend)

## Objetivo
Catálogo central de templates usados na criação e atualização de issues e tarefas locais.

## Estrutura

- `create/`: templates para criação de issue GitHub e tarefa local.
- `update/`: templates para atualização de issue GitHub.

## Consumo no backend

- `App\\Github\\GithubIssueTemplateCatalog` carrega `create/`.
- `App\\Github\\GithubIssueUpdateTemplateCatalog` carrega `update/`.

## Endpoints

- `GET /tasks/templates`
- `GET /tasks/update-templates`

## Regras

- Não criar catálogo de template no frontend.
- Todo novo template deve ser adicionado neste diretório.
- Filtro de visibilidade e uso é sempre no backend.

## Controle de acesso por template

Cada template pode declarar `access`.

Exemplo simples:

```php
'access' => [
    'rolesAny' => ['ROLE_ADMIN'],
    'capabilitiesAny' => ['template.create.support-request.use'],
],
```

Exemplo com regra separada para visualizar e usar:

```php
'access' => [
    'view' => [
        'rolesAny' => ['ROLE_SUPPORT'],
    ],
    'use' => [
        'capabilitiesAny' => ['template.update.status-update.use'],
    ],
],
```

## Resolver central

- `App\\Github\\UserCapabilityResolver`
- `App\\Github\\TemplateAccessService`

## Observações

- `ROLE_ADMIN` tem acesso total por padrão.
- Mapa de capabilities por role deve ficar centralizado no resolver.
