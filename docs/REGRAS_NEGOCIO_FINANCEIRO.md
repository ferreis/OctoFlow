# Regras de Negocio - Financeiro (OctoFlow)

Data de referencia: 01/04/2026  
Status: base oficial para implementacao

Nota GOD-30 em 07/07/2026: categoria permanece obrigatoria para novos lancamentos, recorrencias e parcelamentos. Registros historicos sem categoria devem continuar operacionais ate saneamento.

## 1. Objetivo

Definir regras funcionais e contabeis do modulo financeiro, com foco em:

- Contas (pagar e receber)
- Recorrencia e parcelamento
- Baixa por conta bancaria e cartao de credito
- Cotacao de moedas e IOF
- Ledger, auditoria e integracoes

## 2. Regras de Interface e Fluxo (Contas)

## 2.1 Visao Geral

ID: RN-UI-01  
REGRA: Botao de atualizacao do resumo rapido  
DESCRICAO: Em "Resumo rapido: pagar x receber", deve existir um botao para recalcular e atualizar os valores na tela sob demanda do usuario.

ID: RN-UI-02  
REGRA: Remover acoes da listagem unificada  
DESCRICAO: Em "Lancamentos unificados (pagar e receber)", remover coluna de acao e botoes de acao.

ID: RN-UI-03  
REGRA: Remover tag desnecessaria  
DESCRICAO: Em "Lancamentos unificados (pagar e receber)", remover a tag "Unificado".

ID: RN-UI-04  
REGRA: Remover texto explicativo redundante  
DESCRICAO: Em "Lancamentos unificados (pagar e receber)", remover o texto "Exibindo contas a pagar e a receber na mesma listagem.".

## 2.2 Contas a Pagar - Cadastro e Baixa

ID: RN-PAY-01  
REGRA: Obrigatoriedade da conta bancaria apenas na baixa  
DESCRICAO: O campo "Conta bancaria" e opcional no cadastro do lancamento. Ele so se torna obrigatorio no momento da baixa quando o meio de pagamento for conta bancaria.

ID: RN-PAY-02  
REGRA: Campos do novo lancamento (simples)  
DESCRICAO:

- Titulo (obrigatorio)
- Tipo de lancamento (obrigatorio)
- Valor BRL (obrigatorio)
- Vencimento (obrigatorio, formato `dd/mm/aaaa`)
- Categoria (obrigatorio)
- Conta bancaria (opcional)

Regras adicionais:

- Vencimento inicia com a data atual (`getdate`) e permanece ate alteracao manual do usuario.

ID: RN-PAY-03  
REGRA: Campos do novo lancamento recorrente  
DESCRICAO:

- Titulo (obrigatorio)
- Tipo de lancamento (obrigatorio)
- Valor BRL (obrigatorio)
- Vencimento (obrigatorio, formato `dd/mm/aaaa`)
- Categoria (obrigatorio)
- Tipo recorrente (obrigatorio)
- Conta bancaria (opcional)

Regras adicionais:

- Vencimento inicia com a data atual (`getdate`) e permanece ate alteracao manual do usuario.

ID: RN-PAY-04  
REGRA: Baixa por conta bancaria ou cartao  
DESCRICAO: Deve ser possivel baixar um lancamento usando:

- Conta bancaria
- Cartao de credito (vinculado ou nao a uma conta bancaria)

ID: RN-PAY-05  
REGRA: Identificacao clara no select de baixa  
DESCRICAO: O select de lancamentos para baixa deve mostrar `Titulo - dd/mm/aaaa` (nome + vencimento), para diferenciar recorrencias de meses diferentes.  
Exemplo: `Unifique - 10/03/2026`, `Unifique - 10/04/2026`.

ID: RN-PAY-06  
REGRA: Baixa em credito com escolha de cartao  
DESCRICAO:

- Incluir checkbox: `Baixa de valor em Credito`.
- Quando marcado, o meio de pagamento passa para cartao de credito.
- Exibir select de cartoes cadastrados.
- Item do select deve seguir formato: `Apelido - Instituicao - XXXX` (4 ultimos digitos).

