# OctoFlow

[![CI](https://github.com/ferreis/OctoFlow/actions/workflows/ci.yml/badge.svg)](https://github.com/ferreis/OctoFlow/actions/workflows/ci.yml)

O **OctoFlow** é uma aplicação web modular para organizar trabalho, integrações com GitHub e gestão financeira pessoal em uma única interface.

O projeto está em desenvolvimento ativo e usa um backend Symfony, dois frontends Vue conectados por Module Federation e uma infraestrutura local baseada em Docker Compose.

## Principais recursos

- Autenticação local e Google OAuth.
- Sessão com access token em memória e refresh token em cookie `HttpOnly`.
- Rotação e revogação de refresh tokens, proteção CSRF e validação de contexto da sessão.
- Dashboard, tarefas locais e sincronização de issues do GitHub.
- Gerenciamento de contas GitHub e repositórios monitorados.
- Módulo financeiro com contas, bancos, investimentos, configurações e relatórios.
- Temas, acessibilidade e preferências de interface por usuário.
- Componentes compartilhados carregados por Module Federation.

## Arquitetura

```mermaid
flowchart LR
    Browser[Navegador] --> Nginx[Nginx HTTPS]
    Nginx --> Host[Vue Host-app]
    Nginx --> Remote[Vue Components-app]
    Nginx --> API[Symfony API]
    Host --> Remote
    Host --> API
    API --> PostgreSQL[(PostgreSQL 16)]
    API --> Redis[(Redis)]
    API --> GitHub[GitHub API]
    API --> Google[Google Identity]
    API --> Mailcatcher[Mailcatcher]
```

### Stack

| Camada | Tecnologias |
| --- | --- |
| Frontend Host | Vue 3, Vue Router, Pinia, Vite, Tailwind CSS |
| Frontend Remote | Vue 3, Vite, Module Federation, Tailwind CSS |
| Backend | PHP 8.4, Symfony 7.3, Doctrine ORM, API Platform |
| Autenticação | Lexik JWT, refresh token rotativo, Google OAuth, CSRF |
| Dados | PostgreSQL 16 e Redis |
| Infraestrutura | Docker Compose, Nginx, PHP-FPM e Mailcatcher |
| Qualidade | PHPUnit, ESLint, builds Vite e GitHub Actions |

## Estrutura do repositório

```text
.
├── .github/
│   ├── dependabot.yml
│   └── workflows/
│       └── ci.yml
├── OctoFlow/
│   ├── docker/
│   │   ├── nginx/
│   │   ├── nodejs/
│   │   ├── pg/
│   │   ├── php/
│   │   └── redis/
│   ├── frontend/
│   │   ├── Host-app/
│   │   └── Components-app/
│   ├── www/
│   │   ├── config/
│   │   ├── migrations/
│   │   ├── src/
│   │   └── tests/
│   └── docker-compose.yml
├── SECURITY.md
└── README.md
```

## Pré-requisitos

Para a execução recomendada:

- Docker Engine.
- Docker Compose v2.
- Git.
- OpenSSL, apenas caso as chaves JWT sejam geradas fora do container.

Para desenvolvimento sem Docker:

- PHP 8.4 com as extensões exigidas pelo projeto.
- Composer 2.
- Node.js 22 ou mais recente.
- PostgreSQL 16.
- Redis.

## Configuração segura

Nunca grave segredos reais no arquivo `OctoFlow/www/.env`, no README, em issues ou em commits.

Crie `OctoFlow/www/.env.local` para os valores da sua máquina:

```dotenv
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=gere-um-valor-aleatorio-longo

DATABASE_URL="postgresql://root:root@database:5432/test_db?serverVersion=16&charset=utf8"
REDIS_URL="redis://redis:6379"

JWT_PASSPHRASE=use-uma-senha-forte
AUTH_REFRESH_COOKIE_SECURE=1
AUTH_REFRESH_COOKIE_SAMESITE=lax

GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_ALLOWED_HD=
```

Gere valores aleatórios, por exemplo:

```bash
openssl rand -hex 32
openssl rand -base64 48
```

Os seguintes arquivos já são ignorados pelo Git:

- `**/.env.local`
- `**/.env.*.local`
- `**/config/jwt/*.pem`
- diretórios `vendor`, `node_modules`, `dist`, `var` e dados persistidos

## Execução com Docker

Na raiz do repositório:

```bash
cd OctoFlow
docker compose up --build -d
```

Instale as dependências do backend:

```bash
docker compose exec backend composer install
```

Gere o par de chaves JWT depois de configurar `JWT_PASSPHRASE`:

```bash
docker compose exec backend php bin/console lexik:jwt:generate-keypair --skip-if-exists
```

Prepare o banco:

```bash
docker compose exec backend php bin/console doctrine:database:create --if-not-exists
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
```

Acompanhe a inicialização:

```bash
docker compose ps
docker compose logs -f
```

### Endereços locais

| Serviço | Endereço |
| --- | --- |
| Aplicação | `https://localhost:4481/OctoFlow` |
| Remote Module Federation | `https://localhost:4481/OctoFlow-mf` |
| PostgreSQL | `localhost:14954` |
| Redis | `localhost:16379` |
| Mailcatcher | `http://localhost:11081` |

O Nginx gera um certificado autoassinado no primeiro uso. O navegador pode exibir um aviso de certificado no ambiente local.

Para encerrar:

```bash
docker compose down
```

Para remover também o volume nomeado do Redis:

```bash
docker compose down --volumes
```

Os dados persistidos em diretórios montados fora da pasta `OctoFlow` não são removidos automaticamente.

## Desenvolvimento e validação

### Backend

```bash
cd OctoFlow/www
composer install
composer validate --strict --no-check-publish
composer audit --locked
php bin/phpunit
```

### Host frontend

```bash
cd OctoFlow/frontend/Host-app
npm ci
npm run lint
npm run build
npm run dev
```

### Remote frontend

```bash
cd OctoFlow/frontend/Components-app
npm ci
npm run lint
npm run build
npm run dev
```

Portas padrão no desenvolvimento direto:

- Host: `5173`
- Remote: `5175`

## Integração contínua

O workflow `.github/workflows/ci.yml` é executado em pushes para `main` e em pull requests.

Ele verifica:

- instalação reproduzível com `npm ci`;
- auditoria das dependências de produção do npm;
- ESLint e build dos dois frontends;
- validação e auditoria do Composer;
- sintaxe PHP;
- migrações em PostgreSQL;
- testes PHPUnit com PostgreSQL e Redis.

O workflow usa permissões somente de leitura, não recebe segredos do repositório e cancela execuções antigas da mesma branch.

## Atualizações de dependências

O Dependabot verifica semanalmente:

- GitHub Actions;
- Composer;
- dependências npm do Host;
- dependências npm do Remote.

Revise alterações de dependências antes de fazer merge, principalmente atualizações principais.

## Segurança

Consulte [SECURITY.md](SECURITY.md) para relatar vulnerabilidades de forma privada.

Regras básicas:

- não publique tokens, senhas, cookies, chaves JWT ou arquivos `.env.local`;
- não use credenciais de exemplo como credenciais reais;
- mantenha a chave JWT privada com permissão restrita;
- não habilite workflows com `pull_request_target` para executar código de forks;
- revise ações e dependências automatizadas antes do merge.

## Licença

O `composer.json` identifica o backend como software proprietário. Nenhuma licença pública foi adicionada ao repositório.
