# 🔐 API de Autenticação Ultra-Segura - Projeto WWW

## 📋 Visão Geral

Implementação de um sistema de autenticação baseado em **Access Token (JWT)** e **Refresh Token** (String Opaca) com mecanismos avançados de segurança inspirados no modelo do Google.

### 🎯 Objetivos de Segurança

1. **Tokens com Tempo de Vida Curto**: Access Token válido por 15 minutos
2. **Tokens de Longa Duração**: Refresh Token válido por 30 dias
3. **Refresh Token Rotation**: Cada uso gera novo token e revoga o anterior
4. **Detecção de Reuso**: Se um RT revogado for usado → revoga TODA a família
5. **Fingerprint de Contexto**: Valida User-Agent + IP para detectar mudanças suspeitas
6. **Proteção contra Token Fixation**: Cookie HttpOnly, Secure, SameSite=Strict

### 🌐 Login Social com Google

O login Google foi acoplado ao mesmo fluxo stateless já existente:

1. O frontend usa Google Identity Services para obter um `credential` (ID token).
2. O backend recebe esse token em `POST /auth/google`.
3. O backend valida `aud`, `iss`, `sub`, `exp` e `email_verified` com o endpoint oficial `tokeninfo` do Google.
4. Se o usuário ainda não existir, a API cria um registro local com `google_subject` e segue emitindo o JWT próprio da aplicação.
5. Se já existir conta local com o mesmo email, o login é bloqueado até existir vínculo explícito, evitando takeover por auto-link inseguro.

---

## 📊 Estrutura de Dados

### Tabela: `refresh_token`

```sql
CREATE TABLE refresh_token (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    
    -- Novos campos de segurança:
    fingerprint_hash VARCHAR(64) NOT NULL,          -- Hash SHA-256 de User-Agent + IP
    user_agent_hash VARCHAR(64) NOT NULL,           -- Hash SHA-256 do User-Agent
    ip_hash VARCHAR(64) NOT NULL,                   -- Hash SHA-256 do IP cliente
    token_family_id VARCHAR(64) NULL,               -- Agrupa tokens da mesma sessão
    parent_token_hash VARCHAR(64) NULL,             -- Hash do token anterior (forma corrente)
    context_changed_at DATETIME NULL,               -- Marca quando contexto mudou drasticamente
    reuse_detected_at DATETIME NULL,                -- Marca detecção de reuso
    
    CONSTRAINT fk_refresh_token_user 
        FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE CASCADE,
    INDEX idx_refresh_token_expires (expires_at),
    INDEX idx_refresh_token_user (user_id),
    INDEX idx_refresh_token_family (token_family_id),
    INDEX idx_refresh_token_fingerprint (fingerprint_hash)
);
```

### Entity PHP: `RefreshToken`

```php
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_token')]
class RefreshToken
{
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;                      // Hash do token (nunca plaintext)
    
    #[ORM\Column(length: 64)]
    private string $fingerprintHash;                // Hash do contexto (User-Agent + IP)
    
    #[ORM\Column(length: 64)]
    private string $userAgentHash;                  // Isolado para análise
    
    #[ORM\Column(length: 64)]
    private string $ipHash;                         // Isolado para análise
    
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenFamilyId;                 // Agrupa toda a cadeia
    
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $parentTokenHash;               // Rastreia filiação
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt;         // Quando foi revogado
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $contextChangedAt;  // Alerta de mudança
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reuseDetectedAt;   // Alerta de vazamento
}
```

---

## 🔑 Componentes Principais

### 1. **FingerprintService**

Gera impressão digital única do cliente para validar mudanças de contexto.

**Responsabilidades:**
- Extrair User-Agent e IP da requisição
- Gerar hashes SHA-256 para comparação
- Avaliar nível de mudança de contexto (none/low/medium/high)

**Métodos Principais:**
```php
// Gera fingerprint completo (User-Agent + IP)
public function generate(Request $request): string

// Valida se contexto é seguro (mesmo cliente)
public function validate(string $stored, string $current): bool

// Avalia severidade de mudança de contexto
public function assessContextChange(
    string $storedUA, string $currentUA,
    string $storedIP, string $currentIP
): string  // 'none' | 'low' | 'medium' | 'high'
```

**Extração de IP (Prioridade):**
1. `X-Forwarded-For` (load balancers, proxies)
2. `X-Real-IP` (nginx reverso proxy)
3. `REMOTE_ADDR` (IP direto)

