<?php

namespace App\Tests\Unit\Github;

use App\Github\GithubIssueUpdateRenderer;
use App\Github\GithubIssueUpdateTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class GithubIssueUpdateRendererTest extends TestCase
{
    private GithubIssueUpdateTemplateCatalog $templateCatalog;
    private GithubIssueUpdateRenderer $renderer;

    protected function setUp(): void
    {
        $this->templateCatalog = new GithubIssueUpdateTemplateCatalog();
        $this->renderer = new GithubIssueUpdateRenderer();
    }

    public function testRenderBuildsUpdateBlockOnTopOfCurrentBody(): void
    {
        $template = $this->templateCatalog->find('status-update');
        self::assertIsArray($template);

        $template['fields'][0]['options'] = [
            [
                'value' => 'user-ana',
                'label' => 'Ana Silva (ana)',
            ],
        ];

        $body = $this->renderer->render(
            $template,
            [
                'owner' => 'user-ana',
                'commitRef' => 'abc1234',
                'currentStatus' => 'Fluxo revisado no backend.',
                'nextStep' => 'Validar com o time de suporte.',
                'notes' => 'Sem impacto adicional.',
            ],
            "## Contexto\nConteudo anterior",
            'Observacao complementar',
            'acme/alpha',
        );

        $this->assertStringContainsString("## Contexto\nConteudo anterior", $body);
        $this->assertStringContainsString('## Atualizacao de status', $body);
        $this->assertStringContainsString('- Responsavel: Ana Silva (ana)', $body);
        $this->assertStringContainsString('- Commit relacionado: [abc1234](https://github.com/acme/alpha/commit/abc1234)', $body);
        $this->assertStringContainsString("### Situacao atual\nFluxo revisado no backend.", $body);
        $this->assertStringContainsString('Observacao complementar', $body);
    }

    public function testRenderRejectsInvalidSelectValue(): void
    {
        $template = $this->templateCatalog->find('status-update');
        self::assertIsArray($template);

        $template['fields'][0]['options'] = [
            [
                'value' => 'user-ana',
                'label' => 'Ana Silva (ana)',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The value "user-invalido" is not valid for the field "Responsavel".');

        $this->renderer->render(
            $template,
            [
                'owner' => 'user-invalido',
            ],
            '',
            '',
            'acme/alpha',
        );
    }
}
