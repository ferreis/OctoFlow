<?php

namespace App\Github;

final class GithubIssueUpdateTemplateCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $templates = $this->loadTemplates();
        usort(
            $templates,
            static fn (array $left, array $right): int => strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''))
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
        $templateFiles = glob(__DIR__ . '/Templates/update/*.php') ?: [];
        sort($templateFiles);

        foreach ($templateFiles as $templateFile) {
            $template = require $templateFile;
            if (!is_array($template)) {
                continue;
            }

            $templateKey = trim((string) ($template['key'] ?? ''));
            $templateLabel = trim((string) ($template['label'] ?? ''));
            if ($templateKey === '' || $templateLabel === '') {
                continue;
            }

            $templates[] = $template;
        }

        return $templates;
    }
}
