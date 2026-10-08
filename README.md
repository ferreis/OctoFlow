# OctoFlow

O **OctoFlow** é uma aplicação web modular para organizar trabalho, integrações com GitHub e gestão financeira pessoal em uma única interface.

O projeto está em desenvolvimento ativo e usa um backend Symfony, dois frontends Vue conectados por Module Federation e uma infraestrutura local baseada em Docker Compose.

## Principais recursos

- Autenticação local com Argon2id, salt individual e pepper externo, além de Google OAuth.
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
| Autenticação | Argon2id com salt e pepper, Lexik JWT, refresh token rotativo, Google OAuth, CSRF |
| Dados | PostgreSQL 16 e Redis |
| Infraestrutura | Docker Compose, Nginx, PHP-FPM e Mailcatcher |
| Qualidade | PHPUnit, Playwright, ESLint, builds Vite e auditoria de dependências |

## Estrutura do repositório

```text
.
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
│   ├── tests/
│   │   └── playwright/
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

Crie `OctoFlow/www/.env.local` para os valores da sua máquina. Use exatamente o mesmo usuário, senha e banco definidos para o PostgreSQL local:

```dotenv
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=gere-um-valor-aleatorio-longo
PASSWORD_PEPPER_FILE=/run/secrets/password_pepper

DATABASE_URL="postgresql://SEU_USUARIO:SUA_SENHA_URL_ENCODED@database:5432/octoflow?serverVersion=16&charset=utf8"
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

O Compose não possui senha padrão do PostgreSQL nem pepper padrão para as senhas. Antes de iniciar, crie arquivos de segredo **fora do repositório**, em uma área restrita ao usuário ou serviço que os utiliza:

```bash
cd OctoFlow
mkdir -p ../data/secrets
openssl rand -base64 48 > ../data/secrets/postgres_password
openssl rand -hex 32 > ../data/secrets/password_pepper
chmod 600 ../data/secrets/postgres_password ../data/secrets/password_pepper
export POSTGRES_USER="octoflow_local"
export POSTGRES_PASSWORD_FILE="$(realpath ../data/secrets/postgres_password)"
export PASSWORD_PEPPER_FILE="$(realpath ../data/secrets/password_pepper)"
export POSTGRES_DB="octoflow"
docker compose up --build -d
```

A senha do arquivo deve ser usada também em `OctoFlow/www/.env.local`. Se ela contiver caracteres reservados de URL, codifique a senha ao montar `DATABASE_URL`.

O Compose monta o pepper como `/run/secrets/password_pepper` e usa `PASSWORD_PEPPER_FILE` no backend para localizar esse arquivo. **Não sobrescreva um pepper já utilizado ao reiniciar, reinstalar ou atualizar o projeto.** Sem o pepper original, os hashes novos deixam de ser verificáveis e será necessário redefinir as senhas dos usuários afetados. Faça backup criptografado, com acesso restrito e separado do backup do banco de dados. Em instalações sem Docker, configure `PASSWORD_PEPPER_FILE` em `OctoFlow/www/.env.local` com um caminho absoluto legível exclusivamente pelo processo PHP. Em instalações Docker, mantenha o valor interno `/run/secrets/password_pepper`.

O diretório externo `../data/secrets` é apenas uma sugestão de desenvolvimento local. Garanta que esteja fora do controle de versão e evite armazenar os arquivos de segredos em locais compartilhados ou publicados pelo servidor web.

O PostgreSQL publicado no host é vinculado somente a `127.0.0.1`. Para mudar a porta local sem expor o serviço externamente:

