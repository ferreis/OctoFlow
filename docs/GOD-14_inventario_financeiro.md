# GOD-14 - Inventario de modulos, funcoes e testes financeiros

Atualizado em: 2026-07-07

## Escopo

Este inventario localiza os pontos financeiros existentes no OctoFlow para facilitar revisao tecnica, planejamento de QA e proximas entregas. A leitura foi feita por busca estrutural em backend Symfony, frontend Vue, migrations, comandos e testes.

## Resumo executivo

- Backend financeiro principal: `OctoFlow/www/src/Finance` com 13 classes e cerca de 8.791 linhas.
- API financeira: `OctoFlow/www/src/Controller/Finance` com 11 controllers e 51 rotas/methods expostos sob `/finance`.
- Jobs/rotinas: `OctoFlow/www/src/Command/Finance` com 4 comandos Symfony.
- Frontend financeiro ativo: `OctoFlow/frontend/Host-app/src/views/finance`, `services/finance*.js`, `stores/financeStore.js`, `components/finance` e `components/shared/FinanceEntriesListPanel.vue`.
- Frontend legado/residual: `OctoFlow/frontend/Host-app/src/components/screens/FinanceScreen.vue` ainda existe com 4.844 linhas, mas a rota atual usa `FinanceLayout.vue`.
- Testes financeiros localizados: apenas `OctoFlow/www/tests/Unit/Finance/FinanceInputTest.php`.
- Lacuna principal: os fluxos de entradas, baixas, recorrencias, parcelas, investimentos, exportacao, importacao, Open Finance e dashboard nao tem testes dedicados localizados.

## Backend - modulos e funcoes publicas

| Arquivo | Responsabilidade inferida | Funcoes publicas principais |
| --- | --- | --- |
| `OctoFlow/www/src/Finance/FinanceCatalogService.php` | Categorias, tipos recorrentes e contas bancarias | `listCategories`, `createCategory`, `updateCategory`, `listRecurringTypes`, `createRecurringType`, `updateRecurringType`, `deleteRecurringType`, `listBankAccounts`, `createBankAccount`, `updateBankAccount`, `updateBankAccountStatus`, `getBankAccountById` |
| `OctoFlow/www/src/Finance/FinanceEntryService.php` | Lancamentos financeiros, baixa, status e saldos | `listEntries`, `createEntry`, `updateEntry`, `softDeleteEntry`, `settleEntry`, `refreshOverdueStatusesForAllUsers`, `getEntryById`, `recalculateEntrySettlement` |
| `OctoFlow/www/src/Finance/FinanceRecurringService.php` | Regras recorrentes e geracao de lancamentos | `listRules`, `createRule`, `updateRule`, `deleteRule`, `generateRuleEntryManually`, `generateMissingEntriesForRange`, `ensureNextMonthlyEntryForRule`, `generateDailySync` |
| `OctoFlow/www/src/Finance/FinanceInstallmentService.php` | Planos parcelados e ajustes | `listPlans`, `createPlan`, `updatePlan`, `softDeletePlan`, `applyPlanAdjustment` |
| `OctoFlow/www/src/Finance/FinanceDebtPlanService.php` | Simulacao e criacao de planos de divida | `listPlans`, `previewPlan`, `createPlan`, `deletePlan` |
| `OctoFlow/www/src/Finance/FinanceInvestmentService.php` | Simulacoes, planos e execucao mensal de investimentos | `createSimulation`, `getSimulation`, `convertSimulationToPlan`, `createPlan`, `listPlans`, `updatePlan`, `runMonthlyPlans` |
| `OctoFlow/www/src/Finance/FinanceDashboardService.php` | KPIs, fluxo de caixa e categorias | `summary`, `cashflow`, `categories` |
| `OctoFlow/www/src/Finance/FinanceCurrencyService.php` | Catalogo de moedas, cotacoes Bacen/manual e cache | `listCurrencies`, `rates`, `createManualRate` |
| `OctoFlow/www/src/Finance/FinanceExportService.php` | Jobs e arquivos de exportacao financeira | `createJob`, `listJobs`, `deleteJob`, `processQueuedJobs`, `getDownloadableFilePath` |
| `OctoFlow/www/src/Finance/FinanceTransferService.php` | Exportacao/importacao JSON do snapshot financeiro | `exportSnapshot`, `importSnapshot` |
| `OctoFlow/www/src/Finance/FinanceOpenFinanceService.php` | Providers, conexoes e sincronizacao Open Finance simulada | `listProviders`, `listConnections`, `createConnection`, `syncConnection`, `deleteConnection` |
| `OctoFlow/www/src/Finance/FinanceInput.php` | Normalizacao e validacao compartilhada de entrada | `normalizeName`, `normalizeNameKey`, `normalizeDirection`, `normalizeMoney`, `normalizeOptionalMoney`, `normalizeDate`, `normalizeOptionalDate`, `normalizeStatus`, `defaultStatusByDirection`, `normalizePagination`, `normalizeBoolean`, `toDatabaseBoolean`, `convertAnnualRateToMonthly`, `normalizeRateInputType` |
| `OctoFlow/www/src/Finance/FinanceConstants.php` | Constantes de dominio financeiro | Direcoes, status, tipos de lancamento, origens, tipos de investimento e status/tipos de exportacao |

