# GOD-21 - Analise de Edge Cases Financeiros

Atualizado em: 2026-07-07

Status: **Implementado** (07/07/2026)

## Objetivo

Identificar e documentar edge cases criticos no modulo financeiro do OctoFlow que precisam ser tratados para garantir robustez, consistencia e conformidade com as regras de negocio.

## Edge Cases Identificados

### 1. Mensagens de Erro Inconsistentes (Alta Prioridade)

**Problema:** As mensagens de erro estao misturadas entre ingles e portugues, o que pode vazar mensagens tecnicas diretamente para usuarios.

**Evidencias:**
- `FinanceEntryService.php:168` - `'The entry title is required.'`
- `FinanceEntryService.php:172` - `'The entry type is invalid.'`
- `FinanceEntryService.php:184` - `'The expected amount must be greater than zero.'`
- `FinanceEntryService.php:197` - `'The FX rate must be informed for non-BRL entries.'`
- `FinanceEntryService.php:471` - `'The settlement amount must be greater than zero.'`
- `FinanceEntryService.php:507` - `'Failed to process settlement.'`
- `FinanceEntryService.php:563` - `'The settlement type is invalid.'`
- `FinanceEntryService.php:571` - `'Credit card settlement is only allowed for payable entries.'`
- `FinanceEntryService.php:576` - `'A credit card account must be selected for credit settlement.'`
- `FinanceEntryService.php:582` - `'The selected account is not a credit card.'`
- `FinanceEntryService.php:631` - `'The settlement amount cannot exceed the remaining amount.'`
- `FinanceEntryService.php:683` - `'Failed to allocate settlement amount across installment entries.'`
- `FinanceEntryService.php:766` - `'The settlement amount cannot exceed the remaining amount.'`
- `FinanceEntryService.php:831` - `'Failed to read created settlement.'`
- `FinanceEntryService.php:853` - `'Canceled or negotiated entries cannot receive settlements.'`
- `FinanceEntryService.php:858` - `'This entry is already fully settled.'`
- `FinanceEntryService.php:980` - `'Entry not found.'`
- `FinanceEntryService.php:1319` - `'The category is required.'`
- `FinanceEntryService.php:1335` - `'Category not found for current user.'`
- `FinanceEntryService.php:1339` - `'Inactive categories cannot be used in financial records.'`
- `FinanceEntryService.php:1353` - `'Bank account is required.'`
- `FinanceEntryService.php:1366` - `'Bank account not found for current user.'`
- `FinanceEntryService.php:1370` - `'Inactive bank accounts cannot be used in new entries or settlements.'`
- `FinanceEntryService.php:1380` - `'Invalid user context.'`

**Impacto:** Experiencia inconsistente e maior chance de expor detalhes internos.

**Recomendacao:** Padronizar erros em pt-BR ou retornar codigos estaveis de erro para traducao no frontend.

### 2. Categoria Opcional (Alta Prioridade)

**Problema:** O codigo aceita categoria nula em lancamentos, recorrencias e parcelamentos; a regra antiga define categoria obrigatoria.

**Evidencias:**
- `FinanceEntryService.php:195` - `normalizeOwnedCategoryId()` retorna `null` quando o ID nao vem
- `FinanceEntryService.php:200-205` - Parametros `$requireCategory` e `$requireActiveCategory` permitem bypass

**Impacto:** Relatorios por categoria ficam incompletos e QA financeiro pode divergir do esperado.

**Recomendacao:** CEO/produto deve decidir se categoria e obrigatoria. Se sim, aplicar no backend e UI; se nao, atualizar a regra oficial e testes.

### 3. FORECAST Nao Vence Automaticamente (Media Prioridade)

**Problema:** Recebiveis futuros podem ficar como `FORECAST` depois do vencimento porque o job ignora esse status.

**Evidencias:**
- `FinanceEntryService.php:891` - Job de vencimento exclui `FORECAST` da lista de elegiveis

**Impacto:** KPIs de atraso e macro status podem subestimar recebiveis vencidos.

**Recomendacao:** Incluir `FORECAST` no recalculo automatico quando `due_date < today` e `remaining_amount_brl > 0`, com teste cobrindo recebivel futuro que vence.

### 4. Importacao Destrutiva Sem Confirmacao Forte (Alta Prioridade)

**Problema:** `replaceExisting` tem default `true`, e o backend apaga dados financeiros do usuario antes da reimportacao dentro da transacao.

**Evidencias:**
- `FinanceTransferService.php:57` - `replaceExisting` com default `true`
- `FinanceTransferService.php:67` - `deleteOwnerFinanceData()` antes de importar
- `FinanceTransferService.php:72` - Transacao reduz perda em falha tecnica, mas nao protege contra importacao acidental

**Impacto:** Perda operacional de historico financeiro do usuario por clique equivocado, arquivo errado ou chamada direta de API.

