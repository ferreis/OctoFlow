# GOD-15 - Regras esperadas por fluxo financeiro

Atualizado em: 2026-07-07

## Escopo

Este documento organiza as regras esperadas do modulo financeiro por fluxo operacional. Ele consolida o que esta definido em documentacao anterior, o que foi confirmado no codigo atual e o que ainda precisa de decisao antes de virar compromisso de entrega.

Fontes consultadas:

- `docs/REGRAS_NEGOCIO_FINANCEIRO.md`
- `docs/GOD-14_inventario_financeiro.md`
- `OctoFlow/www/src/Finance`
- `OctoFlow/www/src/Controller/Finance`
- `OctoFlow/frontend/Host-app/src/views/finance`

Legenda de situacao:

- **Confirmado no codigo**: comportamento observado nos servicos atuais.
- **Esperado definido**: regra funcional documentada, mas nao necessariamente completa no codigo.
- **Lacuna**: ponto que precisa de alinhamento do CEO/produto ou cobertura tecnica antes de implementacao segura.

## 1. Regras transversais

### 1.1 Isolamento por usuario

Situacao: **Confirmado no codigo**

- Toda operacao financeira deve resolver `owner_id` a partir do usuario autenticado.
- Consultas, criacoes, alteracoes, baixas, exportacoes e importacoes devem escopar dados por `owner_id`.
- IDs de categoria, conta bancaria, tipo recorrente e planos so podem ser usados quando pertencem ao mesmo `owner_id`.

Criterios de aceite:

- Um usuario nao consegue listar, alterar, baixar, exportar ou importar dados financeiros de outro usuario.
- Tentativa de usar ID de outro usuario retorna erro funcional, sem revelar dados sensiveis.

### 1.2 Dinheiro, datas e moeda

Situacao: **Confirmado no codigo com lacunas**

- Valores monetarios devem ser numericos e arredondados para 2 casas decimais.
- Entrada com virgula decimal deve ser aceita na normalizacao.
- Datas obrigatorias devem ser validas; datas opcionais vazias viram `null`.
- Lancamentos em moeda diferente de `BRL` devem informar `fx_rate_to_brl`.
- Percentuais e taxas devem ser parametrizaveis quando afetarem valor final.

Lacunas:

- Juros e IOF da baixa em cartao ainda tem defaults no backend (`2.99%` e `0.38%`) quando o payload nao informa valores.
- O documento antigo pede parametros versionados por vigencia para juros, multa e IOF; isso ainda deve ser tratado como regra esperada, nao como comportamento garantido.

### 1.3 Status de lancamentos

Situacao: **Confirmado no codigo com divergencia documentada**

Status permitidos:

- `PAYABLE`: `PENDING`, `FORECAST`, `PAID`, `PARTIAL`, `OVERDUE`, `SCHEDULED`, `CANCELED`, `NEGOTIATED`.
- `RECEIVABLE`: `PENDING`, `FORECAST`, `RECEIVED`, `PARTIAL`, `OVERDUE`, `SCHEDULED`, `CANCELED`, `NEGOTIATED`.

Recalculo esperado:

- Saldo zerado em `PAYABLE` vira `PAID`.
- Saldo zerado em `RECEIVABLE` vira `RECEIVED`.
- Valor parcialmente baixado vira `PARTIAL`.
- Vencido com saldo aberto vira `OVERDUE`.
- `CANCELED` e `NEGOTIATED` podem ser mantidos como status manuais.
- Macro status de leitura: `OPEN`, `FINISHED` e `PROJECTION`.

Lacuna:

- `FinanceInput::defaultStatusByDirection()` retorna sempre `PENDING`. O documento antigo define `FORECAST` para receitas futuras e `SCHEDULED` para saidas futuras. Essa divergencia precisa ser decidida antes de QA final de previsao financeira.

### 1.4 Historico, ledger e auditoria

Situacao: **Parcialmente confirmado no codigo**

- Criacao, recalculo, cancelamento e geracoes automaticas registram historico em `finance_entry_status_history`.
- Baixas com conta bancaria alteram `finance_bank_account.current_balance_brl` e geram movimento em `finance_bank_account_ledger`.
- Para `PAYABLE`, baixa em conta debita saldo; para `RECEIVABLE`, baixa credita saldo.
- Reversoes de baixa, quando ocorrerem por cancelamento, devem registrar movimento inverso no ledger bancario.

Lacunas:

