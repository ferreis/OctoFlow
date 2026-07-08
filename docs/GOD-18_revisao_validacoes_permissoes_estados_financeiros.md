# GOD-18 - Revisao de validacoes, permissoes e estados financeiros

Atualizado em: 2026-07-07

## Objetivo

Revisar validacoes de entrada, mensagens de erro, transicoes de status financeiro, regras de permissao e riscos de exposicao ou alteracao indevida de dados sensiveis no modulo financeiro do OctoFlow.

Esta revisao apoia a missao da God King Dev de entregar analise de projetos, desenvolvimento, QA, automacao e seguranca de dados com solucoes praticas, confiaveis e bem estruturadas.

## Fontes revisadas

- `docs/GOD-13_mapa_regras_fluxos_financeiros.md`
- `docs/GOD-14_inventario_financeiro.md`
- `docs/GOD-15_regras_fluxos_financeiros.md`
- `docs/REGRAS_NEGOCIO_FINANCEIRO.md`
- `OctoFlow/www/src/Finance`
- `OctoFlow/www/src/Controller/Finance`
- `OctoFlow/www/tests/Unit/Finance/FinanceInputTest.php`
- `OctoFlow/frontend/Host-app/src/composables/useFinancePermissions.js`
- `OctoFlow/frontend/Host-app/src/views/finance`
- `OctoFlow/frontend/Host-app/src/services/finance.js`

## Resultado executivo

GOD-18 esta concluida como revisao. As validacoes principais existem nos servicos financeiros, o isolamento por `owner_id` aparece de forma consistente nas consultas e alteracoes revisadas, e as transicoes centrais de baixa parcial/total estao implementadas. Tambem foram encontrados riscos que precisam virar correcao antes de expor o modulo como fluxo financeiro robusto:

1. O backend financeiro nao aplica permissao granular `finance:read`/`finance:write`; os controllers exigem apenas usuario autenticado.
2. A importacao de snapshot financeiro e destrutiva por padrao e nao exige confirmacao forte no backend.
3. `FORECAST` nao entra no job de vencimento automatico, entao recebiveis futuros podem nao virar `OVERDUE` quando passam da data.
4. Categoria permanece opcional no codigo, enquanto a regra antiga define categoria obrigatoria para novos lancamentos/parcelamentos.
5. Mensagens de erro estao misturadas entre ingles e portugues, o que pode vazar mensagens tecnicas diretamente para usuarios.

## Validacoes e mensagens revisadas

### Cobertura confirmada

- `FinanceInput` valida direcao, dinheiro, datas, status por direcao, paginacao, booleanos e taxa mensal/anual. Ex.: `FinanceInput.php:17`, `:27`, `:67`, `:94`, `:112`.
- `FinanceEntryService` valida titulo, tipo de lancamento, valor esperado positivo, taxa de cambio para moeda diferente de BRL, valor menor que baixado, tipo de baixa, baixa acima do saldo, baixa por cartao apenas para `PAYABLE`, cartao do tipo `CREDIT`, conta ativa e ownership de categoria/conta. Ex.: `FinanceEntryService.php:154`, `:177`, `:191`, `:277`, `:531`, `:601`, `:834`, `:1273`, `:1295`.
- `FinanceCatalogService` valida nome/tipo de categoria, duplicidade por usuario, tipo recorrente `Mensal` depreciado, exclusao de tipo em uso, nome/tipo de conta bancaria. Ex.: `FinanceCatalogService.php:82`, `:123`, `:199`, `:238`, `:280`, `:345`, `:389`.
- `FinanceRecurringService` valida titulo, valor, frequencia `MONTHLY`, dia do mes, intervalo de datas, tipo recorrente obrigatorio, categoria/conta do usuario e conta ativa. Ex.: `FinanceRecurringService.php:60`, `:129`, `:286`, `:712`, `:734`, `:756`.
- `FinanceInstallmentService` valida titulo, total, parcelas, valor liquido positivo, ajuste permitido, ajuste positivo e limite do saldo aberto. Ex.: `FinanceInstallmentService.php:92`, `:238`, `:373`.
- `FinanceDebtPlanService`, `FinanceInvestmentService`, `FinanceCurrencyService`, `FinanceExportService`, `FinanceTransferService` e `FinanceOpenFinanceService` tambem possuem validacoes de campos obrigatorios, ranges e referencias do usuario.

### Lacunas de validacao

