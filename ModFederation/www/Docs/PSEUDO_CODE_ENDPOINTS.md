# 🔐 Pseudo-Código Detalhado: Endpoints de Autenticação

## Endpoint 1: `POST /auth/login`

### Algoritmo Completo

```
ENTRADA:
  - email: string
  - password: string
  - request: HttpRequest (contém User-Agent, IP, etc)

SAÍDA:
  - 200 OK:
    {
      "token": "<JWT Access Token>",
      "token_type": "Bearer",
      "expires_in": 900,  // segundos
      "user": { id, email, roles, isActive }
    }
    Cookie: refresh_token=<plainToken> (HttpOnly, Secure, SameSite=Strict)
  
  - 400 Bad Request: Dados inválidos
  - 401 Unauthorized: Credenciais incorretas
  - 403 Forbidden: Conta desativada

PSEUDO-CÓDIGO:

1. VALIDAÇÃO DE ENTRADA
   ├─ Decodifica JSON do request body
   │  ├─ Se erro JSON → retorna 400 "Invalid JSON payload"
   │  └─ decoded = parse JSON
   │
   ├─ Extrai e normaliza email
   │  ├─ email = trim(decoded['email']).toLowerCase()
   │  ├─ Se vazio → retorna 400 "Email required"
   │  └─ Valida formato email (RFC 5322)
   │
   └─ Extrai senha
      ├─ password = decoded['password']
      ├─ Se vazio → retorna 400 "Password required"
      └─ ✓ Válido

2. BUSCA E VALIDAÇÃO DO USUÁRIO
   ├─ user = UserRepository.findOneByEmail(email)
   │  ├─ Se não encontrado → retorna 401 "Invalid credentials"
   │  └─ user encontrado
   │
   ├─ Verifica senha com hash
   │  ├─ passwordHasher.isPasswordValid(user, password)
   │  │  • Usa PBKDF2 ou bcrypt para comparação
   │  │  • Timing-safe comparison
   │  │
   │  ├─ Se falsa → retorna 401 "Invalid credentials"
   │  │  (mensagem genérica NÃO revela se email existe)
   │  │
   │  └─ ✓ Senha válida
   │
   └─ Verifica se conta está ativa
      ├─ if (!user.isActive) → retorna 403 "Account disabled"
      └─ ✓ Conta ativa

3. GERAR ACCESS TOKEN (JWT)
   ├─ payload = {
   │    sub: user.getId(),              // Subject (user ID)
   │    email: user.getEmail(),
   │    roles: user.getRoles(),
   │    iat: now(),                     // Issued At
   │    exp: now() + 900                // Expira em 15 minutos
   │  }
   │
   ├─ accessToken = JWTTokenManager.create(user)
   │  ├─ Assina com chave privada (RS256 ou HS256)
   │  ├─ Retorna string JWT codificada
   │  └─ ✓ JWT válido
   │
   └─ Armazena em variável para resposta

4. EMITIR REFRESH TOKEN
   ├─ Call: RefreshTokenManager.issue(user, request)
   │
   │  Dentro do método issue():
   │  
   │  a) Geração de Valores
   │     ├─ plainToken = bin2hex(random_bytes(64))  // 128 caracteres hex
   │     ├─ tokenHash = SHA256(plainToken)           // Para armazenamento
   │     ├─ createdAt = now()
   │     ├─ expiresAt = now() + 2592000 segundos    // 30 dias
   │     │
   │     └─ ✓ Valores gerados
   │
   │  b) Fingerprint de Contexto
   │     ├─ userAgent = request.headers['User-Agent']
   │     ├─ clientIp = extractClientIp(request)
   │     │  (tenta X-Forwarded-For → X-Real-IP → REMOTE_ADDR)
   │     │
   │     ├─ fingerprintHash = SHA256(userAgent + '|' + clientIp)
   │     ├─ userAgentHash = SHA256(userAgent)
   │     ├─ ipHash = SHA256(clientIp)
   │     │
   │     └─ ✓ Hashes gerados
   │
   │  c) Token Family (Primeira Emissão)
   │     ├─ tokenFamilyId = bin2hex(random_bytes(16))  // UUID
   │     ├─ parentTokenHash = null                      // Nenhum pai
   │     │
   │     └─ ✓ Família criada
   │
   │  d) Persistência no Banco
   │     ├─ refreshToken = new RefreshToken()
   │     │  • user = user
   │     │  • tokenHash = tokenHash
   │     │  • fingerprintHash = fingerprintHash
   │     │  • userAgentHash = userAgentHash
   │     │  • ipHash = ipHash
   │     │  • tokenFamilyId = tokenFamilyId
   │     │  • parentTokenHash = null
   │     │  • expiresAt = expiresAt
   │     │  • createdAt = createdAt
   │     │  • revokedAt = null
   │     │  • contextChangedAt = null
   │     │  • reuseDetectedAt = null
   │     │
   │     ├─ EntityManager.persist(refreshToken)
   │     ├─ EntityManager.flush()
   │     │
   │     └─ ✓ Token salvo no BD (apenas tokenHash, nunca plaintoken)
   │
   │  e) Retorno
   │     └─ return IssuedRefreshToken(user, plainToken, expiresAt)
   │        (IssuedRefreshToken é valor temporário, NÃO persiste!)
   │
   └─ ✓ Refresh token emitido

5. CONSTRUIR RESPOSTA JSON
   ├─ responseBody = {
   │    "token": accessToken,               // JWT
   │    "token_type": "Bearer",
   │    "expires_in": 900,                  // segundos
   │    "user": {
   │      "id": user.getId(),
   │      "email": user.getEmail(),
   │      "roles": user.getRoles(),
   │      "isActive": user.isActive()
   │    }
   │  }
   │
   └─ ✓ Response construído

6. PREPARAR COOKIE DE REFRESH TOKEN
   ├─ cookie = new Cookie('refresh_token')
   │  • value = issuedRefreshToken.plainToken  ← APENAS aqui é enviado plaintext!
   │  • path = '/'
   │  • expires = issuedRefreshToken.expiresAt
   │  • httpOnly = true                        ← Inacessível via JS (XSS safe)
   │  • secure = true                          ← HTTPS only (MITM safe)
   │  • sameSite = 'Strict'                    ← Mesmo site (CSRF safe)
   │
   └─ ✓ Cookie preparado

7. RETORNAR RESPOSTA AO CLIENT
   ├─ httpResponse.setContent(responseBody)
   ├─ httpResponse.setStatusCode(200)
   ├─ httpResponse.headers.setCookie(cookie)
   │
   └─ ✓ Enviado ao cliente

=============================================================================
FLUXO VISUAL:

Client                          Server
   │                             │
   ├──────POST /auth/login────→ │
   │    { email, password }      │
   │                             │ 1. Valida entrada
   │                             │ 2. Busca user
   │                             │ 3. Verifica senha (bcrypt)
   │                             │ 4. Gera JWT (AT)
   │                             │ 5. Gera plainToken (RT)
   │                             │ 6. Salva SHA256(plainToken) no BD
   │                             │ 7. Armazena fingerprint
   │                             │ 8. Cria token family
   │                             │
   │←──────200 OK──────────────│
   │    { token, user }          │
   │    Cookie: refresh_token=X  │
   │                             │
   │ ✓ Client armazena:         
   │   - AT em memória (RAM)    
   │   - RT em Cookie (auto)    

=============================================================================
```