---

### 2. **RefreshTokenManager**

Orquestra toda a lógica de emissão, rotação e revogação de tokens.

**Fluxo de Emissão (Issue)**

```
┌─────────────────────────────────────────────────────────────┐
│ Client faz LOGIN com Email + Senha                          │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ AuthController::login()                                     │
│ 1. Valida credenciais                                       │
│ 2. Gera Access Token (JWT, 15 min)                          │
│ 3. Chama refreshTokenManager->issue($user, $request)        │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ RefreshTokenManager::issue()                                │
│ ┌──────────────────────────────────────────────────────┐    │
│ │ Geração de Dados:                                    │    │
│ │ • plainToken = random_bytes(64) em hex               │    │
│ │ • tokenHash = SHA-256(plainToken)                    │    │
│ │ • expiresAt = now + 30 dias                          │    │
│ │                                                      │    │
│ │ Fingerprint de Contexto:                             │    │
│ │ • fingerprintHash = SHA-256(UA + IP)                 │    │
│ │ • userAgentHash = SHA-256(UA)                        │    │
│ │ • ipHash = SHA-256(IP)                               │    │
│ │                                                      │    │
│ │ Família de Tokens:                                   │    │
│ │ • tokenFamilyId = novo UUID (primeira vez)           │    │
│ │ • parentTokenHash = null (primeira vez)              │    │
│ └──────────────────────────────────────────────────────┘    │
│                                                             │
│ Persiste RefreshToken com todos os dados                    │
│ Retorna IssuedRefreshToken(user, plainToken, expiresAt)     │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ AuthController::login() retorna ao Cliente:                 │
│ {                                                           │
│   "token": "eyJhbGc...",      ← Access Token (JWT)          │
│   "token_type": "Bearer",                                   │
│   "expires_in": 900,           ← Em segundos (15 min)       │
│   "user": { ... }                                           │
│   Cookie: refresh_token=<plainToken> (HttpOnly)             │
│ }                                                           │
└─────────────────────────────────────────────────────────────┘
```

**Fluxo de Rotação (Rotate)**

