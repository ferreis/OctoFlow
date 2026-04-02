# Components App (Remote - OctoFlow)

## O que é
Aplicação remota (Vue 3 + Vite) que expõe componentes compartilhados para o host via Module Federation.

## Requisitos

- Node.js 20+
- npm

## Scripts

- `npm run dev` - sobe em `http://localhost:5175`
- `npm run build` - build de produção
- `npm run preview` - preview local do build
- `npm run lint` - validação de lint

## Papel do remote

- Renderizar UI compartilhada.
- Receber dados/callbacks prontos do host.
- Não assumir autenticação/sessão/orquestração principal.
- Pode usar Pinia apenas para estado interno local de componentes/preview.
- Pode usar Vue Router apenas no shell interno de preview (sem fluxo de negócio federado).

## Exposições principais

- `./TaskCrudPanel`
- `./GithubWorkspacePanel`
- `./GithubWorkspaceSummaryCard`
- `./GithubIssueComposerPanel`
- `./GithubProjectsCard`
- `./MarkdownPreview`
- `./MultiSelect`
- `./IssueTemplateForm`
- `./IssueTemplatePreview`
- `./FinanceKpiCard`
- `./FinanceStatusBadge`
- `./FinanceEmptyState`
- `./FinanceTrendMiniChart`
