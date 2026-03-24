<?php

return [
    'key' => 'feature-request',
    'name' => 'Feat - Nova Funcionalidade',
    'description' => 'Estrutura para novas funcionalidades com descricao, regra de negocio e criterios de aceitacao.',
    'access' => [],
    'titlePrefix' => 'feat',
    'defaultLabels' => ['feature'],
    'fields' => [
        [
            'key' => 'description',
            'label' => 'Descricao',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva a funcionalidade e o objetivo da entrega.',
        ],
        [
            'key' => 'businessRule',
            'label' => 'Regra de negocio',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Explique a regra de negocio que precisa ser atendida.',
        ],
        [
            'key' => 'acceptanceCriteria',
            'label' => 'Criterios de aceitacao',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Liste os criterios de aceitacao esperados para concluir a funcionalidade.',
        ],
    ],
];
