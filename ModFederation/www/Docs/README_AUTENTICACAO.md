```
╔═══════════════════════════════════════════════════════════════════════════╗
║                                                                           ║
║              🔐 AUTENTICAÇÃO ULTRA-SEGURA (PROJETO WWW)                 ║
║           Inspirada no modelo do Google com Token Rotation               ║
║                                                                           ║
╚═══════════════════════════════════════════════════════════════════════════╝

📖 DOCUMENTAÇÃO COMPLETA
═════════════════════════════════════════════════════════════════════════════

1. 📋 VISÃO GERAL (COMECE AQUI)
   └─ IMPLEMENTATION_SUMMARY.md
      • O que foi implementado
      • Como começar
      • Checklist de validação
      • Próximos passos

2. 🏗️ ARQUITETURA DETALHADA
   └─ AUTHENTICATION_ARCHITECTURE.md
      • Estrutura de dados (SQL)
      • Componentes principais
      • Fluxos de autenticação (diagramas)
      • Cenários de segurança
      • Mecanismos de proteção

3. 💻 PSEUDO-CÓDIGO DOS ENDPOINTS
   └─ PSEUDO_CODE_ENDPOINTS.md
      • Algoritmo de /auth/login (passo a passo)
      • Algoritmo de /auth/refresh (passo a passo)
      • Detecção de reuso (fluxo crítico)
      • Validação de fingerprint
      • Casos de erro

4. 📊 ESQUEMA DE BANCO DE DADOS
   └─ DATABASE_SCHEMA.md
      • SQL completo da tabela refres_token
      • Explicação de cada campo
      • Exemplos de registros
      • Queries úteis
      • Performance & índices

5. 🔁 CADEIA DE COMUNICACAO (CONSOLIDADO)
   └─ AUTH_COMMUNICATION_CHAIN.md
      • Campos criticos (`parent_token_hash`, `context_changed_at`, `reuse_detected_at`)
      • Fluxo completo frontend -> proxy -> nginx -> backend -> banco
      • Parametros atuais de TTL (AT 10 min, RT 14 dias)
      • Comandos de verificacao rapida

═════════════════════════════════════════════════════════════════════════════

🚀 QUICK START
═════════════════════════════════════════════════════════════════════════════

```bash
# 1. Executar migration (adiciona 7 novos campos)
php bin/console doctrine:migrations:migrate

# 2. Testar login
curl -X POST http://localhost:8000/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"senha"}'

# 3. Testar refresh (navegador envia cookie automaticamente)
curl -X POST http://localhost:8000/auth/refresh \
  -H "Cookie: refresh_token=..."

