<?php

namespace App\Services\CMS;


use RuntimeException;

class SheetWriteService
{
    public function __construct(
        private JsonContentService $sheets,
        private DataValidationService $validator,
        private SpreadsheetSchemaService $schema
    ) {}

    public function checksum(array $row, array $headers): string
    {
        $values = [];
        foreach ($headers as $header) {
            $values[] = (string) ($row[$header] ?? '');
        }

        return hash('sha256', implode("\x1f", $values));
    }

    public function nextId(string $tab): string
    {
        $prefix = match ($tab) {
            'Pages' => 'p',
            'Products' => 'pr',
            'Categories' => 'c',
            'Sections' => 's',
            'Navigation' => 'n',
            default => throw new RuntimeException("No ID prefix configured for {$tab}."),
        };
        $key = (string) ($this->schema->tab($tab)['key'] ?? '');
        if ($key === '') {
            throw new RuntimeException("No key column for {$tab}.");
        }

        $max = 0;
        foreach ($this->sheets->readSheet($tab, true) as $row) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', trim((string) ($row[$key] ?? '')), $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return sprintf('%s%03d', $prefix, $max + 1);
    }

    public function save(string $tab, array $input): array
    {
        $data = $this->readAllTabs();
        $headers = $this->schema->expectedHeaders($tab);
        if ($headers === []) {
            return $this->failure(0, 'unknown tab: '.$tab);
        }

        $rows = array_values($data[$tab] ?? []);
        $editing = isset($input['__row']) && (int) $input['__row'] > 0;
        $rowNumber = $editing ? (int) $input['__row'] : $this->nextRowNumber($rows);
        $existing = $editing ? $this->rowAt($rows, $rowNumber) : null;

        if ($editing && $existing === null) {
            return $this->failure($rowNumber, 'conflict: row no longer exists');
        }
        if ($editing && ! hash_equals($this->checksum($existing, $headers), (string) ($input['__checksum'] ?? ''))) {
            return $this->failure($rowNumber, 'conflict: row changed since it was loaded');
        }

        $candidate = $this->candidateRow($tab, $headers, $input, $existing ?? [], ! $editing);
        $data[$tab] = $this->withRow($rows, $rowNumber, $candidate);

        $errors = array_merge(
            $this->validator->validateScope($data, [$tab => [$rowNumber]])['errors'],
            $this->validator->duplicatesForRow($data, $tab, $rowNumber)
        );
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values($errors), 'row' => $rowNumber];
        }

        $values = array_map(static fn (string $header): mixed => $candidate[$header] ?? '', $headers);
        if ($editing) {
            $this->sheets->updateRow($tab, $rowNumber, $values);
            $written = $rowNumber;
        } else {
            $written = $this->sheets->appendRow($tab, $values);
        }
        $this->log($tab, $editing ? 'update' : 'create', $this->recordId($tab, $candidate));

        return ['ok' => true, 'errors' => [], 'row' => $written];
    }

    public function delete(string $tab, int $row, string $checksum): array
    {
        $headers = $this->schema->expectedHeaders($tab);
        $existing = $this->rowAt($this->sheets->readSheet($tab, true), $row);
        if ($headers === [] || $existing === null || ! hash_equals($this->checksum($existing, $headers), $checksum)) {
            return $this->failure($row, 'conflict: row changed since it was loaded');
        }

        $this->sheets->deleteRow($tab, $row);
        $this->log($tab, 'delete', $this->recordId($tab, $existing));

        return ['ok' => true, 'errors' => [], 'row' => $row];
    }

    private function readAllTabs(): array
    {
        $data = [];
        foreach ($this->schema->tabNames() as $name) {
            $data[$name] = $this->sheets->readSheet($name, true);
        }

        return $data;
    }

    private function rowAt(array $rows, int $rowNumber): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['__row'] ?? 0) === $rowNumber) {
                return $row;
            }
        }

        return null;
    }

    private function nextRowNumber(array $rows): int
    {
        $numbers = array_map(static fn (array $row): int => (int) ($row['__row'] ?? 0), $rows);

        return $numbers === [] ? 2 : max($numbers) + 1;
    }

    private function withRow(array $rows, int $rowNumber, array $candidate): array
    {
        $record = ['__row' => $rowNumber] + $candidate;
        foreach ($rows as $index => $row) {
            if ((int) ($row['__row'] ?? 0) === $rowNumber) {
                $rows[$index] = $record;

                return array_values($rows);
            }
        }
        $rows[] = $record;

        return array_values($rows);
    }

    private function candidateRow(string $tab, array $headers, array $input, array $base, bool $create): array
    {
        $key = (string) ($this->schema->tab($tab)['key'] ?? '');
        $managed = array_filter([$key, 'created_at', 'updated_at']);
        $row = [];
        foreach ($headers as $header) {
            $row[$header] = $base[$header] ?? '';
        }
        foreach ($headers as $header) {
            if (! in_array($header, $managed, true) && array_key_exists($header, $input)) {
                $row[$header] = is_scalar($input[$header]) ? (string) $input[$header] : '';
            }
        }
        if ($create && $key !== '') {
            $row[$key] = $this->nextId($tab);
        }
        $now = date('Y-m-d H:i:s');
        if ($create && array_key_exists('created_at', $row)) {
            $row['created_at'] = $now;
        }
        if (array_key_exists('updated_at', $row)) {
            $row['updated_at'] = $now;
        }

        return $row;
    }

    private function recordId(string $tab, array $row): string
    {
        $key = (string) ($this->schema->tab($tab)['key'] ?? '');

        return (string) ($row[$key] ?? '');
    }

    private function failure(int $row, string $message): array
    {
        return ['ok' => false, 'errors' => [['message' => $message]], 'row' => $row];
    }

    private function log(string $tab, string $action, string $id): void
    {
        $directory = (string) config('publishing.paths.logs');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $entry = [
            'at' => gmdate('c'),
            'tab' => $tab,
            'action' => $action,
            'id' => $id,
        ];
        file_put_contents(
            $directory.'/admin-actions.log',
            json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
