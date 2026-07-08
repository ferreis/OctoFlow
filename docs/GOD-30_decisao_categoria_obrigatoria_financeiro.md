# GOD-30 - Decisao de categoria obrigatoria em financeiro

Data de referencia: 07/07/2026  
Responsavel: Analista de Negocio  
Status: decisao funcional concluida para handoff tecnico

## Objetivo e criterios de aceite confirmados

Objetivo: decidir se categoria deve ser obrigatoria nos fluxos financeiros principais ou se o comportamento atual, que aceita categoria vazia, deve virar regra oficial.

Criterios de aceite desta analise:

- Comparar a regra oficial existente com o comportamento atual de backend, frontend e schema.
- Definir a regra funcional final dentro do escopo de negocio.
- Registrar impactos em relatorios por categoria, experiencia de cadastro e dados historicos.
- Entregar criterios de aceite para backend, frontend e QA.
- Registrar riscos, lacunas e pontos que precisam de decisao do CTO fora deste escopo.

## Decisao funcional

Categoria deve ser obrigatoria para novos registros e edicoes cadastrais dos fluxos financeiros principais:

- Lancamentos manuais a pagar e a receber.
- Regras recorrentes que geram lancamentos.
- Planos de parcelamento e parcelas geradas a partir deles.

A decisao mantem a regra oficial de `docs/REGRAS_NEGOCIO_FINANCEIRO.md`, onde categoria ja aparece como obrigatoria em `RN-PAY-02`, `RN-PAY-03`, `RN-PAY-08` e nos campos obrigatorios de `FinanceEntry`.

O comportamento atual de permitir `Sem categoria` nao deve virar regra oficial para novos cadastros. Ele deve permanecer apenas como compatibilidade operacional para dados legados, importacoes antigas e registros ja existentes enquanto nao houver saneamento de base.

## Regra final

1. Categoria e obrigatoria ao criar lancamento simples, regra recorrente e parcelamento.
2. Categoria e obrigatoria ao editar dados cadastrais desses registros; nao deve ser permitido salvar categoria vazia nem remover categoria ja existente.
3. Categoria deve pertencer ao mesmo usuario (`owner_id`) e estar ativa para novas operacoes.
4. Lancamentos gerados por recorrencia ou parcelamento devem herdar a categoria da regra ou plano de origem.
5. Conta bancaria continua opcional no cadastro e obrigatoria apenas nas baixas bancarias, conforme `RN-PAY-01`.
6. Registros historicos sem categoria continuam consultaveis, exportaveis, baixaveis e exibidos em relatorios como `Sem categoria` ate saneamento.
7. Baixa, cancelamento, exclusao logica e consulta de registros legados nao devem ser bloqueados apenas por ausencia de categoria.
8. Categoria inativa pode continuar aparecendo em registros historicos, mas nao deve ser selecionavel em novos cadastros ou edicoes cadastrais.

## Fora do escopo desta decisao

- Tornar `category_id` obrigatorio via constraint de banco imediatamente.
- Backfill automatico de registros historicos sem categoria.
- Redefinir regra de categoria para planos de divida, investimentos e transacoes externas de Open Finance.
- Criar categoria automatica generica como `A categorizar`.

Esses pontos devem ser tratados pelo CTO em follow-up se forem prioridade, porque podem afetar migracao de dados, relatorios existentes e UX de captura rapida.

## Evidencias verificadas

- `docs/REGRAS_NEGOCIO_FINANCEIRO.md` define categoria como obrigatoria em lancamento simples, recorrente e parcelamento.
- `docs/GOD-15_regras_fluxos_financeiros.md` registrava a divergencia entre regra antiga obrigatoria e codigo atual opcional.
- `docs/GOD-18_revisao_validacoes_permissoes_estados_financeiros.md` apontou categoria opcional como risco medio para relatorios e QA.
- Backend atual normaliza categoria vazia como `null` em lancamentos, recorrencias e parcelamentos.
- Schema atual permite `category_id DEFAULT NULL` em `finance_entry`, `finance_recurring_rule` e `finance_installment_plan`.
- Frontend atual exibe a opcao `Sem categoria` nos formularios de lancamento, recorrencia e parcelamento.

