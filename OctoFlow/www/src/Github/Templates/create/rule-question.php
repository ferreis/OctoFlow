<?php

return [
    'key' => 'rule-question',
    'name' => 'Duvida de regra',
    'description' => 'Template para validar divergencias entre frontend, backend e regra esperada.',
    'titlePrefix' => 'question',
    'defaultLabels' => ['question'],
    'fields' => [
        [
            'key' => 'moduleOrScreen',
            'label' => 'Modulo ou tela',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: cadastro, checkout, perfil, tarefa local.',
        ],
        [
            'key' => 'fieldOrRule',
            'label' => 'Campo ou regra em duvida',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Ex.: status, obrigatoriedade, mascara, validacao.',
        ],
        [
            'key' => 'frontendBehavior',
            'label' => 'Comportamento observado no frontend',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva o que a interface esta fazendo hoje.',
        ],
        [
            'key' => 'backendBehavior',
            'label' => 'Comportamento observado no backend',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Descreva o retorno da API, validacao ou regra aplicada.',
        ],
        [
            'key' => 'expectedRule',
            'label' => 'Regra que precisa ser confirmada',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Explique qual regra deveria valer e qual decisao precisa ser tomada.',
        ],
        [
            'key' => 'evidence',
            'label' => 'Evidencias',
            'type' => 'list',
            'style' => 'bullets',
            'required' => false,
            'placeholder' => 'Links, prints, payloads, respostas ou passos de teste.',
        ],
    ],
];
