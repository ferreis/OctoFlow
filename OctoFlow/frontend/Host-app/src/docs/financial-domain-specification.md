# Especificação do Domínio Financeiro - OctoFlow

## Resumo

O módulo financeiro do OctoFlow é um sistema completo para gestão financeira pessoal e empresarial, incluindo dashboard com KPIs, gerenciamento de contas bancárias, categorias, lançamentos (entries), regras recorrentes, planos de parcelamento, investimentos, moedas e taxas de câmbio.

## Arquitetura

### Componentes Principais

| Componente | Localização | Responsabilidade |
|------------|-------------|------------------|
| `financeStore.js` | `Host-app/src/stores/` | Estado centralizado e ações do módulo |
| `finance.js` | `Host-app/src/services/` | Integração com API backend |
| `FinanceLayout.vue` | `Host-app/src/views/finance/` | Layout principal do módulo |
| `FinanceAccountsView.vue` | `Host-app/src/views/finance/` | Gestão de contas e lançamentos |
| `FinanceBanksView.vue` | `Host-app/src/views/finance/` | Gestão de contas bancárias |
| `FinanceInvestmentsView.vue` | `Host-app/src/views/finance/` | Investimentos e simulações |
| `FinanceReportsView.vue` | `Host-app/src/views/finance/` | Relatórios e exportações |
| `FinanceSettingsView.vue` | `Host-app/src/views/finance/` | Configurações (categorias, tipos, moedas) |

### Componentes Remotos (Components-app)

| Componente | Props | Uso |
|------------|-------|-----|
| `FinanceKpiCard` | `label`, `value`, `caption`, `tone` | Exibição de KPIs no dashboard |
| `FinanceStatusBadge` | `status`, `label` | Badge de status de lançamentos |
| `FinanceEmptyState` | `title`, `description`, `actionLabel` | Estado vazio em listas |
| `FinanceTrendMiniChart` | `points`, `strokeColor`, `height` | Gráficos de tendência miniatura |

## Regras de Negócio

### 1. Lançamentos (Entries)

#### Direções
- **PAYABLE** (Pagar): Despesas, contas a pagar
- **RECEIVABLE** (Receber): Receitas, contas a receber

#### Tipos de Lançamento
- **ONE_OFF** (Único): Lançamento avulso
- **RECURRING** (Recorrente): Gerado automaticamente por regra
- **INSTALLMENT** (Parcelamento): Parte de um plano de parcelamento
- **ADJUSTMENT** (Ajuste): Ajuste manual
- **DEBT** (Dívida): Dívida (apenas para PAYABLE)

#### Status dos Lançamentos
- **PENDING**: Aguardando pagamento/recebimento
- **PARTIAL**: Pagamento parcial
- **PAID/RECEIVED**: Totalmente pago/recebido
- **OVERDUE**: Atrasado
- **CANCELED**: Cancelado

#### Validações
- Título obrigatório (máx. 120 caracteres)
- Valor obrigatório (mín. 0, máx. 999.999.999)
- Data de vencimento obrigatória
- Categoria obrigatória (deve estar ativa)

### 2. Contas Bancárias

#### Tipos de Conta
- **CHECKING** (Corrente)
- **SAVINGS** (Poupança)
- **CREDIT** (Cartão de Crédito)
- **INVESTMENT** (Investimento)
- **OTHER** (Outro)

#### Campos
- Nome (obrigatório, máx. 80 caracteres)
- Agência (opcional, máx. 20 caracteres)
- Número da conta (opcional, máx. 40 caracteres)
- Tipo (obrigatório)
- Saldo atual (mín. -999.999.999, máx. 999.999.999)
- Status (ativo/inativo)

#### Regras
- Contas inativas não aparecem em seleções padrão
- Cartões de crédito são filtrados separadamente para parcelamentos
- Saldo pode ser negativo (overdraft)

### 3. Categorias

#### Tipos (Kind)
- **BOTH**: Ambos (pagar e receber)
- **PAYABLE**: Somente pagar
- **RECEIVABLE**: Somente receber
- **INVESTMENT**: Investimento

#### Regras
- Categorias inativas não aparecem em seleções
- Nome obrigatório (máx. 80 caracteres)
- Não é possível excluir categorias em uso

### 4. Regras Recorrentes

#### Configurações
- Direção (PAYABLE/RECEIVABLE)
- Título (máx. 120 caracteres)
- Valor (mín. 0)
- Dia do mês (1-31)
- Data de início
- Tipo recorrente (obrigatório)
- Categoria (obrigatória)
- Conta bancária padrão (opcional)

#### Geração Manual
- Botão "Gerar" cria lançamento único baseado na regra
- Respeita o dia do mês configurado
- Gera lançamento com status PENDING

### 5. Planos de Parcelamento

