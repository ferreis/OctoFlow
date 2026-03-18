<?php

return [
    'key' => 'incident-report',
    'name' => 'Incidente operacional',
    'description' => 'Template para indisponibilidade, degradação ou falha critica em produção.',
    'titlePrefix' => 'incident',
    'defaultLabels' => ['bug'],
    'fields' => [
        [
            'key' => 'affectedService',
            'label' => 'Servico afetado',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: auth, pagamentos, painel do cliente.',
        ],
        [
            'key' => 'impact',
            'label' => 'Impacto percebido',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Quantos usuarios ou processos foram afetados?',
        ],
        [
            'key' => 'detection',
            'label' => 'Como o incidente foi detectado',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Alerta, atendimento, monitoramento, relato interno.',
        ],
        [
            'key' => 'mitigation',
            'label' => 'Mitigação imediata',
            'type' => 'textarea',
            'required' => false,
            'placeholder' => 'Acoes ja executadas para reduzir impacto.',
        ],
        [
            'key' => 'nextActions',
            'label' => 'Proximas acoes',
            'type' => 'list',
            'style' => 'checklist',
            'required' => true,
            'placeholder' => 'Uma ação por linha.',
        ],
        [
            'key' => 'communication',
            'label' => 'Plano de comunicação',
            'type' => 'textarea',
            'required' => false,
            'placeholder' => 'Como esse incidente precisa ser comunicado para o time ou clientes?',
        ],
    ],
];
