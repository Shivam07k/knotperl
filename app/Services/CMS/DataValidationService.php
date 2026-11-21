<?php

namespace App\Services\CMS;

class DataValidationService
{
    public function __construct(private SpreadsheetSchemaService $schema) {}

    public function validate(array $data): array
    {
        $definitions = $this->schema->schema()['tabs'];
        $errors = [];
        $warnings = [];
        $keys = [];

        // Collect key values first so cross-sheet references can be checked.
        foreach ($definitions as $definition) {
            $fields = $this->uniqueFields($definition);
            $keys[$definition['name']] = [];
            foreach ($fields as $field) {
                $keys[$definition['name']][$field] = [];
                foreach ($data[$definition['name']] ?? [] as $row) {
                    $value = trim((string) ($row[$field] ?? ''));
                    if ($value !== '') {
                        $keys[$definition['name']][$field][$value] = true;
                    }
                }
            }
        }

        $normalized = [];
        foreach ($definitions as $definition) {
            $sheet = $definition['name'];
            $headers = $definition['headers'];
            $required = $definition['required'] ?? [];
            $types = $definition['types'] ?? [];
            $enums = $definition['enums'] ?? [];
            $references = $definition['references'] ?? [];
            $images = $definition['images'] ?? [];
            $seen = [];
            $normalized[$sheet] = [];

            foreach ($data[$sheet] ?? [] as $row) {
                $rowNumber = $row['__row'] ?? '?';
                $record = ['__row' => $rowNumber];
                foreach ($headers as $header) {
                    $record[$header] = $row[$header] ?? '';
                }

                foreach ($required as $field) {
                    if (trim((string) $record[$field]) === '') {
                        $errors[] = $this->issue($sheet, $rowNumber, ucfirst(str_replace('_', ' ', $field)).' is required.');
                    }
                }

                foreach ($definition['unique'] ?? [] as $field) {
                    $value = trim((string) $record[$field]);
                    if ($value === '') {
                        continue;
                    }
                    if (isset($seen[$field][$value])) {
                        $errors[] = $this->duplicateIssue($sheet, $rowNumber, $field, $value, $seen[$field][$value]);
                    } else {
                        $seen[$field][$value] = $rowNumber;
                    }
                }

                foreach ($types as $field => $type) {
                    $raw = trim((string) $record[$field]);
                    $record[$field] = match ($type) {
                        'int' => $this->toInt($raw, $sheet, $rowNumber, $field, $errors),
                        'float' => $this->toFloat($raw, $sheet, $rowNumber, $field, $errors),
                        'bool' => $this->toBool($raw, $sheet, $rowNumber, $field, $errors),
                        'date' => $this->toDate($raw, $sheet, $rowNumber, $field, $errors),
                        default => $raw,
                    };
                }

                foreach ($enums as $field => $enumName) {
                    $value = trim((string) $record[$field]);
                    if ($value === '') {
                        continue;
                    }
                    $allowed = is_array($enumName) ? $enumName : $this->schema->enums($enumName);
                    if (! in_array($value, $allowed, true) && ! in_array(strtolower($value), array_map('strtolower', $allowed), true)) {
                        $errors[] = $this->issue($sheet, $rowNumber, 'Invalid '.str_replace('_', ' ', $field).' "'.$value.'". Allowed: '.implode(', ', $allowed));
                    }
                }

                foreach ($references as $field => $reference) {
                    $value = trim((string) $record[$field]);
                    if ($value === '') {
                        continue;
                    }
                    [$refSheet, $refKey] = array_pad(explode('.', $reference, 2), 2, '');
                    if ($refKey === '') {
                        foreach ($definitions as $candidate) {
                            if ($candidate['name'] === $refSheet) {
                                $refKey = $candidate['key'];
                                break;
                            }
                        }
                    }
                    if (! isset($keys[$refSheet][$refKey][$value])) {
                        $errors[] = $this->issue($sheet, $rowNumber, str_replace('_', ' ', $field).' "'.$value.'" does not exist in '.$refSheet.'.');
                    }
                }

                foreach ($images as $field) {
                    foreach (explode('|', (string) $record[$field]) as $image) {
                        $image = trim($image);
                        if ($image !== '' && ! $this->validImageReference($image)) {
                            $errors[] = $this->issue($sheet, $rowNumber, 'Image reference "'.$image.'" is invalid.');
                        }
                    }
                }

                if ($sheet === 'Settings' && in_array((string) ($record['key'] ?? ''), ['form_endpoint', 'newsletter_endpoint'], true)) {
                    $endpoint = trim((string) ($record['value'] ?? ''));
                    if ($endpoint !== '') {
                        $parts = filter_var($endpoint, FILTER_VALIDATE_URL) ? parse_url($endpoint) : false;
                        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
                        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
                        $local = in_array($host, ['localhost', '127.0.0.1'], true);
                        if ($scheme === '' || ($scheme !== 'https' && ! $local)) {
                            $errors[] = $this->issue($sheet, $rowNumber, 'Endpoint "'.$endpoint.'" must use HTTPS (HTTP is only allowed for localhost).');
                        }
                    }
                }

                if ($sheet === 'Products') {
                    if ((float) $record['regular_price'] > 0 && (float) $record['sale_price'] > 0 && (float) $record['sale_price'] > (float) $record['regular_price']) {
                        $errors[] = $this->issue($sheet, $rowNumber, 'Sale price is higher than the regular price.');
                    }
                    if (trim((string) $record['primary_image']) === '') {
                        $warnings[] = $this->issue($sheet, $rowNumber, 'No primary image; the card will use a placeholder.');
                    }
                }

                if ($sheet === 'Testimonials' && ((int) $record['rating'] < 1 || (int) $record['rating'] > 5)) {
                    $errors[] = $this->issue($sheet, $rowNumber, 'Rating must be between 1 and 5.');
                }

                $normalized[$sheet][] = $record;
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => $normalized,
        ];
    }

    /**
     * Validates the full dataset but keeps only issues for the given tab/rows.
     * Full-dataset validation remains the publish gate; this is for single-record edits.
     */
    public function validateScope(array $data, array $scope): array
    {
        $errors = array_values(array_filter(
            $this->validate($data)['errors'],
            static function (array $error) use ($scope): bool {
                $sheet = $error['sheet'] ?? null;
                if (! isset($scope[$sheet])) {
                    return false;
                }

                return in_array((int) ($error['row'] ?? 0), array_map('intval', $scope[$sheet]), true);
            }
        ));

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => []];
    }

