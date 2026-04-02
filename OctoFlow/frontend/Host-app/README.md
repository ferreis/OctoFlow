# Host App (OctoFlow)

## O que é
Aplicação host (Vue 3 + Vite) que orquestra autenticação, navegação e consumo de componentes remotos via Module Federation.

## Rotas do host

- Públicas:
  - `/auth/login`
  - `/auth/register`
  - `/auth/google-password-setup`
- Protegidas:
  - `/app/dashboard`
  - `/app/tasks`
  - `/app/finance/:section?`
  - `/app/profile`
  - `/app/test`
- Compatibilidade (redirecionamento):
  - `/dashboard`, `/tasks`, `/finance`, `/profile`

## Requisitos

- Node.js 20+
- npm

## Scripts

- `npm run dev` - sobe em `http://localhost:5173`
- `npm run build` - build de produção
- `npm run preview` - preview local do build
- `npm run lint` - validação de lint

## Variáveis importantes

- `VITE_API_BASE_URL` (default: `/OctoFlow/api`)
- `VITE_API_PROXY_TARGET` (default: `https://nginx:443`)
- `VITE_REMOTE_APP_URL` (fallback do remote em ambiente local)

## Autenticação (resumo)

- Front envia `access_token` no header `Authorization`.
- Se backend responder `401`, front chama `/auth/refresh`.
- Refresh retorna novo `access_token` e novo `refresh_token`.
- Request original é reenviada uma vez.
- Guardas de rota são apenas UX; autorização real sempre no backend (`401/403`).

## Contrato de serviços frontend

- Services em `src/services/*` não recebem mais `request` por parâmetro.
- Toda chamada usa pipeline central da store de sessão (auth + CSRF + refresh).

## Headers de identidade enviados pelo host

- `X-Tab-Id`
- `X-Browser-Id`
- `X-Browser-Fingerprint`

Esses headers são usados pelo backend para análise de contexto de sessão no fluxo de refresh token.
