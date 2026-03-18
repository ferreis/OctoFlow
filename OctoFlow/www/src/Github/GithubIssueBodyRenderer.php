<?php

namespace App\Github;

final class GithubIssueBodyRenderer
{
    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $fieldValues
     *
     * @return array{title: string, body: string}
     */
    public function render(array $template, string $title, array $fieldValues, string $requesterEmail): array
    {
        $normalizedTitle = trim($title);
        if ($normalizedTitle === '') {
            throw new \InvalidArgumentException('The issue title is required.');
        }

        $templateName = trim((string) ($template['name'] ?? 'Issue'));
        $formattedTitle = $this->formatTitle($template, $normalizedTitle);
        $lines = [
            '> Solicitante: ' . $this->normalizePlainValue($requesterEmail),
            '> Data da solicitação: ' . date('Y-m-d H:i:s'),
            '> Origem: OctoFlow',
            '',
        ];

        $fieldDefinitions = $template['fields'] ?? [];
        if (!is_array($fieldDefinitions)) {
            throw new \InvalidArgumentException('The selected issue template is invalid.');
        }

        foreach ($fieldDefinitions as $fieldDefinition) {
            if (!is_array($fieldDefinition)) {
                continue;
            }

            $fieldKey = trim((string) ($fieldDefinition['key'] ?? ''));
            $fieldLabel = trim((string) ($fieldDefinition['label'] ?? $fieldKey));
            $required = (bool) ($fieldDefinition['required'] ?? false);
            $normalizedValue = $this->normalizeFieldValue($fieldDefinition, $fieldValues[$fieldKey] ?? null);

            if ($required && $this->isEmptyValue($normalizedValue)) {
                throw new \InvalidArgumentException(sprintf('The field "%s" is required.', $fieldLabel));
            }

            if ($this->isEmptyValue($normalizedValue)) {
                continue;
            }

            $lines[] = '## ' . $fieldLabel;

            if (is_array($normalizedValue)) {
                $listStyle = trim((string) ($fieldDefinition['style'] ?? 'bullets'));
                foreach ($normalizedValue as $item) {
                    $prefix = $listStyle === 'checklist' ? '- [ ] ' : '- ';
                    $lines[] = $prefix . $item;
                }
            } else {
                $lines[] = $this->resolveDisplayValue($fieldDefinition, $normalizedValue);
            }

            $lines[] = '';
        }

        $body = trim(implode("\n", $lines));

        return [
            'title' => $formattedTitle,
            'body' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $template
     */
    private function formatTitle(array $template, string $title): string
    {
        $titlePrefix = trim((string) ($template['titlePrefix'] ?? ''));
        if ($titlePrefix === '') {
            return $title;
        }

        if (preg_match('/^\[' . preg_quote($titlePrefix, '/') . '\]\s+/i', $title) === 1) {
            return $title;
        }

        return sprintf('[%s] %s', $titlePrefix, $title);
    }

    /**
     * @param array<string, mixed> $fieldDefinition
     */
    private function normalizeFieldValue(array $fieldDefinition, mixed $value): string|array
    {
        $fieldType = trim((string) ($fieldDefinition['type'] ?? 'text'));

        if ($fieldType === 'list') {
            if (is_array($value)) {
                $rawItems = $value;
            } else {
                $rawItems = preg_split('/\R+/', is_string($value) ? $value : '') ?: [];
            }

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

        if ($fieldType === 'select') {
            $allowedValues = [];
            $options = $fieldDefinition['options'] ?? [];

            if (is_array($options)) {
                foreach ($options as $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $optionValue = $this->normalizePlainValue($option['value'] ?? null);
                    if ($optionValue !== '') {
                        $allowedValues[] = $optionValue;
                    }
                }
            }

            if ($normalizedValue !== '' && $allowedValues !== [] && !in_array($normalizedValue, $allowedValues, true)) {
                throw new \InvalidArgumentException(sprintf('The value "%s" is not valid for the field "%s".', $normalizedValue, (string) ($fieldDefinition['label'] ?? 'select')));
            }
        }

        return $normalizedValue;
    }

    /**
     * @param array<string, mixed> $fieldDefinition
     */
    private function resolveDisplayValue(array $fieldDefinition, string $value): string
    {
        $fieldType = trim((string) ($fieldDefinition['type'] ?? 'text'));
        if ($fieldType !== 'select') {
            return $value;
        }

        $options = $fieldDefinition['options'] ?? [];
        if (!is_array($options)) {
            return $value;
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optionValue = $this->normalizePlainValue($option['value'] ?? null);
            if ($optionValue === $value) {
                $optionLabel = $this->normalizePlainValue($option['label'] ?? null);

                return $optionLabel !== '' ? $optionLabel : $value;
            }
        }

        return $value;
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
}
