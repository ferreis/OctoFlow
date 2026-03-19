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

    public function testRenderFeatureRequestAddsPrefixAndStructuredFields(): void
    {
        $template = $this->templateCatalog->find('feature-request');
        self::assertIsArray($template);

        $renderedIssue = $this->renderer->render($template, 'Integrar workspace GitHub', [
            'description' => 'Hoje a abertura de issue e manual.',
            'businessRule' => 'A funcionalidade precisa seguir o fluxo operacional definido pelo time.',
            'acceptanceCriteria' => "Criar issue no repositorio configurado\nAdicionar a issue ao backlog interno",
        ], 'owner@example.com');

        $this->assertSame('[feat] Integrar workspace GitHub', $renderedIssue['title']);
        $this->assertStringContainsString('> Solicitante: owner@example.com', $renderedIssue['body']);
        $this->assertStringContainsString('## Descriçao', $renderedIssue['body']);
        $this->assertStringContainsString('## Regra de negocio', $renderedIssue['body']);
        $this->assertStringContainsString('## Criterios de aceitação', $renderedIssue['body']);
        $this->assertStringContainsString('Criar issue no repositorio configurado', $renderedIssue['body']);
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

    public function testRenderRuleQuestionAddsQuestionPrefixAndComparisonSections(): void
    {
        $template = $this->templateCatalog->find('rule-question');
        self::assertIsArray($template);

        $renderedIssue = $this->renderer->render($template, 'Campo status diverge entre frontend e backend', [
            'moduleOrScreen' => 'Tela de cadastro',
            'fieldOrRule' => 'Campo status',
            'frontendBehavior' => 'O frontend aceita salvar com status vazio.',
            'backendBehavior' => 'A API retorna erro informando que o campo e obrigatorio.',
            'expectedRule' => 'Confirmar se o campo deve ser obrigatorio nos dois lados.',
            'evidence' => "POST /customers\n422 campo status obrigatorio",
        ], 'owner@example.com');

        $this->assertSame('[question] Campo status diverge entre frontend e backend', $renderedIssue['title']);
        $this->assertStringContainsString('## Modulo ou tela', $renderedIssue['body']);
        $this->assertStringContainsString('## Campo ou regra em duvida', $renderedIssue['body']);
        $this->assertStringContainsString('## Comportamento observado no frontend', $renderedIssue['body']);
        $this->assertStringContainsString('## Comportamento observado no backend', $renderedIssue['body']);
        $this->assertStringContainsString('## Regra que precisa ser confirmada', $renderedIssue['body']);
    }
}
