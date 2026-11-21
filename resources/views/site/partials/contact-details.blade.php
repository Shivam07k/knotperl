<ul class="contact-list contact-details">
    @foreach($contact as $entry)
        @php($type = (string) $entry['type'])
        @php($digits = preg_replace('/\D/', '', (string) $entry['value']))
        <li>
            <span class="contact-icon" aria-hidden="true">{{ strtoupper(substr($type, 0, 1)) }}</span>
            @if($type === 'email')
                <a href="mailto:{{ e($entry['value']) }}">{{ $entry['value'] }}</a>
            @elseif($type === 'phone')
                <a href="tel:{{ e($digits) }}">{{ $entry['value'] }}</a>
            @elseif($type === 'whatsapp')
                <a href="https://wa.me/{{ e($digits) }}" target="_blank" rel="noopener noreferrer">{{ $entry['value'] }}</a>
            @else
                <span>{{ $entry['value'] }}</span>
            @endif
        </li>
    @endforeach
</ul>
