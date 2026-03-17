# Frontend Configuration Endpoint

O frontend carrega configurações de autenticação do backend via `GET /auth/config`.

## Implementação Backend (Symfony)

Crie um novo controller para retornar configurações públicas de autenticação:

```php
// src/Controller/AuthConfigController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class AuthConfigController extends AbstractController
{
    #[Route('/auth/config', name: 'auth_config', methods: ['GET'])]
    public function getConfig(): JsonResponse
    {
        // Retorna apenas configurações públicas
        return $this->json([
            'googleClientId' => $_ENV['VITE_GOOGLE_CLIENT_ID'] ?? '',
        ]);
    }
}
```

## Variáveis de Ambiente

No seu `.env.local` (Backend):

```dotenv
VITE_GOOGLE_CLIENT_ID=433674454327-47ta9sqcamfl8nbv4u5dilmk0dvplv3r.apps.googleusercontent.com
```

**NÃO comitar credenciais no Git:**
- A chave secreta do Google (usada para validar tokens) deve estar APENAS no backend
- O `clientId` público pode estar no backend (servirá ao frontend sob demanda)

## Fluxo de Autenticação Seguro

1. Frontend carrega o `clientId` do backend via `/auth/config`
2. Frontend usa `clientId` para inicializar Google Sign-In no navegador
3. Usuário faz login no Google, recebe um JWT token
4. Frontend envia o JWT token para `POST /auth/google` (implementado)
5. **Backend valida o JWT usando a chave secreta** (nunca exposta ao frontend)
6. Backend retorna token de sessão JWT próprio

## Por que isso é mais seguro?

✅ Credenciais secretas do Google jamais são expostas ao frontend  
✅ `.env` do frontend está vazio (sem dados sensíveis)  
✅ Se a chave secreta mudar, não precisa redeployed frontend  
✅ Centraliza controle de autenticação no backend  

## Verificação

Teste localmente:

```bash
curl http://localhost:5173/ModFederation/api/auth/config
# Resposta esperada:
# {"googleClientId":"433674454327-..."}
```

Após implementar, o frontend vai:
1. Chamar `/auth/config` no `onMounted`
2. Usar `googleClientId` retornado para inicializar Google Sign-In
3. Não terá nenhuma referência local a `VITE_GOOGLE_CLIENT_ID`
