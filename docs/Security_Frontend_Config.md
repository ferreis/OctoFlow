# Frontend Configuration Endpoint

O frontend carrega configuracoes de autenticacao do backend via `GET /auth/config`.

## Implementacao Backend (Symfony)

Controller que retorna configuracoes publicas de autenticacao:

```php
// src/Controller/AuthConfigController.php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auth/config', name: 'auth_config', methods: ['GET'])]
final class AuthConfigController
{
    public function __construct(
        #[Autowire('%env(string:GOOGLE_OAUTH_CLIENT_ID)%')]
        private readonly string $googleClientId,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'googleClientId' => $this->googleClientId,
        ]);
    }
}
```

Observacoes sobre a implementacao real:
- Usa `#[Autowire]` para injetar a variavel de ambiente (nao `$_ENV`)
- Classe `final` com invocacao `__invoke` (single action controller)
- Nao herda de `AbstractController`
- Retorna `JsonResponse` diretamente (nao `$this->json()`)

## Variaveis de Ambiente

No `www/.env`:

```dotenv
GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_ALLOWED_HD=
```

No `www/.env.local` (ambiente de desenvolvimento):

```dotenv
GOOGLE_OAUTH_CLIENT_ID=433674454327-47ta9sqcamfl8nbv4u5dilmk0dvplv3r.apps.googleusercontent.com
GOOGLE_OAUTH_ALLOWED_HD=
```

**NAO comitar credenciais no Git:**
- A chave secreta do Google (usada para validar tokens) deve estar APENAS no backend
- O `clientId` publico pode estar no backend (servira ao frontend sob demanda)
- A variavel chama-se `GOOGLE_OAUTH_CLIENT_ID` (nao `VITE_GOOGLE_CLIENT_ID`)

## Controle de Acesso

No `security.yaml`:

```yaml
access_control:
    - { path: ^/auth/config$, roles: PUBLIC_ACCESS }
```

O endpoint e publico e nao exige autenticacao.

## Fluxo de Autenticacao Seguro

1. Frontend carrega o `clientId` do backend via `GET /auth/config`
2. Frontend usa `clientId` para inicializar Google Sign-In no navegador
3. Usuario faz login no Google, recebe um ID Token (JWT)
4. Frontend envia o ID Token para `POST /auth/google` com campo `credential`
5. **Backend valida o ID Token usando `GoogleIdentityVerifier`** (nunca expoe a chave secreta)
6. Backend retorna Access Token (JWT) proprio + cookie refresh_token

## Por que isso e mais seguro?

- Credenciais secretas do Google jamais sao expostas ao frontend
- `.env` do frontend nao contem dados sensiveis de autenticacao
- Se a chave secreta mudar, nao precisa redeploy no frontend
- Centraliza controle de autenticacao no backend

## Verificacao

Teste localmente:

```bash
# Via Vite dev proxy
curl http://localhost:5173/OctoFlow/api/auth/config
# Resposta esperada:
# {"googleClientId":"433674454327-..."}

# Via Nginx HTTPS (porta 4481)
curl -k https://localhost:4481/OctoFlow/api/auth/config
```

Apos implementar, o frontend vai:
1. Chamar `/auth/config` no `onMounted`
2. Usar `googleClientId` retornado para inicializar Google Sign-In
3. Nao tera nenhuma referencia local a credenciais Google
