Catalogo central de templates do sistema.

Estrutura:

- `create/`: templates usados na criacao de issue GitHub e tarefa local
- `update/`: templates usados na atualizacao de issue GitHub

Consumo:

- `App\\Github\\GithubIssueTemplateCatalog` carrega `create/`
- `App\\Github\\GithubIssueUpdateTemplateCatalog` carrega `update/`

Endpoints:

- `GET /tasks/templates`
- `GET /tasks/update-templates`

Regra:

- nao criar catalogo de template no frontend
- qualquer novo template deve ser adicionado aqui no backend

Controle de acesso:

- cada template pode declarar `access`
- o filtro de visibilidade e uso acontece no backend
- o frontend recebe apenas templates ja liberados para o usuario

Formato simples:

```php
'access' => [
    'rolesAny' => ['ROLE_ADMIN'],
    'capabilitiesAny' => ['template.create.support-request.use'],
],
```

Formato com regra separada para ver e usar:

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

Resolver central:

- `App\\Github\\UserCapabilityResolver`
- `App\\Github\\TemplateAccessService`

Observacao:

- `ROLE_ADMIN` tem acesso total por padrao
- os mapas de capability por role devem ser mantidos no resolver central
