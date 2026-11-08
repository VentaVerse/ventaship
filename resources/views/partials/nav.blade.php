<nav class="x-nav" aria-label="Main">
    @foreach($navGroups as $group)
        @if($group['label'])
            <div class="x-nav__group">{{ $group['label'] }}</div>
        @endif
        @foreach($group['items'] as $item)
            @php $active = \App\Support\Navigation::isActive($item); @endphp
            <a class="x-nav__item{{ $active ? ' is-active' : '' }}" href="{{ route($item['route']) }}"
               @if($active) aria-current="page" @endif>
                <x-ui.icon :name="$item['icon']" size="15" />
                <span>{{ $item['label'] }}</span>
                @if(($item['badge'] ?? 0) > 0)
                    <span class="x-nav__badge">{{ number_format($item['badge']) }}</span>
                @endif
            </a>
        @endforeach
    @endforeach
</nav>