## Impacto esperado

- Relatorios por categoria ficam mais completos para novos dados.
- QA passa a ter uma regra unica para cadastro financeiro.
- Usuario precisa escolher categoria antes de salvar novos registros financeiros principais.
- Historico antigo sem categoria continua visivel, evitando bloqueio operacional.
- Implementacao deve separar validacao de novos cadastros/edicoes cadastrais da operacao de registros legados.

## Criterios de aceite para backend

- Criar lancamento sem `categoryId` deve retornar erro funcional de campo obrigatorio.
- Criar regra recorrente sem `categoryId` deve retornar erro funcional de campo obrigatorio.
- Criar parcelamento sem `categoryId` deve retornar erro funcional de campo obrigatorio.
- Editar cadastro de lancamento, recorrencia ou parcelamento deve exigir categoria para salvar, inclusive quando o registro legado ainda nao possui categoria.
- Remover categoria de registro que ja possui categoria deve ser bloqueado em edicoes cadastrais.
- Baixar, cancelar, excluir logicamente ou consultar registro legado sem categoria deve continuar permitido quando as demais regras forem validas.
- Categoria informada deve pertencer ao usuario autenticado.
- Categoria inativa nao deve ser aceita em novos cadastros ou edicoes cadastrais.
- Lancamentos gerados por recorrencia devem receber a categoria da regra recorrente.
- Lancamentos gerados por parcelamento devem receber a categoria do plano de parcelamento.
- Respostas de erro devem ser consistentes e compreensiveis para exibicao na UI.

## Criterios de aceite para frontend

- Formularios de lancamento simples, recorrencia e parcelamento devem marcar categoria como obrigatoria.
- A opcao `Sem categoria` deve ser removida desses formularios para novos cadastros.
- O envio deve ser bloqueado quando categoria estiver vazia.
- Ao editar registro legado sem categoria, a UI deve solicitar categoria antes de salvar alteracoes cadastrais.
- A UI nao deve permitir limpar categoria de registro que ja possui categoria.
- Listagens, detalhes e relatorios devem continuar exibindo `Sem categoria` para historico sem categoria.
- Categorias inativas nao devem aparecer como opcoes selecionaveis para novos cadastros.
- Mensagens de validacao devem indicar claramente que categoria e obrigatoria.

## Criterios de aceite para QA

- Tentar criar lancamento simples sem categoria e validar erro.
- Tentar criar recorrencia sem categoria e validar erro.
- Tentar criar parcelamento sem categoria e validar erro.
- Criar lancamento simples com categoria ativa e validar persistencia.
- Criar recorrencia com categoria ativa e validar que o lancamento gerado herda a categoria.
- Criar parcelamento com categoria ativa e validar que as parcelas geradas herdam a categoria.
- Tentar usar categoria de outro usuario e validar bloqueio.
- Tentar usar categoria inativa e validar bloqueio.
- Validar que registros historicos sem categoria continuam aparecendo em listagens e relatorios como `Sem categoria`.
- Validar que baixa de registro historico sem categoria continua funcionando quando as demais regras de baixa forem atendidas.

## Riscos e lacunas

- Existem ou podem existir registros historicos com `category_id` nulo. Nao aplicar constraint `NOT NULL` antes de plano de saneamento.
- Importacoes de snapshot podem trazer dados antigos sem categoria. A regra de importacao precisa decidir se preserva historico, rejeita novos snapshots ou exige mapeamento.
- Relatorios por categoria ainda terao linha `Sem categoria` enquanto houver legado.
- Planos de divida, investimentos e Open Finance usam categoria em outros contextos e devem ter decisao propria se o CTO quiser padronizacao total.

## Recomendacao de proxima acao

Encaminhar para desenvolvimento a implementacao da obrigatoriedade em backend e frontend, mantendo compatibilidade com historico sem categoria. QA deve usar os criterios acima como matriz minima de validacao.
