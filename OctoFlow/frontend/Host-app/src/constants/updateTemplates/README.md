# Templates de Atualizacao de Issue

Esta pasta concentra os templates usados na aba de atualizacao da issue em [IssueEditModal.vue](../../components/tasks/IssueEditModal.vue).

Cada template define:
- qual opcao aparece no seletor
- quais campos o usuario vai preencher
- como esses campos viram Markdown no preview final

## Como o sistema conecta os templates

1. Crie um arquivo novo nesta pasta, por exemplo `riskUpdate.js`.
2. Exporte um objeto de template.
3. Importe esse objeto em [index.js](./index.js).
4. Adicione o template no array `updateTemplates` na ordem desejada.
5. O [TasksScreen.vue](../../components/screens/TasksScreen.vue) injeta esse catalogo no [IssueEditModal.vue](../../components/tasks/IssueEditModal.vue) pela prop `update-templates`.

Observacao:
- O arquivo [../updateTemplates.js](../updateTemplates.js) existe apenas como compatibilidade de import antigo.
- A fonte oficial agora e esta pasta.

## Estrutura minima de um template

```js
export const riskUpdateTemplate = {
  key: 'risk-update',
  label: 'Risco',
  description: 'Padrao para registrar risco identificado.',
  markdownTitle: 'Risco identificado',
  fields: [
    {
      key: 'date',
      label: 'Data',
      type: 'text',
      renderAs: 'bullet',
      defaultValue: () => buildCurrentDateLabel(),
    },
    {
      key: 'risk',
      label: 'Risco',
      type: 'textarea',
      placeholder: 'Descreva o risco.',
    },
  ],
}
```

## Propriedades do template

### `key`
- Identificador unico do template.
- Deve ser estavel.
- Nao repita chaves ja existentes.

### `label`
- Nome exibido no `select` de template.

### `description`
- Texto auxiliar exibido abaixo do seletor e no bloco de campos.

### `markdownTitle`
- Titulo usado na secao Markdown gerada.
- Se nao for informado, o sistema usa `label`.

### `fields`
- Lista de campos renderizados no formulario.

## Tipos de campo suportados

### `text`
- Renderiza `input type="text"`.
- Salva o valor como texto simples.

Exemplo:

```js
{
  key: 'owner',
  label: 'Responsavel',
  type: 'text',
  placeholder: 'Quem esta conduzindo',
}
```

### `textarea`
- Renderiza `textarea`.
- Ideal para contexto, analise, observacoes.

Exemplo:

```js
{
  key: 'currentStatus',
  label: 'Situacao atual',
  type: 'textarea',
  placeholder: 'Descreva o estado atual.',
}
```

### `list`
- Renderiza `textarea`, mas cada linha vira um item no Markdown.
- O sistema divide por quebra de linha e remove linhas vazias.

Exemplo:

```js
{
  key: 'pendingItems',
  label: 'Pendencias',
  type: 'list',
  listStyle: 'checklist',
  placeholder: 'Uma pendencia por linha.',
}
```

Valores aceitos em `listStyle`:
- `bullet`: gera `- item`
- `checklist`: gera `- [ ] item`

### `select`
- Renderiza um `select`.
- No Markdown final o sistema salva o `label` da opcao, nao o `value`.

Exemplo:

```js
{
  key: 'severity',
  label: 'Severidade',
  type: 'select',
  options: [
    { value: 'low', label: 'Baixa' },
    { value: 'high', label: 'Alta' },
  ],
}
```

## Propriedades opcionais de campo

### `defaultValue`
- Valor inicial do campo.
- Pode ser `string` ou `function`.

Exemplo:

```js
defaultValue: () => buildCurrentDateLabel()
```

### `placeholder`
- Texto de ajuda exibido no campo.

### `renderAs`
- Controla como o valor vira Markdown.

Valores usados hoje:
- `bullet`: gera `- Label: valor`
- `commit`: tenta transformar hash/URL em link de commit do GitHub

Se `renderAs` nao for informado:
- `text` e `textarea` viram secao `### Label`
- `list` vira lista
- `select` vira o label da opcao selecionada

## Exemplo completo

```js
import { buildCurrentDateLabel } from './helpers'

export const riskUpdateTemplate = {
  key: 'risk-update',
  label: 'Risco',
  description: 'Padrao para registrar risco e mitigacao.',
  markdownTitle: 'Risco identificado',
  fields: [
    {
      key: 'date',
      label: 'Data',
      type: 'text',
      renderAs: 'bullet',
      defaultValue: () => buildCurrentDateLabel(),
    },
    {
      key: 'owner',
      label: 'Responsavel',
      type: 'text',
      renderAs: 'bullet',
      placeholder: 'Quem registrou o risco',
    },
    {
      key: 'risk',
      label: 'Risco identificado',
      type: 'textarea',
      placeholder: 'Descreva claramente o risco.',
    },
    {
      key: 'mitigation',
      label: 'Mitigacao',
      type: 'list',
      listStyle: 'checklist',
      placeholder: 'Uma acao por linha.',
    },
  ],
}
```

Registro no [index.js](./index.js):

```js
import { riskUpdateTemplate } from './riskUpdate'

export const updateTemplates = [
  statusUpdateTemplate,
  blockerUpdateTemplate,
  handoffUpdateTemplate,
  resolutionUpdateTemplate,
  riskUpdateTemplate,
]
```

## Boas praticas

- Use `key` curto e estavel em kebab-case.
- Prefira `label` curto e `description` objetiva.
- Nao crie campos demais se o update puder ser resumido.
- Use `list` quando a informacao naturalmente for lista.
- Use `renderAs: 'commit'` apenas para campos de commit.
- Reaproveite [helpers.js](./helpers.js) quando houver default dinamico compartilhado.
- Se o template tiver um comportamento novo de renderizacao, ajuste tambem o [IssueEditModal.vue](../../components/tasks/IssueEditModal.vue) ou os utilitarios de [issueTemplate.js](../../utils/issueTemplate.js).

## Checklist para adicionar um novo template

- Criar arquivo na pasta `updateTemplates`
- Exportar o objeto do template
- Importar em [index.js](./index.js)
- Adicionar no array `updateTemplates`
- Validar no modal de edicao da issue
- Confirmar se o preview Markdown ficou correto
