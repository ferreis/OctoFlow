<?php

return [
    'key' => 'resolution-update',
    'label' => 'Resolução',
    'description' => 'Fechamento estruturado do chamado.',
    'access' => [],
    'markdownTitle' => 'Resolução do chamado',
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
            'key' => 'appliedFix',
            'label' => 'Ajuste aplicado',
            'type' => 'textarea',
            'placeholder' => 'Explique a mudança realizada.',
        ],
    ],
];
