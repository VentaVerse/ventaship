<div x-data="confirmDialog" x-cloak x-show="open" @confirm.window="show($event.detail)" @keydown.escape.window="close()"
     class="modal-backdrop" @click.self="close()">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="x-confirm-title" x-ref="dialog" tabindex="-1">
        <h2 class="modal__title" id="x-confirm-title" x-text="title"></h2>
        <div class="modal__actions">
            <x-ui.button @click="close()">Cancel</x-ui.button>
            <x-ui.button variant="primary" @click="submit()"><span x-text="confirm"></span></x-ui.button>
        </div>
    </div>
</div>
