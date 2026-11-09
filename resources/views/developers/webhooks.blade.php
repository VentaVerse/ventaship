@php
    $addUrl = route('developers.index', ['tab' => 'webhooks', 'add' => 1]);
    $openAdd = "adding = true; \$nextTick(() => document.getElementById('f-endpoint-url').focus())";
    $noFree = $freeApps->isEmpty();
@endphp
<section class="x-panel api-panel" x-data="{ adding: {{ $adding ? 'true' : 'false' }} }">
    <div class="api-bar">
        <h2 class="api-bar__title">Endpoints</h2>
        <x-ui.button variant="primary" :href="$addUrl" :disabled="$noFree" x-on:click.prevent="{{ $openAdd }}">Add endpoint</x-ui.button>
    </div>

    @unless($noFree)
        <form class="api-inline" method="POST" action="{{ route('developers.endpoints.store') }}" novalidate x-show="adding" @unless($adding) x-cloak @endunless>
            @csrf
            <x-ui.field label="URL" name="webhook_url" for="f-endpoint-url" required class="api-inline__grow">
                <x-ui.input type="url" id="f-endpoint-url" name="webhook_url" value="{{ old('app') ? old('webhook_url') : '' }}" maxlength="2048" autocomplete="off" placeholder="https://" required />
            </x-ui.field>
            <x-ui.field label="Key" name="app" for="f-endpoint-app" required>
                <x-ui.select id="f-endpoint-app" name="app" required>
                    @foreach($freeApps as $free)
                        <option value="{{ $free->public_id }}" @selected(old('app') === $free->public_id)>{{ $free->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <div class="api-inline__acts">
                <x-ui.button variant="primary" type="submit">Add</x-ui.button>
                <x-ui.button :href="route('developers.index', ['tab' => 'webhooks'])" x-on:click.prevent="adding = false">Cancel</x-ui.button>
            </div>
        </form>
    @endunless

    @if($endpoints->isEmpty())
        <x-ui.empty title="No endpoints" x-show="!adding">
            <x-slot:action><x-ui.button variant="primary" :href="$addUrl" :disabled="$noFree" x-on:click.prevent="{{ $openAdd }}">Add endpoint</x-ui.button></x-slot:action>
        </x-ui.empty>
    @else
        <table class="api-table">
            <thead>
                <tr>
                    <th scope="col">Endpoint</th>
                    <th scope="col">Key</th>
                    <th scope="col">Status</th>
                    <th scope="col">Signing secret</th>
                    <th scope="col"><span class="x-sr">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($endpoints as $row)
                    @php
                        $app = $row['app'];
                        $pid = $app->public_id;
                        $editUrl = route('developers.index', ['tab' => 'webhooks', 'edit' => $pid]);
                    @endphp
                    <tr x-data="{ editing: {{ $row['editing'] ? 'true' : 'false' }} }">
                        <td class="api-url">
                            <span class="api-mono" x-show="!editing" @if($row['editing']) x-cloak @endif>{{ $app->webhook_url }}</span>
                            <form class="api-rename" method="POST" action="{{ route('developers.endpoints.update', $app) }}" novalidate x-show="editing" @unless($row['editing']) x-cloak @endunless>
                                @csrf
                                @method('PUT')
                                <x-ui.input type="url" :id="'endpoint-' . $pid" name="webhook_url" :value="$row['editing'] ? old('webhook_url', $app->webhook_url) : $app->webhook_url" maxlength="2048" autocomplete="off" required :aria-label="'Endpoint URL for ' . $app->name" />
                                <x-ui.button size="sm" variant="primary" type="submit">Save</x-ui.button>
                                <x-ui.button size="sm" :href="route('developers.index', ['tab' => 'webhooks'])" x-on:click.prevent="editing = false">Cancel</x-ui.button>
                            </form>
                        </td>
                        <td class="api-sub" data-label="Key">{{ $app->name }}</td>
                        <td><x-ui.badge :tone="$row['health'][1]">{{ $row['health'][0] }}</x-ui.badge></td>
                        <td data-label="Signing secret">
                            <x-ui.secret-inline :label="'Signing secret ' . $app->name" :masked="$row['secret']"
                                :revealUrl="route('developers.reveal', [$app, 'webhook'])" :id="'f-secret-' . $pid" />
                        </td>
                        <td class="api-act">
                            <x-ui.row-menu :label="'Actions for ' . $app->name">
                                <x-ui.row-menu-item type="submit" :form="'test-' . $pid">Send test</x-ui.row-menu-item>
                                <x-ui.row-menu-item :href="$editUrl" x-on:click.prevent="run(() => { editing = true; $nextTick(() => document.getElementById('endpoint-{{ $pid }}').focus()) })">Edit</x-ui.row-menu-item>
                                <x-ui.row-menu-item x-on:click="run(() => $dispatch('confirm', {{ Js::from(['title' => 'Roll the signing secret for '.$app->name.'? The current secret stops working.', 'confirm' => 'Roll secret', 'dismiss' => 'Cancel', 'form' => 'secret-'.$pid]) }}))">Roll secret</x-ui.row-menu-item>
                                <x-ui.row-menu-item tone="bad" x-on:click="run(() => $dispatch('confirm', {{ Js::from(['title' => 'Delete the endpoint for '.$app->name.'?', 'confirm' => 'Delete', 'dismiss' => 'Cancel', 'form' => 'delete-'.$pid]) }}))">Delete</x-ui.row-menu-item>
                            </x-ui.row-menu>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

@push('forms')
    @foreach($endpoints as $row)
        @php $app = $row['app']; @endphp
        <form id="test-{{ $app->public_id }}" method="POST" action="{{ route('developers.endpoints.test', $app) }}">@csrf</form>
        <form id="secret-{{ $app->public_id }}" method="POST" action="{{ route('developers.endpoints.secret', $app) }}">@csrf</form>
        <form id="delete-{{ $app->public_id }}" method="POST" action="{{ route('developers.endpoints.destroy', $app) }}">@csrf @method('DELETE')</form>
    @endforeach
@endpush
