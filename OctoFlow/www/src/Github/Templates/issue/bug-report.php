<?php

return [
    'key' => 'bug-report',
    'name' => 'Bug report',
    'description' => 'Modelo para falhas com contexto, reprodução e severidade.',
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
                ['value' => 'medium', 'label' => 'Media'],
                ['value' => 'high', 'label' => 'Alta'],
                ['value' => 'critical', 'label' => 'Critica'],
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
            'placeholder' => 'O que esta acontecendo de fato?',
        ],
        [
            'key' => 'evidence',
            'label' => 'Evidencias ou links',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Logs, prints, links ou IDs relacionados.',
        ],
    ],
];
