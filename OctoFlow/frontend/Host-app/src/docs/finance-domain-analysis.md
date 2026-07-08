# Análise do Domínio Financeiro - OctoFlow

**Autor:** Analista Financeiro  
**Data:** 2026-07-07  
**Status:** Em revisão  
**Issue:** [GOD-49](/GOD/issues/GOD-49)

---

## Resumo Executivo

O módulo financeiro do OctoFlow é um sistema robusto de gestão financeira pessoal/profissional que abrange contas a pagar, contas a receber, recorrências, parcelamentos, investimentos e relatórios. O frontend implementa componentes Vue 3 com Composition API e Pinia para gerenciamento de estado.

---

## 1. Estrutura do Domínio

### 1.1 Componentes Frontend

| Componente | Caminho | Função |
|------------|---------|--------|
| `FinanceEntryFormModal.vue` | `src/components/finance/` | Modal de criação/edição de lançamentos |
| `FinanceInstallmentFormModal.vue` | `src/components/finance/` | Modal de criação/edição de parcelamentos |
| `FinanceRecurringFormModal.vue` | `src/components/finance/` | Modal de criação/edição de regras recorrentes |
| `FinanceSettlementFormModal.vue` | `src/components/finance/` | Modal de baixa de lançamentos |
| `FinanceTabsNavbar.vue` | `src/components/finance/` | Navegação entre abas do módulo financeiro |

### 1.2 Views

| View | Caminho | Função |
|------|---------|--------|
| `FinanceAccountsView.vue` | `src/views/finance/` | View principal de gestão de contas (64KB) |
| `FinanceBanksView.vue` | `src/views/finance/` | Gerenciamento de contas bancárias |
| `FinanceInvestmentsView.vue` | `src/views/finance/` | Gestão de investimentos |
| `FinanceReportsView.vue` | `src/views/finance/` | Relatórios financeiros (38KB) |
| `FinanceSettingsView.vue` | `src/views/finance/` | Configurações do módulo (28KB) |
| `FinanceLayout.vue` | `src/views/finance/` | Layout wrapper do módulo |

### 1.3 Stores e Services

| Arquivo | Caminho | Função |
|---------|---------|--------|
| `financeStore.js` | `src/stores/` | Store Pinia do domínio financeiro |
| `finance.js` | `src/services/` | Serviços de API do módulo financeiro |
| `financeInvestments.js` | `src/services/` | Serviços de API de investimentos |

---

## 2. Regras de Negócio Mapeadas

### 2.1 Direção Financeira

- **PAYABLE** (Pagar): Contas que o usuário deve pagar
- **RECEIVABLE** (Receber): Contas que o usuário deve receber

### 2.2 Tipos de Lançamento

- **ONE_OFF** (Avulso): Lançamento único
- **ADJUSTMENT** (Ajuste): Lançamento de ajuste
- **DEBT** (Dívida): Lançamento de dívida (apenas para PAYABLE)
- **RECURRING** (Recorrente): Gerado automaticamente por regra recorrente
- **INSTALLMENT** (Parcelamento): Gerado automaticamente por plano de parcelamento

### 2.3 Status dos Lançamentos

- **PENDING** (Pendente): Aguardando pagamento/recebimento
- **PARTIAL** (Parcial): Parcialmente pago/recebido
- **PAID** (Pago): Totalmente pago (apenas para PAYABLE)
- **RECEIVED** (Recebido): Totalmente recebido (apenas para RECEIVABLE)
- **CANCELED** (Cancelado): Lançamento cancelado

### 2.4 Tipos de Baixa

- **PAYMENT** (Pagamento): Baixa por pagamento
- **ADVANCE** (Adiantamento): Baixa por adiantamento
- **DISCOUNT** (Desconto): Baixa por desconto

### 2.5 Cálculos Financeiros

#### 2.5.1 Valores em BRL
- Todos os valores monetários são armazenados em BRL (Real Brasileiro)
- Precisão de 2 casas decimais (`Math.round(value * 100) / 100`)
- Formatação: `Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })`

#### 2.5.2 Cálculos de Progresso (Dashboard)
```javascript
// Progresso de Recebimento
progressPercent = (realizedIncomeAmount / expectedIncomeAmount) * 100

// Progresso de Pagamento
progressPercent = (realizedExpenseAmount / expectedExpenseAmount) * 100

// Valor Restante
remainingAmount = Math.max(0, expectedAmount - realizedAmount)
```

#### 2.5.3 Cálculos de Visão Geral
```javascript
// Total Geral
total = expectedIncomeAmount - expectedExpenseAmount
totalRealized = realizedIncomeAmount - realizedExpenseAmount
```

