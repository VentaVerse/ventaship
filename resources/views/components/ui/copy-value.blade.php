@props(['label', 'value', 'id', 'short' => false])

{{-- A value shown read-only with a Copy button (the credential field's copy, without View or a fetch). --}}
@php
    $config = ['url' => null, 'value' => $value, 'mask' => $value, 'isSet' => true, 'name' => null];
    $inputClass = 'x-input ac-cred__input is-shown ' . ($short ? 'st-copy--short' : 'ac-cred__input--url');
@endphp

<x-ui.field :label="$label" :for="$id">
    <div class="ac-cred" x-data="credentialField(@js($config))">
        <input type="text" id="{{ $id }}" class="{{ $inputClass }}" value="{{ $value }}" readonly
               autocomplete="off" spellcheck="false" autocapitalize="off" x-ref="input">
        <span class="ac-cred__acts">
            <x-ui.copy-button :label="'Copy ' . $label" />
        </span>
        <p class="fm-error ac-cred__error" x-show="error" x-cloak x-text="error"></p>
    </div>
</x-ui.field>
