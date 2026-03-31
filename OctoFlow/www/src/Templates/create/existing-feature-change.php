<?php

return [
    'key' => 'existing-feature-change',
    'name' => 'Feat - Alteração de funcionalidade existente',
    'description' => 'Template para alterar comportamento de funcionalidade existente com contexto, impacto e critério de aceite.',
    'access' => [],
    'titlePrefix' => 'feat',
    'defaultLabels' => ['improvement'],
    'fields' => [
        [
            'key' => 'affectedFeature',
            'label' => 'Funcionalidade afetada',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: filtro de tarefas, aprovação de pagamento, fluxo de cadastro.',
        ],
        [
            'key' => 'expectedChange',
            'label' => 'Alteração esperada',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Explique o novo comportamento esperado após a alteração.',
        ],
    ],
];