## Backend - controllers e rotas

Base de seguranca: `OctoFlow/www/src/Controller/CsrfChallengeController.php` reconhece `/finance` para desafio CSRF. Os controllers usam `FinanceControllerHelper` para JSON, resposta nao autorizada e tratamento basico de excecoes.

| Controller | Base | Endpoints principais |
| --- | --- | --- |
| `FinanceCatalogController.php` | `/finance` | `GET/POST /categories`, `PATCH /categories/{id}`, `GET/POST /recurring-types`, `PATCH/DELETE /recurring-types/{id}`, `GET/POST /bank-accounts`, `PATCH /bank-accounts/{id}`, `PATCH /bank-accounts/{id}/status` |
| `FinanceEntryController.php` | `/finance` | `GET/POST /entries`, `GET/PATCH/DELETE /entries/{id}`, `POST /entries/{id}/settlements` |
| `FinanceRecurringController.php` | `/finance` | `GET/POST /recurring-rules`, `PATCH/DELETE /recurring-rules/{id}`, `POST /recurring-rules/{id}/generate-manual` |
| `FinanceInstallmentController.php` | `/finance` | `GET/POST /installment-plans`, `PATCH /installment-plans/{id}`, `POST /installment-plans/{id}/adjustment` |
| `FinanceDebtPlanController.php` | `/finance` | `GET/POST /debt-plans`, `POST /debt-plans/preview`, `DELETE /debt-plans/{id}` |
| `FinanceInvestmentController.php` | `/finance` | `POST /investment/simulations`, `GET /investment/simulations/{id}`, `POST /investment/simulations/{id}/convert-to-plan`, `GET/POST /investment/plans`, `PATCH /investment/plans/{id}` |
| `FinanceDashboardController.php` | `/finance/dashboard` | `GET /summary`, `GET /cashflow`, `GET /categories` |
| `FinanceCurrencyController.php` | `/finance/currencies` | `GET /`, `GET /rates`, `POST /rates/manual` |
| `FinanceExportController.php` | `/finance` | `GET/POST /exports`, `GET /exports/{id}/download`, `DELETE /exports/{id}` |
| `FinanceTransferController.php` | `/finance/migration` | `GET /export`, `POST /import` |
| `FinanceOpenFinanceController.php` | `/finance/open-finance` | `GET /providers`, `GET/POST /connections`, `POST /connections/{id}/sync`, `DELETE /connections/{id}` |

## Backend - comandos financeiros

| Comando | Classe | Acao |
| --- | --- | --- |
| `finance:entries:refresh-status` | `FinanceEntryStatusRefreshCommand.php` | Atualiza status vencidos para todos os usuarios |
| `finance:recurrence:sync` | `FinanceRecurrenceSyncCommand.php` | Gera recorrencias em lote |
| `finance:investment:run` | `FinanceInvestmentRunCommand.php` | Executa geracao mensal de planos de investimento |
| `finance:exports:process` | `FinanceExportsProcessCommand.php` | Processa jobs de exportacao financeira |

## Frontend - modulos e funcoes

Rotas ativas em `OctoFlow/frontend/Host-app/src/router/index.js`:

- `/app/finance/accounts` -> `FinanceAccountsView.vue`
- `/app/finance/banks` -> `FinanceBanksView.vue`
- `/app/finance/investments` -> `FinanceInvestmentsView.vue`
- `/app/finance/settings` -> `FinanceSettingsView.vue`
- `/app/finance/reports` -> `FinanceReportsView.vue`
- Redirecionamentos legados: `/finance`, `/finance/:section`