---

## Endpoint 2: `POST /auth/refresh`

### Algoritmo Completo

```
ENTRADA:
  - Cookie: refresh_token=<plainToken>  (enviado automaticamente pelo navegador)
  - request: HttpRequest (contém User-Agent, IP, etc)

SAÍDA:
  - 200 OK:
    {
      "token": "<NEW JWT Access Token>",
      "token_type": "Bearer",
      "expires_in": 900,
      "user": { id, email, roles, isActive }
    }
    Cookie: refresh_token=<NEW plainToken> (HttpOnly, Secure, SameSite=Strict)
  
  - 401 Unauthorized: Token inválido/revogado/expirado
    Cookie: <cleared> (remove cookie)

PSEUDO-CÓDIGO:

1. EXTRAIR REFRESH TOKEN DO COOKIE
   ├─ refreshTokenCookie = request.cookies['refresh_token']
   │
   ├─ Validação:
   │  ├─ if (refreshTokenCookie == null) → retorna 401 "Cookie missing"
   │  ├─ if (refreshTokenCookie == '') → retorna 401 "Cookie empty"
   │  │
   │  └─ ✓ Cookie presente
   │
   └─ plainToken = refreshTokenCookie

2. ROTACIONAR TOKEN (Core da Segurança)
   ├─ Call: RefreshTokenManager.rotate(plainToken, request)
   │
   │  Dentro do método rotate():
   │  
   │  a) PASSO 1: Encontrar Token Atual
   │     ├─ now = DateTimeImmutable.now()
   │     ├─ tokenHash = SHA256(plainToken)  // Converte para hash
   │     │
   │     ├─ existingToken = RefreshTokenRepository.findByHash(tokenHash)
   │     │  (NOTE: findByHash() encontra MESMO SE REVOGADO)
   │     │
   │     ├─ if (existingToken == null) → retorna null
   │     │  (Token nunca existiu no BD)
   │     │
   │     └─ ✓ Token encontrado
   │
   │  b) PASSO 2: DETECÇÃO DE REUSO (CRÍTICO) 🚨
   │     ├─ if (existingToken.isRevoked()) {
   │     │    // ⚠️ Token revogado sendo reutilizado!
   │     │    // Isso indica que o cookie foi vazado/roubado
   │     │
   │     │  ├─ existingToken.setReuseDetectedAt(now)
   │     │  ├─ EntityManager.flush()
   │     │  │
   │     │  ├─ 🚨 ACIONADOR CRÍTICO:
   │     │  │  ├─ family_id = existingToken.getTokenFamilyId()
   │     │  │  ├─ family_tokens = RefreshTokenRepository.findByTokenFamilyId(family_id)
   │     │  │  │
   │     │  │  └─ Para cada token na família:
   │     │  │     ├─ if (!token.isRevoked()) {
   │     │  │     │    token.setRevokedAt(now)
   │     │  │     │  }
   │     │  │     └─ EntityManager.flush()
   │     │  │
   │     │  ├─ LOG CRÍTICO:
   │     │  │  "SECURITY ALERT: Token reuse detected"
   │     │  │  "User: %d, Family: %s, Time: %s, IP: %s, UA: %s"
   │     │  │
   │     │  ├─ NOTIFICAR: Envia email ao usuário
   │     │  │  "Acesso anormal em sua conta detectado"
   │     │  │  "Se não foi você, clique para confirmar segurança"
   │     │  │
   │     │  └─ return null  ← NEGA acesso
   │     │
   │     └─ } // Fim if revogado
   │
   │  c) PASSO 3: VALIDAÇÃO DE FINGERPRINT 🔍
   │     ├─ Extrai dados do request ATUAL
   │     │  ├─ currentUserAgent = request.headers['User-Agent']
   │     │  ├─ currentIp = extractClientIp(request)
   │     │  ├─ currentUserAgentHash = SHA256(currentUserAgent)
   │     │  └─ currentIpHash = SHA256(currentIp)
   │     │
   │     ├─ Compara com dados ARMAZENADOS
   │     │  ├─ stored_ua = existingToken.getUserAgentHash()
   │     │  ├─ stored_ip = existingToken.getIpHash()
   │     │  │
   │     │  └─ contextChange = assessContextChange(
   │     │       stored_ua, currentUserAgentHash,
   │     │       stored_ip, currentIpHash
   │     │     )
   │     │     // Retorna: 'none' | 'low' | 'medium' | 'high'
   │     │     //   none:   Contexto idêntico ✅
   │     │     //   low:    Novo device, mesmo IP ⚠️
   │     │     //   medium: Mesmo device, novo IP ⚠️
   │     │     //   high:   Ambos diferentes 🚨
   │     │
   │     ├─ if (contextChange == 'high') {
   │     │    // Mudança drástica - pode ser hacker
   │     │    // MAS não nega acesso ainda (risco de false positives)
   │     │
   │     │    ├─ existingToken.setContextChangedAt(now)
   │     │    ├─ EntityManager.flush()
   │     │    │
   │     │    ├─ LOG WARNING:
   │     │    │  "Unusual context change detected"
   │     │    │  "User: %d, Old_UA: ..., New_UA: ..."
   │     │    │  "IP: %s → %s"
   │     │    │
   │     │    └─ // Continua rotação mas com registro
   │     │
   │     └─ }
   │
   │  d) PASSO 4: ROTAÇÃO (Revoga Atual + Emite Novo)
   │     ├─ Revoga token atual
   │     │  ├─ existingToken.setRevokedAt(now)
   │     │  ├─ EntityManager.flush()
   │     │  │
   │     │  └─ LOG INFO: "Token rotated"
   │     │
   │     └─ Emite novo token na MESMA FAMÍLIA
   │        ├─ issuedRefreshToken = issue(
   │        │    user,
   │        │    request,
   │        │    parentTokenHash = existingToken.getTokenHash()
   │        │  )
   │        │
   │        │  Dentro de issue():
   │        │  
   │        │  i) Geração de Valores
   │        │     ├─ newPlainToken = bin2hex(random_bytes(64))
   │        │     ├─ newTokenHash = SHA256(newPlainToken)
   │        │     ├─ newExpiresAt = now() + 2592000 segundos
   │        │     │
   │        │     └─ ✓ Novos valores
   │        │
   │        │  ii) Fingerprint do NOVO Contexto
   │        │      ├─ newUserAgent = request.headers['User-Agent']
   │        │      ├─ newClientIp = extractClientIp(request)
   │        │      ├─ newFingerprintHash = SHA256(newUA + '|' + newIP)
   │        │      ├─ newUserAgentHash = SHA256(newUA)
   │        │      ├─ newIpHash = SHA256(newIP)
   │        │      │
   │        │      └─ ✓ Novos hashes
   │        │
   │        │  iii) Família de Tokens
   │        │       ├─ if (parentTokenHash != null) {
   │        │       │    tokenFamilyId = getTokenFamilyIdByParent(parentTokenHash)
   │        │       │    // Reutiliza MESMA família
   │        │       │  }
   │        │       │
   │        │       ├─ Novo token terá:
   │        │       │  • tokenFamilyId = existingToken.tokenFamilyId
   │        │       │  • parentTokenHash = existingToken.tokenHash
   │        │       │  // Forma uma CORRENTE de rotações
   │        │       │
   │        │       └─ ✓ Família mantida
   │        │
   │        │  iv) Persistência
   │        │      ├─ newRefreshToken = new RefreshToken()
   │        │      │  • user = user
   │        │      │  • tokenHash = newTokenHash
   │        │      │  • fingerprintHash = newFingerprintHash
   │        │      │  • userAgentHash = newUserAgentHash
   │        │      │  • ipHash = newIpHash
   │        │      │  • tokenFamilyId = tokenFamilyId
   │        │      │  • parentTokenHash = parentTokenHash
   │        │      │  • expiresAt = newExpiresAt
   │        │      │  • createdAt = now
   │        │      │  • revokedAt = null
   │        │      │  • contextChangedAt = null
   │        │      │  • reuseDetectedAt = null
   │        │      │
   │        │      ├─ EntityManager.persist(newRefreshToken)
   │        │      ├─ EntityManager.flush()
   │        │      │
   │        │      └─ ✓ Novo token em BD
   │        │
   │        │  v) Retorno
   │        │     └─ return IssuedRefreshToken(
   │        │          user,
   │        │          newPlainToken,  ← Enviado ao client
   │        │          newExpiresAt
   │        │        )
   │        │
   │        └─ ✓ Token emitido
   │
   └─ return issuedRefreshToken  ← Se tudo OK

3. VALIDAR RESULTADO DA ROTAÇÃO
   ├─ if (issuedRefreshToken == null) {
   │    // Algo falhou: token inválido/revogado/reuso detectado
   │
   │    ├─ cleanCookie = new Cookie('refresh_token')
   │    │  • value = ''
   │    │  • expires = now() - 1 day  (força limpeza)
   │    │
   │    ├─ httpResponse.headers.setCookie(cleanCookie)
   │    │  (Remove cookie do navegador do cliente)
   │    │
   │    ├─ return 401 JsonResponse {
   │    │    "message": "Invalid or expired refresh token"
   │    │  }
   │    │
   │    └─ Client deve fazer LOGIN novamente
   │
   └─ } else {
      ✓ Rotação bem-sucedida, continua...
      }

4. GERAR NOVO ACCESS TOKEN (JWT)
   ├─ newAccessToken = JWTTokenManager.create(issuedRefreshToken.user)
   │  ├─ payload = {
   │  │    sub: user.getId(),
   │  │    email: user.getEmail(),
   │  │    roles: user.getRoles(),
   │  │    iat: now(),
   │  │    exp: now() + 900  ← 15 minutos novo
   │  │  }
   │  │
   │  ├─ Assina com chave privada
   │  └─ ✓ JWT gerado
   │
   └─ Armazena em variável para resposta

5. CONSTRUIR RESPOSTA JSON
   ├─ responseBody = {
   │    "token": newAccessToken,  ← NOVO JWT
   │    "token_type": "Bearer",
   │    "expires_in": 900,
   │    "user": {
   │      "id": user.getId(),
   │      "email": user.getEmail(),
   │      "roles": user.getRoles(),
   │      "isActive": user.isActive()
   │    }
   │  }
   │
   └─ ✓ Response construído

6. PREPARAR NOVO COOKIE DE REFRESH TOKEN
   ├─ newCookie = new Cookie('refresh_token')
   │  • value = issuedRefreshToken.plainToken  ← NOVO plainToken
   │  • path = '/'
   │  • expires = issuedRefreshToken.expiresAt
   │  • httpOnly = true                        ← XSS safe
   │  • secure = true                          ← HTTPS only
   │  • sameSite = 'Strict'                    ← CSRF safe
   │
   └─ ✓ Novo cookie preparado

7. RETORNAR RESPOSTA AO CLIENT
   ├─ httpResponse.setContent(responseBody)
   ├─ httpResponse.setStatusCode(200)
   ├─ httpResponse.headers.setCookie(newCookie)  ← ATUALIZA cookie
   │
   └─ ✓ Enviado ao cliente

=============================================================================
FLUXO VISUAL:

Client                          Server
   │                             │
   ├─────POST /auth/refresh─────→│
   │   (Cookie: refresh_token=X)  │
   │                             │ 1. Extrai RT do cookie
   │                             │ 2. Valida: existe?
   │                             │ 3. Valida: revogado? (REUSO DETECT)
   │                             │ 4. Valida: fingerprint mudou?
   │                             │ 5. Revoga RT_ATUAL
   │                             │ 6. Gera novo RT_NOVO
   │                             │ 7. Gera novo AT (JWT)
   │                             │ 8. Armazena tudo no BD
   │                             │
   │←─────200 OK─────────────────│
   │    { token (novo), user }    │
   │    Cookie: refresh_token=Y   │ (NOVO plainToken)
   │                             │
   │ ✓ Client atualiza:         
   │   - AT em memória (novo)   
   │   - RT em Cookie (novo)    
   │   - Ambos criados há pouco  

=============================================================================
CASOS DE ERRO:

ERRO 1: Cookie Faltando
└─Client não envia Cookie refresh_token
   → Server retorna 401 "Cookie missing"
   → Client deve fazer LOGIN novamente

ERRO 2: Token Inválido
└─plainToken não existe no BD
   → Server retorna 401 "Invalid token"
   → Client remove cookie localmente
   → Client deve fazer LOGIN novamente

ERRO 3: Token Revogado (primeira, uso normal)
└─Token foi revogado normalmente em rotação anterior
   → Deveria ter sido substituído
   → Server retorna 401 (ou cliente deveria ter novo)

ERRO 4: Token Revogado (reuso detectado) 🚨
└─Token foi revogado E agora está tentando ser reutilizado
   → ALERTA CRÍTICO acionado
   → Toda família revogada
   → Server retorna 401
   → Email enviado ao usuário
   → Logs de auditoria criados

ERRO 5: Token Expirado
└─expiresAt <= now
   → Repository.findByHash() retorna null (filtro expiração)
   → Server retorna 401
   → Client deve fazer LOGIN novamente

ERRO 6: Contexto Mudou Drasticamente
└─Novo User-Agent E novo IP
   → contextChange = 'high'
   → contextChangedAt registrado
   → LOG WARNING enviado
   → ⚠️ MAS rotação ainda ocorre
   → (Client legítimo que viajou não é negado)

=============================================================================
```

