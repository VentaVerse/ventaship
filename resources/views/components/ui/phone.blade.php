@props(['id', 'name', 'value' => '', 'required' => false])
@aware(['fieldControlId' => null, 'fieldMessage' => null, 'label' => null])

@php
    $bad = $fieldMessage !== null && $fieldMessage !== '';
    $digits = \App\Rules\PhMobile::national($value);
    // Shown as people say it, 917 555 5555; resources/js/phoneInput.js keeps that grouping while typing.
    $shown = trim(substr($digits, 0, 3).' '.substr($digits, 3, 3).' '.substr($digits, 6));
    $shown = (string) preg_replace('/\s+/', ' ', $shown);
@endphp

<div @class(['x-phone', 'is-invalid' => $bad]) x-data="phoneInput">
    <span class="x-phone__code" aria-hidden="true">+63</span>
    <input type="tel" id="{{ $id }}" name="{{ $name }}" value="{{ $shown }}"
           inputmode="numeric" pattern="9[0-9]{2} ?[0-9]{3} ?[0-9]{4}" autocomplete="tel-national" aria-label="{{ ($label ?: 'Mobile number').', +63' }}"
           x-on:input="typed($event)" x-on:paste="paste($event)" @if($required) required @endif
           {{ \App\View\Components\Ui\Field::control($attributes, $fieldControlId, $fieldMessage, 'x-phone__input') }}>
</div>