Arquivos principais:

| Arquivo | Responsabilidade inferida |
| --- | --- |
| `src/views/finance/FinanceLayout.vue` | Shell do modulo financeiro e carregamento de catalogos |
| `src/components/finance/FinanceTabsNavbar.vue` | Navegacao por abas/rotas financeiras |
| `src/views/finance/FinanceAccountsView.vue` | Contas a pagar/receber, lancamentos, baixas, recorrencias e parcelamentos |
| `src/views/finance/FinanceBanksView.vue` | CRUD/status de contas bancarias |
| `src/views/finance/FinanceInvestmentsView.vue` | Simulacoes e planos de investimento |
| `src/views/finance/FinanceSettingsView.vue` | Categorias, tipos recorrentes e cotacoes manuais |
| `src/views/finance/FinanceReportsView.vue` | KPIs, graficos, exportacao e migracao JSON |
| `src/components/shared/FinanceEntriesListPanel.vue` | Lista filtravel/paginada de lancamentos |
| `src/stores/financeStore.js` | Estado Pinia compartilhado: catalogos, entries, dashboard, moedas e loading states |
| `src/services/finance.js` | Cliente HTTP para endpoints financeiros gerais |
| `src/services/financeInvestments.js` | Cliente HTTP para endpoints de investimento |
| `src/utils/financeInputSanitizers.js` | Sanitizacao de texto, ids, datas, moeda, decimais, inteiros e toggles |
| `src/constants/financeTerms.js` | Dicionarios e tradutores de termos/status/tipos financeiros |
| `src/composables/useDashboardFinanceData.js` | Carregamento reutilizavel de dados financeiros no dashboard |
| `src/composables/useFinancePermissions.js` | Resolucao de permissoes/capacidades financeiras |

Funcoes exportadas do frontend localizadas:

- `src/services/finance.js`: `fetchFinanceCategories`, `createFinanceCategory`, `updateFinanceCategory`, `fetchFinanceRecurringTypes`, `createFinanceRecurringType`, `updateFinanceRecurringType`, `deleteFinanceRecurringType`, `fetchFinanceBankAccounts`, `createFinanceBankAccount`, `updateFinanceBankAccount`, `updateFinanceBankAccountStatus`, `fetchFinanceEntries`, `createFinanceEntry`, `updateFinanceEntry`, `deleteFinanceEntry`, `createFinanceSettlement`, `fetchFinanceRecurringRules`, `createFinanceRecurringRule`, `updateFinanceRecurringRule`, `deleteFinanceRecurringRule`, `generateFinanceRecurringRuleManually`, `fetchFinanceInstallmentPlans`, `createFinanceInstallmentPlan`, `updateFinanceInstallmentPlan`, `applyFinanceInstallmentPlanAdjustment`, `fetchFinanceDebtPlans`, `previewFinanceDebtPlan`, `createFinanceDebtPlan`, `deleteFinanceDebtPlan`, `fetchFinanceDashboardSummary`, `fetchFinanceDashboardCashflow`, `fetchFinanceDashboardCategories`, `fetchFinanceCurrencies`, `fetchFinanceCurrencyRates`, `createFinanceCurrencyRateManual`, `createFinanceExport`, `fetchFinanceExports`, `deleteFinanceExport`, `fetchFinanceMigrationSnapshot`, `importFinanceMigrationSnapshot`, `fetchFinanceOpenFinanceProviders`, `fetchFinanceOpenFinanceConnections`, `createFinanceOpenFinanceConnection`, `syncFinanceOpenFinanceConnection`, `deleteFinanceOpenFinanceConnection`.
- `src/services/financeInvestments.js`: `createFinanceSimulation`, `fetchFinanceSimulation`, `convertFinanceSimulationToPlan`, `fetchFinanceInvestmentPlans`, `createFinanceInvestmentPlan`, `updateFinanceInvestmentPlan`.
- `src/utils/financeInputSanitizers.js`: `sanitizeSingleLineText`, `sanitizeSearchText`, `sanitizeIdentifier`, `sanitizeDateInput`, `sanitizeMonthInput`, `sanitizeDateTimeLocalInput`, `sanitizeCurrencyCode`, `sanitizeDecimal`, `sanitizeInteger`, `sanitizeToggle`.
- `src/constants/financeTerms.js`: `translateFinanceTerm`, `translateFinanceExportType`, `translateFinanceExportStatus`.
- `src/composables/useDashboardFinanceData.js`: `useDashboardFinanceData`.
- `src/composables/useFinancePermissions.js`: `useFinancePermissions`.

