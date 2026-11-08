@props(['paginator' => null, 'side' => 2])

@php
    $p = $paginator;
    $last = $p ? (int) $p->lastPage() : null;
    $current = $p ? (int) $p->currentPage() : 1;
    $pages = [];

    if ($p && $last > 1) {
        $window = range(max(1, $current - $side), min($last, $current + $side));
        $wanted = array_values(array_unique(array_merge([1], $window, [$last])));
        sort($wanted);

        $previous = null;
        foreach ($wanted as $n) {
            if ($previous !== null && $n - $previous > 1) {
                $pages[] = $n - $previous === 2 ? $previous + 1 : null;
            }
            $pages[] = $n;
            $previous = $n;
        }
    }
@endphp

@if($p && $last > 1)
    <div {{ $attributes->merge(['class' => 'x-pager']) }}>
        <span class="x-pager__count x-num">{{ number_format($p->firstItem() ?? 0) }} to {{ number_format($p->lastItem() ?? 0) }} of {{ number_format($p->total()) }}</span>

        <div class="x-pager__nav">
            <x-ui.button size="sm" :href="$p->previousPageUrl()" :disabled="! $p->previousPageUrl()">
                <x-ui.icon name="chevron-left" size="14" /> Previous
            </x-ui.button>

            <ol class="x-pager__pages" aria-label="Pages">
                @foreach($pages as $n)
                    <li>
                        @if($n === null)
                            <span class="x-pager__gap" aria-hidden="true">&hellip;</span>
                        @elseif($n === $current)
                            <span class="x-pager__page is-on" aria-current="page"><span class="x-sr">Page </span>{{ $n }}</span>
                        @else
                            <a class="x-pager__page" href="{{ $p->url($n) }}"><span class="x-sr">Page </span>{{ $n }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>

            <x-ui.button size="sm" :href="$p->nextPageUrl()" :disabled="! $p->nextPageUrl()">
                Next <x-ui.icon name="chevron-right" size="14" />
            </x-ui.button>
        </div>
    </div>
@endif
