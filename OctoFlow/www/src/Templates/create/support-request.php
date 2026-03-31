<?php

return [
    'key' => 'support-request',
    'name' => 'Chamado de suporte',
    'description' => 'Template para atendimento, duvidas operacionais e apoio funcional.',
    'access' => [],
    'titlePrefix' => 'support',
    'defaultLabels' => ['qa'],
    'fields' => [
        [
            'key' => 'requestArea',
            'label' => 'Area solicitante',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: financeiro, atendimento, produto, operacoes.',
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
            'label' => 'Situacao atual',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'O que o usuario ou time esta vendo agora?',
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
            'label' => 'Urgencia',
            'type' => 'select',
            'required' => true,
            'defaultValue' => 'normal',
            'options' => [
                ['value' => 'baixa', 'label' => 'Baixa'],
                ['value' => 'normal', 'label' => 'Normal'],
                ['value' => 'alta', 'label' => 'Alta'],
                ['value' => 'critica', 'label' => 'Critica'],
            ],
        ],
        [
            'key' => 'references',
            'label' => 'Links, IDs ou evidencias',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Tickets relacionados, URLs, prints ou IDs.',
        ],
    ],
];