    /**
     * Duplicate issues for one candidate row, attributed to that row in both directions.
     * validate() reports a duplicate only on the later row, so scoping to an earlier edited
     * row would otherwise miss a value already used further down the tab.
     */
    public function duplicatesForRow(array $data, string $tab, int $row): array
    {
        $definition = null;
        foreach ($this->schema->schema()['tabs'] as $candidate) {
            if ($candidate['name'] === $tab) {
                $definition = $candidate;
                break;
            }
        }
        if ($definition === null) {
            return [];
        }

        $rows = $data[$tab] ?? [];
        $record = null;
        foreach ($rows as $candidate) {
            if ((int) ($candidate['__row'] ?? 0) === $row) {
                $record = $candidate;
                break;
            }
        }
        if ($record === null) {
            return [];
        }

        $errors = [];
        foreach ($this->uniqueFields($definition) as $field) {
            $value = trim((string) ($record[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            foreach ($rows as $other) {
                if ((int) ($other['__row'] ?? 0) === $row) {
                    continue;
                }
                if (trim((string) ($other[$field] ?? '')) === $value) {
                    $errors[] = $this->duplicateIssue($tab, $row, $field, $value, (int) ($other['__row'] ?? 0));
                    break;
                }
            }
        }

        return $errors;
    }

    public function formatReport(array $result): string
    {
        $lines = [];
        foreach (['errors', 'warnings'] as $kind) {
            foreach ($result[$kind] ?? [] as $issue) {
                $lines[] = strtoupper($kind).' '.$issue['sheet'].' row '.$issue['row'].': '.$issue['message'];
            }
        }

        return $lines === [] ? 'No validation issues.' : implode(PHP_EOL, $lines);
    }

    private function issue(string $sheet, mixed $row, string $message): array
    {
        return ['sheet' => $sheet, 'row' => $row, 'message' => $message];
    }

    private function uniqueFields(array $definition): array
    {
        return array_unique(array_merge([$definition['key']], $definition['unique'] ?? []));
    }

    private function duplicateIssue(string $sheet, mixed $row, string $field, string $value, mixed $existingRow): array
    {
        return $this->issue($sheet, $row, 'Duplicate '.str_replace('_', ' ', $field).' "'.$value.'" (already on row '.$existingRow.').');
    }

    private function toInt(string $raw, string $sheet, mixed $row, string $field, array &$errors): int
    {
        if ($raw === '') {
            return 0;
        }
        if (! is_numeric($raw) || (int) $raw != $raw) {
            $errors[] = $this->issue($sheet, $row, ucfirst(str_replace('_', ' ', $field)).' must be a whole number.');
        }

        return (int) $raw;
    }

    private function toFloat(string $raw, string $sheet, mixed $row, string $field, array &$errors): float
    {
        if ($raw === '') {
            return 0.0;
        }
        if (! is_numeric($raw) || (float) $raw < 0) {
            $errors[] = $this->issue($sheet, $row, ucfirst(str_replace('_', ' ', $field)).' must be a non-negative number.');
        }

        return (float) $raw;
    }

    private function toBool(string $raw, string $sheet, mixed $row, string $field, array &$errors): bool
    {
        $value = strtolower($raw);
        if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['', 'false', '0', 'no', 'off'], true)) {
            return false;
        }
        $errors[] = $this->issue($sheet, $row, ucfirst(str_replace('_', ' ', $field)).' must be true or false.');

        return false;
    }

    private function toDate(string $raw, string $sheet, mixed $row, string $field, array &$errors): string
    {
        if ($raw === '') {
            return '';
        }
        if (strtotime($raw) === false) {
            $errors[] = $this->issue($sheet, $row, ucfirst(str_replace('_', ' ', $field)).' is not a valid date.');
        }

        return $raw;
    }

    private function validImageReference(string $value): bool
    {
        if (preg_match('#^https?://#i', $value)) {
            return true;
        }

        return (bool) preg_match('#^(assets|products|categories|services|testimonials|pages|uploads|images)/#i', $value);
    }
}
