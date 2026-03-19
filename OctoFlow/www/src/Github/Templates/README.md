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
