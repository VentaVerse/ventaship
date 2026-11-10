@props(['label', 'value', 'id'])

{{--
    A value shown read-only and in full (it wraps, nothing is cut off), with a Copy button below that copies it
    exactly. A read-only textarea so the label points at it and it can be selected by hand.
--}}
@php
    $config = ['url' => null, 'value' => $value, 'mask' => $value, 'isSet' => true, 'name' => null];
    // Browsers without field-sizing keep enough rows for a phone-width column.
    $rows = max(2, (int) ceil(mb_strlen($value) / 36));
@endphp

<x-ui.field :label="$label" :for="$id" class="x-copyvalue-field">
    <div class="x-copyvalue" x-data="credentialField(@js($config))">
        <textarea id="{{ $id }}" class="x-input x-copyvalue__text" rows="{{ $rows }}" readonly
                  autocomplete="off" spellcheck="false" autocapitalize="off" x-ref="input">{{ $value }}</textarea>
        <div class="x-copyvalue__acts">
            <x-ui.copy-button :label="'Copy ' . $label" />
        </div>
        <p class="fm-error" x-show="error" x-cloak x-text="error"></p>
    </div>
</x-ui.field>
