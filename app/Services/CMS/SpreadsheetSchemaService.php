<?php

namespace App\Services\CMS;

use RuntimeException;

class SpreadsheetSchemaService
{
    private ?array $schema = null;

    public function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $path = config('cms.schema_path');
        $schema = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($schema) || empty($schema['tabs'])) {
            throw new RuntimeException('CMS schema definition is missing or invalid.');
        }

        return $this->schema = $schema;
    }

    public function tabNames(): array
    {
        return array_column($this->schema()['tabs'], 'name');
    }

    public function tab(string $name): ?array
    {
        foreach ($this->schema()['tabs'] as $tab) {
            if ($tab['name'] === $name) {
                return $tab;
            }
        }

        return null;
    }

    public function expectedHeaders(string $tab): array
    {
        return $this->tab($tab)['headers'] ?? [];
    }

    public function enums(string $name): array
    {
        $schema = $this->schema();

        return match ($name) {
            'status_values' => $schema['status_values'] ?? [],
            'section_types' => $schema['section_types'] ?? [],
            'page_templates' => $schema['page_templates'] ?? [],
            default => is_array($name) ? $name : [],
        };
    }

    /**
     * @param  array<int, string>  $actualTabs  sheet tab names present in the spreadsheet
     * @param  array<string, array<int, string>>  $headers  first row per tab
     */
    public function verify(array $actualTabs, array $headers): array
    {
        $expected = $this->tabNames();
        $missingTabs = array_values(array_diff($expected, $actualTabs));
        $extraTabs = array_values(array_diff($actualTabs, $expected));
        $missingColumns = [];
        foreach ($expected as $tab) {
            if (in_array($tab, $missingTabs, true)) {
                continue;
            }
            $expectedColumns = $this->expectedHeaders($tab);
            $actualColumns = array_map(static fn ($value): string => trim((string) $value), $headers[$tab] ?? []);
            $missing = array_values(array_diff($expectedColumns, $actualColumns));
            if ($missing !== []) {
                $missingColumns[$tab] = $missing;
            }
        }

        $ok = $missingTabs === [] && $missingColumns === [];
        $messages = [];
        foreach ($missingTabs as $tab) {
            $messages[] = "Missing tab: {$tab}";
        }
        foreach ($missingColumns as $tab => $columns) {
            $messages[] = "{$tab}: missing column(s) ".implode(', ', $columns);
        }
        foreach ($extraTabs as $tab) {
            $messages[] = "Extra tab ignored: {$tab}";
        }

        return [
            'ok' => $ok,
            'missing_tabs' => $missingTabs,
            'extra_tabs' => $extraTabs,
            'missing_columns' => $missingColumns,
            'messages' => $messages,
        ];
    }
}
