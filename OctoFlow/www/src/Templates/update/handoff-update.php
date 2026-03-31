<?php

return [
    'key' => 'handoff-update',
    'label' => 'Repasse',
    'description' => 'Contexto pronto para troca de responsável.',
    'access' => [],
    'markdownTitle' => 'Repasse de atendimento',
    'fields' => [
        [
            'key' => 'nextOwner',
            'label' => 'Próximo responsável',
            'type' => 'select',
            'renderAs' => 'bullet',
            'options' => [],
        ],
        [
            'key' => 'commitRef',
            'label' => 'Commit relacionado',
            'type' => 'text',
            'renderAs' => 'commit',
            'placeholder' => 'Hash, SHA curto ou URL do commit',
        ],
        [
            'key' => 'currentContext',
            'label' => 'Contexto atual',
            'type' => 'textarea',
            'placeholder' => 'Resumo do que já foi feito e da situação atual.',
        ],
        [
            'key' => 'doneItems',
            'label' => 'Itens concluídos',
            'type' => 'list',
            'listStyle' => 'bullet',
            'placeholder' => 'Um item por linha.',
        ],
        [
            'key' => 'pendingItems',
            'label' => 'Pendências',
            'type' => 'list',
            'listStyle' => 'checklist',
            'placeholder' => 'Uma pendência por linha.',
        ],
    ],
];
