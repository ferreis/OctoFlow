<?php

return [
    'key' => 'status-update',
    'label' => 'Atualizacao de status',
    'description' => 'Resumo rapido do andamento atual do chamado.',
    'markdownTitle' => 'Atualizacao de status',
    'fields' => [
        [
            'key' => 'owner',
            'label' => 'Responsavel',
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
            'label' => 'Situacao atual',
            'type' => 'textarea',
            'placeholder' => 'Descreva o estado atual do chamado.',
        ],
        [
            'key' => 'nextStep',
            'label' => 'Proximo passo',
            'type' => 'textarea',
            'placeholder' => 'Informe a proxima acao prevista.',
        ],
        [
            'key' => 'notes',
            'label' => 'Observacoes',
            'type' => 'textarea',
            'placeholder' => 'Riscos, alinhamentos ou contexto adicional.',
        ],
    ],
];
