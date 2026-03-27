# Host App (OctoFlow)

## O que é
Aplicação host (Vue 3 + Vite) que orquestra autenticação, navegação e consumo de componentes remotos via Module Federation.

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

## Headers de identidade enviados pelo host

- `X-Tab-Id`
- `X-Browser-Id`
- `X-Browser-Fingerprint`

Esses headers são usados pelo backend para análise de contexto de sessão no fluxo de refresh token.
