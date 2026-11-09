@php
    $createUrl = route('developers.index', ['tab' => 'keys', 'create' => 1]);
    $openCreate = "creating = true; \$nextTick(() => document.getElementById('f-key-name').focus())";
    // Cancel and Esc: the form closes and focus goes back to the header's Create key.
    $closeCreate = "creating = false; \$nextTick(() => document.getElementById('create-key')?.focus())";
    $baseCopy = ['url' => null, 'value' => $baseUrl, 'mask' => $baseUrl, 'isSet' => true, 'name' => null];
@endphp
<div class="api-keys" x-data="{ status: '' }" x-on:credential-status="status = ''; $nextTick(() => status = $event.detail)">
<p class="x-sr" role="status" aria-live="polite" x-text="status"></p>
<div class="api-cards" x-data="{ creating: {{ $creating ? 'true' : 'false' }} }" x-on:create-key.window="{{ $openCreate }}">
    <section class="x-panel api-card api-base" aria-label="Base URL">
        <div class="api-row">
            <label class="api-row__label" for="f-base-url">Base URL</label>
            <div class="api-row__val">
                <div class="ac-cred" x-data="credentialField(@js($baseCopy))">
                    <input type="text" id="f-base-url" class="x-input ac-cred__input is-shown" value="{{ $baseUrl }}" readonly
                           autocomplete="off" spellcheck="false" autocapitalize="off" x-ref="input">
                    <span class="ac-cred__acts">
                        <x-ui.copy-button label="Copy base URL" />
                    </span>
                    <p class="fm-error ac-cred__error" x-show="error" x-cloak x-text="error"></p>
                </div>
            </div>
        </div>
    </section>

    <form class="x-panel api-inline" method="POST" action="{{ route('developers.keys.store') }}" novalidate x-show="creating" @unless($creating) x-cloak @endunless
          x-on:keydown.escape.prevent.stop="{{ $closeCreate }}">
        @csrf
        <x-ui.field label="Name" name="name" for="f-key-name" required>
            <x-ui.input id="f-key-name" name="name" value="{{ old('name') }}" maxlength="255" autocomplete="off" required />
        </x-ui.field>
        <div class="api-inline__acts">
            <x-ui.button variant="primary" type="submit">Create</x-ui.button>
            <x-ui.button :href="route('developers.index', ['tab' => 'keys'])" x-on:click.prevent="{{ $closeCreate }}">Cancel</x-ui.button>
        </div>
    </form>

    @if($keys->isEmpty())
        <x-ui.empty title="No API keys" x-show="!creating">
            <x-slot:action><x-ui.button variant="primary" :href="$createUrl" x-on:click.prevent="{{ $openCreate }}">Create key</x-ui.button></x-slot:action>
        </x-ui.empty>
    @endif

    @foreach($keys as $row)
        @php
            $app = $row['app'];
            $k = $row['key'];
            $rid = $row['id'];
            $pid = $app->public_id;
            $hook = $row['hook_card'] ? $row['webhook'] : null;
            $hookHere = $row['hook_card'];
            $renameUrl = route('developers.index', ['tab' => 'keys', 'rename' => $rid]);
            $urlValue = old('app') === $pid ? old('webhook_url') : ($hook['url'] ?? '');
            $removeOnEmpty = $hook === null ? '' : "if (! \$refs.url.value.trim()) { \$event.preventDefault(); \$dispatch('confirm', " . Js::from(['title' => 'Remove the webhook for '.$row['name'].'?', 'confirm' => 'Remove', 'dismiss' => 'Cancel', 'form' => 'delete-'.$pid, 'tone' => 'danger']) . ') }';
        @endphp
        <section class="x-panel api-card {{ $row['auto'] ? 'is-new' : '' }}" aria-label="{{ $row['name'] }}" x-data="{ renaming: {{ $row['renaming'] ? 'true' : 'false' }} }">
            <header class="api-card__head">
                <div class="api-card__id">
                    <h2 class="api-card__name" x-show="!renaming" @if($row['renaming']) x-cloak @endif>{{ $row['name'] }}</h2>
                    <form class="api-rename" method="POST" action="{{ route('developers.keys.rename', $app) }}" novalidate x-show="renaming" @unless($row['renaming']) x-cloak @endunless>
                        @csrf
                        @method('PUT')
                        @if($k)<input type="hidden" name="key" value="{{ $k->id }}">@endif
                        <x-ui.input :id="'rename-' . $rid" name="name" :value="$row['rename_value']" maxlength="255" autocomplete="off" required :aria-label="'New name for ' . $row['name']" />
                        <x-ui.button size="sm" variant="primary" type="submit">Save</x-ui.button>
                        <x-ui.button size="sm" :href="route('developers.index', ['tab' => 'keys'])" x-on:click.prevent="renaming = false">Cancel</x-ui.button>
                    </form>
                    <span class="api-card__meta x-num">Last used {{ $row['last_used']?->diffForHumans() ?? 'never' }} · Created {{ $row['created']?->format('j M Y') }}</span>
                </div>
                <x-ui.row-menu :label="'Actions for ' . $row['name']">
                    <x-ui.row-menu-item x-on:click="run(() => $dispatch('confirm', {{ Js::from(['title' => 'Roll '.$row['name'].'? The current key stops working.', 'confirm' => 'Roll key', 'dismiss' => 'Cancel', 'form' => 'roll-'.$rid, 'tone' => 'danger']) }}))">Roll key</x-ui.row-menu-item>
                    @if($hook)
                        <x-ui.row-menu-item x-on:click="run(() => $dispatch('confirm', {{ Js::from(['title' => 'Roll the webhook secret for '.$row['name'].'? The current secret stops working.', 'confirm' => 'Roll secret', 'dismiss' => 'Cancel', 'form' => 'secret-'.$pid, 'tone' => 'danger']) }}))">Roll webhook secret</x-ui.row-menu-item>
                    @endif
                    <x-ui.row-menu-item :href="$renameUrl" x-on:click.prevent="run(() => { renaming = true; $nextTick(() => document.getElementById('rename-{{ $rid }}').focus()) })">Rename</x-ui.row-menu-item>
                    <x-ui.row-menu-item tone="bad" x-on:click="run(() => $dispatch('confirm', {{ Js::from(['title' => 'Revoke '.$row['name'].'? Apps using it stop working.', 'confirm' => 'Revoke', 'dismiss' => 'Cancel', 'form' => 'revoke-'.$rid, 'tone' => 'danger']) }}))">Revoke</x-ui.row-menu-item>
                </x-ui.row-menu>
            </header>

            <div class="api-row">
                @if($k && $row['viewable'])
                    <label class="api-row__label" for="f-token-{{ $k->id }}">API key</label>
                @else
                    <span class="api-row__label">API key</span>
                @endif
                <div class="api-row__val">
                    @if($k && $row['viewable'])
                        <x-ui.secret-inline :label="'API key ' . $row['name']" :masked="$row['masked']"
                            :revealUrl="route('developers.reveal', [$app, 'token:' . $k->id])"
                            :id="'f-token-' . $k->id" :autoView="$row['auto']" />
                    @elseif($k)
                        <code class="api-code">{{ $k->prefix }}••••</code>
                    @else
                        <x-ui.badge>No key</x-ui.badge>
                    @endif
                </div>
            </div>

            @if($hookHere)
                <div class="api-row">
                    <label class="api-row__label" for="f-url-{{ $pid }}">Webhook URL</label>
                    <div class="api-row__val">
                        <form class="api-url" method="POST" action="{{ route('developers.endpoints.update', $app) }}" novalidate x-on:submit="{{ $removeOnEmpty }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="app" value="{{ $pid }}">
                            <x-ui.input type="url" :id="'f-url-' . $pid" name="webhook_url" :value="$urlValue" maxlength="2048" autocomplete="off" placeholder="https://" x-ref="url" />
                            <x-ui.button size="sm" type="submit" :aria-label="'Save webhook URL ' . $row['name']">Save</x-ui.button>
                        </form>
                        @if($hook)
                            <x-ui.badge :tone="$hook['health'][1]">{{ $hook['health'][0] }}</x-ui.badge>
                        @else
                            <x-ui.badge>No webhook</x-ui.badge>
                        @endif
                    </div>
                </div>
            @endif

            @if($hook)
                <div class="api-row">
                    @if($hook['secret'] !== null)
                        <label class="api-row__label" for="f-secret-{{ $pid }}">Webhook secret</label>
                    @else
                        <span class="api-row__label">Webhook secret</span>
                    @endif
                    <div class="api-row__val">
                        <x-ui.secret-inline :label="'Webhook secret ' . $row['name']" :masked="$hook['secret']"
                            :revealUrl="route('developers.reveal', [$app, 'webhook'])" :id="'f-secret-' . $pid" />
                        <x-ui.button size="sm" type="submit" :form="'test-' . $pid" :aria-label="'Send test webhook ' . $row['name']">Send test</x-ui.button>
                    </div>
                </div>
            @endif
        </section>
    @endforeach
</div>
</div>

@push('forms')
    @foreach($keys as $row)
        @foreach(['roll' => 'developers.keys.roll', 'revoke' => 'developers.keys.revoke'] as $action => $routeName)
            <form id="{{ $action }}-{{ $row['id'] }}" method="POST" action="{{ route($routeName, $row['app']) }}">
                @csrf
                @if($row['key'])<input type="hidden" name="key" value="{{ $row['key']->id }}">@endif
            </form>
        @endforeach
        @if($row['hook_card'] && $row['webhook'])
            @php $pid = $row['app']->public_id; @endphp
            <form id="test-{{ $pid }}" method="POST" action="{{ route('developers.endpoints.test', $row['app']) }}">@csrf</form>
            <form id="secret-{{ $pid }}" method="POST" action="{{ route('developers.endpoints.secret', $row['app']) }}">@csrf</form>
            <form id="delete-{{ $pid }}" method="POST" action="{{ route('developers.endpoints.destroy', $row['app']) }}">@csrf @method('DELETE')</form>
        @endif
    @endforeach
@endpush
