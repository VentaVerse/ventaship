@props(['label'])

{{--
    A row's actions behind an icon button. The menu is position: fixed and placed from the button (rowMenu), so the
    panel and table edges cannot clip it. Items are <x-ui.row-menu-item>s.
--}}
<div {{ $attributes->merge(['class' => 'x-rowmenu']) }} x-data="rowMenu" x-on:keydown.escape.stop="close()"
     x-on:focusout="onFocusOut($event)">
    <button type="button" class="x-btn x-btn--sm x-rowmenu__trigger" x-ref="trigger" aria-haspopup="menu"
            aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'" aria-label="{{ $label }}"
            x-on:click="toggle()" x-on:keydown="onTriggerKey($event)">
        <x-ui.icon name="more" size="18" />
    </button>
    <div class="x-rowmenu__pop" role="menu" aria-label="{{ $label }}" x-ref="menu" x-show="open" x-cloak
         x-on:click.outside="close(false)" x-on:keydown="onKey($event)">
        {{ $slot }}
    </div>
</div>
