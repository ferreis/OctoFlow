<?php

return [
    'key' => 'existing-feature-change',
    'name' => 'Feat - Alteracao de funcionalidade existente',
    'description' => 'Template para alterar comportamento de funcionalidade existente com contexto, impacto e criterio de aceite.',
    'access' => [],
    'titlePrefix' => 'feat',
    'defaultLabels' => ['improvement'],
    'fields' => [
        [
            'key' => 'affectedFeature',
            'label' => 'Funcionalidade afetada',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: filtro de tarefas, aprovacao de pagamento, fluxo de cadastro.',
        ],
        [
            'key' => 'currentBehavior',
            'label' => 'Comportamento atual',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva como a funcionalidade funciona hoje.',
        ],
        [
            'key' => 'expectedChange',
            'label' => 'Alteracao esperada',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Explique o novo comportamento esperado apos a alteracao.',
        ],
        [
            'key' => 'businessImpact',
            'label' => 'Impacto no negocio',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva o ganho esperado e o problema que sera resolvido.',
        ],
        [
            'key' => 'acceptanceCriteria',
            'label' => 'Criterios de aceitacao',
            'type' => 'list',
            'style' => 'checklist',
            'required' => true,
            'placeholder' => 'Um criterio por linha.',
        ],
        [
            'key' => 'risksAndDependencies',
            'label' => 'Riscos e dependencias',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Liste riscos, dependencias e pontos de atencao.',
        ],
    ],
];
