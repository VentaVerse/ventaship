@props(['href'])
{{-- The back link at the top of a second-level page. --}}
<a {{ $attributes->merge(['class' => 'x-back']) }} href="{{ $href }}"><x-ui.icon name="chevron-left" size="14" /> {{ $slot }}</a>
