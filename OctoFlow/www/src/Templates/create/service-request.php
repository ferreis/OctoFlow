<?php

return [
    'key' => 'service-request',
    'name' => 'Solicitação de serviço',
    'description' => 'Template para ajustes operacionais, acessos e demandas recorrentes.',
    'access' => [],
    'titlePrefix' => 'service',
    'defaultLabels' => ['improvement'],
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
                ['value' => 'configuracao', 'label' => 'Configuração'],
            ],
        ],
        [
            'key' => 'businessContext',
            'label' => 'Contexto de negócio',
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
            'placeholder' => 'Ex.: até sexta, antes do deploy, data específica.',
        ],
        [
            'key' => 'approvals',
            'label' => 'Aprovações ou responsáveis',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Uma aprovação ou responsável por linha.',
        ],
        [
            'key' => 'completionCriteria',
            'label' => 'Critérios de conclusão',
            'type' => 'list',
            'style' => 'checklist',
            'required' => true,
            'placeholder' => 'Um critério por linha.',
        ],
    ],
];