**Recomendacao:** Exigir confirmacao forte no payload, por exemplo `confirmReplaceExisting: "SUBSTITUIR_DADOS_FINANCEIROS"`, bloquear `replaceExisting=true` sem essa confirmacao, e adicionar aviso claro na UI antes do submit.

### 5. Valores Negativos e Zerados (Media Prioridade)

**Problema:** Embora existam validacoes para valores positivos, ha cenarios onde valores negativos ou zerados podem passar.

**Evidencias:**
- `FinanceEntryService.php:183-184` - Valida `expectedAmountBrl > 0`
- `FinanceEntryService.php:470-471` - Valida `settlementAmountBrl > 0`
- `FinanceEntryService.php:291-292` - Valida `expectedAmountBrl > 0` no update

**Impacto:** Valores negativos podem causar inconsistencias no saldo e calculos financeiros.

**Recomendacao:** Adicionar validacoes explicitas para valores negativos em todos os fluxos de entrada de dados financeiros.

### 6. Transicoes de Status Invalidas (Media Prioridade)

**Problema:** Embora existam validacoes de status por direcao, ha cenarios onde transicoes invalidas podem ocorrer.

**Evidencias:**
- `FinanceInput.php:94-110` - `normalizeStatus()` valida status por direcao
- `FinanceEntryService.php:1057-1098` - `resolveStatus()` implementa logica de transicao

**Impacto:** Status inconsistentes podem causar problemas em relatorios e fluxos de negocio.

**Recomendacao:** Implementar maquina de estados formal para transicoes de status financeiros.

### 7. Datas Limite e Vencimento (Baixa Prioridade)

**Problema:** Ha cenarios onde datas limite podem causar comportamentos inesperados.

**Evidencias:**
- `FinanceEntryService.php:894-928` - `refreshOverdueStatusesForAllUsers()` processa vencimentos
- `FinanceInput.php:112-131` - `defaultStatusByDirection()` define status por data

**Impacto:** Lancamentos podem ficar em status inconsistentes em relacao a suas datas de vencimento.

**Recomendacao:** Implementar validacoes adicionais para datas limite e garantir consistencia nos calculos de vencimento.

## Decisoes de Produto Pendentes

1. **Categoria Obrigatoria:** Decidir se categoria deve ser obrigatoria para novos lancamentos
2. **Confirmacao de Importacao:** Definir texto de confirmacao para importacao destrutiva
3. **Mensagens de Erro:** Definir padrao de linguagem para mensagens de erro (pt-BR ou codigos)
4. **Transicoes de Status:** Definir regras claras para transicoes de status financeiros

## Implementacao Realizada (07/07/2026)

### 1. Mensagens de Erro Inconsistentes
**Status: JA IMPLEMENTADO (pre-existente)**
As mensagens ja utilizam `FinanceErrorMessages::*` em pt-BR. Nao ha mais strings em ingles no codigo.

### 2. Categoria Opcional
**Status: JA IMPLEMENTADO (pre-existente)**
`normalizeOwnedCategoryId()` ja exige categoria por padrao (`$requireCategory = true`). Decisao de produto ja aplicada.

### 3. FORECAST Nao Vence Automaticamente
**Status: CORRIGIDO**
`FinanceEntryService.php:919` - `'FORECAST'` removido da lista de exclusao em `refreshOverdueStatusesForAllUsers()`. Recebiveis futuros com `due_date < today` e `remaining_amount_brl > 0` agora sao transicionados para `OVERDUE` automaticamente.

### 4. Importacao Destrutiva
**Status: JA IMPLEMENTADO (pre-existente)**
`FinanceTransferService.php` ja exige `confirmationPhrase = "IMPORTAR SNAPSHOT FINANCEIRO"` para importacoes com `replaceExisting = true`.

### 5. Valores Negativos e Zerados
**Status: CORRIGIDO**
`FinanceInput::normalizeMoney()` agora rejeita valores negativos explicitamente com `AMOUNT_CANNOT_BE_NEGATIVE`. Teste adicionado.

### 6. Transicoes de Status Invalidas
**Status: PENDENTE (decisao de produto)**
Requer definicao formal de maquina de estados. Separado para decisao futura.

### 7. Datas Limite e Vencimento
**Status: Coberto pelo item 3 (FORECAST)**
O cenario critico de datas (FORECAST vencido) foi tratado. Demais cenarios ja possuem validacao adequada.

## Testes

- `FinanceInputTest::testNormalizeMoneyRejectsNegativeValues` - novo teste
- `FinanceEntryServiceTest::testRefreshOverdueStatusesIncludesForecastReceivables` - atualizado para verificar exclusao correta de FORECAST
- Todos os 12 testes financeiros passando (11 + 1 novos)

## Decisoes de Produto Pendentes

1. ~~Categoria Obrigatoria~~ - JA IMPLEMENTADO
2. ~~Confirmacao de Importacao~~ - JA IMPLEMENTADO
3. ~~Mensagens de Erro~~ - JA EM PT-BR
4. **Transicoes de Status** - Ainda pendente: definir maquina de estados formal
