@props(['name', 'label', 'account' => null, 'key', 'masked' => null, 'revealUrl' => null, 'context' => null, 'id' => null])

{{--
    A stored secret as a masked field. Only its length and last 4 characters reach the page; View fetches the value
    from $revealUrl (POST, throttled, audited) and shows it for 30 seconds. The input carries a name only while it is
    being edited, so an untouched field posts nothing.
--}}
@php
    $id ??= 'cred-' . str_replace('_', '-', $key);
    $revealUrl ??= $account ? route('accounts.reveal', [$account, $key]) : null;
    $isSet = $masked !== null;
    $length = (int) ($masked['length'] ?? 0);
    // A short value would be mostly given away by its last 4 characters: dots only.
    $mask = ! $isSet ? '' : ($length >= 8
        ? str_repeat('•', min(16, $length - 4)) . $masked['last4']
        : str_repeat('•', max(4, $length)));
    $aria = fn (string $action) => trim($action . ' ' . ($context ? $context . ' ' : '') . $label);
    $config = ['url' => $revealUrl, 'mask' => $mask, 'isSet' => $isSet, 'name' => $name];
@endphp

<x-ui.field :label="$label" :for="$id" :errorKey="'secrets.' . $key">
    <div class="ac-cred" x-data="credentialField(@js($config))">
        @if($isSet)
            <input type="text" id="{{ $id }}" class="x-input ac-cred__input" value="{{ $mask }}" readonly
                   autocomplete="off" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore
                   x-ref="input" x-bind:name="state === 'editing' ? name : null" x-bind:readonly="state !== 'editing'"
                   x-bind:class="{ 'is-shown': state === 'viewing', 'is-editing': state === 'editing' }">
        @else
            <span class="ac-cred__unset" x-show="state !== 'editing'"><x-ui.badge>Not set</x-ui.badge></span>
            <input type="text" id="{{ $id }}" class="x-input ac-cred__input is-editing" hidden
                   autocomplete="off" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore
                   x-ref="input" x-bind:name="state === 'editing' ? name : null" x-bind:hidden="state !== 'editing'">
        @endif
        <span class="ac-cred__acts">
            @if($isSet)
                <x-ui.button size="sm" x-show="state === 'masked'" x-on:click="view()" x-bind:disabled="busy" :aria-label="$aria('View')">View</x-ui.button>
                <x-ui.button size="sm" x-show="state === 'viewing'" x-cloak x-on:click="hide()" :aria-label="$aria('Hide')">Hide</x-ui.button>
            @endif
            <x-ui.button size="sm" x-show="state !== 'editing'" x-on:click="edit()" :aria-label="$aria('Edit')">Edit</x-ui.button>
            <x-ui.button size="sm" x-show="state === 'editing'" x-cloak x-on:click="cancel()" :aria-label="$aria('Cancel')">Cancel</x-ui.button>
            <span class="ac-cred__tick x-num" aria-hidden="true" x-show="state === 'viewing'" x-cloak x-text="tick"></span>
        </span>
        <p class="fm-error ac-cred__error" x-show="error" x-cloak x-text="error"></p>
    </div>
</x-ui.field>
