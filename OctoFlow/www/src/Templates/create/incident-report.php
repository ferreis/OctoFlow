<?php

return [
    'key' => 'incident-report',
    'name' => 'Incidente operacional',
    'description' => 'Template para indisponibilidade, degradação ou falha crítica em produção.',
    'access' => [],
    'titlePrefix' => 'incident',
    'defaultLabels' => ['bug', 'blocked'],
    'fields' => [
        [
            'key' => 'affectedService',
            'label' => 'Serviço afetado',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: auth, pagamentos, painel do cliente.',
        ],
        [
            'key' => 'impact',
            'label' => 'Impacto percebido',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Quantos usuários ou processos foram afetados?',
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
            'placeholder' => 'Ações já executadas para reduzir impacto.',
        ],
        [
            'key' => 'nextActions',
            'label' => 'Próximas ações',
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
