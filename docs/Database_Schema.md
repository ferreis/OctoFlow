# 📊 Estrutura de Dados - Tabela `refresh_token`

## SQL Completo

```sql
CREATE TABLE IF NOT EXISTS refresh_token (
    -- Identificação
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT "ID único do token",
    user_id INT NOT NULL COMMENT "Referência ao usuário",
    
    -- Token em si (sempre hash)
    token_hash VARCHAR(64) NOT NULL UNIQUE COMMENT "SHA-256 do token (nunca plaintext)",
    
    -- Fingerprint de Contexto
    fingerprint_hash VARCHAR(64) NOT NULL COMMENT "SHA-256(User-Agent|IP) - contexto completo",
    user_agent_hash VARCHAR(64) NOT NULL COMMENT "SHA-256(User-Agent) - isolado para análise",
    ip_hash VARCHAR(64) NOT NULL COMMENT "SHA-256(IP) - isolado para análise",
    
    -- Token Family & History
    token_family_id VARCHAR(64) DEFAULT NULL COMMENT "UUID - agrupa toda cadeia de rotações",
    parent_token_hash VARCHAR(64) DEFAULT NULL COMMENT "Hash do token anterior (forma corrente)",
    
    -- Ciclo de Vida
    created_at DATETIME NOT NULL COMMENT "Quando foi gerado",
    expires_at DATETIME NOT NULL COMMENT "Quando expira (30 dias desde criação)",
    revoked_at DATETIME DEFAULT NULL COMMENT "Quando foi revogado (NULL = ativo)",
    
    -- Alertas & Auditoria
    context_changed_at DATETIME DEFAULT NULL COMMENT "Timestamp quando contexto mudou drasticamente",
    reuse_detected_at DATETIME DEFAULT NULL COMMENT "Timestamp quando reuso foi detectado",
    
    -- Foreign Key
    CONSTRAINT fk_refresh_token_user 
        FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE CASCADE,
    
    -- Índices para Performance
    INDEX idx_refresh_token_user (user_id) COMMENT "Buscar todos os tokens de um usuário",
    INDEX idx_refresh_token_expires (expires_at) COMMENT "Garbage collection de expirados",
    INDEX idx_refresh_token_family (token_family_id) COMMENT "Buscar família completa",
    INDEX idx_refresh_token_fingerprint (fingerprint_hash) COMMENT "Análise de padrões",
    
    COMMENT = "Armazena Refresh Tokens com rastreamento completo e segurança avançada"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Campos Explicados

### Campos Obrigatórios

| Campo | Tipo | Descrição | Exemplo |
|-------|------|-----------|---------|
| `id` | INT | ID único (Primary Key) | `1, 2, 3, ...` |
| `user_id` | INT | Remete ao usuário dono | `5` (user.id=5) |
| `token_hash` | VARCHAR(64) | SHA-256 do token (UNIQUE) | `3f4d5e6a...` |
| `created_at` | DATETIME | Quando gerado | `2025-03-09 10:30:45` |
| `expires_at` | DATETIME | Quando expira | `2025-04-08 10:30:45` (30 dias depois) |

### Campos de Segurança (Novo)

| Campo | Tipo | Descrição | Exemplo |
|-------|------|-----------|---------|
| `fingerprint_hash` | VARCHAR(64) | SHA-256(UA\|IP) | `a1b2c3d4...` |
| `user_agent_hash` | VARCHAR(64) | SHA-256(User-Agent) | `f5e6d7c8...` |
| `ip_hash` | VARCHAR(64) | SHA-256(IP) | `7g8h9i0j...` |
| `token_family_id` | VARCHAR(64) | UUID da família | `abc123-xyz789` |
| `parent_token_hash` | VARCHAR(64) | Hash do anterior | `prev3f4d5e6a...` |

### Campos de Auditoria (Novo)

| Campo | Tipo | Descrição | Exemplo |
|-------|------|-----------|---------|
| `revoked_at` | DATETIME | Quando revogado | `2025-03-09 10:35:00` ou NULL |
| `context_changed_at` | DATETIME | Alerta mudança contexto | `2025-03-09 10:32:15` ou NULL |
| `reuse_detected_at` | DATETIME | Alerta detecção reuso | `2025-03-09 10:36:00` ou NULL |

---

## Estados de um Refresh Token

```
┌─────────────────────┐
│ CRIADO (LOGIN)      │
├─────────────────────┤
│ revoked_at: NULL    │
│ reuse_detected: NULL│
│ context_changed: NULL
└────────┬────────────┘
         │
         ├─ [Rotação Normal]
         │  └─ REVOGADO (rotação)
         │     └─ revoked_at = 2025-03-09 10:35:00
         │        (Token velho é revogado, novo emitido)
         │
         ├─ [Reuso Detectado]
         │  └─ REVOGADO (cascata)
         │     └─ revoked_at = 2025-03-09 10:36:00
         │     └─ reuse_detected_at = 2025-03-09 10:36:00
         │        (Família inteira revogada)
         │
         └─ [Contexto Anormal]
            └─ ALERTA REGISTRADO
               └─ context_changed_at = 2025-03-09 10:32:15
                  (Mas continua válido por enquanto)
