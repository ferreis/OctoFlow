<?php

return [
    'key' => 'support-request',
    'name' => 'Chamado de suporte',
    'description' => 'Template para atendimento, dúvidas operacionais e apoio funcional.',
    'access' => [],
    'titlePrefix' => 'support',
    'defaultLabels' => ['qa'],
    'fields' => [
        [
            'key' => 'requestArea',
            'label' => 'Área solicitante',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: financeiro, atendimento, produto, operações.',
        ],
        [
            'key' => 'requestSummary',
            'label' => 'Resumo do chamado',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva o que foi solicitado e o contexto do atendimento.',
        ],
        [
            'key' => 'currentSituation',
            'label' => 'Situação atual',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'O que o usuário ou time está vendo agora?',
        ],
        [
            'key' => 'expectedOutcome',
            'label' => 'Resultado esperado',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Qual resultado resolveria o chamado?',
        ],
        [
            'key' => 'urgency',
            'label' => 'Urgência',
            'type' => 'select',
            'required' => true,
            'defaultValue' => 'normal',
            'options' => [
                ['value' => 'baixa', 'label' => 'Baixa'],
                ['value' => 'normal', 'label' => 'Normal'],
                ['value' => 'alta', 'label' => 'Alta'],
                ['value' => 'critica', 'label' => 'Crítica'],
            ],
        ],
        [
            'key' => 'references',
            'label' => 'Links, IDs ou evidências',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Tickets relacionados, URLs, prints ou IDs.',
        ],
    ],
];
