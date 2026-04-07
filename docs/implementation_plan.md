# Plano de Melhoria Completa — Módulo Financeiro OctoFlow

## Diagnóstico do Estado Atual

O `FinanceScreen.vue` é um monolito de **4.710 linhas** contendo:
- **~3.170 linhas de `<script>`** — 45+ variáveis reativas, 30+ funções, 15+ computed properties
- **~1.540 linhas de `<template>`** — 5 tabs com 15+ painéis renderizados via `v-if`
- **0 linhas de `<style>`** — migrado para `finance-components.css`  
- **55+ imports** de services diretos no componente

### Problemas Identificados

| # | Problema | Impacto |
|---|---|---|
| 1 | **Monolito de 4.7K linhas** — todo estado, lógica e UI em um único arquivo | Manutenção impossível, debug doloroso |
| 2 | **Navegação via `v-if`** — 5 tabs sem URL, sem deep-link, sem lazy loading | Toda lógica carrega junto, sem bookmark |
| 3 | **Estado descentralizado** — `financeStore` existe mas quase não é usado | O componente mantém 45+ refs/reactives locais |
| 4 | **Estilos inline sobreviventes** — Migração JSON ainda usa `style=""` hardcoded | Quebra o design system |
| 5 | **Tab "Dívidas"** — duplica conceito de Parcelamento com complexidade extra | Confusão para o usuário |
| 6 | **Sem loading states visuais** — apenas texto "Atualizando..." | UX pobre |
| 7 | **Modais inline no template** — 250+ linhas de modal no final do componente | Acoplamento forte |

---

## Mudanças Propostas

### 1. Remoção da Tab "Dívidas"

> [!IMPORTANT]
> Confirmar: remover completamente toda a lógica de `debtPlans`, `debtForm`, `debtPreview` e os services `createFinanceDebtPlan`, `deleteFinanceDebtPlan`, `previewFinanceDebtPlan`?

A funcionalidade de dívidas será **absorvida pelo Parcelamento** (que já faz exatamente a mesma coisa — entrada + N parcelas). Isso remove:
- Sub-tab "Dívidas" em Contas
- ~400 linhas de template (painel de dívidas + formulário + preview)
- ~200 linhas de script (estado + funções de debt)
- 3 imports de service

---

### 2. Fragmentação do Monolito em Componentes

O `FinanceScreen.vue` será reduzido a um **shell de roteamento** (~100 linhas) e a lógica será distribuída:

#### Novos Componentes (Views de Rota)

| Arquivo | Responsabilidade | Linhas ~est. |
|---|---|---|
| `views/finance/FinanceLayout.vue` | Shell com tab nav + `<router-view>` | ~80 |
| `views/finance/FinanceReportsView.vue` | KPI cards + tabelas de cashflow + categorias | ~350 |
| `views/finance/FinanceAccountsView.vue` | Sub-tabs (Visão Geral / Pagar / Receber) + listagem | ~300 |
| `views/finance/FinanceBanksView.vue` | CRUD de contas bancárias | ~250 |
| `views/finance/FinanceInvestmentsView.vue` | CRUD + simulação de investimentos | ~350 |
| `views/finance/FinanceSettingsView.vue` | Categorias + fontes + migração + cotações | ~400 |

#### Novos Componentes Compartilhados

| Arquivo | Responsabilidade |
|---|---|
| `components/finance/FinanceOverviewPanel.vue` | Resumo rápido pagar × receber |
| `components/finance/FinanceEntryFormModal.vue` | Modal de criar/editar lançamento |
| `components/finance/FinanceSettlementFormModal.vue` | Modal de baixa de lançamento |
| `components/finance/FinanceRecurringFormModal.vue` | Modal de regra recorrente |
| `components/finance/FinanceInstallmentFormModal.vue` | Modal de parcelamento |
| `components/finance/FinanceRenegotiationFormModal.vue` | Modal de renegociação |
| `components/finance/FinanceExportPanel.vue` | Exportação CSV + migração JSON |
| `components/finance/FinanceCurrencyPanel.vue` | Cotações de moeda |
| `components/finance/FinanceCategoryPanel.vue` | CRUD de categorias |
| `components/finance/FinanceSimulationPanel.vue` | Simulação de investimento |

---

### 3. Rotas Aninhadas (vue-router)

#### [MODIFY] [index.js](file:///home/ferreis/Documentos/OctoFlow/OctoFlow/frontend/Host-app/src/router/index.js)

```javascript
{
  path: 'finance',
  component: FinanceLayout,
  children: [
    { path: '',           redirect: { name: 'finance-accounts' } },
    { path: 'accounts',   name: 'finance-accounts',    component: () => import('../views/finance/FinanceAccountsView.vue') },
    { path: 'banks',      name: 'finance-banks',       component: () => import('../views/finance/FinanceBanksView.vue') },
    { path: 'investments', name: 'finance-investments', component: () => import('../views/finance/FinanceInvestmentsView.vue') },
    { path: 'settings',   name: 'finance-settings',    component: () => import('../views/finance/FinanceSettingsView.vue') },
    { path: 'reports',    name: 'finance-reports',      component: () => import('../views/finance/FinanceReportsView.vue') },
  ],
},
```

