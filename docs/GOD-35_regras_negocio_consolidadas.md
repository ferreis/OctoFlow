# Regras de Negocio - Financeiro OctoFlow (Consolidado)

Data: 07/07/2026
Issue: GOD-35 — Refatoracao do modulo financeiro
Projeto: OctoFlow (corrigido — nao Netuno_2)

## Resumo

Documento consolidado de regras de negocio financeiro do OctoFlow, com status de implementacao apos auditoria e correcoes. Substitui rascunhos anteriores como fonte unica da verdade.

## Stack do Projeto

- Backend: Symfony PHP (`/OctoFlow/www/src/Finance/`)
- Frontend: Vue 3 + Pinia (`/OctoFlow/frontend/Host-app/`)
- Banco: PostgreSQL com migrations versionadas
- Container: Docker (php, nginx, postgres, redis)

## Regras por Fluxo

### 1. Contas a Pagar / Receber (Fluxo de Lances)

| ID | Regra | Status | Observacao |
|---|---|---|---|
| RN-PAY-01 | Conta bancaria opcional no cadastro, obrigatoria na baixa | ✅ OK | Backend valida; frontend exige no modal de baixa |
| RN-PAY-02 | Campos obrigatorios do lcto simples | ✅ OK | Titulo, tipo, valor BRL, vencimento, categoria |
| RN-PAY-03 | Campos obrigatorios do lcto recorrente | ✅ OK | Mesmo + tipo recorrente obrigatorio |
| RN-PAY-04 | Baixa por conta bancaria ou cartao | ⚠️ Parcial | Backend pronto; frontend sem UI de cartao (corrigido nesta issue) |
| RN-PAY-05 | Select de baixa com `Titulo - dd/mm/aaaa` | ✅ OK | `formatSettlementEntryOptionLabel` no SettlementFormModal |
| RN-PAY-06 | Baixa em credito com checkbox + select cartao | ❌ Corrigido | Frontend foi atualizado nesta issue para enviar `useCreditCard` + `creditCardId` |
| RN-PAY-07 | Gerar novo "a pagar" ao baixar em cartao | ❌ Corrigido | Backend `FinanceEntryService::settleEntry` ja cria; frontend agora envia parametros |
| RN-PAY-08 | Campos do parcelamento | ✅ OK | 6 campos obrigatorios + conta opcional |

### 2. Tipos de Recorrencia

| ID | Regra | Status | Observacao |
|---|---|---|---|
| RN-REC-01 | Tipos permitidos: Assinatura, Conta fixa, Salario | ✅ OK | Garantidos por usuario |
| RN-REC-02 | Remover opcao `Mensal` | ✅ OK | Filtrado no frontend (`availableRecurringTypes`) |
| RN-REC-03 | Exclusao logica com `deleted_at` | ✅ OK | Itens excluidos nao aparecem como ativos |

### 3. IOF e Moedas

| ID | Regra | Status | Observacao |
|---|---|---|---|
| RN-FX-01 | Coleta diaria de IOF via API | ❌ Pendente | IOF tratado como default fixo (0.38%) — sem consulta externa |
| RN-FX-02 | Cadastro de moedas monitoradas | ✅ OK | SettingsView + FinanceCurrencyService |
| RN-FX-03 | Cotacao automatica 1x/dia | ✅ OK | Cache por `owner_id + currency_code + quote_date` |
| RN-FX-04 | Botao de atualizacao manual c/ confirmacao | ✅ OK | Backend `forceRefresh` + confirmacao via frontend |
| RN-FX-05 | Insercao manual de cotacao | ✅ OK | `createManualRate` endpoint |
| RN-FX-06 | Persistencia de cotacao | ✅ OK | Tabela `finance_currency_rate` |

### 4. Nucleo Contabil e Ledger

| ID | Regra | Status | Observacao |
|---|---|---|---|
| RN-CORE-01 | Partidas dobradas atomicas | ❌ Nao implementado | Ledger bancario simples — sem ledger contabil |
| RN-CORE-02 | Imutabilidade de registros | ❌ Nao implementado | Sem estorno vinculado |
| RN-CORE-03 | Hierarquia de plano de contas | ❌ Nao implementado | Fora do escopo atual |
| RN-CORE-04 | Exclusao logica fora do ledger | ✅ OK | `deleted_at` implementado |