ID: RN-PAY-07  
REGRA: Gerar novo "a pagar" ao baixar em cartao  
DESCRICAO: Ao baixar um lancamento com `Baixa de valor em Credito`, criar automaticamente novo lancamento `a pagar` vinculado ao cartao selecionado.

Regras de valor:

- Considerar juros e IOF no novo lancamento.
- Juros devem ser parametrizados (nao hardcoded) por cartao ou por regra global.
- Referencia de mercado para operacoes de pagamento via cartao: faixa mensal de `1,99%` a `5,99%` + IOF.
- Regras de rotativo podem usar referencia de `12%` a `15%` ao mes, respeitando teto legal configuravel de `100%` sobre a divida original.

ID: RN-PAY-08  
REGRA: Campos do novo parcelamento  
DESCRICAO:

- Titulo (obrigatorio)
- Valor total (obrigatorio)
- Entrada (opcional)
- Parcelas (obrigatorio)
- Primeiro vencimento (obrigatorio)
- Categoria (obrigatorio)
- Conta bancaria padrao (opcional)

## 2.3 Tipos de recorrencia

ID: RN-REC-01  
REGRA: Tipos permitidos  
DESCRICAO: Tipos validos de recorrencia:

- Assinatura: servicos e assinaturas recorrentes
- Conta fixa: despesas fixas mensais (aluguel, condominio etc.)
- Salario: recebimento recorrente mensal

ID: RN-REC-02  
REGRA: Remocao de tipo antigo  
DESCRICAO: Remover opcao `Mensal` da lista de tipos recorrentes.

ID: RN-REC-03  
REGRA: Exclusao deve excluir de fato  
DESCRICAO: Acao "Excluir" nao pode apenas desativar visualmente o item mantendo exibicao como ativo.  
Implementacao permitida:

- Soft delete (`deleted_at`) para exclusao logica
- Hard delete para exclusao fisica

Obrigatorio:

- Itens excluidos nao devem continuar aparecendo na tela principal como ativos.

## 2.4 IOF e Moedas (Configuracoes)

ID: RN-FX-01  
REGRA: Coleta diaria de IOF via API  
DESCRICAO: Sistema deve consultar API de IOF diariamente e persistir o valor usado no dia.

ID: RN-FX-02  
REGRA: Cadastro de moedas monitoradas  
DESCRICAO: Em Configuracoes, deve existir `input` ou `select` para sigla da moeda. Usuario seleciona e clica em adicionar para incluir em tabela de monitoramento.

ID: RN-FX-03  
REGRA: Limite de consulta automatica  
DESCRICAO: Cotacao de cada moeda deve ser atualizada automaticamente no maximo 1 vez por dia. No restante do dia, usar valor salvo em banco.

ID: RN-FX-04  
REGRA: Atualizacao manual com confirmacao  
DESCRICAO: Deve existir botao para forcar consulta da API. Antes da consulta, solicitar confirmacao explicita do usuario.

ID: RN-FX-05  
REGRA: Insercao manual de cotacao  
DESCRICAO: Deve existir opcao para cadastrar/editar valores de moeda manualmente.

ID: RN-FX-06  
REGRA: Persistencia obrigatoria de cotacao  
DESCRICAO: Salvar cotacoes em tabela com campos:

- `datetime`
- `moeda`
- `sigla`
- `taxa`

## 3. Regra de Negocio - Nucleo Contabil e Ledger

ID: RN-CORE-01  
REGRA: Partidas dobradas atomicas  
DESCRICAO: Toda transacao deve gerar no minimo dois lancamentos no ledger (Debito e Credito). A soma dos debitos deve ser rigorosamente igual a dos creditos.

ID: RN-CORE-02  
REGRA: Imutabilidade de registros  
DESCRICAO: Lancamentos confirmados no ledger nao podem ser editados ou excluidos. Correcoes devem ser feitas via estorno vinculado ao ID original.