```
┌─────────────────────────────────────────────────────────────┐
│ Client faz requisição com Access Token EXPIRADO             │
│ Envia Cookie: refresh_token=<plainToken>                    │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ AuthController::refresh()                                   │
│ 1. Extrai plainToken do Cookie                              │
│ 2. Chama refreshTokenManager->rotate(plainToken, $request)  │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│ RefreshTokenManager::rotate()                                │
│ ┌────────────────────────────────────────────────────────┐   │
│ │ PASSO 1: Encontra Token Atual                          │   │
│ │ tokenHash = SHA-256(plainToken)                        │   │
│ │ existingToken = DB.findByHash(tokenHash)               │   │
│ │                                                        │   │
│ │ Se não encontrado → return null ❌                     │   │
│ └────────────────────────────────────────────────────────┘   │
│                                                              │
│ ┌────────────────────────────────────────────────────────┐   │
│ │ PASSO 2: Verifica Revogação (Detecção de Reuso) 🚨     │   │
│ │ if (existingToken.isRevoked()) {                       │   │
│ │   // ⚠️ Token revogado sendo reutilizado!              │   │
│ │   //    Indica vazamento do cookie                     │   │
│ │                                                        │   │
│ │   existingToken.reuseDetectedAt = now                  │   │
│ │   DB.flush()                                           │   │
│ │                                                        │   │
│ │   // 🚨 AÇÃO CRÍTICA: Revoga TODA a família            │   │
│ │   revokeTokenFamily(existingToken.tokenFamilyId)       │   │
│ │                                                        │   │
│ │   Logs: "Token reuse detected for user %s"             │   │
│ │   return null ❌                                       │   │
│ │ }                                                      │   │
│ └────────────────────────────────────────────────────────┘   │
│                                                              │
│ ┌────────────────────────────────────────────────────────┐   │
│ │ PASSO 3: Valida Fingerprint (Contexto)                 │   │
│ │ currentFingerprint = fingerprint.generate($request)    │   │
│ │ contextChange = fingerprint.assessContextChange(       │   │
│ │   stored.userAgentHash,                                │   │
│ │   current.userAgentHash,                               │   │
│ │   stored.ipHash,                                       │   │
│ │   current.ipHash                                       │   │
│ │ )                                                      │   │
│ │                                                        │   │
│ │ // Classificação:                                      │   │
│ │ // ✅ 'none'   → Contexto idêntico                     │   │
│ │ // ⚠️  'low'   → Novo device, mesmo IP                 │   │
│ │ // ⚠️  'medium'→ Mesmo device, novo IP                 │   │
│ │ // 🚨 'high'   → Ambos mudaram (muito suspeito!)       │   │
│ │                                                        │   │
│ │ if (contextChange === 'high') {                        │   │
│ │   existingToken.contextChangedAt = now                 │   │
│ │   // Continua rotação mas registra alerta              │   │
│ │   // Logs: "Unusual context change detected"           │   │
│ │ }                                                      │   │
│ └────────────────────────────────────────────────────────┘   │
│                                                              │
│ ┌────────────────────────────────────────────────────────┐   │
│ │ PASSO 4: Rotação (Revoga Atual + Emite Novo)           │   │
│ │ existingToken.revokedAt = now                          │   │
│ │ DB.flush()                                             │   │
│ │                                                        │   │
│ │ newToken = issue(                                      │   │
│ │   user,                                                │   │
│ │   $request,                                            │   │
│ │   parentTokenHash = existingToken.tokenHash            │   │
│ │ )                                                      │   │
│ │                                                        │   │
│ │ // O novo token:                                       │   │
│ │ // • Continua na MESMA familia (tokenFamilyId)         │   │
│ │ // • Tem parentTokenHash apontando para o anterior     │   │
│ │ // • Possui novo fingerprint (contexto atual)          │   │
│ │ // • É armazenado sem revogação                        │   │
│ └────────────────────────────────────────────────────────┘   │
└──────────────────┬───────────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────┐
│ AuthController::refresh() retorna ao Cliente:                │
│ {                                                            │
│   "token": "eyJhbGc...", NEW   ← Novo Access Token           │
│   "token_type": "Bearer",                                    │
│   "expires_in": 900,                                         | 
│   "user": { ... }                                            | 
│   Cookie: refresh_token=<newPlainToken> (HttpOnly) UPDATED   │
│ }                                                            │
└──────────────────────────────────────────────────────────────┘
```

---

## 🚨 Cenários de Segurança

### Cenário 1: Fluxo Normal de Autenticação

```
T=0s      T=15min         T=30min
│         │               │
├─LOGIN──┬─AT EXPIRA───┬─RT EXPIRA
│        │             │
│    ┌───RT emitido    │
│    │ (válido até 30 dias)
│    │
│    └─CLIENT armazena em Cookie HttpOnly
│       (inacessível via JavaScript)
│
T=7min: Client faz requisição
├─Envia AT expirado no header Authorization
└─Envia Cookie: refresh_token (automaticamente)

    Servidor recebe /refresh
    ├─Token válido ✅
    ├─Não revogado ✅
    ├─Contexto OK (IP e UA iguais) ✅
    │
    └─ROTACIONA:
      ├─Revoga RT anterior
      └─Emite novo RT (30 dias de novo)

Response enviado ao cliente:
├─Novo Access Token (AT + 15 min)
└─Novo Refresh Token (via Cookie HttpOnly)
```

### Cenário 2: Detecção de Reuso (CRÍTICO)

