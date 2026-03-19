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
            'key' => 'rootCause',
            'label' => 'Causa raiz',
            'type' => 'textarea',
            'placeholder' => 'Descreva a origem do problema.',
        ],
        [
            'key' => 'appliedFix',
            'label' => 'Ajuste aplicado',
            'type' => 'textarea',
            'placeholder' => 'Explique a mudanca realizada.',
        ],
        [
            'key' => 'validation',
            'label' => 'Validacao realizada',
            'type' => 'list',
            'listStyle' => 'checklist',
            'placeholder' => 'Um teste ou validacao por linha.',
        ],
        [
            'key' => 'followUp',
            'label' => 'Acompanhamentos',
            'type' => 'list',
            'listStyle' => 'bullet',
            'placeholder' => 'Itens adicionais ou monitoramentos futuros.',
        ],
    ],
];
