{{-- Book this shipment? (resources/js/bookCheck.js): the summary, the courier's fee or its refusal, Back to edit and Book shipment. --}}
<div class="modal-backdrop" x-show="check.open" x-cloak @click.self="closeCheck()" @keydown.escape.window="closeCheck()">
    <div class="modal sh-book" role="dialog" aria-modal="true" aria-labelledby="sh-book-title" x-ref="checkDialog" tabindex="-1"
         @keydown.tab="trapCheck($event)" x-bind:aria-busy="check.state === 'loading' ? 'true' : 'false'">
        <h2 class="modal__title" id="sh-book-title">Book this shipment?</h2>
        <dl class="sh-book__sum">
            <template x-for="row in checkRows" :key="row[0]">
                <div class="sh-book__row"><dt x-text="row[0]"></dt><dd x-text="row[1]"></dd></div>
            </template>
        </dl>
        <div class="sh-book__fee" x-show="check.state !== 'refused'" role="status" aria-live="polite">
            <span>Shipping fee</span>
            <b class="sh-num" x-show="check.state === 'ready'" x-text="check.label"></b>
            <span class="sh-book__muted" x-show="check.state === 'loading'">Checking…</span>
            <span class="sh-book__muted" x-show="check.state === 'none'">Not available</span>
        </div>
        <div class="sh-book__err" role="alert" x-show="check.state === 'refused'">
            <b x-text="check.title"></b>
            <span x-show="check.detail !== ''" x-text="check.detail"></span>
        </div>
        <div class="modal__actions">
            <x-ui.button x-ref="checkBack" x-bind:class="check.state === 'refused' && 'x-btn--primary'" @click="closeCheck()">{{ $backLabel ?? 'Back to edit' }}</x-ui.button>
            <x-ui.button x-bind:class="check.state !== 'refused' && 'x-btn--primary'" x-bind:disabled="!canBook" @click="confirmBook()">Book shipment</x-ui.button>
        </div>
    </div>
</div>
