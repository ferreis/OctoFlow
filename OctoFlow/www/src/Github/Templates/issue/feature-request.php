<?php

return [
    'key' => 'feature-request',
    'name' => 'Feat - Nova Funcionalidade',
    'description' => 'Estrutura para novas funcionalidades com Descrição, regra de negocio e criterios de aceitação.',
    'titlePrefix' => 'feat',
    'defaultLabels' => ['enhancement'],
    'fields' => [
        [
            'key' => 'description',
            'label' => 'Descriçao',
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
            'label' => 'Criterios de aceitação',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Liste os criterios de aceitação esperados para concluir a funcionalidade.',
        ],
    ],
];