### 2.6 Regras de Recorrência

- Regras recorrentes geram lançamentos automaticamente
- Cada regra possui: direção, título, valor, dia do mês, tipo recorrente, categoria
- Tipos recorrentes excluem "mensal" (já tratado pelo sistema)
- Geração manual possível via `generateFinanceRecurringRuleManually`

### 2.7 Planos de Parcelamento

- Planos de parcelamento dividem valores em múltiplas parcelas
- Campos: valor total, entrada, número de parcelas, juros, desconto, multa
- Ajustes via `applyFinanceInstallmentPlanAdjustment`
- Apenas para direção PAYABLE

### 2.8 Investimentos

- Simulações de investimento com conversão para planos
- Planos de investimento com CRUD completo
- Endpoints específicos: `/finance/investment/simulations`, `/finance/investment/plans`

### 2.9 Moedas e Câmbio

- Suporte a múltiplas moedas
- Taxas de câmbio com atualização manual
- Endpoints: `/finance/currencies`, `/finance/currencies/rates`

### 2.10 Integração Open Finance

- Conexões com provedores de Open Finance
- Sincronização automática de dados financeiros
- Endpoints: `/finance/open-finance/providers`, `/finance/open-finance/connections`

---

## 3. Fluxos Financeiros

### 3.1 Fluxo de Lançamento

```
1. Usuário cria lançamento (ONE_OFF ou ADJUSTMENT)
2. Sistema valida: título, valor, vencimento, categoria
3. Lançamento criado com status PENDING
4. Usuário pode baixar (settlement)
5. Sistema atualiza status para PAID/RECEIVED
```

### 3.2 Fluxo de Baixa (Settlement)

```
1. Usuário seleciona lançamento para baixa
2. Sistema lista lançamentos disponíveis (remainingAmount > 0, status != PAID/RECEIVED/CANCELED)
3. Usuário preenche: tipo, valor, data, conta bancária
4. Sistema valida e registra baixa
5. Sistema atualiza status do lançamento
```

### 3.3 Fluxo de Recorrência

```
1. Usuário cria regra recorrente
2. Sistema agenda geração automática
3. Lançamentos gerados automaticamente no dia definido
4. Usuário pode gerar manualmente via generateManual
```

### 3.4 Fluxo de Parcelamento

```
1. Usuário cria plano de parcelamento
2. Sistema calcula parcelas (total, entrada, juros, desconto, multa)
3. Lançamentos gerados automaticamente
4. Usuário pode aplicar ajustes via applyAdjustment
```

### 3.5 Fluxo de Investimento

```
1. Usuário cria simulação de investimento
2. Sistema calcula projeções
3. Usuário converte simulação para plano
4. Sistema registra plano de investimento
```

---

## 4. Integrações e APIs

### 4.1 Endpoints Principais

| Endpoint | Método | Função |
|----------|--------|--------|
| `/finance/categories` | GET/POST/PATCH | Gerenciamento de categorias |
| `/finance/bank-accounts` | GET/POST/PATCH | Gerenciamento de contas bancárias |
| `/finance/entries` | GET/POST/PATCH/DELETE | CRUD de lançamentos |
| `/finance/entries/{id}/settlements` | POST | Registro de baixas |
| `/finance/recurring-types` | GET/POST/PATCH/DELETE | Tipos de recorrência |
| `/finance/recurring-rules` | GET/POST/PATCH/DELETE | Regras recorrentes |
| `/finance/installment-plans` | GET/POST/PATCH | Planos de parcelamento |
| `/finance/debt-plans` | GET/POST/DELETE | Planos de dívida |
| `/finance/currencies` | GET | Moedas disponíveis |
| `/finance/currencies/rates` | GET | Taxas de câmbio |
| `/finance/currencies/rates/manual` | POST | Criação manual de taxa |
| `/finance/dashboard/summary` | GET | Resumo do dashboard |
| `/finance/dashboard/cashflow` | GET | Fluxo de caixa |
| `/finance/dashboard/categories` | GET | Resumo por categorias |
| `/finance/exports` | GET/POST/DELETE | Exportação de dados |
| `/finance/migration/export` | GET | Snapshot de migração |
| `/finance/migration/import` | POST | Importação de migração |
| `/finance/investment/simulations` | POST | Simulações de investimento |
| `/finance/investment/plans` | GET/POST/PATCH | Planos de investimento |
| `/finance/open-finance/providers` | GET | Provedores Open Finance |
| `/finance/open-finance/connections` | GET/POST/DELETE | Conexões Open Finance |

### 4.2 Autenticação e Segurança

