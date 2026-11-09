@props(['id', 'name', 'value' => '', 'required' => false])

<div class="x-phone" x-data="phoneInput">
    <span class="x-phone__code" aria-hidden="true">+63</span>
    <input type="tel" id="{{ $id }}" name="{{ $name }}" value="{{ \App\Rules\PhMobile::national($value) }}"
           inputmode="numeric" maxlength="10" pattern="9[0-9]{9}" autocomplete="tel-national" aria-label="Mobile number, +63"
           x-on:input="clean($event)" x-on:paste="paste($event)" @if($required) required @endif
           {{ $attributes->merge(['class' => 'x-phone__input']) }}>
</div>
