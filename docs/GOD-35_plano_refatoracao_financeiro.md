# GOD-35 - Plano de Refatoração do Módulo Financeiro

## Status
- Data: 2026-07-07
- Prioridade: Média
- Issue: GOD-35
- Progresso: ~80% concluído (pendente decisão CEO + UX)

## Resumo Executivo

O módulo financeiro do OctoFlow passou por uma refatoração inicial que fragmentou o monolito `FinanceScreen.vue` (~4.844 linhas) em 6 views independentes com roteamento lazy-loaded. Esta issue conclui o trabalho pendente: remoção do legado, extração de modais, testes e melhorias de UX.

## O que já foi concluído (entregas anteriores)

| Item | Status |
|---|---|
| Router com 6 rotas financeiras lazy-loaded | ✅ |
| FinanceLayout.vue como shell com `<router-view>` | ✅ |
| financeStore.js centralizado (Pinia) com catálogos, dashboard, entries | ✅ |
| FinanceAccountsView.vue (gerenciamento de lançamentos) | ✅ |
| FinanceBanksView.vue (CRUD contas bancárias) | ✅ |
| FinanceInvestmentsView.vue (simulação e planos) | ✅ |
| FinanceSettingsView.vue (categorias, tipos, moedas) | ✅ |
| FinanceReportsView.vue (KPIs, cashflow, exportação, migração) | ✅ |
| finance-components.css (estilos padronizados) | ✅ |
| Componentes compartilhados (TabsNavbar, EntriesListPanel, Remote components) | ✅ |
| Remoção do monolito FinanceScreen.vue | ✅ (07/07) |
| Extração de modais do AccountsView para 4 componentes isolados | ✅ (07/07) |
| Testes unitários backend: RecurringService, InstallmentService, DashboardService, CurrencyService | ✅ (07/07) |

## O que precisa ser feito (GOD-35)

### 1. ✅ Remover monolito legado FinanceScreen.vue
- Arquivo deletado em 07/07/2026
- Nenhum import ativo encontrado

### 2. ✅ Extrair modais do FinanceAccountsView.vue em componentes isolados
- Criados:
  - `components/finance/FinanceEntryFormModal.vue`
  - `components/finance/FinanceSettlementFormModal.vue`
  - `components/finance/FinanceRecurringFormModal.vue`
  - `components/finance/FinanceInstallmentFormModal.vue`
- AccountsView atualizado para usar os 4 componentes
- Build verificado com sucesso

### 3. ✅ Expandir cobertura de testes do backend financeiro
- Novos testes criados:
  - `FinanceRecurringServiceTest.php` (3 cenários: list, create, validation)
  - `FinanceInstallmentServiceTest.php` (3 cenários: list, create, empty)
  - `FinanceDashboardServiceTest.php` (3 cenários: summary, filtered, cashflow)
  - `FinanceCurrencyServiceTest.php` (4 cenários: list, validation x2, create)
- Total: 9 novos testes, 4 novos arquivos
- Nota: execução do PHPUnit dependente de mbstring (não disponível no ambiente atual)

### 4. [PENDENTE] Melhorias de UX
- Skeleton loaders nos painéis (substituir "Atualizando..." / "Carregando...")
- Debounce (300ms) no input de busca de lançamentos (já existe no settlement search)
- Atalho `Ctrl+N` para novo lançamento

### 5. [AGUARDANDO CEO] Limpeza de DebtPlan
- DashboardScreen ainda consome `financeDebtPlans` do composable `useDashboardFinanceData`
- Backend: `FinanceDebtPlanService`, `FinanceDebtPlanController` e rota `/debt-plans`
- Frontend: `finance.js` exporta `fetchFinanceDebtPlans`, `previewFinanceDebtPlan`, `createFinanceDebtPlan`, `deleteFinanceDebtPlan`
- **Decisão pendente do CEO**: remover completamente ou manter? Se remover, migrar dados existentes para parcelamento.

## Dependências

| Bloqueador | Impacto | Ação |
|---|---|---|
| Decisão CEO sobre DebtPlans (#5) | Bloqueia remoção de ~400 linhas backend + frontend | **Perguntar ao CEO**: remover DebtPlan ou manter? |
| Ambiente PHP sem mbstring | Impede execução do PHPUnit localmente | Rodar via Docker ou instalar extensão |

## Riscos

- **DebtPlan**: removido do AccountsView mas ainda referenciado no DashboardScreen (via `useDashboardFinanceData`). Se removermos, o dashboard perde a seção de dívidas. Decisão do CEO necessária.
- **ExportService e OpenFinanceService** ainda sem testes unitários (menor prioridade, menor risco)
- Build frontend verificado e passando sem erros

## Próximas Ações

1. ✅ Remover FinanceScreen.vue — concluído
2. ✅ Extrair modais — concluído, build verificado
3. ✅ Testes backend — 4 novos arquivos, 9 cenários
4. ⏳ Perguntar ao CEO: devo remover DebtPlans? Impacta DashboardScreen + backend
5. ⏳ Melhorias de UX (skeletons, debounce, atalho Ctrl+N) — baixa prioridade, pode ser issue separada

## Resumo Final

| Item | Status |
|---|---|
| Monolito removido | ✅ |
| Modais extraídos | ✅ |
| Testes backend expandidos | ✅ (9 novos cenários) |
| Decisão DebtPlan | ⏳ Aguardando CEO |
| UX improvements | ⏳ Pode virar issue separada |
