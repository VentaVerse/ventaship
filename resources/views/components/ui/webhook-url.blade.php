@props(['label', 'masked', 'revealUrl', 'id' => 'f-webhook-url', 'context' => null, 'plain' => false])

{{--
    The webhook address with its token as dots. View and Copy read the full address from $revealUrl (POST, audited).
    The buttons share one width. $plain: the address carries no secret (SPX), so it is shown in full with Copy only.
--}}
@php
    $config = $plain
        ? ['url' => null, 'value' => $masked, 'mask' => $masked, 'isSet' => true, 'name' => null]
        : ['url' => $revealUrl, 'mask' => $masked, 'isSet' => true, 'name' => null];
    $aria = fn (string $action) => trim($action . ' ' . ($context ? $context . ' ' : '') . $label);
@endphp

<x-ui.field :label="$label" :for="$id">
    <div class="ac-cred" x-data="credentialField(@js($config))">
        <input type="text" id="{{ $id }}" class="x-input ac-cred__input ac-cred__input--url" value="{{ $masked }}" readonly
               autocomplete="off" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore
               x-ref="input" x-bind:class="{ 'is-shown': state === 'viewing' }">
        <span class="ac-cred__acts ac-cred__acts--even">
            @unless($plain)
                <x-ui.button size="sm" x-ref="viewButton" x-show="state === 'masked'" x-on:click="view()" x-bind:disabled="busy" :aria-label="$aria('View')">View</x-ui.button>
                <x-ui.button size="sm" x-ref="hideButton" x-show="state === 'viewing'" x-cloak x-on:click="hide(true)" :aria-label="$aria('Hide')">Hide</x-ui.button>
            @endunless
            <x-ui.copy-button :label="$aria('Copy')" />
        </span>
        <span class="ac-cred__tick x-num" aria-hidden="true" x-show="state === 'viewing'" x-cloak x-text="tick"></span>
        <p class="fm-error ac-cred__error" x-show="error" x-cloak x-text="error"></p>
    </div>
</x-ui.field>
