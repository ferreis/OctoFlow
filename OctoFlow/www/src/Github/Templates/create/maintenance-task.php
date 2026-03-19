<?php

return [
    'key' => 'maintenance-task',
    'name' => 'Tarefa de manutencao',
    'description' => 'Template para rotinas tecnicas, ajustes internos e manutencoes planejadas.',
    'titlePrefix' => 'chore',
    'defaultLabels' => [],
    'fields' => [
        [
            'key' => 'scope',
            'label' => 'Escopo',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva o que sera ajustado ou revisado nesta manutencao.',
        ],
        [
            'key' => 'motivation',
            'label' => 'Motivacao',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Explique por que esta manutencao precisa acontecer agora.',
        ],
        [
            'key' => 'executionPlan',
            'label' => 'Plano de execucao',
            'type' => 'list',
            'style' => 'checklist',
            'required' => true,
            'placeholder' => 'Uma etapa por linha.',
        ],
        [
            'key' => 'validationPlan',
            'label' => 'Plano de validacao',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Como a manutencao sera validada apos a execucao.',
        ],
        [
            'key' => 'riskNotes',
            'label' => 'Riscos e observacoes',
            'type' => 'textarea',
            'required' => false,
            'placeholder' => 'Riscos conhecidos, janelas, dependencias ou cuidados extras.',
        ],
    ],
];