---

## Quadro Comparativo: Login vs Refresh

```
┌─────────────────────┬────────────────────────┬─────────────────────────┐
│ Aspecto             │ LOGIN                  │ REFRESH                 │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ URL                 │ POST /auth/login       │ POST /auth/refresh      │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Entrada             │ email, password        │ Cookie: refresh_token   │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Autenticação        │ Validação de senha     │ Validação de token      │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Access Token        │ Gera novo (JWT)        │ Gera novo (JWT)         │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Refresh Token       │ Gera novo + família    │ Rotaciona (revoga+novo) │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Fingerprint         │ Cria (primeira vez)    │ Valida + renova         │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Token Family        │ tokenFamilyId = novo   │ tokenFamilyId = reusa   │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Parent Token        │ parentTokenHash = null │ parentTokenHash = atual  │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Detecção Reuso      │ N/A                    │ if (revoked) alert ⚠️   │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Cascata Revogação   │ N/A                    │ Revoga família inteira   │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Status Normal       │ 200 OK                 │ 200 OK                  │
├─────────────────────┼────────────────────────┼─────────────────────────┤
│ Status Erro         │ 400, 401, 403          │ 401 + clear cookie      │
└─────────────────────┴────────────────────────┴─────────────────────────┘
```

---

## Sumário de Segurança

