import { buildCurrentDateLabel } from './helpers'

export const handoffUpdateTemplate = {
  key: 'handoff-update',
  label: 'Repasse',
  description: 'Contexto pronto para troca de responsavel.',
  markdownTitle: 'Repasse de atendimento',
  fields: [
    {
      key: 'date',
      label: 'Data',
      type: 'text',
      renderAs: 'bullet',
      defaultValue: () => buildCurrentDateLabel(),
    },
    {
      key: 'nextOwner',
      label: 'Proximo responsavel',
      type: 'text',
      renderAs: 'bullet',
      placeholder: 'Pessoa ou time que assume a issue',
    },
    {
      key: 'commitRef',
      label: 'Commit relacionado',
      type: 'text',
      renderAs: 'commit',
      placeholder: 'Hash, SHA curto ou URL do commit',
    },
    {
      key: 'currentContext',
      label: 'Contexto atual',
      type: 'textarea',
      placeholder: 'Resumo do que ja foi feito e da situacao atual.',
    },
    {
      key: 'doneItems',
      label: 'Itens concluidos',
      type: 'list',
      listStyle: 'bullet',
      placeholder: 'Um item por linha.',
    },
    {
      key: 'pendingItems',
      label: 'Pendencias',
      type: 'list',
      listStyle: 'checklist',
      placeholder: 'Uma pendencia por linha.',
    },
  ],
}