ID: RN-CORE-03  
REGRA: Hierarquia de plano de contas  
DESCRICAO: Estrutura obrigatoria:

- Ativos (1)
- Passivos (2)
- Patrimonio Liquido (3)
- Receitas (4)
- Despesas (5)

Subcontas herdam natureza da conta pai.

ID: RN-CORE-04  
REGRA: Exclusao logica fora do ledger  
DESCRICAO: Entidades operacionais (fora do ledger) usam `deleted_at`. Registros com `deleted_at` preenchido devem ser ignorados em consultas de saldo e relatorios.

## 4. Gestao de Lancamentos e Fluxo de Caixa

Campos obrigatorios (`FinanceEntry`):

- `id` (UUID)
- `owner_id`
- `direction` (`Payable`/`Receivable`)
- `status`
- `gross_amount`
- `due_date`
- `category_id`
- `account_id`

ID: RN-ENTRY-01  
REGRA: Calculo de valor liquido  
DESCRICAO: `net_amount = gross_amount - descontos + taxas + juros`. O valor liquido impacta o saldo na liquidacao.

ID: RN-ENTRY-02  
REGRA: Geracao lazy de recorrencia  
DESCRICAO: Nao gerar recorrencias em lote no futuro. Instanciar dinamicamente ao consultar periodo com recorrencia, com idempotencia por competencia (mes/ano).

ID: RN-ENTRY-03  
REGRA: Ajuste de residuo em parcelas  
DESCRICAO: Em parcelamento (`INSTALLMENT`), ajustar ultima parcela para absorver arredondamento:  
`P_final = V_total - (P_comum * (n - 1))`.

ID: RN-ENTRY-04  
REGRA: Status preditivo (forecast)  
DESCRICAO:

- Receitas futuras: `FORECAST`
- Saidas futuras: `SCHEDULED`
- Vencido sem liquidacao: `OVERDUE`

## 5. Simulacao e Gestao de Dividas

ID: RN-DEBT-01  
REGRA: Amortizacao SAC  
DESCRICAO: `A = P / n` (amortizacao constante). Juros decrescentes sobre saldo devedor. Parcela: `M_t = A + (SD_{t-1} * i)`.

ID: RN-DEBT-02  
REGRA: Amortizacao Price  
DESCRICAO: Parcela constante:  
`M = P * [i * (1 + i)^n] / [(1 + i)^n - 1]`.

ID: RN-DEBT-03  
REGRA: Multa e juros de mora  
DESCRICAO: Parcela em atraso aplica:

- Multa fixa de 2% sobre valor da parcela
- Juros de mora de 1% ao mes (`0,033%` ao dia), pro rata die

ID: RN-DEBT-04  
REGRA: Comprometimento de renda  
DESCRICAO: Bloquear ou alertar novas simulacoes quando soma das parcelas exceder 30% da receita media liquida dos ultimos 3 meses.

## 6. Investimentos e Performance

ID: RN-INVEST-01  
REGRA: Custo medio ponderado  
DESCRICAO: Custo de aquisicao inclui taxas (corretagem/B3).  
Formula de referencia: `PM = Soma(Custo Total) / Soma(Quantidade)`.  
Venda parcial reduz quantidade, mantendo preco medio para o saldo remanescente.

ID: RN-INVEST-02  
REGRA: Calculo TWRR  
DESCRICAO: `TWRR = Produto(1 + R_i) - 1`, com `R_i` por subperiodo entre fluxos de caixa.

ID: RN-INVEST-03  
REGRA: Isencao de IR em acoes  
DESCRICAO: Se soma de vendas mensais de acoes comuns for `<= R$ 20.000`, lucro isento. Acima disso, aplicar 15% sobre ganho liquido via DARF 6015.

ID: RN-INVEST-04  
REGRA: Compensacao de prejuizo  
DESCRICAO: Prejuizos de renda variavel compensam lucros futuros, respeitando categoria:

- Day trade (20%) compensa em day trade
- Swing trade (15%) compensa em swing trade

## 7. Integracao Open Finance e Banking

ID: RN-OPEN-01  
REGRA: Ciclo de consentimento  
DESCRICAO: Fluxo obrigatorio:

1. Pedido de criacao
2. Autenticacao no banco
3. Confirmacao (`AUTHORISED`)

Validade maxima: 365 dias.

ID: RN-OPEN-02  
REGRA: Conciliacao inteligente  
DESCRICAO: Matching automatico entre transacao bancaria e lancamento previsto quando:

- Valor identico (tolerancia `0,01%`)
- Data na janela de `-3` a `+3` dias
- CNPJ/CPF da contraparte coincidente

ID: RN-OPEN-03  
REGRA: Seguranca FAPI  
DESCRICAO: Todas as chamadas exigem header `x-fapi-interaction-id` e mTLS com certificados ICP-Brasil.

## 8. Governanca e Auditoria

ID: RN-AUDIT-01  
REGRA: Trilha de auditoria SOX  
DESCRICAO: Todo evento `create`, `update`, `void` deve registrar:

- ID do ator
- Timestamp UTC
- IP
- Payload antigo
- Payload novo

ID: RN-AUDIT-02  
REGRA: Integridade de log (hash chaining)  
DESCRICAO: Cada entrada de log contem hash criptografico com hash da entrada anterior. Alteracao retroativa invalida a cadeia.

ID: RN-AUDIT-03  
REGRA: Monitoramento AML/KYC  
DESCRICAO: Transacoes acima de limites parametricos de risco (exemplo: `3` desvios padrao acima da media mensal do usuario) devem ser sinalizadas para revisao humana imediata.

## 9. Criterios de aceite (implementacao)

## 9.1 Contas e lancamentos

- Criar/editar lancamento sem conta bancaria deve funcionar.
- Baixa via conta bancaria exige conta bancaria.
- Baixa via cartao exige cartao selecionado quando checkbox de credito estiver marcado.
- Select de baixa deve exibir nome + vencimento.
- Baixa em cartao deve gerar novo `a pagar` vinculado ao cartao com juros/IOF aplicados.

## 9.2 Recorrencia e exclusao

- O tipo `Mensal` nao pode aparecer para novos cadastros.
- Exclusao nao pode apenas desativar e manter item ativo em tela.
- Recorrencias devem ser geradas de forma lazy no periodo consultado.

## 9.3 Moedas e IOF

- Cotacao automatica no maximo 1 vez por dia por sigla.
- Botao de atualizacao manual exige confirmacao.
- Cadastro manual de cotacao deve persistir normalmente.

## 9.4 Contabil e auditoria

- Nenhuma transacao confirmada pode quebrar partidas dobradas.
- Lancamento confirmado no ledger nao pode ser alterado ou apagado diretamente.
- Toda alteracao relevante deve entrar na trilha de auditoria com hash chaining.

## 10. Ordem sugerida de execucao tecnica

1. Ajustes de UI de Contas (RN-UI-01..04).
2. Regras de formulario e baixa em Contas a Pagar (RN-PAY-01..08).
3. Tipos de recorrencia e comportamento de exclusao (RN-REC-01..03).
4. Tabela e servicos de cotacao/IOF (RN-FX-01..06).
5. Garantias de ledger e fluxo de caixa (RN-CORE, RN-ENTRY).
6. Simulacao de dividas e investimentos (RN-DEBT, RN-INVEST).
7. Open Finance e auditoria (RN-OPEN, RN-AUDIT).

## 11. Observacoes de implementacao

- Onde houver percentual financeiro (juros, multa, IOF), usar parametros versionados por data de vigencia.
- Evitar regra hardcoded em frontend; concentrar calculo no backend.
- Host deve orquestrar autenticacao, sessao e permissao; componentes remotos apenas exibem/recebem dados e callbacks.
