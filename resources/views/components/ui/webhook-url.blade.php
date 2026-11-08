@props(['label', 'masked', 'revealUrl', 'id' => 'f-webhook-url', 'context' => null])

{{-- The webhook address with its token as dots. View and Copy read the full address from $revealUrl (POST, audited). --}}
@php
    $config = ['url' => $revealUrl, 'mask' => $masked, 'isSet' => true, 'name' => null];
    $aria = fn (string $action) => trim($action . ' ' . $label);
@endphp

<x-ui.field :label="$label" :for="$id">
    <div class="ac-cred" x-data="credentialField(@js($config))">
        <input type="text" id="{{ $id }}" class="x-input ac-cred__input ac-cred__input--url" value="{{ $masked }}" readonly
               autocomplete="off" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore
               x-ref="input" x-bind:class="{ 'is-shown': state === 'viewing' }">
        <span class="ac-cred__acts">
            <x-ui.button size="sm" x-show="state === 'masked'" x-on:click="view()" x-bind:disabled="busy" :aria-label="$aria('View')">View</x-ui.button>
            <x-ui.button size="sm" x-show="state === 'viewing'" x-cloak x-on:click="hide()" :aria-label="$aria('Hide')">Hide</x-ui.button>
            <x-ui.button size="sm" x-on:click="copy()" x-bind:disabled="busy" :aria-label="$aria('Copy')">Copy</x-ui.button>
            <span class="ac-cred__tick x-num" aria-hidden="true" x-show="state === 'viewing'" x-cloak x-text="tick"></span>
        </span>
        <p class="fm-error ac-cred__error" x-show="error" x-cloak x-text="error"></p>
    </div>
</x-ui.field>