```
Hora: 10:00 - Cliente autorizado em IP 200.1.1.1 (Chrome)
├─RT_V1 emitido
└─Cookie: refresh_token=abcd...

Hora: 10:30 - Hacker obtém o cookie (XSS ou traição)
├─Tenta usar o mesmo RT_V1 de IP 192.168.1.50 (Firefox)
│
└─Requisição POST /refresh
   ├─plainToken = "abcd..."
   │
   └─RefreshTokenManager::rotate()
      ├─Encontra RT_V1 no BD ✅
      │
      ├─[PASSO 2] Verifica: isRevoked()? ❌❌❌
      │  ┌─ NÃO (ainda é a primeira rotação)
      │  │
      │  └─Continua...
      │
      ├─[PASSO 3] Valida fingerprint
      │  ┌─ userAgent antigo: SHA-256("Mozilla...Chrome/...")
      │  ├─ userAgent novo: SHA-256("Mozilla...Firefox/...")
      │  │ ❌ Diferente! (contextChange = LOW)
      │  │
      │  ├─ ipHash antigo: SHA-256("200.1.1.1")
      │  └─ ipHash novo: SHA-256("192.168.1.50")
      │    ❌ Diferente! (contextChange = HIGH)
      │
      │  ⚠️ contextChange == 'HIGH'
      │     existingToken.contextChangedAt = now
      │     Logs alerta, mas permite rotação
      │
      └─[PASSO 4] Rotaciona normalmente
         ├─Revoga RT_V1
         └─Emite RT_V2 (nova cookie)

       ✅ Hacker recebe novo token!

Hora: 10:31 - Cliente LEGÍTIMO tenta usar RT_V1 original
├─POST /refresh
├─plainToken = "abcd..."
│
└─RefreshTokenManager::rotate()
   ├─Encontra RT_V1 no BD ✅
   │
   └─[PASSO 2] Verifica: isRevoked()? ✅✅✅
      ┌─ SIM! (foi revogado há 1 minuto)
      │
      ├─ existingToken.reuseDetectedAt = now
      ├─ DB.flush()
      │
      ├─ 🚨 ALERTA CRÍTICO ACIONADO:
      │  "Tentativa de reuso de RT revogado"
      │  "Possível vazamento do token!"
      │
      └─ revokeTokenFamily(RT_V1.tokenFamilyId)
         ├─Encontra TODA a família:
         │ • RT_V1 (já revogado)
         │ • RT_V2 (novo, do hacker)
         │
         └─Revoga tudo! 🔒
            ├─RT_V1.revokedAt = now
            └─RT_V2.revokedAt = now

Response ao cliente:
├─null (invalid token)
└─Client deve fazer LOGIN novamente

Ações de Mitigação:
├─Revoga TODAS as sessões do usuário
├─Notifica usuário: "Acesso anormal detectado"
├─Registra em auditoria com timestamps
├─Pode exigir re-autenticação multifator
└─Alerta time de segurança
```

### Cenário 3: Viagem/Mudança de Rede Legítima

```
Cliente em São Paulo (IP1, Chrome)
├─Faz login
└─RT_V1 emitido com fingerprint completo

12 horas depois: Cliente em New York (IP2, mesmo Chrome)
├─Usa AT ancora (ainda válido por 15 min)
└─Quando AT expira, faz refresh

POST /refresh
├─plainToken = RT_V1
│
└─RefreshTokenManager::rotate()
   ├─[PASSO 2] Verifica: isRevoked()? ❌ (primeira rotação)
   │
   ├─[PASSO 3] Valida fingerprint
   │  userAgent: SHA-256("Mozilla...Chrome/...")
   │  userAgent novo: SHA-256("Mozilla...Chrome/...")
   │  ✅ IGUAL (mesmo navegador)
   │
   │  IP: SHA-256("75.x.x.x") [São Paulo]
   │  IP novo: SHA-256("40.x.x.x") [New York]
   │  ❌ Diferente!
   │
   │  contextChange = 'MEDIUM' (apenas IP mudou)
   │  ⚠️ Registra: contextChangedAt = now
   │     Logs: "Moderate context change: new IP detected"
   │
   └─[PASSO 4] Rotaciona normalmente
      └─RT_V2 emitido

✅ Client consegue novo token normalmente
   (mudança legítima de localização é permitida)
```

---

## 🔐 Segurança Detalhada

### Token Hashing

```php
// NUNCA é armazenado em plaintext:
// ❌ DB tem: "abc123xyz..."

// AO INVÉS:
// ✅ DB tem: SHA-256("abc123xyz...") = "3f4...8ab"

// Quando client envia:
// 1. Recebe plaintext: "abc123xyz..."
// 2. Calcula: SHA-256("abc123xyz...") = "3f4...8ab"
// 3. Compara com BD: ✅ Match
// 4. Usa sempre hash_equals() para comparação (timing-safe)

// Benefício:
// Se BD vaza, tokens ainda são seguros
// Hacker precisa quebrar SHA-256 (infeasível)
```

### Cookie HttpOnly, Secure, SameSite

```php
Cookie::create('refresh_token')
    ->withHttpOnly(true)        // Inacessível via JavaScript
                                // Proteção contra XSS
                                
    ->withSecure(true)          // Enviado APENAS via HTTPS
                                // Proteção contra MITM
    
    ->withSameSite('Strict')    // Enviado APENAS em requisições do mesmo site
                                // Proteção contra CSRF
```

