<?php

namespace App\Github;

final class GithubIssueUpdateRenderer
{
    private const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    /**
     * @param array<string, mixed>|null $template
     * @param array<string, mixed> $fieldValues
     */
    public function render(
        ?array $template,
        array $fieldValues,
        string $currentBody,
        string $additionalNotes = '',
        ?string $repositoryKey = null,
    ): string {
        $sections = [];
        $normalizedCurrentBody = trim($currentBody);
        $normalizedAdditionalNotes = trim($additionalNotes);
        $updateBlock = $template === null ? '' : $this->renderTemplate($template, $fieldValues, $repositoryKey);

        if ($normalizedCurrentBody !== '') {
            $sections[] = $normalizedCurrentBody;
        }

        if ($updateBlock !== '') {
            $sections[] = $updateBlock;
        }

        if ($normalizedAdditionalNotes !== '') {
            $sections[] = $normalizedAdditionalNotes;
        }

        return trim(implode("\n\n", $sections));
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $fieldValues
     */
    private function renderTemplate(array $template, array $fieldValues, ?string $repositoryKey): string
    {
        $lines = ['## ' . trim((string) ($template['markdownTitle'] ?? $template['label'] ?? 'Atualizacao'))];
        $hasContent = false;
        $timestampLabel = $this->buildTimestampLabel();

        if ($timestampLabel !== '') {
            $lines[] = '- Data e hora: ' . $timestampLabel;
            $hasContent = true;
        }

        $fieldDefinitions = $template['fields'] ?? [];
        if (!is_array($fieldDefinitions)) {
            throw new \InvalidArgumentException('The selected issue update template is invalid.');
        }

        foreach ($fieldDefinitions as $fieldDefinition) {
            if (!is_array($fieldDefinition)) {
                continue;
            }

            $fieldKey = trim((string) ($fieldDefinition['key'] ?? ''));
            if ($fieldKey === '') {
                continue;
            }

            $normalizedValue = $this->normalizeFieldValue($fieldDefinition, $fieldValues[$fieldKey] ?? null, $repositoryKey);
            if ($this->isEmptyValue($normalizedValue)) {
                continue;
            }

            $hasContent = true;

            if (is_array($normalizedValue)) {
                $lines[] = '### ' . trim((string) ($fieldDefinition['label'] ?? $fieldKey));

                foreach ($normalizedValue as $item) {
                    $prefix = trim((string) ($fieldDefinition['listStyle'] ?? 'bullet')) === 'checklist' ? '- [ ] ' : '- ';
                    $lines[] = $prefix . $item;
                }

                $lines[] = '';
                continue;
            }

            $fieldLabel = trim((string) ($fieldDefinition['label'] ?? $fieldKey));
            $renderMode = trim((string) ($fieldDefinition['renderAs'] ?? ''));

            if ($renderMode === 'bullet' || $renderMode === 'commit') {
                $lines[] = sprintf('- %s: %s', $fieldLabel, $normalizedValue);
                continue;
            }

            $lines[] = '### ' . $fieldLabel;
            $lines[] = $normalizedValue;
            $lines[] = '';
        }

        return $hasContent ? trim(implode("\n", $lines)) : '';
    }

    /**
     * @param array<string, mixed> $fieldDefinition
     */
    private function normalizeFieldValue(array $fieldDefinition, mixed $value, ?string $repositoryKey): string|array
    {
        $fieldType = trim((string) ($fieldDefinition['type'] ?? 'text'));

        if ($fieldType === 'list') {
            $rawTextValue = is_string($value) ? $value : '';
            $rawItems = is_array($value) ? $value : (preg_split('/\R+/', $rawTextValue) ?: []);
            $normalizedItems = [];

            foreach ($rawItems as $item) {
                $normalizedItem = $this->normalizePlainValue($item);
                if ($normalizedItem !== '') {
                    $normalizedItems[] = $normalizedItem;
                }
            }

            return $normalizedItems;
        }

        $normalizedValue = $this->normalizePlainValue($value);
        if ($normalizedValue === '') {
            return '';
        }

        if ($fieldType === 'select') {
            $resolvedValue = $this->resolveSelectLabel($fieldDefinition, $normalizedValue);
            if ($resolvedValue === null) {
                throw new \InvalidArgumentException(sprintf(
                    'The value "%s" is not valid for the field "%s".',
                    $normalizedValue,
                    trim((string) ($fieldDefinition['label'] ?? 'select'))
                ));
            }

            $normalizedValue = $resolvedValue;
        }

        if (trim((string) ($fieldDefinition['renderAs'] ?? '')) === 'commit') {
            return $this->buildCommitReferenceMarkdown($normalizedValue, $repositoryKey);
        }

        return $normalizedValue;
    }

    /**
     * @param array<string, mixed> $fieldDefinition
     */
    private function resolveSelectLabel(array $fieldDefinition, string $value): ?string
    {
        $options = $fieldDefinition['options'] ?? [];
        if (!is_array($options) || $options === []) {
            return $value;
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optionValue = $this->normalizePlainValue($option['value'] ?? null);
            if ($optionValue !== $value) {
                continue;
            }

            $optionLabel = $this->normalizePlainValue($option['label'] ?? null);

            return $optionLabel !== '' ? $optionLabel : $optionValue;
        }

        return null;
    }

    private function buildCommitReferenceMarkdown(string $value, ?string $repositoryKey): string
    {
        $commitUrl = $this->resolveCommitUrl($value, $repositoryKey);
        if ($commitUrl === '') {
            return $value;
        }

        return sprintf('[%s](%s)', $this->extractCommitLabel($value, $commitUrl), $commitUrl);
    }

    private function resolveCommitUrl(string $value, ?string $repositoryKey): string
    {
        $normalizedValue = trim($value);
        if ($normalizedValue === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $normalizedValue) === 1) {
            return $normalizedValue;
        }

        if (preg_match('/^([\w.-]+\/[\w.-]+)@([a-f0-9]{7,40})$/i', $normalizedValue, $matches) === 1) {
            return sprintf('https://github.com/%s/commit/%s', $matches[1], $matches[2]);
        }

        if (preg_match('/^[a-f0-9]{7,40}$/i', $normalizedValue) !== 1) {
            return '';
        }

        $normalizedRepositoryKey = trim((string) $repositoryKey);
        if ($normalizedRepositoryKey === '') {
            return '';
        }

        return sprintf('https://github.com/%s/commit/%s', $normalizedRepositoryKey, $normalizedValue);
    }

    private function extractCommitLabel(string $rawValue, string $commitUrl): string
    {
        $normalizedValue = trim($rawValue);
        if ($normalizedValue === '') {
            return 'Commit';
        }

        if (preg_match('/^https?:\/\//i', $normalizedValue) !== 1) {
            if (preg_match('/^([\w.-]+\/[\w.-]+)@([a-f0-9]{7,40})$/i', $normalizedValue, $matches) === 1) {
                return $matches[2];
            }

            return $normalizedValue;
        }

        $path = parse_url($commitUrl, \PHP_URL_PATH);
        if (!is_string($path) || trim($path) === '') {
            return $normalizedValue;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => trim($segment) !== ''));
        $lastSegment = $segments[count($segments) - 1] ?? '';

        return $lastSegment !== '' ? $lastSegment : $normalizedValue;
    }

    private function normalizePlainValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function isEmptyValue(string|array $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return $value === '';
    }

    private function buildTimestampLabel(): string
    {
        $timestamp = new \DateTimeImmutable('now', new \DateTimeZone(self::DEFAULT_TIMEZONE));

        return sprintf('%s, %s', $timestamp->format('d/m/Y'), $timestamp->format('H:i'));
    }
}
