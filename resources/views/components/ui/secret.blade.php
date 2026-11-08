@props(['label', 'masked' => null, 'revealUrl', 'id', 'context' => null, 'autoView' => false])

{{--
    A generated secret as a masked field with View and Copy only: it is replaced, never edited. Only its length and
    last 4 characters reach the page; View and Copy fetch it from $revealUrl (POST, throttled, audited). With
    $autoView the page fetches it once on load (a key just issued).
--}}
@php
    $length = (int) ($masked['length'] ?? 0);
    $mask = $masked === null ? '' : ($length >= 8
        ? str_repeat('•', min(16, $length - 4)) . $masked['last4']
        : str_repeat('•', max(4, $length)));
    $aria = fn (string $action) => trim($action . ' ' . ($context ? $context . ' ' : '') . $label);
    $config = ['url' => $revealUrl, 'mask' => $mask, 'isSet' => true, 'name' => null, 'autoView' => (bool) $autoView];
@endphp

<x-ui.field :label="$label" :for="$id">
    @if($masked === null)
        <div><x-ui.badge tone="attn">Not set</x-ui.badge></div>
    @else
        <div class="ac-cred" x-data="credentialField(@js($config))">
            <input type="text" id="{{ $id }}" class="x-input ac-cred__input" value="{{ $mask }}" readonly
                   autocomplete="off" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore
                   x-ref="input" x-bind:class="{ 'is-shown': state === 'viewing' }">
            <span class="ac-cred__acts">
                <x-ui.button size="sm" x-show="state === 'masked'" x-on:click="view()" x-bind:disabled="busy" :aria-label="$aria('View')">View</x-ui.button>
                <x-ui.button size="sm" x-show="state === 'viewing'" x-cloak x-on:click="hide()" :aria-label="$aria('Hide')">Hide</x-ui.button>
                <x-ui.button size="sm" x-on:click="copy()" x-bind:disabled="busy" :aria-label="$aria('Copy')">Copy</x-ui.button>
                {{ $slot }}
                <span class="ac-cred__tick x-num" aria-hidden="true" x-show="state === 'viewing'" x-cloak x-text="tick"></span>
            </span>
            <p class="fm-error ac-cred__error" x-show="error" x-cloak x-text="error"></p>
        </div>
    @endif
</x-ui.field>
