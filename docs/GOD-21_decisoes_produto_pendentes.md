# GOD-21 - Decisoes de Produto Pendentes

Atualizado em: 2026-07-07

## Objetivo

Documentar decisoes de produto que precisam ser tomadas para completar o tratamento de edge cases financeiros no modulo OctoFlow.

## Decisoes Pendentes

### 1. Categoria Obrigatoria (Alta Prioridade)

**Contexto:** O codigo atual permite que categoria seja opcional em lancamentos, recorrencias e parcelamentos. A regra de negocio antiga define categoria obrigatoria para novos lancamentos.

**Impacto:**
- Relatorios por categoria ficam incompletos
- QA financeiro pode divergir do esperado
- Dados inconsistentes em exportacoes

**Opcoes:**
1. **Manter categoria obrigatoria** - Atualizar backend e UI para exigir categoria
2. **Manter categoria opcional** - Atualizar regra oficial e testes

**Recomendacao:** Manter categoria obrigatoria para garantir consistencia dos dados financeiros.

### 2. Confirmacao de Importacao Destrutiva (Alta Prioridade)

**Contexto:** A funcao `importSnapshot()` tem `replaceExisting` com default `true`, o que apaga dados financeiros antes da reimportacao.

**Impacto:**
- Perda operacional de historico financeiro
- Risco de importacao acidental ou maliciosa

**Opcoes:**
1. **Exigir confirmacao forte** - Adicionar campo `confirmReplaceExisting: "SUBSTITUIR_DADOS_FINANCEIROS"`
2. **Manter default false** - Alterar default de `replaceExisting` para `false`
3. **Adicionar aviso na UI** - Manter comportamento mas adicionar warning claro

**Recomendacao:** Exigir confirmacao forte no payload para proteger contra perda acidental de dados.

### 3. Padrao de Mensagens de Erro (Media Prioridade)

**Contexto:** Mensagens de erro estavam misturadas entre ingles e portugues. Implementamos padronizacao em pt-BR.

**Impacto:**
- Experiencia inconsistente para usuarios
- Risco de vazamento de detalhes tecnicos

**Opcoes:**
1. **Manter pt-BR** - Manter todas as mensagens em portugues
2. **Usar codigos de erro** - Retornar codigos estaveis e traduzir no frontend
3. **Manter ingles** - Manter mensagens em ingles para consistencia tecnica

**Recomendacao:** Manter pt-BR para melhor experiencia do usuario, conforme padrao da empresa.

### 4. Transicoes de Status (Media Prioridade)

**Contexto:** As transicoes de status atuais sao baseadas em regras simples, mas podem causar comportamentos inesperados.

**Impacto:**
- Status inconsistentes em relatorios
- Problemas em fluxos de negocio dependentes

**Opcoes:**
1. **Implementar maquina de estados** - Criar formalizacao completa das transicoes
2. **Manter logica atual** - Apenas adicionar validacoes pontuais
3. **Documentar regras** - Criar documentacao clara das transicoes permitidas

**Recomendacao:** Implementar maquina de estados formal para garantir consistencia e previsibilidade.

### 5. FORECAST Vencimento Automatico (Baixa Prioridade)

**Contexto:** O job de vencimento exclui `FORECAST` da lista de elegiveis para virar `OVERDUE`.

**Impacto:**
- KPIs de atraso podem subestimar recebiveis vencidos
- Macro status pode ficar inconsistente

**Opcoes:**
1. **Incluir FORECAST no job** - Atualizar query para incluir FORECAST
2. **Manter exclusao** - Manter comportamento atual e documentar
3. **Criar job separado** - Criar job especifico para FORECAST vencido

**Recomendacao:** Incluir FORECAST no recalculo automatico quando `due_date < today` e `remaining_amount_brl > 0`.

## Proximos Passos

1. Aguardar decisoes do CEO/produto sobre itens pendentes
2. Implementar mudancas conforme decisoes tomadas
3. Atualizar testes para cobrir novos cenarios
4. Documentar mudancas no CHANGELOG
