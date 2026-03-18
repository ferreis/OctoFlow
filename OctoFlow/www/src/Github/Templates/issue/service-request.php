<?php

return [
    'key' => 'service-request',
    'name' => 'Solicitação de servico',
    'description' => 'Template para ajustes operacionais, acessos e demandas recorrentes.',
    'titlePrefix' => 'service',
    'defaultLabels' => [],
    'fields' => [
        [
            'key' => 'requestType',
            'label' => 'Tipo da solicitação',
            'type' => 'select',
            'required' => true,
            'defaultValue' => 'operacional',
            'options' => [
                ['value' => 'operacional', 'label' => 'Operacional'],
                ['value' => 'acesso', 'label' => 'Acesso'],
                ['value' => 'dados', 'label' => 'Dados'],
                ['value' => 'configuração', 'label' => 'Configuração'],
            ],
        ],
        [
            'key' => 'businessContext',
            'label' => 'Contexto de negocio',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Explique por que essa solicitação foi aberta.',
        ],
        [
            'key' => 'requestedAction',
            'label' => 'Ação solicitada',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva a alteração ou entrega esperada.',
        ],
        [
            'key' => 'dueDate',
            'label' => 'Prazo desejado',
            'type' => 'text',
            'required' => false,
            'placeholder' => 'Ex.: ate sexta, antes do deploy, data especifica.',
        ],
        [
            'key' => 'approvals',
            'label' => 'Aprovacoes ou responsaveis',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Uma aprovação ou responsavel por linha.',
        ],
        [
            'key' => 'completionCriteria',
            'label' => 'Criterios de conclusao',
            'type' => 'list',
            'style' => 'checklist',
            'required' => true,
            'placeholder' => 'Um criterio por linha.',
        ],
    ],
];
