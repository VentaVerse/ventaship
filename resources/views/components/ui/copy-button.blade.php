@props(['label', 'variant' => 'default'])

{{--
    The Copy button of a credentialField: "Copied" (or "Couldn't copy") for a moment after a copy. All three labels
    share one grid cell, so the button keeps the width of the longest. The live region says the same.
--}}
<x-ui.button size="sm" :variant="$variant" class="x-copy" data-copy="idle" x-bind:data-copy="copied" x-on:click="copy()" x-bind:disabled="busy" :aria-label="$label">
    <span class="x-copy__label x-copy__label--idle">Copy</span>
    <span class="x-copy__label x-copy__label--ok" aria-hidden="true"><x-ui.icon name="check" size="13" />Copied</span>
    <span class="x-copy__label x-copy__label--fail" aria-hidden="true">Couldn't copy</span>
</x-ui.button>
<span class="x-sr" aria-live="polite" x-text="copyNote"></span>