### 5. Gestao de Lances e Fluxo de Caixa

| ID | Regra | Status | Observacao |
|---|---|---|---|
| RN-ENTRY-01 | Calculo de valor liquido | ✅ OK | `remaining_amount_brl = expected - settled` |
| RN-ENTRY-02 | Geracao lazy de recorrencia | ✅ OK | Geracao sob demanda + job diario |
| RN-ENTRY-03 | Ajuste de residuo em parcelas | ✅ OK | Ultima parcela absorve arredondamento |
| RN-ENTRY-04 | Status preditivo (forecast) | ✅ OK | `defaultStatusByDirection` implementado |

### 6. Dividas e Investimentos

| ID | Regra | Status | Observacao |
|---|---|---|---|
| RN-DEBT-01 | Amortizacao SAC | ❌ Nao implementado | Apenas divisao simples |
| RN-DEBT-02 | Amortizacao Price | ❌ Nao implementado | Apenas divisao simples |
| RN-DEBT-03 | Multa e juros de mora | ❌ Nao implementado | Sem fluxo completo |
| RN-DEBT-04 | Comprometimento de renda | ❌ Nao implementado | Bloqueio 30% nao implementado |
| RN-INVEST-01/04 | Regras de investimento | ⚠️ Parcial | Simulacao existe; sem IR, TWRR ou custo medio |

### 7. Open Finance

| ID | Regra | Status | Observacao |
|---|---|---|---|
| RN-OPEN-01/03 | Open Finance real | ❌ Stub | `FinanceOpenFinanceService` e mock — sem chamadas reais |

### 8. Auditoria

| ID | Regra | Status | Observacao |
|---|---|---|---|
| RN-AUDIT-01/02 | Trilha SOX + hash chaining | ❌ Nao implementado | `finance_entry_status_history` existe sem hash chain |

## Divergencias Corrigidas nesta Issue

### Criticas

1. **Baixa em credito (RN-PAY-06/07)**: Frontend nao oferecia UI para baixa em cartao de credito, embora o backend ja tivesse logica completa. **Corrigido** — adicionado checkbox `useCreditCard` + select de cartoes + envio de `creditCardInterestRatePercent` e `creditCardIofRatePercent`.

### UI/UX

2. **RN-UI-02 (acoes na listagem unificada)**: O componente `FinanceEntriesListPanel.vue` ja esconde acoes quando `showDirectionFilter` esta ativo (`shouldShowEntryActions`). **Nao precisou de correcao** — codigo ja estava conforme.

3. **RN-UI-03 (tag unificado)**: Título do AccountsView usa "Lancamentos (pagar e receber)" sem "unificado". **OK**.

### Pendentes (fora do escopo GOD-35)

4. Coleta automatica de IOF (RN-FX-01) — requer API externa
5. Partidas dobradas contabeis (RN-CORE-01/02) — requer nova arquitetura
6. SAC/Price em dividas (RN-DEBT-01/02) — requer engine de calculo
7. Auditoria com hash chain (RN-AUDIT-01/02) — requer infra de logging
8. Open Finance real (RN-OPEN) — requer aprovacao compliance

## Ordem de Execucao Recomendada

1. ✅ **Concluido GOD-35**: Baixa em credito no frontend, modais extraidos, testes backend
2. 🔜 Proxima: Coleta IOF automatica (GOD-36 sugerida)
3. 🔜 Proxima: Decisao DebtPlan (manter/remover)
4. 🔜 Futuro: Ledger contabil + partidas dobradas
5. 🔜 Futuro: Auditoria com hash chain
6. 🔜 Futuro: Open Finance real

## Como Rodar

```bash
# Backend (Docker)
cd OctoFlow && docker-compose up -d
docker exec -it octoflow_php php bin/console doctrine:migrations:migrate

# Frontend
cd OctoFlow/OctoFlow/frontend/Host-app && npm run dev

# Testes backend (via Docker)
docker exec -it octoflow_php php vendor/bin/phpunit tests/Unit/Finance/
```