- Categoria opcional: `FinanceEntryService::createEntry()` aceita `categoryId` nulo (`FinanceEntryService.php:195`) e `normalizeOwnedCategoryId()` retorna `null` quando o ID nao vem (`FinanceEntryService.php:1273`). Isso diverge de `RN-PAY-02`, `RN-PAY-03`, `RN-PAY-08` e de `docs/REGRAS_NEGOCIO_FINANCEIRO.md`.
- Importacao destrutiva sem confirmacao forte no backend: `FinanceTransferService::importSnapshot()` usa `replaceExisting` com default `true` e chama `deleteOwnerFinanceData()` antes de importar (`FinanceTransferService.php:57`, `:67`, `:72`). A UI envia o arquivo direto em `submitFinanceMigrationImport()` sem etapa de confirmacao irreversivel (`FinanceReportsView.vue:692`).
- Mensagens de erro inconsistentes: ha mensagens em ingles e portugues no backend financeiro. Exemplos: `The entry title is required.`, `The settlement amount cannot exceed the remaining amount.`, `O ajuste nao pode ser maior que o valor restante do parcelamento.`, `Plano de divida nao encontrado.`. Recomendo padronizar no backend ou mapear por codigo de erro na API.

## Transicoes de status revisadas

### Cobertura confirmada

- Default por vencimento:
  - Sem data: `PENDING`.
  - Data passada: `OVERDUE`.
  - Futuro `PAYABLE`: `SCHEDULED`.
  - Futuro `RECEIVABLE`: `FORECAST`.
  - Evidencia: `FinanceInput::defaultStatusByDirection()` em `FinanceInput.php:112`.
- Baixa/recalculo:
  - Saldo zerado em `PAYABLE` vira `PAID`.
  - Saldo zerado em `RECEIVABLE` vira `RECEIVED`.
  - Saldo parcial vira `PARTIAL`.
  - Vencido com saldo aberto vira `OVERDUE`.
  - `CANCELED` e `NEGOTIATED` podem ser mantidos como status manuais.
  - Evidencia: `FinanceEntryService::resolveStatus()` em `FinanceEntryService.php:1042`.
- Soft delete de lancamento reverte movimentacoes com conta bancaria, apaga baixas, marca status `CANCELED`, zera valores e registra historico. Evidencia: `FinanceEntryService.php:359`.
- Job de vencimento atualiza lancamentos abertos para `OVERDUE`. Evidencia: `FinanceEntryService::refreshOverdueStatusesForAllUsers()` em `FinanceEntryService.php:879`.

### Lacuna de status

- O job de vencimento exclui `FORECAST` da lista de elegiveis para virar `OVERDUE` (`FinanceEntryService.php:891`). Pela regra documentada, recebiveis futuros com saldo aberto devem sair de `FORECAST` e virar `OVERDUE` depois do vencimento. Hoje eles podem permanecer como projecao ate algum outro fluxo recalcular o status.

## Permissoes e protecao de dados

### Cobertura confirmada

- Os controllers financeiros exigem autenticacao (`#[IsGranted('IS_AUTHENTICATED_FULLY')]`), por exemplo `FinanceEntryController.php:16`, `FinanceCatalogController.php:15`, `FinanceTransferController.php:14`.
- Os servicos revisados resolvem `owner_id` a partir do usuario autenticado e aplicam `owner_id` em buscas, insercoes e atualizacoes, reduzindo risco de IDOR entre usuarios.
- IDs associados, como categoria, conta bancaria, tipo recorrente, planos, export jobs e snapshots, sao validados por `owner_id` na maioria dos fluxos revisados.
- O frontend possui `useFinancePermissions()` com `finance:read`, `finance:write`, `finance:*`, `admin` e aliases financeiros (`useFinancePermissions.js:17`) e desabilita acoes de escrita nas views financeiras.

### Riscos encontrados

#### Alto - Backend nao aplica permissao granular financeira

Os controllers financeiros exigem apenas autenticacao, nao `finance:read` ou `finance:write`. A UI desabilita acoes com `canWriteFinance`, mas essa protecao pode ser contornada chamando a API diretamente. Evidencias:

- `FinanceCatalogController.php:15`
- `FinanceEntryController.php:16`
- `FinanceTransferController.php:14`
- `FinanceReportsView.vue:645`, `:692`, `:950`, `:991`
- `useFinancePermissions.js:31`

Impacto: usuario autenticado sem permissao financeira de escrita pode criar, alterar, baixar, importar, exportar ou apagar dados financeiros se conseguir chamar os endpoints diretamente. Mesmo quando os dados ficam restritos ao proprio `owner_id`, isso quebra o modelo de permissao apresentado pela interface.