- Cada view é **lazy-loaded** — só carrega quando acessada
- URLs com deep-link: `/app/finance/reports` funciona direto
- Preserva lifecycle hooks nativos do Vue (`onMounted`, `onUnmounted`)
- Mantém compatibilidade com redirecionamentos legados existentes

---

### 4. Centralização de Estado no Pinia (financeStore)

#### [MODIFY] [financeStore.js](file:///home/ferreis/Documentos/OctoFlow/OctoFlow/frontend/Host-app/src/stores/financeStore.js)

O store será expandido para gerenciar **todo** o estado compartilhado:

```javascript
// Estado centralizado
state: () => ({
  // Catálogos (carregados uma vez, usados por todas as views)
  categories: [],
  bankAccounts: [],
  recurringTypes: [],

  // Dashboard / KPIs
  dashboard: { summary: null, cashflow: [], categories: [] },

  // Lançamentos
  entries: { items: [], meta: { page: 1, itemsPerPage: 10, total: 0 } },
  entryFilters: { direction: '', status: '', search: '', startDate: '', endDate: '' },

  // Loading states centralizados
  loading: { dashboard: false, entries: false, catalogs: false, ... },

  // Flags de inicialização
  catalogsLoaded: false,
}),
```

**Benefícios:**
- Categorias e bancos carregam **uma vez** e ficam disponíveis em todas as views
- Trocar de tab não recarrega dados desnecessariamente
- Qualquer modal pode acessar `categories` / `bankAccounts` sem prop drilling

---

### 5. Padronização Visual (eliminar inline styles restantes)

#### Arquivos afetados:
- Migração JSON (linhas 4352-4403) — ainda usa `style=""` hardcoded
- Modais (linhas 4407-4700) — usa classes globais `app-modal-*` mas com mix de inline

#### Novas classes em `finance-components.css`:
```css
.finance-migration-grid { ... }      /* Grid exportar/importar */
.finance-migration-card { ... }      /* Card individual */
.finance-field-hint { ... }          /* Hint abaixo do input */
.finance-settlement-toggle-field { ... }
```

---

### 6. Melhorias de UX

| Melhoria | Descrição |
|---|---|
| **Skeleton Loaders** | Substituir "Atualizando..." por skeletons animados nos painéis |
| **Debounce na busca** | Input de busca em lançamentos com 300ms de debounce |
| **Atalhos de teclado** | `Ctrl+N` abre modal de novo lançamento, `Esc` fecha modal |
| **Transições de rota** | `<transition>` suave entre as views financeiras |
| **Toast de ações** | Feedback visual claro após salvar/excluir (já funciona via `notifyUser`) |
| **Badges contadores** | Mostrar contagem de itens pendentes nas tabs |

---

## Ordem de Execução

| Fase | O que | Risco |
|---|---|---|
| **1** | Expandir `financeStore.js` com catálogos + loading states | Baixo |
| **2** | Criar `FinanceLayout.vue` + configurar rotas aninhadas | Médio |
| **3** | Extrair `FinanceReportsView.vue` (tab mais independente) | Baixo |
| **4** | Extrair `FinanceBanksView.vue` | Baixo |
| **5** | Extrair `FinanceSettingsView.vue` | Baixo |
| **6** | Extrair `FinanceInvestmentsView.vue` | Baixo |
| **7** | Extrair `FinanceAccountsView.vue` + remover Dívidas | Médio |
| **8** | Extrair modais em componentes isolados | Baixo |
| **9** | Padronizar inline styles restantes | Baixo |
| **10** | Melhorias de UX (skeletons, debounce, transições) | Baixo |
| **11** | Deletar o monolito `FinanceScreen.vue` original | — |

---

## Open Questions

> [!IMPORTANT]
> **1. Dívidas → Parcelamento**: Confirma que posso remover completamente o módulo de dívidas? Existem dados de dívidas salvos no backend que precisam de migração?

> [!WARNING]  
> **2. Open Finance**: Vi imports de `fetchFinanceOpenFinanceConnections`, `syncFinanceOpenFinanceConnection`, etc. Esses providers/connections são usados ativamente ou podem ser removidos também?

> [!IMPORTANT]
> **3. Cotações de Moeda**: As cotações ficam em Settings atualmente. Faz mais sentido mover para um lugar diferente ou manter lá?

> [!NOTE]
> **4. Exportação XLSX vs CSV**: Vi que o painel diz "Nova exportação XLSX" mas o botão gera CSV. Qual é o formato real? Devo padronizar?

---

## Verificação

### Build
```bash
npx vite build  # deve compilar sem erros
```

### Funcional (manual)
- Navegar entre todas as tabs pela URL direta (`/app/finance/reports`, etc.)
- Criar/editar/excluir lançamento, recorrência, parcelamento
- Filtrar e paginar lançamentos
- Exportar CSV e migrar JSON
- Trocar de tema e verificar que tudo segue as variáveis CSS
- Testar em viewport mobile (< 768px)

### Regressão
- Verificar que o Module Federation continua funcionando (RemoteFinanceKpiCard, etc.)
- Testar deep-links: `/app/finance/settings` deve abrir direto na aba correta
- HMR funcional em dev