```bash
export POSTGRES_HOST_PORT="14954"
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
| PostgreSQL | `127.0.0.1:14954` por padrão |
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

## Proteção de senhas: Argon2id, salt e pepper

O backend Symfony utiliza `App\\Security\\Hasher\\Argon2idPepperedPasswordHasher` para senhas locais. O fluxo de armazenamento é:

1. A senha recebida é combinada com o **pepper secreto** usando HMAC-SHA-256.
2. O resultado alimenta `password_hash(..., PASSWORD_ARGON2ID)`, que produz **salt aleatório individual** para cada hash.
3. O banco armazena somente o hash versionado, iniciado por `octoflow:argon2id:v1:`. O salt e os parâmetros Argon2id fazem parte do próprio hash; o pepper não é armazenado nele nem no banco.

O mesmo algoritmo é usado nos fluxos de cadastro, criação de usuário por comando, definição de senha após login Google e alteração da senha pelo perfil. A autenticação Google OAuth em si não exige senha local.

**Migração de senhas existentes:** hashes antigos reconhecidos por `password_verify` continuam aceitos. Após o primeiro login local bem-sucedido, o backend substitui o hash antigo por Argon2id com pepper. A migração é individual e não exige conhecer previamente as senhas dos usuários. Alterações de senha passam diretamente a usar o algoritmo novo. Contas que não fizerem login continuarão com seus hashes antigos até a próxima autenticação ou redefinição de senha.

**Operação:** cada ambiente precisa de um pepper próprio e persistente. O pepper não deve ser enviado ao frontend, persistido em logs, colocado em variáveis públicas do Vite ou incluído em commits. A aplicação requer o arquivo configurado para as operações de senha; valide a montagem e as permissões antes de colocar a instância em produção.

## Desenvolvimento e validação

### Backend

```bash
cd OctoFlow/www
composer install
composer validate --strict --no-check-publish
composer audit --locked
php bin/phpunit
# Executar os testes unitários específicos do algoritmo de senha:
php bin/phpunit tests/Unit/Security/Argon2idPepperedPasswordHasherTest.php
```

O backend precisa das variáveis de ambiente e serviços auxiliares correspondentes aos testes executados. Para rodar dentro do container já configurado, use `docker compose exec backend php bin/phpunit` a partir do diretório `OctoFlow`.

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

### Testes Playwright de autenticação

Com a API iniciada, execute a suíte de segurança:

```bash
cd OctoFlow/tests/playwright
npm install
PLAYWRIGHT_API_BASE_URL="https://localhost:4481/OctoFlow/api" npm test
```

O Playwright verifica respostas genéricas para credenciais inválidas, comportamento de nonce Google e, quando configurados os dados de uma **conta exclusiva de testes**, limites de senha, login, cookies de sessão, rotação de refresh token e logout. Para habilitar os cenários autenticados:

```bash
E2E_USER_EMAIL="usuario-e2e@example.test" \\
E2E_USER_PASSWORD="senha-exclusiva-de-teste" \\
PLAYWRIGHT_API_BASE_URL="https://localhost:4481/OctoFlow/api" \\
npm test
```

Não utilize credenciais de produção. Veja [a documentação da suíte](OctoFlow/tests/playwright/README.md) para detalhes. Os testes que exigem credenciais são ignorados quando elas não estão definidas; `npm test` requer que a API esteja disponível.

Portas padrão no desenvolvimento direto:

- Host: `5173`
- Remote: `5175`

## Integração contínua

A branch `dev` não possui workflows GitHub Actions neste momento. Portanto, os comandos de validação descritos acima devem ser executados manualmente antes do merge. Não presuma que PHPUnit, Playwright, auditorias ou builds foram executados automaticamente pelo GitHub.

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

- não publique tokens, senhas, cookies, chaves JWT, o arquivo do pepper ou arquivos `.env.local`;
- não use credenciais de exemplo como credenciais reais;
- mantenha a chave JWT privada com permissão restrita;
- caso workflows sejam introduzidos futuramente, não execute código de forks com segredos via `pull_request_target`;
- revise ações e dependências automatizadas antes do merge.

## Licença

O `composer.json` identifica o backend como software proprietário. Nenhuma licença pública foi adicionada ao repositório.
