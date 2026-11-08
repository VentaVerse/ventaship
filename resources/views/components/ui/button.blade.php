@props(['variant' => 'default', 'size' => 'md', 'href' => null, 'type' => 'button', 'disabled' => false])

@php
    $classes = 'x-btn x-btn--' . $variant . ' x-btn--' . $size . ($disabled ? ' is-disabled' : '');
@endphp

@if($href && ! $disabled)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@elseif($href)
    <span aria-disabled="true" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</span>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
