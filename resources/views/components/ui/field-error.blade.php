@props(['name', 'id' => null, 'keepFlash' => false])

{{-- The error of a control that is not an x-ui.field (a radio group, a tile set). Shown here, so the flash skips it. --}}
@php($message = $errors->first($name))
@if($message !== '')
    @unless($keepFlash) @php(\App\View\InlineErrors::mark($name)) @endunless
    <div {{ $attributes->merge(['class' => 'fm-error', 'id' => $id ?? \App\View\Components\Ui\Field::controlId($name).'-error']) }}>{{ $message }}</div>
@endif
