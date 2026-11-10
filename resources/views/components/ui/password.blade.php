@props(['id', 'name', 'autocomplete' => 'current-password'])

{{-- A password input with the login page's show/hide eye. Other attributes go to the input. --}}
<div class="x-pass" x-data="{ show: false }">
    <x-ui.input :attributes="$attributes->merge(['type' => 'password', 'id' => $id, 'name' => $name, 'autocomplete' => $autocomplete, 'spellcheck' => 'false', 'autocapitalize' => 'none', 'x-bind:type' => 'show ? \'text\' : \'password\''])" />
    <button type="button" class="x-pass__eye" x-cloak x-on:click="show = ! show" aria-controls="{{ $id }}"
            aria-label="Show password" x-bind:aria-label="show ? 'Hide password' : 'Show password'" x-bind:aria-pressed="show ? 'true' : 'false'">
        <span x-show="! show"><x-ui.icon name="eye" size="16" /></span>
        <span x-show="show"><x-ui.icon name="eye-off" size="16" /></span>
    </button>
</div>
