@props(['tone' => 'mute'])

<span {{ $attributes->merge(['class' => 'x-badge x-badge--' . $tone]) }}>
    <span class="x-badge__dot" aria-hidden="true"></span>
    <span class="x-badge__label">{{ $slot }}</span>
</span>
