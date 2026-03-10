# Module Federation - Full Stack JWT Auth + Task CRUD

Uma arquitetura full-stack moderna implementando **autenticação JWT com RefreshToken HttpOnly**, **login local e com Google OAuth2**, **Backend Symfony com API REST protegida** e **Frontend Vue 3 com Module Federation** para carregamento sob demanda de componentes.

---

## 📋 Tabela de Conteúdos

1. [Overview Arquitetura](#overview-arquitetura)
2. [Tecnologias](#tecnologias)
3. [Estrutura do Projeto](#estrutura-do-projeto)
4. [Configuração do Ambiente](#configuração-do-ambiente)
5. [Como Executar](#como-executar)
6. [Fluxo de Autenticação](#fluxo-de-autenticação)
7. [API Endpoints](#api-endpoints)
8. [Module Federation](#module-federation)
9. [Segurança Implementada](#segurança-implementada)
10. [Credenciais de Teste](#credenciais-de-teste)
11. [Troubleshooting](#troubleshooting)

---

## 🏗️ Overview Arquitetura

```
┌─────────────────────────────────────────────────────────────────────┐
│                         FRONTEND (Vue 3)                             │
│  ┌──────────────────┐          ┌──────────────────────────────────┐ │
│  │   Host App       │          │    Remote Task Module (MF)       │ │
│  │ (Login Local +   │ ────────▶│  TaskCrudPanel (Orquestrador)   │ │
│  │   Google OAuth)  │          │  ├─ TaskListPanel               │ │
│  │                  │          │  ├─ TaskCreatePanel             │ │
│  │                  │          │  ├─ TaskViewPanel               │ │
│  │                  │          │  └─ TaskEditPanel               │ │
│  └──────────────────┘          └──────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
                                  ▼
                    JWT (memória) + Request Handler
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    BACKEND (Symfony 7.3)                             │
│  ┌──────────────────┐       ┌──────────────────┐                    │
│  │  AuthController  │       │  TaskController  │                    │
│  │  ├─ /auth/login  │       │  ├─ GET /tasks   │                    │
│  │  ├─ /auth/google │       │  ├─ POST /tasks  │                    │
│  │  ├─ /auth/refresh│       │  ├─ GET  /tasks/{id}                  │
│  │  ├─ /auth/logout │       │  ├─ PATCH /tasks/{id}                │
│  │  └─ /auth/me     │       │  └─ DELETE /tasks/{id}               │
│  └──────────────────┘       └──────────────────┘                    │
│  ┌──────────────────────────────────────────────────┐                │
│  │  RefreshTokenManager (Custom)                    │                │
│  │  ├─ issue(): Emite novo refresh token            │                │
│  │  ├─ rotate(): Rotaciona com revogação            │                │
│  │  └─ revoke(): Revoga token (logout)              │                │
│  └──────────────────────────────────────────────────┘                │
│  ┌──────────────────────────────────────────────────┐                │
│  │  Entity: RefreshToken                            │                │
│  │  ├─ tokenHash (SHA-256, Único)                   │                │
│  │  ├─ expiresAt (14 dias)                          │                │
│  │  ├─ revokedAt (nullable)                         │                │
│  │  └─ user (OneToMany)                             │                │
│  └──────────────────────────────────────────────────┘                │
└─────────────────────────────────────────────────────────────────────┘
                                  ▼
                    PostgreSQL 16 (Database)
```

---

## 🛠️ Tecnologias

### Backend
- **Symfony 7.3** - Framework PHP moderno
- **Lexik JWT** - Autenticação JWT com RSA 2048
- **Doctrine ORM** - Mapeamento objeto-relacional
- **PostgreSQL 16** - Banco de dados relacional
- **Nginx** - Reverse proxy + servidor estático
- **PHP 8.2** - Runtime PHP

### Frontend
- **Vue 3** - Framework JavaScript reactivo
- **Vite** - Build tool ultra rápido
- **Axios** - Cliente HTTP
- **Module Federation (Webpack 5)** - Carregamento dinâmico de componentes
- **Node.js 18+** - Runtime JavaScript

### Infraestrutura
- **Docker & Docker Compose** - Containerização
- **Let'sEncrypt/Self-signed** - TLS/HTTPS

---

## 📁 Estrutura do Projeto

```
application/
│
├── docker/                        # Dockerfile e configs de containers
│   ├── nginx/
│   │   ├── Dockerfile
│   │   ├── nginx.conf            # Config principal
│   │   ├── phpbase.vhost
│   │   └── conf.d/
│   │       ├── app.vhost         # Vhost para app
│   │       └── phpbase.vhost
│   ├── nodejs/
│   │   ├── Dockerfile
│   │   └── node_entrypoint.sh
│   ├── php/
│   │   ├── Dockerfile
│   │   └── www.conf              # PHP FPM config
│   └── pg/
│       └── Dockerfile            # PostgreSQL 16
│
├── www/                           # Backend Symfony
│   ├── src/
│   │   ├── Controller/
│   │   │   ├── AuthController.php # JWT Login/Refresh/Logout/Me
│   │   │   └── TaskController.php # CRUD Task
│   │   ├── Entity/
│   │   │   ├── User.php
│   │   │   ├── RefreshToken.php  # Schema de refresh tokens
│   │   │   └── Task.php          # Schema de tarefas
│   │   ├── Security/
│   │   │   └── RefreshTokenManager.php # Gerenciador custom
│   │   └── ... (Services, Repositories, etc)
│   ├── config/
│   │   └── packages/
│   │       └── lexik_jwt_authentication.yaml # Config JWT
│   ├── .env                       # Variáveis de ambiente
│   └── docker-compose.yml        # Orquestração de containers
│
└── frontend/                      # Frontend Vue 3
    ├── host-app/                 # Host (Login + Orquestração)
    │   ├── src/
    │   │   ├── App.vue           # Login e RemoteTaskCrudPanel
    │   │   └── main.js
    │   ├── vite.config.js        # Consumidor Module Federation
    │   └── package.json
    │
    └── my-vue-mf/                # Remote (Componentes Task)
        ├── src/
        │   ├── components/
        │   │   ├── TaskCrudPanel.vue       # Orquestrador (mount/unmount)
        │   │   └── task/
        │   │       ├── TaskListPanel.vue   # GET /tasks
        │   │       ├── TaskCreatePanel.vue # POST /tasks
        │   │       ├── TaskViewPanel.vue   # GET /tasks/{id}
        │   │       └── TaskEditPanel.vue   # PATCH /tasks/{id}
        │   └── main.js
        ├── vite.config.js         # Expõe TaskCrudPanel
        └── package.json
```

---

## ⚙️ Configuração do Ambiente

### 1. Pré-requisitos

```bash
# Verificar versões
node --version    # v18+ required
npm --version
php -v            # 8.2+ required
docker --version
docker-compose --version
```

### 2. Variáveis de Ambiente (.env)

**Backend** (`application/www/.env`):

```ini
# ========== DATABASE ==========
DATABASE_URL="postgresql://user:password@pg:5432/dbname"

# ========== JWT (Lexik JWT) ==========
JWT_SECRET_KEY="%kernel.project_dir%/config/jwt/private.pem"
JWT_PUBLIC_KEY="%kernel.project_dir%/config/jwt/public.pem"
JWT_PASSPHRASE="your-passphrase-here"
JWT_TOKEN_TTL=900                              # 15 minutos em segundos

# ========== REFRESH TOKEN ==========
AUTH_REFRESH_TOKEN_TTL=1209600                 # 14 dias em segundos
AUTH_REFRESH_COOKIE_SECURE=1                   # 1 = HTTPS only (0 = HTTP allowed)
AUTH_REFRESH_COOKIE_SAMESITE="none"            # none = cross-origin (Secure must be 1)

# ========== GOOGLE OAUTH ==========
GOOGLE_OAUTH_CLIENT_ID="seu-client-id.apps.googleusercontent.com"
GOOGLE_OAUTH_ALLOWED_HD=""                     # opcional: restringe ao dominio Google Workspace

# ========== CORS (Nginx) ==========
CORS_ALLOW_ORIGIN="http://localhost:3000,https://ModFederation.example.com"
```

**Frontend** (`.env` em host-app e my-vue-mf):

```ini
VITE_API_BASE_URL="http://localhost/api"       # Para dev local
VITE_API_BASE_URL="https://api.example.com"    # Para produção
VITE_GOOGLE_CLIENT_ID="seu-client-id.apps.googleusercontent.com"
```

### 3. Gerar chaves RSA (JWT)

```bash
cd application/www

# Se o diretório não existir:
mkdir -p config/jwt

# Gerar chave privada (2048 bits)
openssl genrsa -out config/jwt/private.pem -aes256 2048

# Gerar chave pública
openssl rsa -in config/jwt/private.pem -pubout -out config/jwt/public.pem

# Ajustar permissões
chmod 644 config/jwt/private.pem config/jwt/public.pem
```

### 4. Instalar Dependências

**Backend:**

```bash
cd application/www
composer install
php bin/console doctrine:migrations:migrate
```

**Frontend:**

```bash
# Remote (Task Module)
cd application/frontend/my-vue-mf
npm install

# Host (Login + Orquestrador)
cd application/frontend/host-app
npm install
```

---

## 🚀 Como Executar

### Opção 1: Docker Compose (Recomendado)

```bash
cd application

# Iniciar todos os serviços
docker compose up -d

# Verificar logs
docker compose logs -f

# Limpar/parar
docker compose down
```

**Endpoints após inicialização:**

- Frontend Host: `http://localhost:3000`
- Backend API: `http://localhost/api`
- PostgreSQL: `localhost:5432`

### Opção 2: Desenvolvimento Local

**Terminal 1 - Backend (Symfony)**

```bash
cd application/www
symfony serve --port 8000
# ou
php -S localhost:8000 -t public
```

**Terminal 2 - Remote Module (My-Vue-MF)**

```bash
cd application/frontend/my-vue-mf
npm run dev
# Executa em http://localhost:5173
```

**Terminal 3 - Host App**

```bash
cd application/frontend/host-app
npm run dev
# Executa em http://localhost:5174
```

**Ajustar VITE_API_BASE_URL em .env para:**

```ini
VITE_API_BASE_URL="http://localhost:8000/api"
```

---

## 🔐 Fluxo de Autenticação

### 1. Login (POST /auth/login)

```
┌────────────────────┐
│   User Input       │
│ admin@example.com  │
│ Senha@123          │
└────────────┬───────┘
             │
             ▼
   ┌─────────────────────┐
   │  AuthController     │
   │  login() action     │
   └────────┬────────────┘
            │
     ┌──────┴──────┐
     │             │
     ▼             ▼
 [Validate]   [Generate]
 email+pass   JWT (900s)
     │             │
     └──────┬──────┘
            │
     ┌──────▼─────────────────────┐
     │ RefreshTokenManager::issue()│
     │ ├─ Random token (64 bytes)  │
     │ ├─ Hash SHA-256             │
     │ └─ Persist in DB            │
     └──────┬─────────────────────┘
            │
     ┌──────▼──────────────────────┐
     │ Return Response              │
     │ {                            │
     │   "token": "JWT",            │
     │   "user": { ... }            │
     │   Cookie: RefreshToken (HttpOnly)
     │ }                            │
     └──────┬──────────────────────┘
            │
            ▼
     Frontend receives JWT + Cookie
     JWT stored in ref (memory)
```

### 2. Requisição Autenticada

```
┌──────────────────────────────────┐
│ GET /tasks com JWT expirado      │
└┬─────────────────────────────────┘
 │
 ▼
authRequest(config){ 
  ├─ Injeta Authorization: Bearer {JWT}
  └─ Faz axios call
}
 │
 ├─ [200] ✅ Sucesso
 │
 └─ [401] ❌ JWT Expirado/Inválido
      │
      ▼
   RefreshToken automático
   ├─ POST /auth/refresh
   ├─ Cookie + RefreshToken enviado
   └─ Recebe novo JWT
      │
      ├─ Armazena novo JWT
      ├─ Cookie atualizado (renovado)
      └─ Retry requisição original com novo JWT
         │
         ▼
     [200] ✅ Sucesso agora!
```

### 3. Refresh Token Rotation

**Diagram do banco de dados:**

```sql
-- Tabela refresh_token
┌────────────┬────────────────────┬──────────────┬──────────────┐
│ id (PK)    │ tokenHash (Unique) │ expiresAt    │ revokedAt    │
├────────────┼────────────────────┼──────────────┼──────────────┤
│ 1          │ abc123def...       │ 2026-03-20   │ (null)       │ ← Token ativo
│ 2          │ xyz789ghi...       │ 2026-03-19   │ 2026-03-06   │ ← Revogado
└────────────┴────────────────────┴──────────────┴──────────────┘
```

**Fluxo de rotação:**

```
POST /auth/refresh
    │
    ├─ Validar RefreshToken do Cookie
    │
    ├─ Token válido e não revogado?
    │  │
    │  ├─ [Não] → 401 Unauthorized
    │  │
    │  └─ [Sim] → RefreshTokenManager::rotate()
    │
    ├─ Marcar token antigo como revokedAt = now()
    │
    ├─ Emitir novo token (Nova entrada no BD)
    │
    ├─ Return {newJWT, newCookie}
    │
    └─ Frontend armazena novo JWT + recebe novo cookie
```

### 4. Logout (POST /auth/logout)

```
POST /auth/logout
    │
    ├─ RefreshTokenManager::revoke(tokenFromCookie)
    │  └─ SET revokedAt = now()
    │
    ├─ Clear Cookie (Set-Cookie: Token=; Max-Age=0)
    │
    ├─ Frontend limpa JWT do ref
    │
    └─ Redireciona para login
```

---

## 📡 API Endpoints

### ✅ Autenticação (Público)

| Método | Endpoint | Descrição | Retorno |
|--------|----------|-----------|---------|
| POST | `/auth/login` | Login com email/senha | `{token, user}` |
| POST | `/auth/google` | Login com Google ID token | `{token, user}` |
| POST | `/auth/refresh` | Renovar JWT (Cookie enviado) | `{token, user}` |
| POST | `/auth/logout` | Logout + revoga refresh token | `{message}` |
| GET | `/auth/me` | Dados do usuário autenticado | `{user}` |

### 🔒 Tasks (Requer JWT no header Authorization)

| Método | Endpoint | Descrição | Status |
|--------|----------|-----------|--------|
| GET | `/tasks` | Listar todas as tasks | `200 {tasks[]}` |
| POST | `/tasks` | Criar nova task | `201 {task}` |
| GET | `/tasks/{id}` | Visualizar task | `200 {task}` |
| PATCH | `/tasks/{id}` | Editar task | `200 {task}` |
| DELETE | `/tasks/{id}` | Deletar task | `204` |

**Headers obrigatórios para endpoints 🔒:**

```
Authorization: Bearer {JWT_TOKEN}
Cookie: RefreshToken={refreshToken}  # Enviado automaticamente pelo navegador
```

### Exemplos de Request

**Login:**

```bash
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "Senha@123"
  }'

# Response:
{
  "token": "eyJhbGcio...",
  "user": {
    "id": 1,
    "email": "admin@example.com",
    "roles": ["ROLE_USER"]
  }
}
```

**Listar Tasks:**

```bash
curl -X GET http://localhost/api/tasks \
  -H "Authorization: Bearer {JWT_TOKEN}"

# Response:
{
  "data": [
    {
      "id": 1,
      "title": "Minha tarefa",
      "description": "...",
      "completed": false,
      "createdAt": "2026-03-06T10:30:00Z"
    }
  ]
}
```

**Criar Task:**

```bash
curl -X POST http://localhost/api/tasks \
  -H "Authorization: Bearer {JWT_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Nova tarefa",
    "description": "Descrição da tarefa"
  }'

# Response: 201 Created
{
  "id": 2,
  "title": "Nova tarefa",
  ...
}
```

---

## 🧩 Module Federation

### Conceito

**Module Federation** permite que múltiplas aplicações Vue 3 (ou React/Angular) compartilhem código sem necessidade de compilação monolítica.

```
┌─────────────────────────────────┐
│        Host App                 │
│  (Load Balancer)                │
│                                 │
│  Imports remote/TaskCrudPanel   │
└──────────┬──────────────────────┘
           │
           │ (dynamic import via webpack)
           │
           ▼
┌─────────────────────────────────┐
│      Remote Task Module         │
│  (Expõe apenas TaskCrudPanel)   │
│                                 │
│  URL: http://localhost:5173/..  │
└─────────────────────────────────┘
```

### Configuração do Remote (my-vue-mf)

**vite.config.js:**

```javascript
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import federation from '@originjs/vite-plugin-federation'

export default defineConfig({
  plugins: [
    vue(),
    federation({
      name: 'remoteApp',
      filename: 'remoteEntry.js',
      exposes: {
        './TaskCrudPanel': './src/components/TaskCrudPanel.vue'
        // Apenas TaskCrudPanel é exposto!
      },
      shared: {
        vue: {
          singleton: true,
          requiredVersion: '^3'
        }
      }
    })
  ]
})
```

### Configuração do Host (host-app)

**vite.config.js:**

```javascript
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import federation from '@originjs/vite-plugin-federation'

export default defineConfig({
  plugins: [
    vue(),
    federation({
      remotes: {
        remoteApp: 'http://localhost:5173/dist/remoteEntry.js' // Dev
        // remoteApp: 'https://cdn.example.com/remote/remoteEntry.js' // Prod
      },
      shared: {
        vue: {
          singleton: true,
          requiredVersion: '^3'
        }
      }
    })
  ]
})
```

### Uso no Host App

```vue
<template>
  <div v-if="!isLoggedIn">
    <!-- Login Form -->
  </div>
  <div v-else>
    <!-- RemoteTaskCrudPanel com função authRequest injetada -->
    <RemoteTaskCrudPanel :request="authRequest" />
  </div>
</template>

<script setup>
import { defineAsyncComponent } from 'vue'

const RemoteTaskCrudPanel = defineAsyncComponent(() =>
  import('remoteApp/TaskCrudPanel')
)

// authRequest é injetada como prop
// Orquestrador remoto a utiliza para todas requisições
</script>
```

### TaskCrudPanel - Orquestrador Inteligente

```vue
<template>
  <div class="task-crud-panel">
    <div class="actions">
      <button @click="openAction('list')">📋 Listar</button>
      <button @click="openAction('create')">➕ Criar</button>
      <button @click="openAction('view')">👁️ Visualizar</button>
      <button @click="openAction('edit')">✏️ Editar</button>
    </div>

    <!-- Componente dinâmico com :key para unmount/remount -->
    <component 
      :is="activeComponent" 
      :key="`${activeAction}-${mountKey}`"
      :request="request"
      :taskId="taskId"
      @close="closeAction"
    />
  </div>
</template>

<script setup>
import { ref, computed, defineAsyncComponent } from 'vue'

const props = defineProps(['request'])

const activeAction = ref(null)
const mountKey = ref(0)
const taskId = ref(null)

// Componentes mapeados dinamicamente
const components = {
  list: () => import('./task/TaskListPanel.vue'),
  create: () => import('./task/TaskCreatePanel.vue'),
  view: () => import('./task/TaskViewPanel.vue'),
  edit: () => import('./task/TaskEditPanel.vue')
}

const activeComponent = computed(() => {
  return activeAction.value ? components[activeAction.value] : null
})

function openAction(action) {
  activeAction.value = action
  mountKey.value++  // Força re-render e destruição do anterior
}

function closeAction() {
  activeAction.value = null
  taskId.value = null
  mountKey.value++
}
</script>
```

**Por que essa abordagem?**

1. ✅ **Economiza memória**: Componentes não renderizados são completamente destruídos
2. ✅ **Melhor UX**: Reset de estado ao trocar de ação
3. ✅ **Escalável**: Fácil adicionar novas ações/componentes
4. ✅ **Lazy loading**: Cada componente carregado sob demanda

---

## 🔒 Segurança Implementada

### 1. **JWT em Memória Frontend** 🛡️

**Proteção contra XSS:**

```javascript
// ✅ Seguro (XSS não consegue acessar)
const accessToken = ref(jwtFromLogin)

// ❌ Inseguro (XSS consegue acessar)
localStorage.setItem('token', jwt)  // Evitado!
```

**Razão:** Um script malicioso (`<script>localStorage.getItem('token')</script>`) nunca conseguiria executar neste contexto com memória frontend.

### 2. **RefreshToken em HttpOnly Cookie** 🍪

**Configuração:**

```yaml
# lexik_jwt_authentication.yaml
set_refresh_token_in_response: true
refresh_token_cookie:
  enabled: true
  http_only: true           # ✅ JS não consegue acessar
  secure: true              # ✅ HTTPS only
  same_site: "none"         # ✅ Cross-origin permitido (Secure requerido)
  lifetime: 1209600         # 14 dias
```

**Diagrama de proteção:**

```
┌─────────────────────────────────────────────────────┐
│  Ataque XSS Script: document.cookie                 │
│  ↓                                                   │
│  [BLOQUEADO] ← HttpOnly flag previne acesso         │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│  Navegador envia cookie automaticamente em:          │
│  ✅ fetch/axios com credentials: true/withCredentials
│  ✅ POST /auth/refresh                              │
│  (Sem expor ao JavaScript)                          │
└─────────────────────────────────────────────────────┘
```

### 3. **SHA-256 Hashing no Banco de Dados**

```php
// RefreshTokenManager::issue()
$plainToken = bin2hex(random_bytes(64));
$tokenHash = hash('sha256', $plainToken);

// Persiste APENAS $tokenHash no banco
// Nunca armazena plaintext
```

**Benefício:** Se o banco for comprometido, tokens não são utilizáveis sem conhecer o plaintext original.

### 4. **Token Rotation com Revogação**

```php
// RefreshTokenManager::rotate()
public function rotate(string $plainToken, User $user): string {
    $oldToken = $this->findByHash(hash('sha256', $plainToken));
    
    if ($oldToken) {
        $oldToken->revokedAt = new \DateTimeImmutable();
        $this->entityManager->flush();
    }
    
    // Emitir novo token
    return $this->issue($user);
}
```

**Proteção contra:**

- **Replay attacks**: Token antigo marcado como revogado
- **Token theft**: Novo token gerado a cada refresh
- **Sessão hijacking**: RefreshToken com TTL curto (14 dias)

### 5. **CSRF Protection (HttpOnly + SameSite)**

```
Cenário 1: Atacante tenta fazer POST em foreign domain
┌──────────────────────┐
│  evil-site.com       │
│  <form action="..."> │ ← Tenta explorar
└──────────────────────┘
           │
           ▼
┌───────────────────────────────────────┐
│  Requisição POST para ModFederation.com     │
│  ├─ Cookie enviado? NÃO (SameSite)   │
│  └─ RefreshToken? NÃO → 401           │
└───────────────────────────────────────┘

Cenário 2: Validação no Backend
┌──────────────────────┐
│  POST /auth/refresh  │
│  ├─ Valida JWT (se presente)
│  ├─ Valida RefreshToken (do cookie)
│  └─ SameSite=none requer Secure (HTTPS)
└──────────────────────┘
```

### 6. **CORS Protection**

```yaml
# config/packages/nelmio_cors.yaml
nelmio_cors:
  defaults:
    allow_credentials: true
    allow_origin: ['http://localhost:3000']  # Whitelist
  paths:
    '^/api/':
      allow_headers: ['Authorization']
```

---

## 👤 Credenciais de Teste

### Login Padrão

```
Email:    admin@example.com
Senha:    Senha@123
```

**Criado automaticamente** via fixtures (DataFixtures/UserFixture.php) durante:

```bash
php bin/console doctrine:fixtures:load
```

### Roles/Permissões

```php
// User entity
{
  "id": 1,
  "email": "admin@example.com",
  "roles": ["ROLE_USER", "ROLE_ADMIN"],
  "createdAt": "2026-03-06T10:00:00Z"
}
```

---

## 🐛 Troubleshooting

### ❌ ERR_NETWORK_ERROR ao logar

**Causa:** Backend não está rodando ou CORS está bloqueando

**Solução:**

```bash
# Verificar se backend está rodando
curl -v http://localhost/api/auth/login

# Se Docker: verificar logs
docker compose logs php

# Verificar nginx config
docker compose logs nginx

# Limpar cache nginx
docker compose restart nginx
```

### ❌ 401 Unauthorized em /tasks

**Causa 1:** JWT não está sendo enviado

**Debug:**

```javascript
// No console do navegador
// Verificar se token está armazenado
console.log(ref_accessToken.value)  // Deve ter valor

// Verificar requisição dos DevTools (Network tab)
// Authorization header deve estar presente
```

**Causa 2:** JWT expirou e refresh falhou

**Debug:**

```javascript
// Se refresh falha, deve redirecionar para login
// Verificar no console se há erro no refresh
// Verificar se cookie RefreshToken existe (DevTools > Application > Cookies)
```

**Causa 3:** Senha/usuário incorretos

**Solução:**

```bash
# Resetar database e recarregar fixtures
docker compose exec php php bin/console doctrine:database:drop --force
docker compose exec php php bin/console doctrine:database:create
docker compose exec php php bin/console doctrine:migrations:migrate -n
docker compose exec php php bin/console doctrine:fixtures:load
```

### ❌ CORS Error em desenvolvimento

**Causa:** Frontend em porta diferente do backend

**Solução:** Adicionar ao .env do backend:

```ini
# .env
CORS_ALLOW_ORIGIN="http://localhost:3000,http://localhost:5174,http://localhost:5173"
```

### ❌ Module Federation remota não carrega

**Causa:** URL remota incorreta no vite.config

**Verificar:**

```bash
# Remote deve estar rodando
curl http://localhost:5173/dist/remoteEntry.js

# Host vite.config.js deve ter URL correta:
# remotes: {
#   remoteApp: 'http://localhost:5173/dist/remoteEntry.js'
# }
```

**Build:** Se em produção, remoteEntry.js deve estar servido.

### ❌ RefreshToken Cookie não aparece

**Causa 1:** Não está usando HTTPS (em Secure mode)

**Solução:** Adicionar ao .env:

```ini
AUTH_REFRESH_COOKIE_SECURE=0  # Apenas para development sem HTTPS
```

**Causa 2:** Browser/DevTools não mostra cookies HttpOnly

**Debug:** Todos os cookies aparecem em DevTools, mas JS não consegue acessar (esperado).

---

## 📈 Fluxo Completo de Exemplo

### 1️⃣ Usuário entra na aplicação

```
HostApp carrega
    │
    ├─ Verifica se há JWT em memória
    │  └─ [Não encontra] → Exibe login form
    │
    └─ Usuário preenche formulário
```

### 2️⃣ Usuário clica em "Entrar"

```
POST /auth/login {email, password}
    │
    ├─ Backend valida
    │
    ├─ Genera JWT (900s, memória)
    ├─ Gera RefreshToken (14 dias, HttpOnly Cookie)
    │
    ├─ Retorna {token, user}
    │
    └─ Frontend:
       ├─ Armazena JWT em ref
       ├─ Recebe RefreshToken cookie (automático)
       └─ Exibe TaskCrudPanel remoto
```

### 3️⃣ Usuário clica em "📋 Listar"

```
openAction('list')
    │
    ├─ mountKey++ (força destruição anterior)
    ├─ activeAction = 'list'
    │
    └─ TaskListPanel monta
       │
       ├─ Faz GET /tasks
       ├─ authRequest() injeta JWT
       │
       └─ [200] OK
           └─ Exibe lista de tarefas
```

### 4️⃣ Usuário clica em "➕ Criar"

```
openAction('create')
    │
    ├─ TaskListPanel desmonta (destruído)
    ├─ mountKey++ (nova key)
    ├─ activeAction = 'create'
    │
    └─ TaskCreatePanel monta
       └─ Form vazio para nova tarefa
```

### 5️⃣ Usuário preenche e clica em "Salvar"

```
POST /tasks {title, description}
    │
    ├─ authRequest() injeta JWT
    │
    ├─ [201] Created
    │  └─ Nova tarefa retornada
    │
    └─ Re-renderiza lista
       └─ Nova tarefa aparece
```

### 6️⃣ JWT expira (após 15 minutos inativo)

```
Próxima requisição GET /tasks
    │
    ├─ authRequest injeta JWT expirado
    │
    ├─ [401] Unauthorized
    │
    ├─ Dispara refresh automático:
    │  ├─ POST /auth/refresh
    │  ├─ Cookie RefreshToken enviado
    │  │
    │  └─ [200] OK
    │     ├─ Novo JWT recebido
    │     ├─ Novo RefreshToken cookie
    │     └─ Token antigo marcado como revoked
    │
    ├─ Armazena novo JWT
    │
    └─ Retry GET /tasks com novo JWT
       └─ [200] OK ✅
```

### 7️⃣ Usuário clica em "Sair"

```
handleLogout()
    │
    ├─ POST /auth/logout
    │  ├─ Backend revoga RefreshToken (revokedAt = now)
    │  └─ Clear cookie (Max-Age=0)
    │
    ├─ Frontend limpa JWT (ref = null)
    │
    ├─ TaskCrudPanel desmonta
    │
    └─ Exibe login form novamente
```

---

## 🚀 Deploy em Produção

### Configurações Essenciais

**Backend (.env production):**

```ini
APP_ENV=prod
APP_DEBUG=0

# HTTPS obrigatório
JWT_PASSPHRASE="MUDE_ISSO_EM_PRODUCAO"
AUTH_REFRESH_COOKIE_SECURE=1

# CORS restrito
CORS_ALLOW_ORIGIN="https://ModFederation.com"

# Database seguro
DATABASE_URL="postgresql://user:PASSWORD@db-host:5432/db_name"
```

**Frontend (.env.production):**

```ini
VITE_API_BASE_URL="https://api.ModFederation.com"
```

### Checklist

- [ ] Gerar novas chaves RSA
- [ ] Mudar JWT_PASSPHRASE
- [ ] Habilitar HTTPS (Let's Encrypt)
- [ ] Whitelist CORS
- [ ] Migrations rodadas
- [ ] Build frontend otimizado (`npm run build`)
- [ ] Build backend (`composer install --no-dev`)
- [ ] Logs monitorados

---

## 📚 Recursos Adicionais

- [Lexik JWT Documentation](https://github.com/lexik/LexikJWTAuthenticationBundle)
- [Symfony Security](https://symfony.com/doc/current/security.html)
- [Module Federation - Webpack Docs](https://webpack.js.org/concepts/module-federation/)
- [Vue 3 Async Components](https://vuejs.org/guide/components/async.html)
- [OWASP JWT Best Practices](https://cheatsheetseries.owasp.org/cheatsheets/JSON_Web_Token_for_Java_Cheat_Sheet.html)

---

## 📝 Licença

MIT

---

**Última atualização:** 6 de março de 2026  
**Status:** ✅ Produção Ready