```
┌─────────────────────────────────────────────────────────────────┐
│ Camada 1: ARMAZENAMENTO                                         │
├─────────────────────────────────────────────────────────────────┤
│ ✅ Token NUNCA em plaintext                                     │
│    BD armazena: SHA256(<token>)                                │
│    Se DB vazar, tokens seguros                                │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ Camada 2: TRANSMISSÃO (HTTP)                                    │
├─────────────────────────────────────────────────────────────────┤
│ Access Token:  Header Authorization: Bearer <JWT>              │
│                ✅ HTTPS only (Transport Layer Security)         │
│                                                                 │
│ Refresh Token: Cookie HttpOnly, Secure, SameSite=Strict       │
│                ✅ Automático (navegador envia sempre)          │
│                ✅ Inacessível via JS (XSS safe)               │
│                ✅ HTTPS only (MITM safe)                       │
│                ✅ Mesmo site (CSRF safe)                       │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ Camada 3: VALIDAÇÃO                                             │
├─────────────────────────────────────────────────────────────────┤
│ ✅ Access Token: Assinatura criptográfica (RS256)              │
│    Falseação detectada imediatamente                           │
│                                                                 │
│ ✅ Refresh Token: Hash-based + Fingerprint                     │
│    Comparação timing-safe (hash_equals)                        │
│    Detecta reuso e contexto                                    │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ Camada 4: CICLO DE VIDA                                         │
├─────────────────────────────────────────────────────────────────┤
│ ✅ Expiração: AT 15 min, RT 30 dias                            │
│ ✅ Rotação: Cada refresh revoga anterior                       │
│ ✅ Cascata: Reuso detectado revoga TODA família                │
│ ✅ Fingerprint: Mudança drástica registrada e investigada      │
│ ✅ Garbage Collection: Tokens expirados deletados              │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ Camada 5: AUDITORIA                                             │
├─────────────────────────────────────────────────────────────────┤
│ ✅ Logs: Cada rotação registrada                               │
│ ✅ Alerta: Reuso detectado notificado                          │
│ ✅ Alerta: Contexto anormal marcado                            │
│ ✅ Email: Usuário notificado de acessos suspeitos              │
│ ✅ Timeline: Corrente de parentTokenHash rastreável            │
└─────────────────────────────────────────────────────────────────┘
```

