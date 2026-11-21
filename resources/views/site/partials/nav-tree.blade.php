<ul class="nav-list nav-depth-{{ $depth ?? 0 }}">
    @foreach($items as $item)
        <li class="nav-item @if(!empty($item['children'])) has-children @endif">
            <a href="{{ e($item['url']) }}" @if(!empty($item['open_new_tab'])) target="_blank" rel="noopener noreferrer" @endif>{{ $item['label'] }}</a>
            @if(!empty($item['children']))
                @include('site.partials.nav-tree', ['items' => $item['children'], 'depth' => ($depth ?? 0) + 1])
            @endif
        </li>
    @endforeach
</ul>