### Fingerprint de Contexto

```
┌─────────────────────────────────────────┐
│ LOGIN (IP: 200.1.1.1, UA: "Chrome...") │
└────────────────┬────────────────────────┘
                 │
                 ▼
        userAgentHash = SHA-256("Chrome...")
        ipHash = SHA-256("200.1.1.1")
        fingerprintHash = SHA-256("Chrome...|200.1.1.1")
        
        Armazenado no BD
        
┌────────────────────────────────────────────┐
│ REFRESH (IP: 192.168.1.1, UA: "Firefox") │ ← Mudança!
└────────┬─────────────────────────────────────┘
         │
         ▼
    Calcula novos hashes:
    newUserAgentHash = SHA-256("Firefox...")
    newIpHash = SHA-256("192.168.1.1")
    
    Compara:
    - UA diferente: ❌
    - IP diferente: ❌
    - contextChange = 'HIGH' 🚨
    
    RESULTADO:
    ✅ Token ainda é rotacionado
    ⚠️  Contexto anormal é registrado
    🔍 Permite análise posterior
```

---

## 📝 Implementação

### .env (Configuração)

```env
# Access Token: JWT válido por 15 minutos
JWT_TOKEN_TTL=900

# Refresh Token: válido por 30 dias (2592000 segundos)
AUTH_REFRESH_TOKEN_TTL=2592000

# Cookie Refresh Token
AUTH_REFRESH_TOKEN_COOKIE_NAME=refresh_token
AUTH_REFRESH_COOKIE_SECURE=true      # HTTPS only
AUTH_REFRESH_COOKIE_SAMESITE=Strict  # Same-site only
```

### services.yaml (Injeção de Dependência)

```yaml
services:
  App\Security\FingerprintService:
    autowire: true
    
  App\Security\RefreshTokenManager:
    autowire: true
    arguments:
      $refreshTokenTtl: '%env(int:AUTH_REFRESH_TOKEN_TTL)%'
      
  App\Controller\AuthController:
    autowire: true
    arguments:
      $refreshCookieName: '%env(string:AUTH_REFRESH_TOKEN_COOKIE_NAME)%'
      $refreshCookieSecure: '%env(bool:AUTH_REFRESH_COOKIE_SECURE)%'
      $refreshCookieSameSite: '%env(string:AUTH_REFRESH_COOKIE_SAMESITE)%'
      $accessTokenTtl: '%env(int:JWT_TOKEN_TTL)%'
```

### Execução de Migration

```bash
php bin/console doctrine:migrations:migrate

# Output:
# >> doctrine:migrations:migrate [...]
# Doctrine Migrations Version 3.x.x
# Executing migration version 20260309120000
# +>> Version20260309120000
# +   << migrated (took 245ms)
```

### Registrando Serviços

```php
// AuthController já tem injeção automática:
public function __construct(
    // ... outros serviços
    private readonly RefreshTokenManager $refreshTokenManager,
    // ...
) {}
```

---

## 🧪 Testes (Pseudo-código)

### Test 1: Fluxo Normal de Login + Refresh

```php
public function testLoginAndRefreshToken()
{
    // 1. Login
    $response = $this->post('/auth/login', [
        'email' => 'user@example.com',
        'password' => 'secure123',
    ]);
    
    $this->assertEquals(200, $response->statusCode);
    $accessToken = $response->json()['token'];
    $refreshToken = $response->cookies['refresh_token'];
    
    // 2. Aguarda expiração do AT (ou simula)
    $this->waitSeconds(900); // ou usa JWT mock
    
    // 3. Refresh
    $response = $this->post('/auth/refresh', cookies: [
        'refresh_token' => $refreshToken,
    ]);
    
    $this->assertEquals(200, $response->statusCode);
    $newAccessToken = $response->json()['token'];
    $newRefreshToken = $response->cookies['refresh_token'];
    
    // 4. Verifica tokens são diferentes
    $this->assertNotEquals($accessToken, $newAccessToken);
    $this->assertNotEquals($refreshToken, $newRefreshToken);
    
    // 5. Token antigo não funciona mais
    $response = $this->post('/auth/refresh', cookies: [
        'refresh_token' => $refreshToken,  // ← ANTIGO
    ]);
    
    $this->assertEquals(401, $response->statusCode);
}
```