- O ledger atual observado e um ledger de conta bancaria, nao um ledger contabil completo de partidas dobradas.
- A regra antiga de partidas dobradas atomicas, imutabilidade contabil, estorno vinculado e `hash chaining` de auditoria ainda deve ser tratada como regra esperada futura.

## 2. Fluxo de cadastros financeiros

Inclui categorias, tipos recorrentes e contas bancarias.

Situacao: **Confirmado no codigo**

### 2.1 Categorias

Regras esperadas:

- Ao listar categorias, o sistema garante categorias padrao por usuario.
- Categorias padrao: `Salario`, `Moradia`, `Alimentacao`, `Transporte`, `Saude`, `Educacao`, `Lazer`, `Investimentos`, `Outros`.
- Tipos permitidos: `PAYABLE`, `RECEIVABLE`, `BOTH`, `INVESTMENT`.
- Nome e tipo sao obrigatorios.
- A combinacao `owner_id + normalized_name + kind` deve ser unica.
- Categoria pode ser ativada/desativada por `is_active`.

Criterios de aceite:

- Nao criar categoria duplicada com mesmo nome normalizado e mesmo tipo para o mesmo usuario.
- Categoria de outro usuario nao pode ser vinculada a lancamento, recorrencia, parcelamento, divida ou investimento.

### 2.2 Tipos recorrentes

Regras esperadas:

- Ao listar tipos recorrentes, o sistema garante tipos padrao por usuario: `Salario`, `Assinatura`, `Conta fixa`.
- Nome e obrigatorio e unico por usuario.
- `Mensal` e tipo depreciado e nao pode ser criado nem usado em atualizacao.
- Tipo recorrente vinculado a regra existente nao pode ser excluido.

Criterios de aceite:

- `Mensal` nao aparece como opcao valida de cadastro.
- Excluir tipo em uso retorna erro funcional claro.

### 2.3 Contas bancarias

Regras esperadas:

- Tipos permitidos: `CHECKING`, `SAVINGS`, `CREDIT`, `INVESTMENT`, `CASH`.
- Nome e tipo sao obrigatorios.
- `bankName` deve usar o banco informado ou o proprio nome como fallback.
- Saldo atual fica em `current_balance_brl`.
- Contas inativas nao podem ser usadas em novos lancamentos, baixas, recorrencias, parcelamentos, dividas ou planos de investimento.
- Cartao de credito deve ser representado por conta do tipo `CREDIT`.

Criterios de aceite:

- Baixa por cartao so aceita conta `CREDIT`.
- Conta inativa continua historica, mas nao deve ser selecionavel para novas operacoes financeiras.

## 3. Fluxo de lancamentos a pagar e a receber

Situacao: **Confirmado no codigo com lacunas**

### 3.1 Criacao e edicao

Regras esperadas:

- `direction` deve ser `PAYABLE` ou `RECEIVABLE`.
- `entry_type` deve pertencer aos tipos permitidos no dominio financeiro.
- Titulo e obrigatorio.
- Valor esperado deve ser maior que zero.
- Conta bancaria e opcional no cadastro.
- Categoria e obrigatoria conforme decisao GOD-30. O codigo atual ainda pode aceitar valor nulo, mas isso deve ser tratado como divergencia de implementacao.
- Se a moeda de entrada nao for `BRL`, a taxa de cambio para BRL e obrigatoria.
- Ao editar, o valor esperado nao pode ficar menor que o valor ja baixado.
- Edicao recalcula saldo restante e status.

Lacunas:

- Resolvido em GOD-30: categoria obrigatoria prevalece para novos lancamentos, recorrencias e parcelamentos. Registros historicos sem categoria devem continuar operacionais ate saneamento.
- A data de vencimento padrao "data atual ate alteracao manual" aparece no documento antigo, mas deve ser validada na UI antes de virar criterio de aceite.

### 3.2 Listagem e filtros

Regras esperadas:

- Listagem deve ignorar `deleted_at`.
- Filtros devem aceitar direcao, status, macro status, categoria, conta, busca textual, periodo e paginacao.
- O select de baixa deve identificar lancamento por titulo e vencimento, para diferenciar recorrencias de meses distintos.

Criterios de aceite:

- Lancamento cancelado por soft delete nao aparece como ativo.
- Recorrencias com mesmo titulo em meses diferentes permanecem distinguiveis no fluxo de baixa.

### 3.3 Baixa simples

Regras esperadas:

