{{--
    The signed-in admin's menu, top right of every app page. Closed on first paint (the list is x-cloak); the chosen
    theme is lit by CSS from data-theme-mode, which theme-boot sets before the page draws. Keyboard and placement come
    from rowMenu.
--}}
<div class="x-prof" x-data="profileMenu" x-on:keydown.escape.stop="close()" x-on:focusout="onFocusOut($event)">
    <button type="button" class="x-prof__btn" x-ref="trigger" aria-haspopup="menu" aria-controls="x-prof-menu"
            aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'" aria-label="{{ $admin->name }}"
            x-on:click="toggle()" x-on:keydown="onTriggerKey($event)">
        <span class="x-prof__avatar" aria-hidden="true">{{ $admin->initials() }}</span>
        <span class="x-prof__name" aria-hidden="true">{{ $admin->name }}</span>
        <x-ui.icon name="chevron-down" size="14" />
    </button>
    <div class="x-rowmenu__pop x-prof__pop" id="x-prof-menu" role="menu" aria-label="{{ $admin->name }}" x-ref="menu"
         x-show="open" x-cloak x-on:click.outside="close(false)" x-on:keydown="onKey($event)">
        <a href="{{ route('account') }}" role="menuitem" tabindex="-1" class="x-rowmenu__item x-prof__item"><x-ui.icon name="user" size="15" />Account</a>
        <a href="{{ route('settings.edit') }}" role="menuitem" tabindex="-1" class="x-rowmenu__item x-prof__item"><x-ui.icon name="cog" size="15" />Settings</a>
        <div class="x-prof__theme" role="group" aria-label="Theme">
            @foreach(['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $mode => $label)
                <button type="button" class="x-prof__mode" data-mode="{{ $mode }}" role="menuitemradio" tabindex="-1"
                        aria-checked="false" x-bind:aria-checked="mode === '{{ $mode }}' ? 'true' : 'false'"
                        x-on:click="setTheme('{{ $mode }}')">{{ $label }}</button>
            @endforeach
        </div>
        <div class="x-prof__sep" role="separator"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" role="menuitem" tabindex="-1" class="x-rowmenu__item x-prof__item"><x-ui.icon name="log-out" size="15" />Sign out</button>
        </form>
    </div>
</div>
