<?php

namespace App\Tests\Unit\Github;

use App\Github\GithubIssueBodyRenderer;
use App\Github\GithubIssueTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class GithubIssueBodyRendererTest extends TestCase
{
    private GithubIssueTemplateCatalog $templateCatalog;
    private GithubIssueBodyRenderer $renderer;

    protected function setUp(): void
    {
        $this->templateCatalog = new GithubIssueTemplateCatalog();
        $this->renderer = new GithubIssueBodyRenderer();
    }

    public function testRenderFeatureRequestAddsPrefixAndChecklist(): void
    {
        $template = $this->templateCatalog->find('feature-request');
        self::assertIsArray($template);

        $renderedIssue = $this->renderer->render($template, 'Integrar workspace GitHub', [
            'problem' => 'Hoje a abertura de issue e manual.',
            'proposal' => 'Criar um fluxo dentro da aplicacao para subir issues.',
            'userImpact' => 'O time reduz o trabalho operacional.',
            'acceptanceCriteria' => [
                'Criar issue no repositorio configurado',
                'Adicionar a issue ao Project selecionado',
            ],
            'outOfScope' => "Automatizar deploy\nCriar dashboard externo",
        ], 'owner@example.com');

        $this->assertSame('[feat] Integrar workspace GitHub', $renderedIssue['title']);
        $this->assertStringContainsString('> Solicitante: owner@example.com', $renderedIssue['body']);
        $this->assertStringContainsString('## Criterios de aceite', $renderedIssue['body']);
        $this->assertStringContainsString('- [ ] Criar issue no repositorio configurado', $renderedIssue['body']);
        $this->assertStringContainsString('## Fora de escopo', $renderedIssue['body']);
        $this->assertStringContainsString('- Automatizar deploy', $renderedIssue['body']);
    }

    public function testRenderRejectsMissingRequiredField(): void
    {
        $template = $this->templateCatalog->find('bug-report');
        self::assertIsArray($template);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The field "Passos para reproduzir" is required.');

        $this->renderer->render($template, 'Erro ao abrir dashboard', [
            'environment' => 'production',
            'severity' => 'critical',
            'steps' => [],
            'expectedBehavior' => 'Renderizar a tela normalmente.',
            'actualBehavior' => 'Retorna 500 ao carregar.',
        ], 'owner@example.com');
    }
}