- Baixa exige valor maior que zero.
- Valor da baixa nao pode exceder o saldo restante, exceto quando for alocado em parcelas abertas do mesmo plano.
- Lancamento `CANCELED`, `NEGOTIATED` ou ja quitado nao aceita nova baixa.
- Tipos de baixa permitidos: `PAYMENT`, `RECEIPT`, `TRANSFER`, `ADJUSTMENT`, `CREDIT_CARD`.
- Se tipo for `ADJUSTMENT`, conta bancaria pode ser nula.
- Nos demais tipos, conta bancaria valida e ativa e obrigatoria.
- Baixa atualiza `settled_amount_brl`, `remaining_amount_brl`, `fully_settled_at` e status.
- Baixa com conta bancaria atualiza saldo e ledger da conta.

Criterios de aceite:

- Baixa parcial vira `PARTIAL`.
- Baixa total de conta a pagar vira `PAID`.
- Baixa total de conta a receber vira `RECEIVED`.
- Tentar baixar acima do saldo retorna erro funcional.

### 3.4 Baixa em cartao de credito

Regras esperadas:

- Baixa em cartao so e permitida para lancamento `PAYABLE`.
- Usuario deve selecionar uma conta do tipo `CREDIT`.
- O lancamento original e baixado com tipo `CREDIT_CARD`.
- O sistema cria automaticamente novo lancamento `PAYABLE` para a fatura do cartao.
- Novo lancamento deve somar valor baixado, juros e IOF.
- Novo lancamento usa `source_system = CREDIT_CARD_SETTLEMENT`.
- Se a data da fatura nao for informada, o vencimento padrao atual e `settledAt + 30 dias`.

Lacunas:

- Juros e IOF precisam sair de defaults hardcoded e ir para configuracao versionada.
- Ainda falta criterio de exibicao do cartao no formato `Apelido - Instituicao - XXXX` no frontend.

## 4. Fluxo de recorrencias

Situacao: **Confirmado no codigo com lacunas**

Regras esperadas:

- Frequencia suportada nesta versao: `MONTHLY`.
- Regra recorrente exige titulo, valor maior que zero, tipo recorrente e dia do mes entre 1 e 31.
- Data final nao pode ser anterior a data inicial.
- Conta bancaria padrao e opcional, mas se informada deve estar ativa.
- Geracao deve ser idempotente por competencia mensal.
- Cada lancamento gerado deve ter `entry_type = RECURRING`, `source_origin = SYSTEM` e `source_system = RECURRING_ENGINE`.
- A geracao pode ocorrer manualmente, por consulta de faixa ou por job diario.
- Exclusao da regra cancela e marca como excluidos os lancamentos gerados ainda ativos, depois remove a regra.

Comportamento atual relevante:

- A regra evita gerar competencias futuras distantes antes do vencimento da competencia anterior.
- A proxima competencia imediata pode ser antecipada para manter visibilidade operacional.
- Se um run ja existe com lancamento ativo, a competencia nao e gerada novamente.

Lacunas:

- O documento antigo pede "geracao lazy" e remocao do tipo `Mensal`; isso esta parcialmente coberto, mas os comandos tambem geram recorrencias em lote.
- Precisa decidir se a exclusao deve ser sempre hard delete da regra ou soft delete com trilha funcional.

## 5. Fluxo de parcelamentos

Situacao: **Confirmado no codigo**

Regras esperadas:

- Parcelamento exige titulo, valor total maior que zero, quantidade de parcelas maior que zero e primeiro vencimento valido.
- Direcao pode ser `PAYABLE` ou `RECEIVABLE`.
- Valor liquido calculado: `total + juros + multa - desconto - entrada`.
- Valor liquido precisa ser maior que zero.
- Se `installmentAmountBrl` nao for informado, o sistema divide o valor liquido pela quantidade de parcelas.
- Ultima parcela absorve diferenca de arredondamento para fechar o total.
- Cada parcela gera um `finance_entry` do tipo `INSTALLMENT`.
- Entrada, quando maior que zero, gera lancamento separado do tipo `ONE_OFF`.
- Plano criado fica `ACTIVE`.
- Exclusao cancela o plano e soft delete dos lancamentos gerados.

Regras de ajuste:

- Ajustes permitidos: `ADVANCE` e `DISCOUNT`.
- Ajuste deve ser maior que zero e nao pode exceder o saldo aberto do parcelamento.
- Ajuste consome parcelas abertas por ordem de parcela/vencimento.
- Parcelas afetadas recalculam saldo e status.

Criterios de aceite:

- Soma das parcelas mais entrada fecha o valor liquido esperado.
- Ajuste parcial nao pode deixar saldo negativo.
- Excluir plano remove os itens da tela ativa sem perder rastreabilidade basica.