Mitigacao recomendada: criar guard/voter backend para `finance:read` e `finance:write` e aplicar nos controllers por tipo de rota. Rotas `GET` de leitura/exportacao devem exigir leitura; rotas destrutivas ou mutaveis devem exigir escrita.

#### Alto - Importacao de snapshot e destrutiva por padrao

`replaceExisting` tem default `true`, e o backend apaga dados financeiros do usuario antes da reimportacao dentro da transacao. A transacao reduz perda em falha tecnica, mas nao protege contra importacao acidental ou maliciosa autorizada. Evidencias:

- `FinanceTransferService.php:57`
- `FinanceTransferService.php:67`
- `FinanceTransferService.php:72`
- `FinanceTransferService.php:839`
- `FinanceReportsView.vue:692`
- `FinanceReportsView.vue:957`

Impacto: perda operacional de historico financeiro do usuario por clique equivocado, arquivo errado ou chamada direta de API.

Mitigacao recomendada: exigir confirmacao forte no payload, por exemplo `confirmReplaceExisting: "SUBSTITUIR_DADOS_FINANCEIROS"`, bloquear `replaceExisting=true` sem essa confirmacao, e adicionar aviso claro na UI antes do submit.

#### Medio - Exportacao e snapshot carregam dados sensiveis em massa

O snapshot retorna `sourceOwnerId` e todas as secoes financeiras do usuario, incluindo contas, lancamentos, baixas, ledger, conexoes externas e transacoes externas. Exportacoes XLSX tambem gravam `Owner ID` em metadata. Evidencias:

- `FinanceTransferService.php:22`
- `FinanceTransferService.php:27`
- `FinanceTransferService.php:28`
- `FinanceTransferService.php:38`
- `FinanceTransferService.php:41`
- `FinanceTransferService.php:44`
- `FinanceExportService.php:316`

Impacto: vazamento de historico financeiro completo se permissao de exportacao nao for restrita, se arquivo for compartilhado por engano ou se logs/telemetria capturarem payload.

Mitigacao recomendada: exigir permissao explicita de exportacao, nao incluir identificadores internos desnecessarios no arquivo de usuario final, e documentar tratamento seguro de snapshots.

#### Medio - Categoria obrigatoria ainda e decisao pendente

O codigo aceita categoria nula em lancamentos, recorrencias e parcelamentos; a regra antiga define categoria obrigatoria. Impacto: relatorios por categoria ficam incompletos e QA financeiro pode divergir do esperado.

Mitigacao recomendada: CEO/produto deve decidir se categoria e obrigatoria. Se sim, aplicar no backend e UI; se nao, atualizar a regra oficial e testes.

#### Medio - `FORECAST` nao vence automaticamente

Recebiveis futuros podem ficar como `FORECAST` depois do vencimento porque o job ignora esse status. Impacto: KPIs de atraso e macro status podem subestimar recebiveis vencidos.

Mitigacao recomendada: incluir `FORECAST` no recalculo automatico quando `due_date < today` e `remaining_amount_brl > 0`, com teste cobrindo recebivel futuro que vence.

#### Baixo - Mensagens de erro nao estao padronizadas

Mensagens aparecem em ingles e portugues e sao retornadas diretamente pelo controller (`FinanceControllerHelper.php:35`). Impacto: experiencia inconsistente e maior chance de expor detalhes internos.

Mitigacao recomendada: padronizar erros em pt-BR ou retornar codigos estaveis de erro para traducao no frontend.

## Cobertura de testes

Teste financeiro localizado: `OctoFlow/www/tests/Unit/Finance/FinanceInputTest.php`.

Cobertura confirmada:

- Direcao valida/invalida.
- Normalizacao monetaria e cents.
- Status por direcao.
- Default de status futuro `SCHEDULED`/`FORECAST`.
- Default vencido/hoje/sem data.
- Conversao de taxa anual para mensal.
- Booleano para banco.

Lacunas de teste relevantes para GOD-18:

- Nao ha teste de controller/API para permissao `finance:read`/`finance:write`.
- Nao ha teste de `FinanceEntryService` para baixa parcial/total, baixa acima do saldo, baixa por cartao, `FORECAST` vencendo para `OVERDUE` e reversao de ledger.
- Nao ha teste de importacao destrutiva exigindo confirmacao forte.
- Nao ha teste de exportacao/snapshot com protecao de dados sensiveis.

## Disposicao

Revisao concluida. Os criterios de aceite foram cobertos por evidencia de codigo e os riscos foram apontados com severidade, impacto e mitigacao sugerida.
