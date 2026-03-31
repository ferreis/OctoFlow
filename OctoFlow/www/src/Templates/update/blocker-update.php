<?php

return [
    'key' => 'blocker-update',
    'label' => 'Bloqueio',
    'description' => 'Padrão para registrar impedimento e ação necessária.',
    'access' => [],
    'markdownTitle' => 'Bloqueio',
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
            'key' => 'blocker',
            'label' => 'Bloqueio identificado',
            'type' => 'textarea',
            'placeholder' => 'Explique claramente o impedimento.',
        ],
        [
            'key' => 'impact',
            'label' => 'Impacto',
            'type' => 'textarea',
            'placeholder' => 'O que está sendo afetado por este bloqueio.',
        ],
        [
            'key' => 'requiredAction',
            'label' => 'Ação necessária',
            'type' => 'textarea',
            'placeholder' => 'O que precisa acontecer para liberar o fluxo.',
        ],
    ],
];
