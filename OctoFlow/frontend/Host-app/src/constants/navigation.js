export const navigationItems = [
  {
    key: 'dashboard',
    short: 'DB',
    label: 'Dashboard',
    eyebrow: '',
    title: 'Dashboard',
  },
  {
    key: 'tasks',
    short: 'TF',
    label: 'Tarefas',
    eyebrow: 'Issues do GitHub',
    title: 'Inbox operacional do OctoFlow',
  },
  {
    key: 'finance-group',
    type: 'group',
    short: 'FN',
    label: 'Financeiro',
    eyebrow: 'Financeiro',
    title: 'Controle financeiro pessoal',
    children: [
      {
        key: 'finance.accounts',
        short: 'CT',
        label: 'Contas',
      },
      {
        key: 'finance.banks',
        short: 'BK',
        label: 'Bancos',
      },
      {
        key: 'finance.investments',
        short: 'IV',
        label: 'Investimentos',
      },
      {
        key: 'finance.reports',
        short: 'RP',
        label: 'Relatórios',
      },
    ],
  },
  {
    key: 'profile',
    short: 'PR',
    label: 'Perfil',
    eyebrow: 'Perfil',
    title: 'Identidade e configuracoes',
  },
]