```

---

## Exemplos de Registros

### Exemplo 1: Token Novo (Criado via LOGIN)

```sql
INSERT INTO refresh_token (
    user_id, token_hash, fingerprint_hash, 
    user_agent_hash, ip_hash, token_family_id, 
    parent_token_hash, created_at, expires_at
) VALUES (
    5,
    SHA2('abcd123456789...', 256),  -- Token real do client
    SHA2('Mozilla Chrome 125|75.1.1.1', 256),
    SHA2('Mozilla Chrome 125', 256),
    SHA2('75.1.1.1', 256),
    'f550e8d0-30e3-41f0-8e71-2e0a98f7e3ac',  -- Nova família
    NULL,  -- Nenhum token anterior (first)
    '2025-03-09 10:30:45',
    '2025-04-08 10:30:45'  -- 30 dias depois
);

-- Record status: ATIVO
-- revoked_at: NULL
-- context_changed_at: NULL
-- reuse_detected_at: NULL
```

### Exemplo 2: Após Primeira Rotação (Normal)

```sql
-- TOKEN ANTIGO (revogado normalmente)
UPDATE refresh_token SET 
    revoked_at = '2025-03-09 10:35:00'
WHERE token_hash = SHA2('abcd123456789...', 256);

-- TOKEN NOVO (emitido após rotação)
INSERT INTO refresh_token (
    user_id, token_hash, fingerprint_hash, 
    user_agent_hash, ip_hash, token_family_id, 
    parent_token_hash, created_at, expires_at
) VALUES (
    5,
    SHA2('defg987654321...', 256),  -- Novo token diferente
    SHA2('Mozilla Chrome 125|75.1.1.1', 256),  -- Mesmo contexto
    SHA2('Mozilla Chrome 125', 256),
    SHA2('75.1.1.1', 256),
    'f550e8d0-30e3-41f0-8e71-2e0a98f7e3ac',  -- MESMA família!
    SHA2('abcd123456789...', 256),  -- Aponta para anterior
    '2025-03-09 10:35:00',
    '2025-04-04 10:35:00'  -- 30 dias desde novo
);

-- Corrente formada:
-- abcd123... → defg987... → (próxima rotação)
```

### Exemplo 3: Reuso Detectado! (Cascata Acionada)

```sql
-- Cenário: Token REVOGADO foi tentado novamente

-- TOKEN ANTIGO (já revogado em rotação anterior)
UPDATE refresh_token SET 
    reuse_detected_at = '2025-03-09 10:36:00'  -- ⚠️ ALERTA!
WHERE token_hash = SHA2('abcd123456789...', 256)
  AND revoked_at IS NOT NULL;

-- CASCATA: Todos da MESMA família são revogados
UPDATE refresh_token SET 
    revoked_at = '2025-03-09 10:36:00'  -- ← REVOGA TODOS!
WHERE token_family_id = 'f550e8d0-30e3-41f0-8e71-2e0a98f7e3ac'
  AND revoked_at IS NULL;

-- Resultado:
-- • Token abcd123 (antigo): revoked + reuse_detected
-- • Token defg987 (novo):  revoked (cascata)
-- • Token ijkl... (próximo, se existir): revoked (cascata)
-- Toda a família bloqueada 🔒

```

### Exemplo 4: Contexto Mudou! (Alerta Registrado)

```sql
-- Cenário: User-Agent OU IP mudou drasticamente

INSERT INTO refresh_token (
    user_id, token_hash, fingerprint_hash, 
    user_agent_hash, ip_hash, token_family_id, 
    parent_token_hash, created_at, expires_at,
    context_changed_at  -- ← ALERTA!
) VALUES (
    5,
    SHA2('mnop456123456...', 256),
    SHA2('Mozilla Firefox 122|192.168.1.50', 256),  -- Mudou!
    SHA2('Mozilla Firefox 122', 256),  -- ← Diferente!
    SHA2('192.168.1.50', 256),  -- ← Diferente!
    'f550e8d0-30e3-41f0-8e71-2e0a98f7e3ac',
    SHA2('defg987654321...', 256),
    '2025-03-09 10:40:00',
    '2025-04-08 10:40:00',
    '2025-03-09 10:40:00'  -- ⚠️ Registra mudança
);

