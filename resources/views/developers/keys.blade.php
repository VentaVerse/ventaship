@php
    $createUrl = route('developers.index', ['tab' => 'keys', 'create' => 1]);
    $openCreate = "creating = true; \$nextTick(() => document.getElementById('f-key-name').focus())";
@endphp
<section class="x-panel api-panel" x-data="{ creating: {{ $creating ? 'true' : 'false' }} }">
    <div class="api-bar">
        <h2 class="api-bar__title">API keys</h2>
        <x-ui.button variant="primary" :href="$createUrl" x-on:click.prevent="{{ $openCreate }}">Create key</x-ui.button>
    </div>

    <form class="api-inline" method="POST" action="{{ route('developers.keys.store') }}" novalidate x-show="creating" @unless($creating) x-cloak @endunless>
        @csrf
        <x-ui.field label="Name" name="name" for="f-key-name" required>
            <x-ui.input id="f-key-name" name="name" value="{{ old('name') }}" maxlength="255" autocomplete="off" required />
        </x-ui.field>
        <div class="api-inline__acts">
            <x-ui.button variant="primary" type="submit">Create</x-ui.button>
            <x-ui.button :href="route('developers.index', ['tab' => 'keys'])" x-on:click.prevent="creating = false">Cancel</x-ui.button>
        </div>
    </form>

    @if($keys->isEmpty())
        <x-ui.empty title="No API keys" x-show="!creating">
            <x-slot:action><x-ui.button variant="primary" :href="$createUrl" x-on:click.prevent="{{ $openCreate }}">Create key</x-ui.button></x-slot:action>
        </x-ui.empty>
    @else
        <table class="api-table">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Key</th>
                    <th scope="col">Last used</th>
                    <th scope="col">Created</th>
                    <th scope="col"><span class="x-sr">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($keys as $row)
                    @php
                        $app = $row['app'];
                        $k = $row['key'];
                        $rid = $row['id'];
                        $keyField = $k ? ['key' => $k->id] : [];
                        $renameUrl = route('developers.index', ['tab' => 'keys', 'rename' => $rid]);
                    @endphp
                    <tr class="{{ $row['auto'] ? 'is-new' : '' }}" x-data="{ renaming: {{ $row['renaming'] ? 'true' : 'false' }} }">
                        <td class="api-name">
                            <span x-show="!renaming" @if($row['renaming']) x-cloak @endif>{{ $row['name'] }}</span>
                            <form class="api-rename" method="POST" action="{{ route('developers.keys.rename', $app) }}" novalidate x-show="renaming" @unless($row['renaming']) x-cloak @endunless>
                                @csrf
                                @method('PUT')
                                @if($k)<input type="hidden" name="key" value="{{ $k->id }}">@endif
                                <x-ui.input :id="'rename-' . $rid" name="name" :value="$row['rename_value']" maxlength="255" autocomplete="off" required :aria-label="'New name for ' . $row['name']" />
                                <x-ui.button size="sm" variant="primary" type="submit">Save</x-ui.button>
                                <x-ui.button size="sm" :href="route('developers.index', ['tab' => 'keys'])" x-on:click.prevent="renaming = false">Cancel</x-ui.button>
                            </form>
                        </td>
                        <td data-label="Key">
                            @if($k && $row['viewable'])
                                <x-ui.secret-inline :label="'API key ' . $row['name']" :masked="$row['masked']"
                                    :revealUrl="route('developers.reveal', [$app, 'token:' . $k->id])"
                                    :id="'f-token-' . $k->id" :autoView="$row['auto']" />
                            @elseif($k)
                                <code class="api-code">{{ $k->prefix }}••••</code>
                            @else
                                <x-ui.badge>No key</x-ui.badge>
                            @endif
                        </td>
                        <td class="x-num" data-label="Last used">{{ $row['last_used']?->diffForHumans() ?? 'Never' }}</td>
                        <td class="x-num" data-label="Created">{{ $row['created']?->format('j M Y') }}</td>
                        <td class="api-act">
                            <x-ui.row-menu :label="'Actions for ' . $row['name']">
                                <x-ui.row-menu-item x-on:click="run(() => $dispatch('confirm', {{ Js::from(['title' => 'Roll '.$row['name'].'? The current key stops working.', 'confirm' => 'Roll key', 'dismiss' => 'Cancel', 'form' => 'roll-'.$rid]) }}))">Roll key</x-ui.row-menu-item>
                                <x-ui.row-menu-item :href="$renameUrl" x-on:click.prevent="run(() => { renaming = true; $nextTick(() => document.getElementById('rename-{{ $rid }}').focus()) })">Rename</x-ui.row-menu-item>
                                <x-ui.row-menu-item tone="bad" x-on:click="run(() => $dispatch('confirm', {{ Js::from(['title' => 'Revoke '.$row['name'].'? Apps using it stop working.', 'confirm' => 'Revoke', 'dismiss' => 'Cancel', 'form' => 'revoke-'.$rid]) }}))">Revoke</x-ui.row-menu-item>
                            </x-ui.row-menu>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

@push('forms')
    @foreach($keys as $row)
        @foreach(['roll' => 'developers.keys.roll', 'revoke' => 'developers.keys.revoke'] as $action => $routeName)
            <form id="{{ $action }}-{{ $row['id'] }}" method="POST" action="{{ route($routeName, $row['app']) }}">
                @csrf
                @if($row['key'])<input type="hidden" name="key" value="{{ $row['key']->id }}">@endif
            </form>
        @endforeach
    @endforeach
@endpush
