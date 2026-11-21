<?php

namespace App\Services\CMS;

use RuntimeException;

class JsonContentService
{
    public function spreadsheetId(): string
    {
        return 'json-content';
    }

    public function meta(): array
    {
        return [
            'id' => $this->spreadsheetId(),
            'title' => 'Content file',
            'tabs' => array_keys($this->data()),
        ];
    }

    public function sheetTitles(): array
    {
        return array_keys($this->data());
    }

    public function readAll(?array $tabs = null, bool $fresh = false): array
    {
        $data = $this->data();
        $tabs ??= array_keys($data);
        $result = [];
        foreach ($tabs as $tab) {
            $result[$tab] = $data[$tab] ?? [];
        }

        return $result;
    }

    public function readSheet(string $tab, bool $fresh = false): array
    {
        return $this->readAll([$tab], $fresh)[$tab] ?? [];
    }

    public function headers(string $tab): array
    {
        return (new SpreadsheetSchemaService())->expectedHeaders($tab);
    }

    public function updateCell(string $tab, string $a1, mixed $value): void
    {
        if (! preg_match('/^([A-Z]+)(\d+)$/i', $a1, $matches)) {
            throw new RuntimeException("Invalid cell reference: {$a1}");
        }
        $column = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }
        $field = $this->headers($tab)[$column - 1] ?? null;
        if ($field === null) {
            throw new RuntimeException("Column out of range for {$tab}: {$a1}");
        }

        $data = $this->data();
        $rows = $data[$tab] ?? [];
        $row = (int) $matches[2];
        $found = false;
        foreach ($rows as &$record) {
            if ((int) ($record['__row'] ?? 0) === $row) {
                $record[$field] = $value;
                $found = true;
                break;
            }
        }
        unset($record);
        if (! $found) {
            $rows[] = ['__row' => $row, $field => $value];
        }
        $data[$tab] = $rows;
        $this->write($data);
    }

    public function appendRow(string $tab, array $values): int
    {
        $data = $this->data();
        $rows = $data[$tab] ?? [];
        $row = $rows === [] ? 2 : max(array_column($rows, '__row')) + 1;
        $values = array_values($values);
        $record = ['__row' => $row];
        foreach ($this->headers($tab) as $index => $header) {
            $record[$header] = $values[$index] ?? '';
        }
        $data[$tab][] = $record;
        $this->write($data);

        return $row;
    }

    public function updateRow(string $tab, int $row, array $values): void
    {
        $data = $this->data();
        $rows = $data[$tab] ?? [];
        $values = array_values($values);
        $found = false;
        foreach ($rows as &$record) {
            if ((int) ($record['__row'] ?? 0) === $row) {
                foreach ($this->headers($tab) as $index => $header) {
                    $record[$header] = $values[$index] ?? '';
                }
                $found = true;
                break;
            }
        }
        unset($record);
        if (! $found) {
            throw new RuntimeException("Row {$row} does not exist in {$tab}.");
        }
        $data[$tab] = $rows;
        $this->write($data);
    }

    public function deleteRow(string $tab, int $row): void
    {
        $data = $this->data();
        $rows = array_values(array_filter(
            $data[$tab] ?? [],
            static fn (array $record): bool => (int) ($record['__row'] ?? 0) !== $row
        ));
        foreach ($rows as &$record) {
            if ((int) ($record['__row'] ?? 0) > $row) {
                $record['__row'] = (int) $record['__row'] - 1;
            }
        }
        unset($record);
        $data[$tab] = $rows;
        $this->write($data);
    }

    public function clearCache(): void
    {
        // Every read comes from the working file.
    }

    private function data(): array
    {
        $path = (string) config('cms.local_fixture_work');
        if (! is_file($path)) {
            $source = (string) config('cms.local_fixture_source');
            if (! is_file($source)) {
                throw new RuntimeException('Content file is missing.');
            }
            $this->ensureDirectory($path);
            copy($source, $path);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException('Content file is not valid JSON.');
        }

        return $data;
    }

    private function write(array $data): void
    {
        $path = (string) config('cms.local_fixture_work');
        $this->ensureDirectory($path);
        file_put_contents($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
    }
}
