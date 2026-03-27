# Estrutura de Dados - `refresh_token`

Documento alinhado com entidade atual `www/src/Entity/RefreshToken.php`.

## 1. Campos

| Campo | Tipo | Obrigatorio | Descricao |
|---|---|---|---|
| `id` | INT | Sim | PK |
| `user_id` | INT | Sim | FK para `app_user` |
| `token_hash` | VARCHAR(64) | Sim | Hash SHA-256 do refresh token |
| `fingerprint_hash` | VARCHAR(64) | Sim | Hash do fingerprint/browse-id |
| `user_agent_hash` | VARCHAR(64) | Sim | Hash do user-agent |
| `ip_hash` | VARCHAR(64) | Sim | Hash do IP |
| `location_hash` | VARCHAR(64) | Sim | Hash da localizacao enviada (`X-Client-Location`) |
| `token_family_id` | VARCHAR(64) | Nao | Familia de rotacao |
| `parent_token_hash` | VARCHAR(64) | Nao | Hash do token pai |
| `expires_at` | TIMESTAMP | Sim | Expiracao |
| `created_at` | TIMESTAMP | Sim | Criacao |
| `revoked_at` | TIMESTAMP | Nao | Revogacao |
| `context_changed_at` | TIMESTAMP | Nao | Mudanca de contexto |
| `reuse_detected_at` | TIMESTAMP | Nao | Reuso detectado |

## 2. Indices

- `idx_refresh_token_expires` (`expires_at`)
- `idx_refresh_token_user` (`user_id`)
- `idx_refresh_token_family` (`token_family_id`)
- `idx_refresh_token_fingerprint` (`fingerprint_hash`)
- `idx_refresh_token_location` (`location_hash`)
- `UNIQUE(token_hash)`

## 3. Migracoes relacionadas

- `Version20260306120000.php` (tabela base)
- `Version20260309120000.php` (fingerprint/user-agent/ip/family)
- `Version20260327190000.php` (location_hash)

## 4. Regras de negocio ligadas ao schema

1. Token puro nunca vai ao banco.
2. Rotacao revoga token anterior e cria novo token com mesma familia.
3. Reuso detectado revoga toda a familia.
4. Mudanca suspeita de contexto pode invalidar refresh token.
5. Limite de tokens ativos por usuario e aplicado no `issue`.
