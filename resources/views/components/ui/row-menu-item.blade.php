@props(['href' => null, 'tone' => null, 'type' => 'button'])

@php $classes = 'x-rowmenu__item' . ($tone === 'bad' ? ' x-rowmenu__item--bad' : ''); @endphp

@if($href)
    <a href="{{ $href }}" role="menuitem" tabindex="-1" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" role="menuitem" tabindex="-1" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
