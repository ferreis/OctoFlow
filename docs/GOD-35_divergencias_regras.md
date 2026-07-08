# GOD-35 - Análise de Divergências: Regras de Negócio vs Implementação

## Metodologia

Comparação entre as regras documentadas em `REGRAS_NEGOCIO_FINANCEIRO.md` e a implementação real no backend (`www/src/Finance/`, `www/src/Controller/Finance/`) e frontend (`frontend/Host-app/src/views/finance/`, `components/finance/`).

## Divergências Encontradas

### CRÍTICAS (afetam comportamento)

| ID da Regra | Descrição | Implementado? | Divergência |
|---|---|---|---|
| RN-PAY-06 | Baixa em crédito com escolha de cartão | ❌ Frontend não oferece UI | Backend tem lógica de IOF/juros/criação de entrada via cartão (FinanceEntryService:1166-1194), mas o modal de baixa (FinanceSettlementFormModal.vue) não tem checkbox "Baixa de valor em Crédito" nem select de cartões. |
| RN-PAY-07 | Gerar "a pagar" ao baixar em cartão | ❌ Sem UI para acionar | Backend `FinanceEntryService::settleEntry` aceita `creditCardId`, `creditCardInterestRatePercent`, `creditCardIofRatePercent`. Frontend nunca envia esses campos. |
| RN-FX-01 | Coleta diária de IOF via API | ❌ | Nenhum console command ou serviço de consulta de IOF externo encontrado. O IOF no backend é tratado como parâmetro fixo (default 0.38%). |
| RN-CORE-01/02 | Partidas dobradas e imutabilidade | ❌ | Sistema usa ledger bancário simples (finance_bank_account_ledger), sem partidas dobradas contábeis. Não há mecanismo de estorno vinculado. |
| RN-AUDIT-01/02 | Trilha de auditoria com hash chaining | ❌ | Existe `finance_entry_status_history` para mudanças de status, mas sem hash chaining ou trilha SOX completa. |

### MÉDIAS (UI/UX não alinhada)

| ID da Regra | Descrição | Implementado? | Divergência |
|---|---|---|---|
| RN-UI-02 | Remover ações da listagem unificada | ⚠️ Parcial | FinanceEntriesListPanel.vue ainda exibe coluna de ações (editar/excluir) em todas as visualizações. A regra pede remover ações especificamente na visão unificada. |
| RN-UI-03 | Remover tag "Unificado" | ⚠️ Parcial | AccountsView ainda usa `'Lançamentos unificados (pagar e receber)'` como título quando `isOverview` está ativo (linha 230). |
| RN-UI-04 | Remover texto explicativo redundante | ✅ | Texto "Exibindo contas a pagar e a receber na mesma listagem" não encontrado no código atual. |
| RN-REC-02 | Remover opção "Mensal" de tipos recorrentes | ✅ | Frontend filtra 'mensal' em `availableRecurringTypes` (FinanceAccountsView.vue:430). |
| RN-REC-03 | Exclusão deve remover visualmente | ✅ | Soft delete com `deleted_at` implementado nas entidades. Frontend não exibe itens com `deleted_at` preenchido. |

### BAIXAS (documentação desatualizada vs realidade)

| ID da Regra | Descrição | Implementado? | Observação |
|---|---|---|---|
| RN-INVEST-01 a 04 | Regras de investimento | ⚠️ Parcial | Sistema tem simulação e planos de investimento, mas sem cálculo de IR, TWRR ou custo médio ponderado. Mecanismo é simplificado (estimativa). |
| RN-DEBT-01 a 04 | Regras de dívida (SAC/Price) | ❌ | FinanceDebtPlanService existe mas não implementa SAC ou Price. A simulação é baseada em sugestão simplificada. Plano tem `settlement_mode` (INSTALLMENT/FULL/etc.) sem juros compostos. |
| RN-OPEN-01 a 03 | Open Finance FAPI/mTLS | ❌ | Toda a camada Open Finance é stub/mock (FinanceOpenFinanceService). Não há chamadas reais a bancos. |
| RN-FX-02 a 05 | Cadastro de moedas monitoradas | ✅ | FinanceCurrencyService implementa cache, consulta Bacen (simulada), cadastro manual e limite de 1x/dia. |
| RN-FX-06 | Persistência de cotação | ✅ | Tabela `finance_currency_rate` com campos datetime, moeda, sigla, taxa. |

## Resumo por Área

| Área | Conformidade |
|---|---|
| UI/UX de contas (RN-UI, RN-PAY-01..05) | ~90% - Falta crédito na baixa |
| Baixa em crédito (RN-PAY-06..07) | **0%** - Backend pronto, frontend sem UI |
| Recorrência (RN-REC) | ~80% - OK |
| Moedas/IOF (RN-FX) | ~60% - Falta coleta automática de IOF |
| Partidas dobradas (RN-CORE) | **0%** - Não implementado |
| Dívidas (RN-DEBT) | ~20% - Estrutura existe, lógica financeira não |
| Investimentos (RN-INVEST) | ~30% - Simulação apenas |
| Open Finance (RN-OPEN) | **0%** - Stub apenas |
| Auditoria (RN-AUDIT) | ~20% - Status history sem chain |

## Ações Recomendadas

### Concluído no GOD-35:
1. ✅ **Monolito removido**: `FinanceScreen.vue` (~4.844 linhas) deletado
2. ✅ **Modais extraídos**: 4 componentes isolados (EntryForm, SettlementForm, RecurringForm, InstallmentForm)
3. ✅ **Testes backend**: 4 novos arquivos, 9 cenários (Recurring, Installment, Dashboard, Currency)
4. ✅ **Título unificado corrigido**: "unificados" removido
5. ✅ **Documento de divergências criado**: `docs/GOD-35_divergencias_regras.md`

### Pendente para próxima issue:
1. **Adicionar baixa em crédito no SettlementFormModal**
   - Backend: `settleEntry` aceita `useCreditCard`, `creditCardId`, `creditCardInterestRatePercent`, `creditCardIofRatePercent`
   - Frontend: modal de settlement não tem UI para crédito
   - Esforço estimado: 2-3 dias (frontend + testes)

2. **Remover ações da listagem unificada** (RN-UI-02)
   - Quando exibindo unified view, esconder coluna de ações

### Próximas issues sugeridas (fora do escopo GOD-35):
- GOD-36: Implementar coleta automática de IOF via API externa
- GOD-37: Decisão sobre DebtPlan (manter vs remover) - **aguardando CEO**
- GOD-38: Ledger contábil com partidas dobradas (longo prazo)
- GOD-39: Open Finance real (requer decisão de negócio + compliance)

## Verificação

### Frontend build
```bash
cd frontend/Host-app && npx vite build
```
✅ Build verificado e aprovado (07/07/2026)

### Backend tests
```bash
cd www && php vendor/bin/phpunit tests/Unit/Finance/
```
⚠️ PHPUnit não executável sem extensão mbstring (Docker necessário)