#### Configurações
- Direção (apenas PAYABLE)
- Título (máx. 120 caracteres)
- Valor total (mín. 0)
- Entrada (down payment)
- Número de parcelas (1-480)
- Juros (valor absoluto)
- Desconto (valor absoluto)
- Multa (valor absoluto)
- Data da primeira parcela
- Categoria (obrigatória)
- Conta bancária padrão (opcional)

#### Ajustes
- **PAYMENT** (Pagamento): Baixa de parcela
- **ADVANCE** (Adiantamento): Antecipação de parcelas
- **DISCOUNT** (Desconto): Redução do valor

### 6. Investimentos

#### Tipos de Investimento
- **SELIC**: Taxa Selic
- **CDI**: CDI
- **IPCA+**: IPCA mais juros
- **PRE**: Prefixado
- **OTHER**: Outro

#### Simulação
- Aporte inicial
- Aporte mensal
- Período (meses)
- Taxa (anual ou mensal)
- Resultado: Total investido, Rendimento, Valor final

#### Conversão para Plano
- Cria plano de investimento a partir de simulação
- Configura: data início, dia do aporte, modo de rendimento
- Gera lançamentos recorrentes automaticamente

### 7. Moedas e Cotações

#### Funcionalidades
- Cadastro de cotações manuais
- Código da moeda (ISO 4217)
- Data da cotação
- Taxa de câmbio para BRL

#### Integração
- Suporte a API do Bacen (Banco Central)
- Atualização automática de cotações

### 8. Dashboard e KPIs

#### Indicadores
- Saldo previsto (receitas previstas - despesas previstas)
- Saldo realizado (receitas recebidas - pagamentos)
- Saldo pendente (valor ainda aberto)
- Atrasos (lançamentos vencidos)
- Receita prevista
- Despesa prevista
- Saldo em contas (soma das contas bancárias)
- Execução de recebimentos (%)
- Execução de pagamentos (%)

#### Gráficos
- Saldo previsto por mês
- Saldo realizado por mês
- Receitas previstas
- Despesas previstas
- Gap previsto × realizado

### 9. Relatórios e Exportações

#### Tipos de Exportação
- **MONTHLY_SUMMARY**: Resumo mensal
- **DETAILED_ENTRIES**: Lançamentos detalhados
- **CASHFLOW**: Fluxo de caixa
- **CATEGORY_ANALYSIS**: Análise por categoria

#### Migração
- Exportação de snapshot completo (JSON)
- Importação de snapshot
- Opção de substituir dados existentes
- Confirmação obrigatória para substituição

## Fluxos Principais

### 1. Fluxo de Criação de Lançamento

```
1. Usuário clica "Adicionar" → Seleciona tipo (Lançamento)
2. Preenche formulário (título, valor, vencimento, categoria)
3. Validações client-side
4. POST /finance/entries
5. Recarrega lista de lançamentos
6. Notificação de sucesso
```

### 2. Fluxo de Pagamento (Baixa)

```
1. Usuário clica "Adicionar" → Seleciona "Baixa de lançamento"
2. Busca lançamento por título/data/valor
3. Seleciona lançamento (valor restante é preenchido automaticamente)
4. Configura: valor, data, conta, tipo (pagamento/adiantamento/desconto)
5. Validações
6. POST /finance/entries/{id}/settlements
7. Recarrega lançamentos e parcelamentos
8. Notificação de sucesso
```

### 3. Fluxo de Regra Recorrente

```
1. Usuário cria regra recorrente
2. Configura: direção, título, valor, dia, tipo, categoria
3. POST /finance/recurring-rules
4. Para gerar lançamento: botão "Gerar"
5. POST /finance/recurring-rules/{id}/generate-manual
6. Lançamento único é criado com status PENDING
```

### 4. Fluxo de Parcelamento

```
1. Usuário cria plano de parcelamento
2. Configura: título, valor total, parcelas, juros, desconto, multa
3. POST /finance/installment-plans
4. Sistema gera parcelas automaticamente
5. Para pagamento: selecionar parcela e registrar baixa
6. Ajustes: adiantamento ou desconto
```

### 5. Fluxo de Investimento

```
1. Usuário configura simulação
2. Seleciona tipo, valores, período, taxa
3. POST /finance/investments/simulations
4. Visualiza resultado (investido, rendimento, valor final)
5. Converte para plano (opcional)
6. POST /finance/investments/plans
7. Sistema gera lançamentos recorrentes
```

### 6. Fluxo de Relatórios

```
1. Usuário acessa tela de relatórios
2. Dashboard carrega KPIs e gráficos
3. Para exportar: seleciona tipo e clica "Gerar CSV"
4. POST /finance/exports
5. Sistema gera arquivo e inicia download
6. Para migração: exportar/importar snapshot JSON
```

## Integração com API

### Endpoints Principais

