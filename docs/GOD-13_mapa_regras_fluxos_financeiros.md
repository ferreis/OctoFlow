# GOD-13 - Mapa de regras e fluxos financeiros existentes

Atualizado em: 2026-07-07

## Objetivo

Consolidar o levantamento dos pontos financeiros do OctoFlow antes de qualquer correcao de comportamento. Este documento fecha a issue pai GOD-13 usando os artefatos detalhados criados nas issues filhas.

## Artefatos de referencia

- `docs/GOD-14_inventario_financeiro.md`: inventario tecnico de arquivos, modulos, funcoes, rotas, comandos, migrations e testes financeiros.
- `docs/GOD-15_regras_fluxos_financeiros.md`: regras esperadas por fluxo financeiro, lacunas, riscos e matriz minima de QA.

## Cobertura dos criterios de aceite

| Criterio | Evidencia |
| --- | --- |
| Arquivos, modulos e funcoes financeiras identificados | GOD-14 lista backend em `OctoFlow/www/src/Finance`, controllers em `OctoFlow/www/src/Controller/Finance`, comandos em `OctoFlow/www/src/Command/Finance`, frontend em `OctoFlow/frontend/Host-app/src/views/finance`, services/stores/componentes, migrations e testes. |
| Fluxos principais documentados | GOD-15 documenta cadastros, lancamentos, receitas, despesas, saldos, baixas, cartao de credito, recorrencias, parcelamentos, dividas, investimentos, moedas/cotacoes, dashboards, relatorios, exportacao/importacao e Open Finance. |
| Premissas, lacunas e duvidas objetivas registradas | GOD-15 registra decisoes pendentes sobre categoria obrigatoria, status futuro, parametros versionados de juros/multa/IOF, ledger contabil, importacao destrutiva e Open Finance real versus mock. Esta consolidacao tambem lista esses pontos para revisao executiva. |

## Mapa tecnico resumido

### Backend financeiro

- `OctoFlow/www/src/Finance/FinanceCatalogService.php`: categorias, tipos recorrentes e contas bancarias.
- `OctoFlow/www/src/Finance/FinanceEntryService.php`: lancamentos, baixas, status, saldo restante, historico e ledger bancario.
- `OctoFlow/www/src/Finance/FinanceRecurringService.php`: regras recorrentes e geracao mensal.
- `OctoFlow/www/src/Finance/FinanceInstallmentService.php`: parcelamentos, parcelas, entrada e ajustes.
- `OctoFlow/www/src/Finance/FinanceDebtPlanService.php`: simulacao e criacao de planos de divida.
- `OctoFlow/www/src/Finance/FinanceInvestmentService.php`: simulacoes, planos e execucao mensal de investimentos.
- `OctoFlow/www/src/Finance/FinanceDashboardService.php`: resumo, fluxo de caixa e agrupamento por categoria.
- `OctoFlow/www/src/Finance/FinanceCurrencyService.php`: moedas, cotacoes Bacen/manual e cache.
- `OctoFlow/www/src/Finance/FinanceExportService.php`: jobs e downloads de exportacao.
- `OctoFlow/www/src/Finance/FinanceTransferService.php`: exportacao/importacao de snapshot financeiro.
- `OctoFlow/www/src/Finance/FinanceOpenFinanceService.php`: providers, conexoes e sync stub/mock.
- `OctoFlow/www/src/Finance/FinanceInput.php`: normalizacao e validacao compartilhada.
- `OctoFlow/www/src/Finance/FinanceConstants.php`: constantes de dominio.

### API, jobs, banco e frontend

- API financeira: `OctoFlow/www/src/Controller/Finance`, com endpoints sob `/finance` e `/finance/dashboard`.
- Jobs financeiros: `OctoFlow/www/src/Command/Finance`, cobrindo status vencido, recorrencias, investimentos e exportacoes.
- Banco de dados: migrations financeiras entre `Version20260325150000.php` e `Version20260608120000.php`, cobrindo cadastros, lancamentos, ledger, recorrencias, parcelamentos, dividas, investimentos, moedas, exportacao, migracao e Open Finance.
- Frontend ativo: `OctoFlow/frontend/Host-app/src/views/finance`, `src/services/finance.js`, `src/services/financeInvestments.js`, `src/stores/financeStore.js`, `src/components/finance`, `src/components/shared/FinanceEntriesListPanel.vue`, `src/utils/financeInputSanitizers.js` e `src/constants/financeTerms.js`.
- Teste financeiro localizado: `OctoFlow/www/tests/Unit/Finance/FinanceInputTest.php`.

## Fluxos principais documentados

- Cadastros financeiros: categorias, tipos recorrentes e contas bancarias.
- Lancamentos: criacao, edicao, listagem, filtros, datas, vencimentos, moeda e status.
- Receitas e despesas: `RECEIVABLE` e `PAYABLE`, com status permitidos e regras de liquidacao.
- Baixas e saldos: baixa simples, baixa parcial/total, ajustes, cartao de credito, ledger bancario e saldo de conta.
- Recorrencias: regra mensal, idempotencia por competencia, geracao manual/job e exclusao.
- Parcelamentos: plano, parcelas, entrada, arredondamento e ajustes.
- Dividas: simulacao, sugestoes, criacao de lancamento ou parcelamento e cancelamento.
- Investimentos: simulacao, conversao para plano, aportes e job mensal.
- Moedas e cotacoes: cache por data, Bacen/PTAX, cotacao manual e cambio para BRL.
- Dashboards e relatorios: resumo, fluxo de caixa, categorias, filtros e exclusao de `deleted_at`.
- Exportacao e importacao: jobs, downloads, snapshot, remapeamento de IDs e risco destrutivo.
- Open Finance: estrutura inicial com providers, conexoes e sync stub/mock.

## Lacunas e decisoes para o CEO

- Definir se categoria sera obrigatoria em todo lancamento ou se o comportamento opcional atual sera mantido.
- Definir regra final de status futuro: manter `PENDING` como default ou aplicar `FORECAST`/`SCHEDULED` conforme data e direcao.
- Priorizar parametrizacao versionada de juros, multa e IOF antes de prometer calculo financeiro formal.
- Confirmar se ledger contabil de partidas dobradas e requisito de curto prazo ou objetivo futuro.
- Exigir confirmacao forte, backup/exportacao previa e copy clara antes de liberar importacao destrutiva de snapshot para cliente.
- Tratar Open Finance como stub/mock ate aprovacao tecnica, juridica e de seguranca para integracao bancaria real.
- Ampliar testes antes de mudancas em saldo, baixa, importacao, recorrencia, parcelamento, investimentos ou relatorios. Hoje a cobertura financeira direta localizada esta concentrada em `FinanceInputTest.php`.

## Verificacao executada

- Conferidos os artefatos `docs/GOD-14_inventario_financeiro.md` e `docs/GOD-15_regras_fluxos_financeiros.md`.
- Confirmada a atribuicao da GOD-13 ao Founding Engineer via API do Paperclip.
- Criada esta consolidacao para deixar o encerramento da GOD-13 rastreavel no repositorio.
