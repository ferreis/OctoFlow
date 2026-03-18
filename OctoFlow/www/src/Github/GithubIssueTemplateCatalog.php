<?php

namespace App\Github;

final class GithubIssueTemplateCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $templates = $this->loadTemplates();
        usort(
            $templates,
            static fn (array $left, array $right): int => strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''))
        );

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $templateKey): ?array
    {
        $normalizedKey = trim($templateKey);
        if ($normalizedKey === '') {
            return null;
        }

        foreach ($this->loadTemplates() as $template) {
            if (($template['key'] ?? null) === $normalizedKey) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadTemplates(): array
    {
        $templates = [];
        $templateFiles = glob(__DIR__ . '/Templates/issue/*.php') ?: [];
        sort($templateFiles);

        foreach ($templateFiles as $templateFile) {
            $template = require $templateFile;
            if (!is_array($template)) {
                continue;
            }

            $templateKey = trim((string) ($template['key'] ?? ''));
            $templateName = trim((string) ($template['name'] ?? ''));
            if ($templateKey === '' || $templateName === '') {
                continue;
            }

            $templates[] = $template;
        }

        return $templates;
    }
}
