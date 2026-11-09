@props(['label', 'masked' => null, 'revealUrl', 'id', 'autoView' => false])

{{--
    x-ui.secret for a table cell: the masked value with View and Copy, no field label ($label names it for screen
    readers). Only the length and last 4 characters reach the page; View and Copy fetch the value from $revealUrl.
--}}
@php
    $length = (int) ($masked['length'] ?? 0);
    $mask = $masked === null ? '' : ($length >= 8
        ? str_repeat('•', min(16, $length - 4)) . $masked['last4']
        : str_repeat('•', max(4, $length)));
    $config = ['url' => $revealUrl, 'mask' => $mask, 'isSet' => true, 'name' => null, 'autoView' => (bool) $autoView];
@endphp

@if($masked === null)
    <x-ui.badge tone="attn">Not set</x-ui.badge>
@else
    <div class="ac-cred x-secret-cell" x-data="credentialField(@js($config))">
        <input type="text" id="{{ $id }}" class="x-input ac-cred__input" value="{{ $mask }}" readonly aria-label="{{ $label }}"
               autocomplete="off" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore
               x-ref="input" x-bind:class="{ 'is-shown': state === 'viewing' }">
        <span class="ac-cred__acts">
            <x-ui.button size="sm" x-show="state === 'masked'" x-on:click="view()" x-bind:disabled="busy" :aria-label="'View ' . $label">View</x-ui.button>
            <x-ui.button size="sm" x-show="state === 'viewing'" x-cloak x-on:click="hide()" :aria-label="'Hide ' . $label">Hide</x-ui.button>
            <x-ui.copy-button :label="'Copy ' . $label" :variant="$autoView ? 'primary' : 'default'" />
        </span>
        <p class="fm-error ac-cred__error" x-show="error" x-cloak x-text="error"></p>
    </div>
@endif
