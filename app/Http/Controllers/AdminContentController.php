<?php

namespace App\Http\Controllers;

use App\Services\CMS\JsonContentService;
use App\Services\CMS\SheetWriteService;
use App\Services\CMS\SpreadsheetSchemaService;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdminContentController extends Controller
{
    private const SENSITIVE = '/token|secret|password|api[_-]?key|credential|webhook/i';

    public function __construct(
        private JsonContentService $sheets,
        private SpreadsheetSchemaService $schema,
        private SheetWriteService $writes,
    ) {}

    public function index(string $tab): View
    {
        $definition = $this->tabOrFail($tab);
        $headers = $definition['headers'] ?? [];
        $readError = null;
        try {
            $rows = $this->sheets->readSheet($tab);
        } catch (RuntimeException $exception) {
            $rows = [];
            $readError = $exception->getMessage();
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['__checksum'] = $this->writes->checksum($row, $headers);
            $rows[$index]['__sensitive'] = $tab === 'Settings'
                && preg_match(self::SENSITIVE, (string) ($row['key'] ?? '')) === 1;
        }

        return view('admin.content.index', [
            'tab' => $tab,
            'tabs' => (array) config('cms.editable_tabs', []),
            'definition' => $definition,
            'rows' => $rows,
            'readError' => $readError,
        ]);
    }

    public function create(string $tab): View
    {
        $this->tabOrFail($tab);

        return view('admin.content.form', [
            'tab' => $tab,
            'fields' => $this->fieldsFor($tab),
            'rowNumber' => null,
            'checksum' => null,
            'action' => route('content.store', $tab),
            'method' => 'post',
        ]);
    }

    public function store(Request $request, string $tab): RedirectResponse
    {
        $headers = $this->tabOrFail($tab)['headers'] ?? [];
        try {
            $result = $this->writes->save($tab, $request->only($headers));
        } catch (RuntimeException $exception) {
            return redirect()->back()->withInput()->with('error', $exception->getMessage());
        }

        return $this->redirectForResult($result, $tab, 'Created');
    }

    public function edit(string $tab, int $row): View|RedirectResponse
    {
        $definition = $this->tabOrFail($tab);
        try {
            $record = null;
            foreach ($this->sheets->readSheet($tab) as $candidate) {
                if ((int) ($candidate['__row'] ?? 0) === $row) {
                    $record = $candidate;
                    break;
                }
            }
        } catch (RuntimeException $exception) {
            return redirect()->route('content.index', $tab)->with('error', $exception->getMessage());
        }
        abort_if($record === null, 404);

        return view('admin.content.form', [
            'tab' => $tab,
            'fields' => $this->fieldsFor($tab, $record),
            'rowNumber' => $row,
            'checksum' => $this->writes->checksum($record, $definition['headers'] ?? []),
            'action' => route('content.update', [$tab, $row]),
            'method' => 'put',
        ]);
    }

    public function update(Request $request, string $tab, int $row): RedirectResponse
    {
        $headers = $this->tabOrFail($tab)['headers'] ?? [];
        $input = $request->only($headers);
        $input['__row'] = $row;
        $input['__checksum'] = (string) $request->input('__checksum', '');
        try {
            $result = $this->writes->save($tab, $input);
        } catch (RuntimeException $exception) {
            return redirect()->back()->withInput()->with('error', $exception->getMessage());
        }

        return $this->redirectForResult($result, $tab, 'Updated');
    }

    public function destroy(string $tab, int $row): RedirectResponse
    {
        $this->tabOrFail($tab);
        try {
            $result = $this->writes->delete($tab, $row, (string) request()->input('__checksum', ''));
        } catch (RuntimeException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return $this->redirectForResult($result, $tab, 'Deleted');
    }

    private function tabOrFail(string $tab): array
    {
        abort_unless(in_array($tab, (array) config('cms.editable_tabs', []), true), 404);
        $definition = $this->schema->tab($tab);
        abort_if($definition === null, 404);

        return $definition;
    }

    private function fieldsFor(string $tab, array $record = []): array
    {
        $definition = $this->tabOrFail($tab);
        $fields = [];
        foreach ($definition['headers'] ?? [] as $header) {
            $type = 'text';
            $options = [];
            if ($tab === 'Settings') {
                $type = $header === 'type'
                    ? 'settings_type'
                    : (string) ($record['type'] ?? 'text');
                if ($header === 'type') {
                    $options = ['text', 'number', 'bool', 'color', 'font', 'url', 'image'];
                }
            } else {
                $type = (string) ($definition['types'][$header] ?? 'text');
                if (isset($definition['enums'][$header])) {
                    $type = 'enum';
                    $enum = $definition['enums'][$header];
                    $options = is_array($enum) ? $enum : $this->schema->enums($enum);
                } elseif (in_array($header, $definition['images'] ?? [], true)) {
                    $type = 'image';
                }
            }

            $fields[] = [
                'header' => $header,
                'label' => ucfirst(str_replace('_', ' ', $header)),
                'type' => $type,
                'options' => $options,
                'value' => (string) ($record[$header] ?? ''),
                'readonly' => $header === ($definition['key'] ?? null)
                    || in_array($header, ['created_at', 'updated_at'], true),
                'skip' => $tab === 'Settings' && $header === 'value'
                    && preg_match(self::SENSITIVE, (string) ($record['key'] ?? '')) === 1,
            ];
        }

        return $fields;
    }

    private function redirectForResult(array $result, string $tab, string $verb): RedirectResponse
    {
        if (! $result['ok']) {
            $messages = array_column($result['errors'], 'message');

            return redirect()->back()->withInput()
                ->with('error', implode('; ', $messages) ?: 'Could not save the row.');
        }

        return redirect()->route('content.index', $tab)
            ->with('success', $verb.' row '.$result['row'].'.');
    }
}
