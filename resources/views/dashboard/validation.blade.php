@extends('layouts.dashboard')

@section('title', 'Validation')

@section('content')
    <h1>Content validation</h1>

    <div class="panel">
        <h2>Schema <span class="badge {{ $report['schema']['ok'] ? 'ok' : 'bad' }}">{{ $report['schema']['ok'] ? 'OK' : 'Issues' }}</span></h2>
        @forelse($report['schema']['messages'] as $message)
            <p>{{ $message }}</p>
        @empty
            <p>All required tabs and columns are present.</p>
        @endforelse
    </div>

    <div class="panel">
        <h2>Data <span class="badge {{ $report['validation']['ok'] ? 'ok' : 'bad' }}">{{ $report['validation']['ok'] ? 'OK' : 'Issues' }}</span></h2>
        <p>{{ count($report['validation']['errors']) }} error(s), {{ count($report['validation']['warnings']) }} warning(s).</p>
        @if($report['validation']['errors'])
            <h3>Errors</h3><pre>@foreach($report['validation']['errors'] as $issue){{ strtoupper('error').' '.$issue['sheet'].' row '.$issue['row'].': '.$issue['message'] }}
@endforeach</pre>
        @endif
        @if($report['validation']['warnings'])
            <h3>Warnings</h3><pre>@foreach($report['validation']['warnings'] as $issue){{ $issue['sheet'].' row '.$issue['row'].': '.$issue['message'] }}
@endforeach</pre>
        @endif
    </div>

    <div class="panel">
        <h2>Preview counts</h2>
        <table>
            @foreach($report['validation']['data'] as $sheet => $rows)
                <tr><td>{{ $sheet }}</td><td>{{ count($rows) }} row(s)</td></tr>
            @endforeach
        </table>
    </div>
@endsection
