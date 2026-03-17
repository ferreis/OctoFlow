<?php

namespace App\Github;

final class GithubIssueTemplateCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const TEMPLATES = [
        'support-request' => [
            'key' => 'support-request',
            'name' => 'Chamado de suporte',
            'description' => 'Template para atendimento, duvidas operacionais e apoio funcional.',
            'titlePrefix' => 'support',
            'defaultLabels' => ['question'],
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
        ],
        'incident-report' => [
            'key' => 'incident-report',
            'name' => 'Incidente operacional',
            'description' => 'Template para indisponibilidade, degradacao ou falha critica em producao.',
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
                    'label' => 'Mitigacao imediata',
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
                    'placeholder' => 'Uma acao por linha.',
                ],
                [
                    'key' => 'communication',
                    'label' => 'Plano de comunicacao',
                    'type' => 'textarea',
                    'required' => false,
                    'placeholder' => 'Como esse incidente precisa ser comunicado para o time ou clientes?',
                ],
            ],
        ],
        'service-request' => [
            'key' => 'service-request',
            'name' => 'Solicitacao de servico',
            'description' => 'Template para ajustes operacionais, acessos e demandas recorrentes.',
            'titlePrefix' => 'service',
            'defaultLabels' => [],
            'fields' => [
                [
                    'key' => 'requestType',
                    'label' => 'Tipo da solicitacao',
                    'type' => 'select',
                    'required' => true,
                    'defaultValue' => 'operacional',
                    'options' => [
                        ['value' => 'operacional', 'label' => 'Operacional'],
                        ['value' => 'acesso', 'label' => 'Acesso'],
                        ['value' => 'dados', 'label' => 'Dados'],
                        ['value' => 'configuracao', 'label' => 'Configuracao'],
                    ],
                ],
                [
                    'key' => 'businessContext',
                    'label' => 'Contexto de negocio',
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => 'Explique por que essa solicitacao foi aberta.',
                ],
                [
                    'key' => 'requestedAction',
                    'label' => 'Acao solicitada',
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => 'Descreva a alteracao ou entrega esperada.',
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
                    'placeholder' => 'Uma aprovacao ou responsavel por linha.',
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
        ],
        'feature-request' => [
            'key' => 'feature-request',
            'name' => 'Nova funcionalidade',
            'description' => 'Estrutura para novas entregas orientadas a impacto e criterios de aceite.',
            'titlePrefix' => 'feat',
            'defaultLabels' => ['enhancement'],
            'fields' => [
                [
                    'key' => 'problem',
                    'label' => 'Problema ou oportunidade',
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => 'Explique por que essa entrega faz sentido agora.',
                ],
                [
                    'key' => 'proposal',
                    'label' => 'Proposta de solucao',
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => 'Descreva o fluxo esperado, APIs ou comportamento desejado.',
                ],
                [
                    'key' => 'userImpact',
                    'label' => 'Impacto para usuario ou time',
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => 'Qual melhoria concreta essa funcionalidade entrega?',
                ],
                [
                    'key' => 'acceptanceCriteria',
                    'label' => 'Criterios de aceite',
                    'type' => 'list',
                    'style' => 'checklist',
                    'required' => true,
                    'placeholder' => 'Um item por linha.',
                ],
                [
                    'key' => 'outOfScope',
                    'label' => 'Fora de escopo',
                    'type' => 'list',
                    'style' => 'bullets',
                    'required' => false,
                    'placeholder' => 'Opcional. Um item por linha.',
                ],
            ],
        ],
        'bug-report' => [
            'key' => 'bug-report',
            'name' => 'Bug report',
            'description' => 'Modelo para falhas com contexto, reproducao e severidade.',
            'titlePrefix' => 'bug',
            'defaultLabels' => ['bug'],
            'fields' => [
                [
                    'key' => 'environment',
                    'label' => 'Ambiente',
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => 'Ex.: producao, staging, localhost, branch x.',
                ],
                [
                    'key' => 'severity',
                    'label' => 'Severidade',
                    'type' => 'select',
                    'required' => true,
                    'defaultValue' => 'medium',
                    'options' => [
                        ['value' => 'low', 'label' => 'Baixa'],
                        ['value' => 'medium', 'label' => 'Media'],
                        ['value' => 'high', 'label' => 'Alta'],
                        ['value' => 'critical', 'label' => 'Critica'],
                    ],
                ],
                [
                    'key' => 'steps',
                    'label' => 'Passos para reproduzir',
                    'type' => 'list',
                    'style' => 'bullets',
                    'required' => true,
                    'placeholder' => 'Um passo por linha.',
                ],
                [
                    'key' => 'expectedBehavior',
                    'label' => 'Comportamento esperado',
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => 'O que deveria acontecer?',
                ],
                [
                    'key' => 'actualBehavior',
                    'label' => 'Comportamento atual',
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => 'O que esta acontecendo de fato?',
                ],
                [
                    'key' => 'evidence',
                    'label' => 'Evidencias ou links',
                    'type' => 'list',
                    'style' => 'bullets',
                    'required' => false,
                    'placeholder' => 'Logs, prints, links ou IDs relacionados.',
                ],
            ],
        ],
        'maintenance-task' => [
            'key' => 'maintenance-task',
            'name' => 'Tarefa tecnica',
            'description' => 'Modelo para manutencao, melhorias operacionais e debito tecnico.',
            'titlePrefix' => 'chore',
            'defaultLabels' => [],
            'fields' => [
                [
                    'key' => 'context',
                    'label' => 'Contexto tecnico',
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => 'Qual parte do sistema precisa de atencao e por que?',
                ],
                [
                    'key' => 'deliverables',
                    'label' => 'Entregaveis',
                    'type' => 'list',
                    'style' => 'checklist',
                    'required' => true,
                    'placeholder' => 'Um item por linha.',
                ],
                [
                    'key' => 'dependencies',
                    'label' => 'Dependencias ou bloqueios',
                    'type' => 'list',
                    'style' => 'bullets',
                    'required' => false,
                    'placeholder' => 'Dependencias externas, PRs ou decisoes pendentes.',
                ],
                [
                    'key' => 'rolloutPlan',
                    'label' => 'Plano de rollout',
                    'type' => 'textarea',
                    'required' => false,
                    'placeholder' => 'Descreva como validar e disponibilizar a mudanca.',
                ],
                [
                    'key' => 'definitionOfDone',
                    'label' => 'Definition of done',
                    'type' => 'list',
                    'style' => 'checklist',
                    'required' => true,
                    'placeholder' => 'Um item por linha.',
                ],
            ],
        ],
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(self::TEMPLATES);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $normalizedKey = trim($key);

        return self::TEMPLATES[$normalizedKey] ?? null;
    }
}
