<?php

return [
    'key' => 'rule-question',
    'name' => 'Dúvida de regra',
    'description' => 'Template para validar divergências entre frontend, backend e regra esperada.',
    'access' => [],
    'titlePrefix' => 'question',
    'defaultLabels' => ['qa'],
    'fields' => [
        [
            'key' => 'moduleOrScreen',
            'label' => 'Módulo ou tela',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: cadastro, checkout, perfil, tarefa local.',
        ],
        [
            'key' => 'fieldOrRule',
            'label' => 'Campo ou regra em dúvida',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: status, obrigatoriedade, máscara, validação.',
        ],
        [
            'key' => 'frontendBehavior',
            'label' => 'Comportamento observado no frontend',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva o que a interface está fazendo hoje.',
        ],
        [
            'key' => 'backendBehavior',
            'label' => 'Comportamento observado no backend',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva o retorno da API, validação ou regra aplicada.',
        ],
        [
            'key' => 'expectedRule',
            'label' => 'Regra que precisa ser confirmada',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Explique qual regra deveria valer e qual decisão precisa ser tomada.',
        ],
        [
            'key' => 'evidence',
            'label' => 'Evidências',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Links, prints, payloads, respostas ou passos de teste.',
        ],
    ],
];
