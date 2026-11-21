@extends('layouts.dashboard')

@section('title', ($rowNumber === null ? 'New ' : 'Edit ').$tab)

@section('content')
    <h1>{{ $rowNumber === null ? 'New' : 'Edit' }} {{ $tab }}</h1>
    <div class="panel">
        <form method="POST" action="{{ $action }}">
            @csrf
            @if($method === 'put')
                @method('PUT')
            @endif
            @if($rowNumber !== null)
                <input type="hidden" name="__row" value="{{ $rowNumber }}">
                <input type="hidden" name="__checksum" value="{{ $checksum }}">
            @endif
            @foreach($fields as $field)
                @if(! $field['skip'])
                    @php($current = (string) old($field['header'], $field['value']))
                    <label>{{ $field['label'] }}
                        @if($field['readonly'])
                            <input value="{{ $current }}" readonly>
                        @elseif(in_array($field['type'], ['settings_type', 'enum', 'bool', 'font'], true))
                            <select name="{{ $field['header'] }}">
                                @if($field['type'] === 'bool')
                                    @foreach(['true', 'false'] as $option)
                                        <option value="{{ $option }}" @if($current === $option) selected @endif>{{ $option }}</option>
                                    @endforeach
                                @elseif($field['type'] === 'font')
                                    @foreach(['Playfair Display', 'Montserrat', 'Georgia', 'system-ui'] as $option)
                                        <option value="{{ $option }}" @if($current === $option) selected @endif>{{ $option }}</option>
                                    @endforeach
                                @else
                                    @foreach($field['options'] as $option)
                                        <option value="{{ $option }}" @if((string) $option === $current) selected @endif>{{ $option }}</option>
                                    @endforeach
                                @endif
                            </select>
                        @elseif(in_array($field['type'], ['int', 'float', 'number'], true))
                            <input type="number" step="{{ $field['type'] === 'float' ? 'any' : '1' }}" name="{{ $field['header'] }}" value="{{ $current }}">
                        @elseif($field['type'] === 'color')
                            <input type="text" name="{{ $field['header'] }}" value="{{ $current }}" placeholder="#rrggbb" pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$">
                            <small>#rgb or #rrggbb</small>
                        @elseif($field['type'] === 'url')
                            <input type="url" name="{{ $field['header'] }}" value="{{ $current }}">
                        @elseif($field['type'] === 'image')
                            <span class="inline">
                                <input type="text" name="{{ $field['header'] }}" value="{{ $current }}">
                                <button type="button" class="secondary" data-image-picker="{{ $field['header'] }}">Browse images</button>
                            </span>
                            <span class="inline image-key-results"></span>
                            @if($field['header'] === 'gallery_images')
                                <small>Separate multiple keys with |</small>
                            @endif
                        @elseif(in_array($field['header'], ['content', 'full_description', 'section_description', 'section_data'], true))
                            <textarea name="{{ $field['header'] }}" rows="5">{{ $current }}</textarea>
                        @else
                            <input type="text" name="{{ $field['header'] }}" value="{{ $current }}">
                        @endif
                    </label>
                @endif
            @endforeach
            <div class="inline">
                <button type="submit">Save</button>
                <a class="button secondary" href="{{ route('content.index', $tab) }}">Cancel</a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-image-picker]').forEach(function (button) {
    button.addEventListener('click', function () {
        var field = document.querySelector('[name="' + button.dataset.imagePicker + '"]');
        var panel = button.parentElement.nextElementSibling;
        var query = field.value.split('|')[0].trim().slice(0, 120);
        panel.textContent = 'Loading…';
        fetch('{{ route('images.keys') }}?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                panel.textContent = '';
                (data.keys || []).slice(0, 20).forEach(function (key) {
                    var option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'secondary';
                    option.textContent = key;
                    option.addEventListener('click', function () {
                        field.value = key;
                        panel.textContent = '';
                    });
                    panel.appendChild(option);
                });
                if (!panel.children.length) panel.textContent = 'No matching keys.';
            })
            .catch(function () { panel.textContent = 'Image lookup unavailable.'; });
    });
});
</script>
@endpush