-- Status: ATIVO (ainda funciona)
-- Mas: context_changed_at foi registrado
-- Auditoria: investigar mudança
-- Email enviado ao usuário
```

---

## Queries Úteis

### Query 1: Todos os Tokens Ativos de um Usuário

```sql
SELECT 
    token_hash,
    fingerprint_hash,
    created_at,
    expires_at,
    CASE 
        WHEN revoked_at IS NOT NULL THEN 'REVOGADO'
        WHEN expires_at <= NOW() THEN 'EXPIRADO'
        ELSE 'ATIVO'
    END AS status
FROM refresh_token
WHERE user_id = 5
  AND revoked_at IS NULL
  AND expires_at > NOW()
ORDER BY created_at DESC;
```

### Query 2: Detectar Tentativa de Reuso

```sql
SELECT 
    user_id,
    token_family_id,
    COUNT(*) as tokens_in_family,
    MAX(reuse_detected_at) as last_reuse_attempt,
    GROUP_CONCAT(
        CONCAT(token_hash, ' at ', COALESCE(reuse_detected_at, 'never'))
        SEPARATOR ', '
    ) as token_history
FROM refresh_token
WHERE reuse_detected_at IS NOT NULL
GROUP BY user_id, token_family_id
ORDER BY reuse_detected_at DESC;
```

### Query 3: Alertas de Contexto Anormal

```sql
SELECT 
    user_id,
    token_hash,
    context_changed_at,
    user_agent_hash,
    ip_hash
FROM refresh_token
WHERE context_changed_at IS NOT NULL
  AND context_changed_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY context_changed_at DESC;
```

### Query 4: Tokens Prontos para Garbage Collection

```sql
SELECT 
    id,
    token_hash,
    user_id,
    expires_at
FROM refresh_token
WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOURS)
ORDER BY expires_at DESC;

-- Execute para limpar:
DELETE FROM refresh_token 
WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOURS);
```

### Query 5: Corrente Completa de um Token

```sql
WITH RECURSIVE token_chain AS (
    -- Base: token específico
    SELECT 
        id,
        token_hash,
        parent_token_hash,
        token_family_id,
        created_at,
        revoked_at,
        1 as depth
    FROM refresh_token
    WHERE token_hash = SHA2('defg987654321...', 256)
    
    UNION ALL
    
    -- Recursivo: busca antecessores
    SELECT 
        rt.id,
        rt.token_hash,
        rt.parent_token_hash,
        rt.token_family_id,
        rt.created_at,
        rt.revoked_at,
        tc.depth + 1
    FROM refresh_token rt
    INNER JOIN token_chain tc ON rt.token_hash = tc.parent_token_hash
)
SELECT 
    depth,
    token_hash,
    created_at,
    revoked_at,
    CASE WHEN revoked_at IS NULL THEN '✅ ATIVO' ELSE '❌ REVOGADO' END as status
FROM token_chain
ORDER BY depth DESC;
```

---

## Performance & Índices

### Análise de Uso

| Operação | Índice Usado | Velocidade |
|----------|--------------|-----------|
| Buscar token por hash | `idx_unique(token_hash)` | O(log N) - ⚡ Rápido |
| Todos tokens de usuário | `idx_user_id` | O(log N) - ⚡ Rápido |
| Encontrar família | `idx_token_family_id` | O(log N) - ⚡ Rápido |
| Limpar expirados | `idx_expires_at` | O(log N) - ⚡ Rápido |
| Análise de fingerprints | `idx_fingerprint_hash` | O(log N) - ⚡ Rápido |

### Tamanho Estimado

```
Por token armazenado:
• id: 4 bytes
• user_id: 4 bytes
• token_hash: 64 bytes (VARCHAR)
• fingerprint_hash: 64 bytes (VARCHAR)
• user_agent_hash: 64 bytes (VARCHAR)
• ip_hash: 64 bytes (VARCHAR)
• token_family_id: 64 bytes (VARCHAR)
• parent_token_hash: 64 bytes (VARCHAR)
• created_at: 8 bytes (DATETIME)
• expires_at: 8 bytes (DATETIME)
• revoked_at: 8 bytes (DATETIME nullable)
• context_changed_at: 8 bytes (DATETIME nullable)
• reuse_detected_at: 8 bytes (DATETIME nullable)
────────────────────────────────
Total: ~550 bytes/token

Para 1 milhão de usuários ativos:
• 1-2 tokens por usuário = 1-2 milhões registros
• 550 bytes × 2M = ~1.1 GB dados
• Com índices: ~2 GB total

Muito gerenciável! ✅
```

### Garbage Collection Recomendado

```bash
# Comando Arthritis que pode ser agendado via Cron

# Diariamente às 03:00 AM
0 3 * * * php /app/www/bin/console auth:cleanup-tokens

# Ou via SQL direto
DELETE FROM refresh_token 
WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOURS);

# Esperado remover: ~2592 tokens/dia (1 token por usuário × 30 dias)
```

---

