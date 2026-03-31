<?php

return [
    'key' => 'feature-request',
    'name' => 'Feat - Nova Funcionalidade',
    'description' => 'Estrutura para novas funcionalidades com descrição, regra de negócio e critérios de aceitação.',
    'access' => [],
    'titlePrefix' => 'feat',
    'defaultLabels' => ['feature'],
    'fields' => [
        [
            'key' => 'description',
            'label' => 'Descrição',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva a funcionalidade e o objetivo da entrega.',
        ],
        [
            'key' => 'businessRule',
            'label' => 'Regra de negócio',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Explique a regra de negócio que precisa ser atendida.',
        ],
        [
            'key' => 'acceptanceCriteria',
            'label' => 'Critérios de aceitação',
            'type' => 'list',
            'style' => 'checklist',
            'required' => true,
            'placeholder' => 'Liste os critérios de aceitação esperados para concluir a funcionalidade.',
        ],
    ],
];