## 6. Fluxo de planos de divida

Situacao: **Confirmado no codigo com lacunas**

Regras esperadas:

- Simulacao usa valor total, valor negociado, proposta ou desconto para definir o valor de referencia.
- Desconto deve ser menor que o valor total.
- Entrada nao pode ser negativa e deve ser menor que o valor de referencia.
- Valor planejado e `valor de referencia - entrada`.
- Limite recomendado usa media dos ultimos 3 meses de receitas, despesas e compromissos de divida do usuario.
- Percentual maximo de comprometimento tem default de `30%`, minimo de `5%` e maximo de `90%`.
- Sugestoes podem ser `FULL` ou `INSTALLMENT`.
- Criacao do plano deve escolher uma sugestao dentro do limite recomendado.
- Plano criado gera lancamento `DEBT` para pagamento a vista ou cria parcelamento vinculado para pagamento parcelado.
- Exclusao cancela o plano e seus lancamentos vinculados.

Lacunas:

- O documento antigo cita SAC e Price, mas o servico atual usa sugestoes por divisao simples do valor planejado. SAC/Price devem ser tratados como regra futura.
- Multa e juros de mora documentados ainda nao aparecem como fluxo completo de calculo de divida.

## 7. Fluxo de investimentos

Situacao: **Confirmado no codigo com lacunas**

Regras esperadas:

- Tipos permitidos: `SELIC`, `CDB`, `CDI`, `TESOURO`, `CUSTOM`.
- Simulacao exige tipo valido, periodo em meses maior que zero e taxa nao negativa.
- Aporte inicial e aporte mensal nao podem ser negativos.
- Taxa pode ser `MONTHLY` ou `ANNUAL`; taxa anual e convertida para taxa mensal composta.
- Simulacao grava pontos mensais com saldo, total investido e rendimento acumulado.
- Conversao de simulacao para plano cria plano `ACTIVE`.
- Plano pode gerar lancamento inicial se houver aporte inicial.
- Job mensal gera lancamentos de aporte para planos ativos.
- Quando `generateYieldEntries` estiver ativo e `yieldMode = ESTIMATED`, job tambem gera lancamento estimado de rendimento.
- Status permitidos para plano: `ACTIVE`, `PAUSED`, `CANCELED`, `FINISHED`.

Lacunas:

- Regras tributarias, custo medio ponderado, TWRR e compensacao de prejuizo estao documentadas como desejadas, mas nao foram confirmadas como implementadas no fluxo atual.
- Nao ha garantia documentada de integracao com corretora ou posicao real de carteira.

## 8. Fluxo de moedas e cotacoes

Situacao: **Confirmado no codigo com lacunas**

Regras esperadas:

- Moedas usam codigos de 3 letras.
- Cotacoes podem ser buscadas em API externa do Bacen/PTAX.
- Cotacao persistida por `owner_id + currency_code + quote_date` deve ser reutilizada quando nao houver `forceRefresh`.
- Insercao manual exige moeda valida e taxa maior que zero.
- Upsert deve manter uma cotacao por moeda/data/usuario.

Lacunas:

- O documento antigo pede consulta automatica no maximo uma vez por dia por moeda e confirmacao explicita para atualizacao manual forcada. O backend tem cache por data e `forceRefresh`, mas a confirmacao e responsabilidade de UI.
- IOF diario via API ainda nao foi confirmado no codigo financeiro atual.

## 9. Fluxo de dashboards e relatorios

Situacao: **Confirmado no codigo**

Regras esperadas:

- Dashboard deve ignorar lancamentos com `deleted_at`.
- Resumo deve considerar totais esperados, liquidados, saldo restante, quantidade em atraso e saldo bancario.
- Fluxo de caixa agrupa por competencia mensal usando `competence_month` ou `due_date`.
- Relatorio por categoria agrupa valores por categoria e direcao.
- Filtros de periodo e status devem ser aplicados antes da agregacao.

Criterios de aceite:

- Lancamento cancelado/excluido nao distorce KPI.
- Valor em atraso considera status `OVERDUE`.
- Fluxo mensal preserva separacao entre `PAYABLE` e `RECEIVABLE`.

## 10. Fluxo de exportacao e importacao

Situacao: **Confirmado no codigo com risco alto**

### 10.1 Exportacao por job

Regras esperadas:

- Tipos permitidos: `PAYABLE`, `RECEIVABLE`, `MONTHLY_SUMMARY`, `CATEGORY`, `CASHFLOW`, `INVESTMENT`.
- Criacao de exportacao gera job `QUEUED`.
- Job expira em 7 dias.
- Job `PROCESSING` nao pode ser deletado.
- Download so e permitido quando job esta `DONE`, arquivo existe e nao expirou.
- Processamento deve marcar sucesso como `DONE` e falhas como `FAILED`.

### 10.2 Snapshot de migracao

Regras esperadas:

- Exportacao de snapshot deve gerar payload com schema, versao, timestamp UTC e secoes de dados financeiros do usuario.
- Importacao aceita `snapshot.data` e remapeia IDs antigos para novos.
- Importacao e transacional.

Risco critico:

- Importacao de snapshot executa hard delete dos dados financeiros do usuario antes de reimportar. Esse fluxo exige confirmacao explicita na UI, backup/exportacao previa e mensagem clara de irreversibilidade antes de uso por cliente.

Criterios de aceite:

- Importacao invalida nao deve apagar dados se falhar antes da transacao.
- Importacao valida deve retornar contadores por secao importada.
- Operacao destrutiva deve ficar restrita a usuario dono e exigir permissao financeira de escrita.

## 11. Fluxo de Open Finance

Situacao: **Stub/mock confirmado no codigo**

Regras atuais:

- Providers precisam estar ativos.
- Conexao aceita status `ACTIVE`, `REVOKED`, `EXPIRED`, `ERROR`.
- Criacao exige `providerId` ou `providerCode`.
- Sync atualiza `last_sync_at` e retorna modo `stub` ou `mock`.
- Provider `MANUAL_MOCK` pode criar conta e transacao mockadas.
- Exclusao remove a conexao.

Lacunas:

- Nao ha confirmacao de fluxo real de consentimento bancario.
- Nao ha confirmacao de FAPI, mTLS, certificados ICP-Brasil ou integracao real com instituicoes.
- Qualquer comunicacao comercial deve tratar Open Finance como estrutura inicial/mock ate validacao tecnica e de compliance.

## 12. Matriz de QA minima por fluxo

| Fluxo | Testes minimos esperados |
| --- | --- |
| Cadastros | Duplicidade de categoria/tipo, bloqueio de `Mensal`, conta inativa nao selecionavel |
| Lancamentos | Criar/editar por direcao, validar moeda nao BRL, impedir valor menor que baixado |
| Baixas | Baixa parcial, baixa total, baixa acima do saldo, baixa sem conta, baixa por ajuste |
| Cartao | Baixa `PAYABLE` com conta `CREDIT`, bloqueio em `RECEIVABLE`, geracao de fatura |
| Recorrencias | Idempotencia por competencia, geracao mensal, exclusao da regra e ocultacao dos gerados |
| Parcelamentos | Arredondamento na ultima parcela, entrada separada, ajuste de desconto/antecipacao |
| Dividas | Simulacao dentro/fora do limite de renda, criacao full e parcelada, cancelamento |
| Investimentos | Simulacao mensal/anual, conversao para plano, job mensal com/sem rendimento estimado |
| Moedas | Cache por data, `forceRefresh`, cotacao manual e moeda invalida |
| Relatorios | Exclusao de `deleted_at`, filtros de periodo/status, agrupamento por mes/categoria |
| Exportacao | Job queued/done/failed, bloqueio de delete processing, expiracao de download |
| Importacao | Snapshot invalido, snapshot valido, confirmacao destrutiva e contadores |
| Open Finance | Provider inativo, sync stub, sync mock e exclusao |

## 13. Pontos de revisao do CEO

- GOD-30 decidiu manter categoria obrigatoria em novos lancamentos, recorrencias e parcelamentos; comportamento opcional fica restrito a legado e compatibilidade.
- Decidir regra final de status futuro: manter default `PENDING` ou aplicar `FORECAST`/`SCHEDULED`.
- Priorizar parametrizacao versionada de juros, multa e IOF antes de promessa de calculo financeiro formal.
- Confirmar se ledger contabil de partidas dobradas e requisito de curto prazo ou objetivo futuro.
- Exigir confirmacao forte e backup para importacao destrutiva de snapshot.
- Tratar Open Finance como mock/estrutura inicial ate aprovacao tecnica, juridica e de seguranca.

## 14. Resultado para entrega

Esta documentacao deixa os fluxos financeiros prontos para revisao funcional e derivacao de tarefas de QA/implementacao. O proximo passo recomendado e transformar as lacunas acima em decisoes ou issues menores antes de mexer em codigo de saldo, baixa, importacao ou integracao bancaria.