## Banco de dados - migrations financeiras

Migrations com alteracoes financeiras:

- `Version20260325150000.php`: schema financeiro base.
- `Version20260325195000.php`: `finance_debt_plan`.
- `Version20260325210000.php`: agencia/numero em `finance_bank_account`.
- `Version20260327110000.php`: soft delete em `finance_entry`.
- `Version20260331143000.php`: soft delete em parcelamentos e dividas.
- `Version20260401150000.php`: `finance_currency_rate`.
- `Version20260526180000.php`: status `FORECAST` em constraints de `finance_entry`.
- `Version20260608120000.php`: recriacao de `finance_negotiation`.

Tabelas de dominio financeiro localizadas:

- Cadastros: `finance_category`, `finance_recurring_type`, `finance_bank_account`.
- Lancamentos e saldos: `finance_entry`, `finance_entry_settlement`, `finance_entry_status_history`, `finance_bank_account_ledger`.
- Recorrencias: `finance_recurring_rule`, `finance_recurring_rule_run`.
- Parcelas/dividas/renegociacao: `finance_installment_plan`, `finance_installment_item`, `finance_debt_plan`, `finance_negotiation`.
- Investimentos: `finance_investment_simulation`, `finance_investment_simulation_point`, `finance_investment_plan`, `finance_investment_plan_run`.
- Exportacao/migracao: `finance_export_job`.
- Open Finance: `finance_external_provider`, `finance_external_connection`, `finance_external_account`, `finance_external_transaction`, `finance_external_transaction_link`.
- Moedas: `finance_currency_rate`.

## Testes financeiros localizados

Arquivo encontrado:

- `OctoFlow/www/tests/Unit/Finance/FinanceInputTest.php`

Casos cobertos:

- `testNormalizeDirectionAcceptsPayableAndReceivable`
- `testNormalizeDirectionRejectsInvalidValues`
- `testNormalizeMoneyAcceptsCommaAndRounds`
- `testNormalizeStatusValidatesByDirection`
- `testNormalizeStatusRejectsStatusNotAllowedForDirection`
- `testDefaultStatusByDirectionUsesScheduleForFuturePayable`
- `testConvertAnnualRateToMonthlyUsesCompoundFormula`
- `testToDatabaseBooleanReturnsNumericBoolean`

Testes nao localizados:

- Nao foram encontrados testes frontend com `finance`/`Finance`.
- Nao foram encontrados testes dedicados para `FinanceEntryService`, `FinanceCatalogService`, `FinanceRecurringService`, `FinanceInstallmentService`, `FinanceDebtPlanService`, `FinanceInvestmentService`, `FinanceDashboardService`, `FinanceCurrencyService`, `FinanceExportService`, `FinanceTransferService` ou `FinanceOpenFinanceService`.
- Nao foram encontrados testes de controller/API financeiros.
- Nao foram encontrados testes de comandos financeiros.

## Riscos e pontos para revisao do CEO

- Cobertura de QA baixa para fluxos financeiros que alteram saldo, status e historico. A unica cobertura direta valida normalizacao de inputs.
- `FinanceScreen.vue` ainda existe como monolito legado e referencia funcionalidades de dividas. A rota atual usa views separadas, mas o arquivo residual aumenta risco de manutencao e confusao de fonte da verdade.
- `FinanceTransferService` importa snapshot financeiro e apaga dados do usuario antes de reimportar. Qualquer evolucao nessa area precisa validacao extra de autorizacao, backup e rollback.
- `FinanceCurrencyService` chama API externa do Bacen; testes futuros devem mockar rede e cobrir fallback/cache/manual.
- Open Finance parece simulado/mockado pelo servico atual; qualquer promessa de integracao bancaria real precisa validacao de escopo, seguranca e compliance antes de compromisso.

## Verificacao executada nesta localizacao

- Busca de arquivos financeiros com `rg --files`, `find` e `rg`.
- Extracao de funcoes publicas com `rg` sobre `src/Finance`, `src/Controller/Finance`, `src/Command/Finance` e frontend financeiro.
- Contagem de linhas com `wc -l` para dimensionar escopo.
- Busca por testes financeiros em `OctoFlow/www/tests` e `OctoFlow/frontend`.
