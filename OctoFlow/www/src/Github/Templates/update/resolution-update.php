<?php

return [
    'key' => 'resolution-update',
    'label' => 'Resolucao',
    'description' => 'Fechamento estruturado do chamado.',
    'access' => [],
    'markdownTitle' => 'Resolucao do chamado',
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
            'key' => 'appliedFix',
            'label' => 'Ajuste aplicado',
            'type' => 'textarea',
            'placeholder' => 'Explique a mudanca realizada.',
        ],
    ],
];