- Todas as requisições passam por `sessionStore.authRequest()`
- Proteção CSRF via `csrfActionId` em mutations
- Headers de autenticação via Bearer token

---

## 5. Store Pinia - Estado Global

### 5.1 Estado

```javascript
{
  // Catálogos
  categories: [],
  bankAccounts: [],
  recurringTypes: [],
  recurringRules: [],
  installmentPlans: [],
  currencies: [],
  currencyRates: [],
  
  // Dashboard
  dashboard: {
    summary: null,
    cashflow: [],
    categories: [],
  },
  
  // Lançamentos
  entries: [],
  entriesMeta: { page, itemsPerPage, total },
  entryFilters: { direction, status, search, startDate, endDate },
  
  // Loading states
  loading: {
    catalogs, bankAccounts, categories, entries,
    recurring, installmentPlans, currency, dashboard,
    investments, exports, migration
  },
  
  // Flags
  catalogsLoaded: false,
  dashboardLoaded: false,
  error: null,
}
```

### 5.2 Getters

- `activeBankAccounts`: Contas bancárias ativas
- `creditCardAccounts`: Contas de cartão de crédito
- `isCatalogsReady`: Verificação de carregamento dos catálogos

### 5.3 Actions Principais

- `loadCatalogs()`: Carrega catálogos base (categorias, contas, tipos)
- `loadDashboardData()`: Carrega dados do dashboard
- `loadEntries()`: Carrega lançamentos com filtros e paginação
- `loadCurrencyData()`: Carrega moedas e taxas
- `reloadCategories()`: Recarrega categorias após CRUD
- `reloadBankAccounts()`: Recarrega contas bancárias após CRUD
- `reloadRecurringData()`: Recarrega tipos e regras recorrentes
- `reloadInstallmentPlans()`: Recarrega planos de parcelamento

---

## 6. Impactos para Refatoração

### 6.1 Componentes Críticos

1. **FinanceAccountsView.vue** (64KB)
   - Maior componente do módulo
   - Contém lógica complexa de filtragem, paginação e modais
   - Recomendação: Decompor em componentes menores

2. **FinanceReportsView.vue** (38KB)
   - Componente complexo de relatórios
   - Recomendação: Separar lógica de cálculo dos componentes de UI

3. **FinanceSettingsView.vue** (28KB)
   - Configurações do módulo
   - Recomendação: Simplificar lógica de configuração

### 6.2 Lógica de Negócio Acoplada

- Cálculos de progresso e totais estão no componente `FinanceAccountsView.vue`
- Recomendação: Extrair para composables ou funções utilitárias

### 6.3 Formatação Monetária

- Formatação BRL duplicada em múltiplos componentes
- Recomendação: Criar composable `useCurrencyFormat`

### 6.4 Sanitização de Input

- Sanitização de input implementada em `utils/financeInputSanitizers.js`
- Recomendação: Padronizar uso em todos os formulários

### 6.5 Permissões

- Permissões financeiras via `useFinancePermissions`
- Recomendação: Manter centralizado e reutilizável

---

## 7. Riscos e Recomendações

### 7.1 Riscos Identificados

1. **Complexidade do FinanceAccountsView**
   - Componente com 2000+ linhas
   - Difícil manutenção e testes
   - Risco de bugs em refatoração

2. **Cálculos de negócio no frontend**
   - Validação de negócio deve ser feita no backend
   - Frontend deve apenas exibir e formatar

3. **Duplicação de formatação**
   - Formatação BRL repetida em vários componentes
   - Risco de inconsistências

### 7.2 Recomendações

1. **Extrair lógica de negócio para composables**
   - `useFinanceCalculations`: Cálculos de progresso, totais
   - `useCurrencyFormat`: Formatação monetária
   - `useFinanceFilters`: Lógica de filtragem

2. **Decompor FinanceAccountsView**
   - Separar em sub-componentes: Overview, Payable, Receivable
   - Extrair modais para componentes independentes

3. **Padronizar formatação**
   - Criar utilitário centralizado de formatação
   - Usar em todos os componentes

4. **Manter coerência com backend**
   - Validações de negócio no backend
   - Frontend apenas exibe e formata

---

## 8. Conclusão

O módulo financeiro do OctoFlow é robusto e bem estruturado, com cobertura completa de operações financeiras. A refatoração deve focar em:

1. Decomposição de componentes complexos
2. Extração de lógica de negócio para composables
3. Padronização de formatação e sanitização
4. Manutenção da coerência com regras de negócio do backend

---

**Próximos Passos:**
1. Revisão pelo CTO
2. Integração ao Guia de Refaturação do Frontend
3. Priorização das melhorias identificadas
