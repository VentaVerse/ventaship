@props(['label' => null, 'for' => null, 'name' => null, 'required' => false, 'wide' => false, 'errorKey' => null])

@php
    $key = $errorKey ?? $name;
    $message = $key ? $errors->first($key) : null;
    $controlId = $for ?? ($name ? 'f-'.\Illuminate\Support\Str::slug($name, '-') : null);
@endphp

<div {{ $attributes->merge(['class' => 'fm-field'.($wide ? ' fm-field--wide' : '')]) }}>
    @if($label)
        <label class="fm-label" @if($controlId) for="{{ $controlId }}" @endif>
            {{ $label }}
            @if($required)<span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if($message)<div class="fm-error" @if($controlId) id="{{ $controlId }}-error" @endif>{{ $message }}</div>@endif
</div>
