@props(['dismiss' => 'Back'])
{{-- The app-wide confirm dialog (resources/js/confirmDialog.js): opened by a `confirm` window event; `tone: 'danger'` makes the confirm red. --}}
<div x-data="confirmDialog" x-cloak x-show="open" @confirm.window="show($event.detail)" @keydown.escape.window="close()"
     class="modal-backdrop" @click.self="close()">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="x-confirm-title" x-ref="dialog" tabindex="-1"
         @keydown.tab="trap($event)">
        <h2 class="modal__title" id="x-confirm-title" x-text="title">Confirm</h2>
        <div class="modal__actions">
            <x-ui.button class="modal__safe" x-ref="safe" @click="close()"><span x-text="dismiss">{{ $dismiss }}</span></x-ui.button>
            <x-ui.button class="modal__go" data-tone="primary" x-bind:data-tone="tone" x-bind:class="tone === 'danger' ? 'x-btn--danger' : 'x-btn--primary'" @click="submit()"><span x-text="confirm">Confirm</span></x-ui.button>
        </div>
    </div>
</div>