---

## Resumo Executivo

```
╔════════════════════════════════════════════════════════════════════╗
║                 ARQUITETURA DE AUTENTICAÇÃO                        ║
║                 (Google-Inspired Pattern)                          ║
╠════════════════════════════════════════════════════════════════════╣
║                                                                    ║
║  1. LOGIN                                                          ║
║     ├─ User envia email + senha                                   ║
║     ├─ Server valida credenciais (bcrypt)                        ║
║     ├─ Gera Access Token (JWT, 15 min)                           ║
║     └─ Gera Refresh Token (String opaca, 30 dias)                ║
║        • Armazena SHA256(RT) no BD                               ║
║        • Cria Family ID para rastreabilidade                     ║
║        • Armazena Fingerprint (UA + IP hash)                     ║
║                                                                    ║
║  2. REQUISIÇÃO AUTENTICADA                                        ║
║     ├─ Client envia AT no header Authorization                   ║
║     ├─ Server valida assinatura JWT                             ║
║     └─ Se AT válido → requisição processada ✅                  ║
║                                                                    ║
║  3. AT EXPIRA                                                      ║
║     ├─ Client recebe 401 Unauthorized                            ║
║     ├─ Client envia RT (via Cookie automático)                   ║
║     ├─ Server rotaciona RT                                       ║
║     │  ├─ Valida: revogado? (reuso detect?) ← CRÍTICO           ║
║     │  ├─ Valida: fingerprint mudou drasticamente?              ║
║     │  ├─ Revoga RT anterior                                     ║
║     │  └─ Emite novo RT (mesma família)                         ║
║     └─ Client recebe novo AT + novo RT                          ║
║                                                                    ║
║  4. REUSO DETECTADO 🚨                                             ║
║     ├─ Hacker consegue RT (XSS, cookie theft)                    ║
║     ├─ Tenta usar RT revogado                                    ║
║     ├─ Server detecta: "revoked being reused"                    ║
║     ├─ Revoga TODA a família                                     ║
║     ├─ Notifica user com email                                   ║
║     ├─ Força novo login                                          ║
║     └─ User seguro ✅ (hacker bloqueado)                        ║
║                                                                    ║
║  PROTEÇÕES ATIVADAS:                                              ║
║  ✅ Token Rotation    Reutilização impossível                     ║
║  ✅ Reuse Detection   Vazamento detectado em 1s                 ║
║  ✅ Family Cascade    Revogação em cadeia                        ║
║  ✅ Fingerprint       Mudança suspeita registrada               ║
║  ✅ Cookie Security   HttpOnly + Secure + SameSite             ║
║  ✅ Hash Protection   DB leak não rouba tokens                   ║
║  ✅ Audit Trail       Toda atividade registrada                  ║
║                                                                    ║
╚════════════════════════════════════════════════════════════════════╝
```

