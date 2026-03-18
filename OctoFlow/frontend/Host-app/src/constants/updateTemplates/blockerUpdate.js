export const blockerUpdateTemplate = {
  key: 'blocker-update',
  label: 'Bloqueio',
  description: 'Padrao para registrar impedimento e acao necessaria.',
  markdownTitle: 'Bloqueio',
  fields: [
    {
      key: 'owner',
      label: 'Responsavel',
      type: 'select',
      renderAs: 'bullet',
      options: [],
    },
    {
      key: 'commitRef',
      label: 'Commit relacionado',
      type: 'text',
      renderAs: 'commit',
      placeholder: 'Hash, SHA curto ou URL do commit',
    },
    {
      key: 'blocker',
      label: 'Bloqueio identificado',
      type: 'textarea',
      placeholder: 'Explique claramente o impedimento.',
    },
    {
      key: 'impact',
      label: 'Impacto',
      type: 'textarea',
      placeholder: 'O que esta sendo afetado por este bloqueio.',
    },
    {
      key: 'requiredAction',
      label: 'Acao necessaria',
      type: 'textarea',
      placeholder: 'O que precisa acontecer para liberar o fluxo.',
    },
  ],
}
