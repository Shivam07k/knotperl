@extends('layouts.dashboard')

@section('title', $tab)

@section('content')
    <h1>{{ $tab }} content</h1>

    <div class="inline" style="margin-bottom:1.4rem">
        @foreach($tabs as $name)
            <a class="button {{ $name === $tab ? '' : 'secondary' }}" href="{{ route('content.index', $name) }}">{{ $name }}</a>
        @endforeach
    </div>

    @if($readError)
        <div class="alert alert-error">{{ $readError }}</div>
    @endif

    {{-- Settings keys are added in Sheets: SheetWriteService has no Settings ID prefix. --}}
    @if($tab !== 'Settings')
        <p><a class="button" href="{{ route('content.create', $tab) }}">Add row</a></p>
    @endif

    <div class="panel">
        <table>
            @if($tab === 'Settings')
                <tr><th>Row</th><th>Key</th><th>Value</th><th>Type</th><th>Description</th><th>Actions</th></tr>
                @forelse($rows as $row)
                    <tr>
                        <td>{{ $row['__row'] }}</td>
                        <td><code>{{ $row['key'] ?? '' }}</code></td>
                        <td>{{ $row['__sensitive'] ? '[hidden]' : ($row['value'] ?? '') }}</td>
                        <td>{{ $row['type'] ?? '' }}</td>
                        <td>{{ $row['description'] ?? '' }}</td>
                        <td><a href="{{ route('content.edit', [$tab, $row['__row']]) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6">No rows.</td></tr>
                @endforelse
            @else
                <tr><th>Row</th><th>{{ $definition['key'] }}</th><th>Status</th><th>Actions</th></tr>
                @forelse($rows as $row)
                    <tr>
                        <td>{{ $row['__row'] }}</td>
                        <td><code>{{ $row[$definition['key']] ?? '' }}</code></td>
                        <td>{{ $row['status'] ?? '-' }}</td>
                        <td>
                            <a href="{{ route('content.edit', [$tab, $row['__row']]) }}">Edit</a>
                            <form method="POST" action="{{ route('content.destroy', [$tab, $row['__row']]) }}" style="display:inline">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="__checksum" value="{{ $row['__checksum'] }}">
                                <button type="submit" class="secondary">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">No rows.</td></tr>
                @endforelse
            @endif
        </table>
    </div>
@endsection
