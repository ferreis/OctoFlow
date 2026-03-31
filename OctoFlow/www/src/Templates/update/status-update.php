<?php

return [
    'key' => 'status-update',
    'label' => 'Atualização de status',
    'description' => 'Resumo rápido do andamento atual do chamado.',
    'access' => [],
    'markdownTitle' => 'Atualização de status',
    'fields' => [
        [
            'key' => 'owner',
            'label' => 'Responsável',
            'type' => 'select',
            'renderAs' => 'bullet',
            'options' => [],
        ],
        [
            'key' => 'commitRef',
            'label' => 'Commit relacionado',
            'type' => 'text',
            'renderAs' => 'commit',
            'placeholder' => 'Hash, SHA curto ou URL do commit',
        ],
        [
            'key' => 'currentStatus',
            'label' => 'Situação atual',
            'type' => 'textarea',
            'placeholder' => 'Descreva o estado atual do chamado.',
        ],
        [
            'key' => 'nextStep',
            'label' => 'Próximo passo',
            'type' => 'textarea',
            'placeholder' => 'Informe a próxima ação prevista.',
        ],
        [
            'key' => 'notes',
            'label' => 'Observações',
            'type' => 'textarea',
            'placeholder' => 'Riscos, alinhamentos ou contexto adicional.',
        ],
    ],
];