| Recurso | Métodos | Endpoint |
|---------|---------|----------|
| Categorias | GET, POST, PATCH | `/finance/categories` |
| Contas Bancárias | GET, POST, PATCH | `/finance/bank-accounts` |
| Lançamentos | GET, POST, PATCH, DELETE | `/finance/entries` |
| Baixas | POST | `/finance/entries/{id}/settlements` |
| Regras Recorrentes | GET, POST, PATCH, DELETE | `/finance/recurring-rules` |
| Tipos Recorrentes | GET, POST, PATCH, DELETE | `/finance/recurring-types` |
| Planos Parcelamento | GET, POST, PATCH | `/finance/installment-plans` |
| Investimentos | GET, POST | `/finance/investments/*` |
| Dashboard | GET | `/finance/dashboard/*` |
| Moedas | GET, POST | `/finance/currencies/*` |
| Exportações | GET, POST, DELETE | `/finance/exports` |
| Migração | GET, POST | `/finance/migration/*` |

### Autenticação

- Todas as requisições usam `sessionStore.authRequest()`
- CSRF tokens para mutations (POST, PATCH, DELETE)
- Headers: `Authorization: Bearer {token}`

## Impactos no Frontend

### Estados de Loading

| Estado | Componente | Descrição |
|--------|------------|-----------|
| `loading.catalogs` | FinanceLayout | Carregamento inicial de catálogos |
| `loading.bankAccounts` | FinanceBanksView | Carregamento de contas bancárias |
| `loading.categories` | FinanceSettingsView | Carregamento de categorias |
| `loading.entries` | FinanceAccountsView | Carregamento de lançamentos |
| `loading.recurring` | FinanceSettingsView | Carregamento de tipos recorrentes |
| `loading.installmentPlans` | FinanceAccountsView | Carregamento de parcelamentos |
| `loading.currency` | FinanceSettingsView | Carregamento de cotações |
| `loading.dashboard` | FinanceReportsView | Carregamento do dashboard |
| `loading.investments` | FinanceInvestmentsView | Carregamento de investimentos |
| `loading.exports` | FinanceReportsView | Processamento de exportações |
| `loading.migration` | FinanceReportsView | Processamento de migração |

### Estados de Erro

| Erro | Tratamento |
|------|------------|
| `error` (global) | Exibe alerta com botão "Tentar novamente" |
| Erros de API | Notificação via `notifyUser()` com mensagem amigável |
| Erros de renderização | `onErrorCaptured()` captura e notifica |

### Estados Vazios

| Componente | Uso |
|------------|-----|
| `RemoteFinanceEmptyState` | Exibido quando não há dados para mostrar |
| Props: `title`, `description`, `actionLabel` | Personalização da mensagem |

### Permissões

- `canWriteFinance`: Controla acesso a mutations
- Usuários somente leitura visualizam formulários desabilitados
- Botões de ação são desabilitados sem permissão

## Gaps e Lacunas Identificadas

### 1. Funções Não Implementadas no Store

O `financeStore.js` não possui actions para:
- CRUD de lançamentos (usado diretamente dos services)
- CRUD de investimentos
- CRUD de exportações
- CRUD de migração

**Recomendação**: Centralizar no store para melhor organização.

### 2. Validações Backend Não Visíveis

As validações de negócio são executadas no backend, mas o frontend não tem acesso às regras específicas.

**Recomendação**: Documentar validações backend ou criar schemas de validação compartilhados.

### 3. Tratamento de Erros Inconsistente

Alguns componentes usam `extractHttpMessage()` enquanto outros tratam erros diretamente.

**Recomendação**: Padronizar tratamento de erros em todos os componentes.

### 4. Falta de Testes Unitários

Não foram identificados testes unitários para os componentes financeiros.

**Recomendação**: Criar testes para store, services e componentes críticos.

### 5. Documentação de API

A documentação da API financeira não está disponível no frontend.

**Recomendação**: Criar documentação OpenAPI/Swagger para o backend.

## Recomendações

### Curto Prazo

1. **Centralizar CRUD no store**: Mover operações de lançamentos, investimentos e exportações para o financeStore
2. **Padronizar tratamento de erros**: Criar composable `useFinanceErrorHandler`
3. **Adicionar testes unitários**: Cobrir store, services e componentes principais

### Médio Prazo

1. **Compartilhar schemas de validação**: Usar schemas JSON ou TypeScript para validações
2. **Implementar cache inteligente**: Cache de catálogos e dados que mudam raramente
3. **Otimizar carregamento**: Lazy loading de views não críticas

### Longo Prazo

1. **GraphQL ou tRPC**: Considerar para melhor tipagem e performance
2. **Offline-first**: Suporte a operações offline com sincronização
3. **Analytics financeiro**: Métricas avançadas e insights automáticos

---

*Documento gerado pelo Analista Financeiro - God King Dev*
*Data: 2026-07-07*
*Issue: [GOD-45](/GOD/issues/GOD-45)*