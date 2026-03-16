<?php

namespace App\Github;

final class GithubIssueTemplateCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const TEMPLATES = [
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
