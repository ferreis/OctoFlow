<?php

return [
    'key' => 'bug-report',
    'name' => 'Bug - Reporte de erro',
    'description' => 'Modelo para falhas com contexto, reprodução e severidade.',
    'access' => [],
    'titlePrefix' => 'bug',
    'defaultLabels' => ['bug'],
    'fields' => [
        [
            'key' => 'environment',
            'label' => 'Ambiente',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: produção, staging, localhost, branch x.',
        ],
        [
            'key' => 'severity',
            'label' => 'Severidade',
            'type' => 'select',
            'required' => true,
            'defaultValue' => 'medium',
            'options' => [
                ['value' => 'low', 'label' => 'Baixa'],
                ['value' => 'medium', 'label' => 'Média'],
                ['value' => 'high', 'label' => 'Alta'],
                ['value' => 'critical', 'label' => 'Crítica'],
            ],
        ],
        [
            'key' => 'steps',
            'label' => 'Passos para reproduzir',
            'type' => 'list',
            'style' => 'bullets',
            'required' => true,
            'placeholder' => 'Um passo por linha.',
        ],
        [
            'key' => 'expectedBehavior',
            'label' => 'Comportamento esperado',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'O que deveria acontecer?',
        ],
        [
            'key' => 'actualBehavior',
            'label' => 'Comportamento atual',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'O que está acontecendo de fato?',
        ],
    ],
];
