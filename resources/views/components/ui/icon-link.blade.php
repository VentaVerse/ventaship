@props(['href', 'icon', 'label'])
{{-- An icon that opens a row's page: the eye views, the pencil edits. $label is the full spoken text, e.g. "Edit SPX". --}}
<a {{ $attributes->merge(['class' => 'x-view']) }} href="{{ $href }}" aria-label="{{ $label }}"><x-ui.icon :name="$icon" size="18" /></a>