### Test 2: Detecção de Reuso

```php
public function testReuseDetectionRevokesFamily()
{
    // 1. Login
    $response = $this->post('/auth/login', [
        'email' => 'user@example.com',
        'password' => 'secure123',
    ]);
    $refreshToken = $response->cookies['refresh_token'];
    
    // 2. Primeira rotação (normal)
    $response = $this->post('/auth/refresh', cookies: [
        'refresh_token' => $refreshToken,
    ]);
    $this->assertEquals(200, $response->statusCode);
    $newRefreshToken = $response->cookies['refresh_token'];
    
    // 3. Tenta reusar o RT ANTIGO (como se houvesse vazamento)
    $response = $this->post('/auth/refresh', cookies: [
        'refresh_token' => $refreshToken,  // ← REVOGADO!
    ]);
    
    // 4. Deve falhar
    $this->assertEquals(401, $response->statusCode);
    
    // 5. Novo RT também deve estar revogado (cascata)
    $response = $this->post('/auth/refresh', cookies: [
        'refresh_token' => $newRefreshToken,  // ← CASCATA!
    ]);
    
    $this->assertEquals(401, $response->statusCode);
    
    // 6. Verifica auditoria
    $auditLog = $this->getLastAuditLog('token_reuse_detected');
    $this->assertNotNull($auditLog);
    $this->assertEquals($userId, $auditLog->userId);
}
```

### Test 3: Validação de Fingerprint

```php
public function testFingerprintValidation()
{
    // 1. Login do Chrome em SP
    $response = $this->post('/auth/login', 
        headers: ['User-Agent' => 'Chrome/...'],
        clientIp: '75.1.1.1',
        data: ['email' => 'user@example.com', 'password' => '...'],
    );
    $refreshToken = $response->cookies['refresh_token'];
    
    // 2. Requisição com mudança EXTREMA de contexto
    // (Firefox em New York simulando hacker)
    $response = $this->post('/auth/refresh',
        headers: ['User-Agent' => 'Firefox/...'],  // ← Diferente!
        clientIp: '40.2.2.2',                      // ← Diferente!
        cookies: ['refresh_token' => $refreshToken],
    );
    
    // 3. Rotação deve ocorrer, mas com alerta
    $this->assertEquals(200, $response->statusCode);
    
    // 4. Verifica que contexto mudou foi registrado
    $token = $this->getRefreshTokenFromDB($refreshToken);
    $this->assertNotNull($token->contextChangedAt);
    $this->assertNotNull($token->contextChange); // 'high'
}
```

---

## 📚 Referência Rápida

| Conceito | Descrição | TTL |
|----------|-----------|-----|
| **Access Token** | JWT enviado no header | 15 min |
| **Refresh Token** | String opaca no cookie | 30 dias |
| **tokenFamilyId** | Agrupa toda a cadeia de RTs | Uma vez gerado, persiste |
| **fingerprint** | Hash de UA + IP | Renovado a cada rotação |
| **Rotation** | Revoga antigo + emite novo | A cada refresh |
| **Cascade Revocation** | Revoga toda a família | Ao detectar reuso |

---

## 🛡️ Checklist de Segurança

- ✅ Tokens nunca em plaintext (apenas hashes SHA-256)
- ✅ Access Token = JWT com expiração curta
- ✅ Refresh Token = String opaca com hash
- ✅ Refresh Token Rotation obrigatória
- ✅ Detecção de Reuso com revogação em cascata
- ✅ Fingerprint de contexto (UA + IP)
- ✅ Cookie HttpOnly, Secure, SameSite=Strict
- ✅ Token Family Tracking para auditoria
- ✅ Logs de contexto mudado e reuso detectado
- ✅ Garbage collection de tokens expirados

---

## 🚀 Próximos Passos

1. **Executar Migration**: `php bin/console doctrine:migrations:migrate`
2. **Testar Login**: `POST /auth/login` com credenciais válidas
3. **Testar Refresh**: `POST /auth/refresh` com cookie refresh_token
4. **Configurar Logs**: Adicionar observabilidade para reuso detectado e contexto alterado
5. **Implementar Auditoria**: Registrar todos os eventos de segurança
6. **Adicionar MFA**: Para casos de contexto mudado drasticamente
7. **Monitoramento**: Alertas para múltiplas tentativas de reuso

---

