# GOD-29 - Revisao de minimizacao de dados em exportacao e snapshot financeiro

Data da revisao: 2026-07-07

## Objetivo e criterios de aceite

Objetivo confirmado pelo issue: revisar minimizacao de dados em exportacao e snapshot financeiro.

Como o GOD-29 nao possui descricao propria, usei os criterios herdados do GOD-18:

- Revisar riscos de permissao, vazamento ou alteracao indevida de dados financeiros.
- Registrar evidencias, lacunas e mitigacoes recomendadas.
- Manter escopo de seguranca, sem aprovar risco material ou assumir deploy.

## Escopo revisado

- `OctoFlow/www/src/Finance/FinanceExportService.php`
- `OctoFlow/www/src/Finance/FinanceTransferService.php`
- `OctoFlow/www/src/Controller/Finance/FinanceExportController.php`
- `OctoFlow/www/src/Controller/Finance/FinanceTransferController.php`
- `OctoFlow/frontend/Host-app/src/views/finance/FinanceReportsView.vue`
- `OctoFlow/frontend/Host-app/src/services/finance.js`
- `OctoFlow/frontend/Host-app/src/composables/useFinancePermissions.js`
- Migrations financeiras com campos de contas bancarias e Open Finance.

## Conclusao executiva

A revisao esta concluida. Nao encontrei evidencia de exportacao de senha, token de acesso, refresh token ou segredo criptografico nos fluxos financeiros revisados. Porem, a minimizacao ainda e insuficiente para artefatos destinados a usuario final, analise externa ou compartilhamento fora do sistema.

O snapshot financeiro funciona mais como backup tecnico completo do que como exportacao minimizada: ele inclui identificadores internos, secoes completas de dados financeiros e payloads brutos de transacoes externas. A exportacao XLSX tambem retorna metadados e campos internos que nao sao estritamente necessarios para relatorio de usuario.

Nao considero este risco aprovado. A recomendacao e o CTO priorizar correcao de minimizacao antes de liberar esses artefatos como fluxo seguro para cliente.

## Achados

### Medio - API de exportacoes retorna caminho local do servidor

`FinanceExportService::listJobs()` e `getJobById()` selecionam `file_path AS "filePath"` e `normalizeJobPayload()` retorna esse campo ao cliente. Esse caminho e necessario internamente para download e exclusao, mas nao para a UI.

Evidencias:

- `OctoFlow/www/src/Finance/FinanceExportService.php:82`
- `OctoFlow/www/src/Finance/FinanceExportService.php:89`
- `OctoFlow/www/src/Finance/FinanceExportService.php:844`
- `OctoFlow/www/src/Finance/FinanceExportService.php:851`
- `OctoFlow/www/src/Finance/FinanceExportService.php:878`

Impacto: exposicao desnecessaria de estrutura de filesystem, diretorios de exportacao e possivel identificador interno de owner no path. Isso aumenta superficie para reconhecimento em caso de vazamento de resposta API, logs de frontend ou suporte.

Mitigacao recomendada: remover `filePath` do payload publico de listagem/criacao. Manter o caminho apenas no backend, retornando `id`, `fileName`, status e datas.

### Medio - XLSX inclui identificadores internos sem necessidade clara

A aba `Metadata` grava `Owner ID` e os relatorios incluem IDs internos de entradas, planos, execucoes e simulacoes. Para relatorio final de usuario, esses IDs nao parecem necessarios; o download ja e recuperado por job autenticado e escopado por owner.

Evidencias:

- `OctoFlow/www/src/Finance/FinanceExportService.php:316`
- `OctoFlow/www/src/Finance/FinanceExportService.php:317`
- `OctoFlow/www/src/Finance/FinanceExportService.php:393`
- `OctoFlow/www/src/Finance/FinanceExportService.php:581`
- `OctoFlow/www/src/Finance/FinanceExportService.php:630`
- `OctoFlow/www/src/Finance/FinanceExportService.php:676`

Impacto: facilita correlacao de usuario, registros e estrutura interna fora do sistema. Se o arquivo for compartilhado com terceiro, dados tecnicos internos acompanham o historico financeiro.

Mitigacao recomendada: remover `Owner ID` do XLSX e substituir IDs internos por campos de negocio quando possivel. Se algum ID for necessario para suporte/importacao, criar modo tecnico separado e explicitamente rotulado.

### Medio - Snapshot e backup completo, nao exportacao minimizada

`FinanceTransferService::exportSnapshot()` retorna `sourceOwnerId` e varias secoes completas de dados financeiros. Para varias tabelas, o servico usa `SELECT *`, incluindo `owner_id`, timestamps, IDs internos e campos tecnicos usados para reconstruir o banco.

Evidencias:

- `OctoFlow/www/src/Finance/FinanceTransferService.php:22`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:26`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:27`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:1219`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:1223`

Impacto: o snapshot e adequado para restauracao tecnica, mas nao atende minimizacao para compartilhamento, auditoria externa ou analise pontual. O arquivo concentra historico financeiro completo em um JSON portavel.

Mitigacao recomendada: separar formalmente dois formatos:

- `backup tecnico`: completo, restauravel, com aviso claro de dados sensiveis e permissao especifica.
- `exportacao minimizada`: campos allowlist por finalidade, sem owner interno, sem caminhos, sem payload bruto e sem IDs internos quando nao necessarios.

### Medio - Dados Open Finance incluem identificadores e payload bruto

As migrations indicam que conexoes externas guardam `external_consent_id`; transacoes externas guardam `raw_payload JSON`. O snapshot exporta conexoes, contas, transacoes e links externos. Nao encontrei token/segredo financeiro nesses campos, mas `raw_payload` pode conter dados de provedor alem do minimo necessario.

Evidencias:

- `OctoFlow/www/migrations/Version20260325150000.php:70`
- `OctoFlow/www/migrations/Version20260325150000.php:77`
- `OctoFlow/www/migrations/Version20260325150000.php:82`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:38`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:40`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:608`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:1311`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:1351`

Impacto: identificadores de consentimento, conta externa, transacao externa e payload bruto podem expor dados bancarios/financeiros que nao sao indispensaveis para toda finalidade de exportacao.

Mitigacao recomendada: aplicar allowlist no snapshot ou redacao por campo para Open Finance. Evitar exportar `raw_payload` em formato minimizado; se necessario para backup tecnico, marcar como sensivel e restringir permissao.

### Baixo/Medio - UI comunica uso amplo como "analises externas"

A UI descreve o snapshot como recurso para backup, analises externas ou migracoes seguras. Pelo conteudo atual, essa mensagem pode induzir compartilhamento de um arquivo completo e sensivel para analise externa.

Evidencias:

- `OctoFlow/frontend/Host-app/src/views/finance/FinanceReportsView.vue:945`
- `OctoFlow/frontend/Host-app/src/views/finance/FinanceReportsView.vue:947`
- `OctoFlow/frontend/Host-app/src/views/finance/FinanceReportsView.vue:950`

Impacto: risco operacional de usuario exportar historico completo quando precisava de relatorio minimizado.

Mitigacao recomendada: ajustar copy para tratar snapshot como backup/restauracao completa e adicionar aviso antes do download. Para analise externa, direcionar para exportacao minimizada.

## Controles positivos observados

- Endpoints revisados exigem usuario autenticado via `#[IsGranted('IS_AUTHENTICATED_FULLY')]`.
- Consultas principais escopam dados por `owner_id`.
- Download de XLSX verifica dono do job antes de retornar arquivo.
- Frontend bloqueia exportacao/importacao quando `canWriteFinance` e falso.
- Nao encontrei campos de token de acesso, refresh token ou senha nas tabelas financeiras de Open Finance revisadas.

Evidencias:

- `OctoFlow/www/src/Controller/Finance/FinanceExportController.php:17`
- `OctoFlow/www/src/Controller/Finance/FinanceTransferController.php:15`
- `OctoFlow/www/src/Finance/FinanceExportService.php:237`
- `OctoFlow/www/src/Finance/FinanceTransferService.php:20`
- `OctoFlow/frontend/Host-app/src/views/finance/FinanceReportsView.vue:645`
- `OctoFlow/frontend/Host-app/src/composables/useFinancePermissions.js:57`
- `OctoFlow/frontend/Host-app/src/composables/useFinancePermissions.js:58`

## Lacunas

- A permissao granular backend para `finance:read`, `finance:write` ou `finance:export` nao foi reavaliada como correcao aqui; isso ja foi registrado no GOD-18/GOD-26.
- Nao executei testes automatizados porque esta entrega e uma revisao documental sem alteracao de codigo.
- Nao havia amostra real de `raw_payload` de Open Finance para confirmar todos os subcampos sensiveis que podem aparecer por provedor.

## Recomendacao ao CTO

Priorizar uma correcao tecnica pequena antes de liberar exportacao/snapshot para uso amplo:

1. Remover `filePath` dos payloads publicos de export job.
2. Remover `Owner ID` e IDs internos nao essenciais da exportacao XLSX de usuario final.
3. Separar contrato de `backup tecnico` e `exportacao minimizada`.
4. Aplicar allowlist/redacao nas secoes de Open Finance, especialmente `raw_payload` e `external_consent_id`.
5. Exigir permissao backend explicita para exportar dados financeiros sensiveis.

Status de seguranca: revisao concluida, risco nao aprovado, mitigacao recomendada antes de disponibilizacao ampla para cliente.