```

═════════════════════════════════════════════════════════════════════════════

🔑 COMPONENTES PRINCIPAIS
═════════════════════════════════════════════════════════════════════════════

┌──────────────────────────────────────────────────────────────────────────┐
│ FingerprintService                                                       │
├──────────────────────────────────────────────────────────────────────────┤
│ Gera e valida "impressão digital" do contexto (User-Agent + IP)         │
│                                                                          │
│ generate()          → SHA-256(UA|IP)                                    │
│ validateUserAgent()  → Verifica se UA mudou                             │
│ validateIp()        → Verifica se IP mudou                              │
│ assessContextChange()→ Classifica mudança (none/low/medium/high)        │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│ RefreshTokenManager                                                      │
├──────────────────────────────────────────────────────────────────────────┤
│ Orquestra lógica de emissão, rotação e revogação de tokens              │
│                                                                          │
│ issue()        → Emite novo RT (login ou rotação)                       │
│ rotate()       → Rotaciona RT (revoga atual + emite novo)               │
│ isValid()      → Valida token sem revogá-lo                             │
│ revokeByPlainToken() → Revoga token específico                          │
│ cleanupExpiredTokens() → Garbage collection                             │
└──────────────────────────────────────────────────────────────────────────┘

═════════════════════════════════════════════════════════════════════════════

🔐 MECANISMOS DE SEGURANÇA
═════════════════════════════════════════════════════════════════════════════

✅ 1. Access Token (JWT)
   • 15 minutos de validade
   • Assinado criptograficamente
   • Impossível falsificar

✅ 2. Refresh Token (String Opaca)
   • 30 dias de validade
   • Aleatório + SHA-256 hash
   • Não é JWT (mais seguro)

✅ 3. Token Rotation
   • Cada uso revoga o anterior
   • Reutilização impossível
   • Hacker perde acesso em dias

✅ 4. Detecção de Reuso
   • Se token revogado for usado → ALERTA
   • Revoga TODA a família
   • Usuário bloqueado (requer login)

✅ 5. Fingerprint de Contexto
   • Valida User-Agent não mudou
   • Valida IP não mudou drasticamente
   • Registra mudanças suspeitas

✅ 6. Cookie Security
   • HttpOnly  → Inacessível via JS (XSS safe)
   • Secure   → HTTPS only (MITM safe)  
   • SameSite → Mesmo site (CSRF safe)

✅ 7. Token Family Tracking
   • Agrupa toda cadeia de rotações
   • Auditoria completa
   • Cascata revogação por família

✅ 8. Hash Protection
   • BD nunca armazena plaintext
   • Vazamento de BD não rouba tokens
   • SHA-256 (infeasível quebrar)

═════════════════════════════════════════════════════════════════════════════

📊 FLUXO VISUAL: LOGIN
═════════════════════════════════════════════════════════════════════════════

┌──────────────┐
│  Client      │
│  Login Form  │
└───────┬──────┘
        │  POST /auth/login
        │  { email, password }
        │
        ▼
┌──────────────────────────────┐
│ AuthController::login()      │
├──────────────────────────────┤
│ ✅ Valida credenciais        │
│ ✅ Gera Access Token (JWT)   │
│ ✅ Gera Refresh Token        │
│ ✅ Cria Fingerprint          │
│ ✅ Cria Token Family         │
│ ✅ Armazena no BD            │
└────┬─────────────────────────┘
     │ 200 OK
     │ { token, user }
     │ Cookie: refresh_token
     │
     ▼
┌──────────────┐
│  Client      │
│ ✅ AT em RAM │
│ ✅ RT em Cookie
└──────────────┘

═════════════════════════════════════════════════════════════════════════════

📊 FLUXO VISUAL: REFRESH TOKEN
═════════════════════════════════════════════════════════════════════════════

┌──────────────┐
│  Client      │
│  AT expirado │
└───────┬──────┘
        │  POST /auth/refresh
        │  (Cookie: refresh_token=X)
        │
        ▼
┌────────────────────────────────────────┐
│ RefreshTokenManager::rotate()          │
├────────────────────────────────────────┤
│ 1️⃣  Encontra token atual no BD         │
│ 2️⃣  Valida: revogado? (reuso detect)  │
│ 3️⃣  Valida: fingerprint OK?            │
│ 4️⃣  Revoga token atual                 │
│ 5️⃣  Emite novo token (mesma familia)   │
│ 6️⃣  Armazena novo no BD                │
└────┬─────────────────────────────────────┘
     │ 200 OK
     │ { token (novo), user }
     │ Cookie: refresh_token (novo)
     │
     ▼
┌──────────────┐
│  Client      │
│ ✅ Novo AT   │
│ ✅ Novo RT   │
└──────────────┘

═════════════════════════════════════════════════════════════════════════════

🚨 CENÁRIO: DETECÇÃO DE REUSO
═════════════════════════════════════════════════════════════════════════════

Hora: 10:00
├─User faz login
├─RT_V1 emitido
└─Armazenado em cookie

Hora: 10:30
├─Hacker consegue cookie (XSS ou traição)
└─Tenta usar RT_V1

Requisição ao /refresh com RT_V1:
├─Server: RT_V1 encontrado
├─Server: isRevoked()? NÃO (primeira rotação)
├─Server: Fingerprint OK? SIM
├─Server: Rotaciona normalmente
├─Server: Revoga RT_V1
└─Server: Emite RT_V2 → Hacker recebe!

Hora: 10:31
├─User legítimo tenta usar RT_V1 original
│  (ainda tem a cookie original)
│
└─Requisição ao /refresh com RT_V1:
   ├─Server: RT_V1 encontrado
   ├─Server: isRevoked()? ✅ SIM! (revogado há 1 min)
   │
   └─🚨 ALERTA CRÍTICO:
      ├─reuseDetectedAt = now
      ├─🔥 Revoga TODA a família (RT_V1 + RT_V2 + ...)
      ├─📧 Email ao usuário
      ├─📝 Logs de auditoria
      └─❌ Retorna 401 Unauthorized

RESULTADO:
├─User bloqueado (deve fazer login)
└─Hacker também bloqueado (token revogado)

═════════════════════════════════════════════════════════════════════════════

📈 ESTADO DO PROJETO
═════════════════════════════════════════════════════════════════════════════

✅ IMPLEMENTADO
├─ Migration Doctrine (7 novos campos)
├─ Entity RefreshToken expandida
├─ FingerprintService completo
├─ RefreshTokenManager refatorado
├─ RefreshTokenRepository com novos métodos
├─ AuthController atualizado
└─ Documentação completa (4 arquivos)

📋 DOCUMENTOS
├─ IMPLEMENTATION_SUMMARY.md   (este arquivo)
├─ AUTHENTICATION_ARCHITECTURE.md
├─ PSEUDO_CODE_ENDPOINTS.md
└─ DATABASE_SCHEMA.md

🧪 PRÓXIMAS ETAPAS (Sugeridas)
├─ Testes automatizados
├─ Integração com observabilidade
├─ Auditoria com email
├─ MFA em contexto suspeito
└─ Rate limiting em /login

═════════════════════════════════════════════════════════════════════════════

📞 ONDE ENCONTRAR INFORMAÇÕES
═════════════════════════════════════════════════════════════════════════════

Pergunta                                 → Onde encontrar
──────────────────────────────────────────────────────────────────────────
"É seguro?"                              → AUTHENTICATION_ARCHITECTURE.md
"Como funciona login?"                   → PSEUDO_CODE_ENDPOINTS.md
"Como funciona refresh?"                 → PSEUDO_CODE_ENDPOINTS.md
"Como detecta reuso?"                    → PSEUDO_CODE_ENDPOINTS.md
"Qual è a estrutura do BD?"              → DATABASE_SCHEMA.md
"Que campos existem na tabela?"          → DATABASE_SCHEMA.md
"Qual è o tamanho do BD?"                → DATABASE_SCHEMA.md
"Como fazer garbage collection?"         → DATABASE_SCHEMA.md
"Como começar?"                          → IMPLEMENTATION_SUMMARY.md
"Qual è o próximo passo?"                → IMPLEMENTATION_SUMMARY.md
"Como validar a instalação?"             → IMPLEMENTATION_SUMMARY.md

═════════════════════════════════════════════════════════════════════════════

🎯 RESUMO = "TL;DR"
═════════════════════════════════════════════════════════════════════════════

┌──────────────────────────────────────────────────────────────────────────┐
│ Access Token = JWT curto (15 min)                                        │
│ Refresh Token = String opaca longa (30 dias)                            │
│ Cada uso de RT revoga o anterior                                        │
│ Se RT revogado for usado → revoga TODA família                          │
│ Fingerprint valida contexto (UA + IP)                                   │
│ Cookie é HttpOnly + Secure + SameSite                                   │
│ BD nunca armazena plaintext (apenas SHA-256)                            │
│ Implementação completa, segura e documentada                            │
└──────────────────────────────────────────────────────────────────────────┘

═════════════════════════════════════════════════════════════════════════════

✨ PRONTO PARA USAR! 🚀

```
