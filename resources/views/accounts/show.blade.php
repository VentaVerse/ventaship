@extends('layouts.app')

@php
    use App\Couriers\CourierAccounts;
    use App\Fulfillment\Couriers;

    $envWords = ['sandbox' => 'Sandbox', 'production' => 'Production'];
    $name = Couriers::name($courier);
    $check = $account && $account->last_check_ok !== null ? ($account->last_check_ok ? ['Connected', 'good'] : ['Check failed', 'bad']) : ['Not checked', 'mute'];
@endphp

@section('width', 'form')
@section('title', $name)

@section('title_lead')<x-courier-tile :courier="$courier" class="ac-title-tile" />@endsection

@if($account)
@section('title_after')
    <span class="ac-title-badges">
        <x-ui.badge :tone="$account->mode === 'production' ? 'good' : 'cool'" data-env-badge>{{ $envWords[$account->mode] ?? ucfirst($account->mode) }}</x-ui.badge>
        <x-ui.badge :tone="$check[1]">{{ $check[0] }}</x-ui.badge>
        @if($account->status === 'inactive')
            <x-ui.badge>Inactive</x-ui.badge>
        @endif
    </span>
@endsection
@endif

@section('crumb')<a href="{{ route('accounts.index') }}">Courier accounts</a>@endsection

@if($account)
@section('actions')
    <form method="POST" action="{{ route('accounts.test', $courier) }}" class="ac-actions">
        @csrf
        <x-ui.button type="submit">Test connection</x-ui.button>
    </form>
@endsection
@endif

@section('content')
@if(! $account)
<section class="x-panel ac-form ac-page">
    <form method="POST" action="{{ route('accounts.setup', $courier) }}" novalidate>
        @csrf
        <div class="st-sec">
            <h2 class="st-h" id="account-env-label">Environment</h2>
            <div class="st-row">
                <div class="ac-seg{{ $errors->has('mode') ? ' is-invalid' : '' }}" id="account-env" role="radiogroup" aria-labelledby="account-env-label" @error('mode') aria-invalid="true" aria-describedby="account-env-error" @enderror>
                    @foreach(CourierAccounts::ENVIRONMENTS as $e)
                        <label class="ac-seg__opt">
                            <input type="radio" class="ac-seg__input" name="mode" value="{{ $e }}" @checked(old('mode', 'sandbox') === $e)>
                            <span class="ac-seg__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                            <span>{{ $envWords[$e] }}</span>
                        </label>
                    @endforeach
                </div>
                <x-ui.field-error name="mode" id="account-env-error" />
            </div>
        </div>
        <div class="st-bar">
            <x-ui.button variant="primary" type="submit">Set up</x-ui.button>
        </div>
    </form>
</section>
@else
@php
    $env = old('mode', $account->mode);
    $env = array_key_exists($env, $envWords) ? $env : 'sandbox';
    $confirm = ['title' => 'Switch ' . $name . ' to Production? Bookings will be real.', 'confirm' => 'Switch to Production', 'dismiss' => 'Back', 'form' => 'account-form', 'submitter' => 'account-save-confirmed'];
    $plainInput = function (array $f, string $id) {
        return ['id' => $id, 'name' => 'settings[' . $f['key'] . ']', 'value' => old('settings.' . $f['key'], $f['value'])];
    };
@endphp

<section class="x-panel ac-page">
    <form method="POST" action="{{ route('accounts.update', $courier) }}" id="account-form" novalidate
          x-data="{ env: @js($env), saved: @js($account->mode), status: '' }"
          x-on:submit="if (env === 'production' && saved !== 'production' && $event.submitter?.id !== 'account-save-confirmed') { $event.preventDefault(); $dispatch('confirm', @js($confirm)) }"
          x-on:credential-status="status = ''; $nextTick(() => status = $event.detail)">
        @csrf
        @method('PUT')
        <div class="st-sec">
            <h2 class="st-h" id="account-env-label">Environment</h2>
            <div class="st-row">
                <div class="ac-seg{{ $errors->has('mode') ? ' is-invalid' : '' }}" id="account-env" role="radiogroup" aria-labelledby="account-env-label" @error('mode') aria-invalid="true" aria-describedby="account-env-error" @enderror>
                    @foreach(CourierAccounts::ENVIRONMENTS as $e)
                        <label class="ac-seg__opt">
                            <input type="radio" class="ac-seg__input" name="mode" value="{{ $e }}" x-model="env" @checked($env === $e)>
                            <span class="ac-seg__tick" aria-hidden="true"><x-ui.icon name="check" size="14" /></span>
                            <span>{{ $envWords[$e] }}</span>
                        </label>
                    @endforeach
                </div>
                <x-ui.field-error name="mode" id="account-env-error" />
            </div>
        </div>

        @foreach($environments as $e => $keys)
            @continue($keys === [])
            <div class="st-sec ac-keys" data-keys="{{ $e }}" x-bind:hidden="env !== @js($e)" @if($e !== $env) hidden @endif>
                <h2 class="st-h">{{ $envWords[$e] }} keys</h2>
                @foreach($keys as $k)
                    <div class="st-row">
                        @if($k['secret'])
                            <x-ui.credential
                                :name="'secrets[' . $k['key'] . ']'"
                                :label="$k['label']"
                                :account="$account"
                                :key="$k['key']"
                                :masked="$k['masked']"
                                :revealUrl="route('accounts.reveal', [$courier, $k['key']])"
                                :context="$envWords[$e]"
                                :id="'f-key-' . $k['key']" />
                        @else
                            @php $in = $plainInput($k, 'f-key-' . $k['key']); @endphp
                            <x-ui.field :label="$k['label']" :for="$in['id']" :errorKey="'settings.' . $k['key']" :keep-flash="$e !== $env">
                                <x-ui.input :id="$in['id']" :name="$in['name']" class="st-ctl x-mono" :value="$in['value']" maxlength="2048" autocomplete="off" spellcheck="false" />
                            </x-ui.field>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        @foreach(['Account' => $accountFields, 'Booking defaults' => $fields] as $heading => $group)
            @continue($group === [])
            <div class="st-sec">
                <h2 class="st-h">{{ $heading }}</h2>
                @foreach($group as $f)
                    @php $in = $plainInput($f, 'f-set-' . $f['key']); @endphp
                    <div class="st-row">
                        <x-ui.field :label="$f['label']" :for="$in['id']" :errorKey="'settings.' . $f['key']">
                            @if(($f['type'] ?? 'text') === 'select')
                                <x-ui.select :id="$in['id']" :name="$in['name']" class="st-ctl" wrapClass="st-ctl">
                                    <option value="">Not set</option>
                                    @foreach($f['options'] ?? [] as $opt)
                                        <option value="{{ $opt['value'] }}" @selected($in['value'] === $opt['value'])>{{ $opt['label'] }}</option>
                                    @endforeach
                                </x-ui.select>
                            @else
                                <x-ui.input :id="$in['id']" :name="$in['name']" class="st-ctl" :value="$in['value']" :placeholder="$f['placeholder'] ?? ''" maxlength="2048" autocomplete="off" />
                            @endif
                        </x-ui.field>
                    </div>
                @endforeach
            </div>
        @endforeach

        @if($webhook)
            <div class="st-sec">
                <h2 class="st-h">Webhook</h2>
                <div class="st-row">
                    <x-ui.webhook-url :label="'Address for ' . $name" :masked="$webhook" :revealUrl="route('accounts.reveal', [$courier, 'webhook_url'])" />
                </div>
            </div>
        @endif

        @if($blocks !== null)
            <div class="st-sec">
                <h2 class="st-h">Number blocks</h2>
                @if($blocks === [])
                    <x-ui.empty title="No blocks" />
                @else
                    <div class="ac-blocks-wrap">
                        <table class="ac-blocks">
                            <thead><tr><th scope="col">First</th><th scope="col">Last</th><th scope="col">Next</th><th scope="col">Remaining</th></tr></thead>
                            <tbody>
                                @foreach($blocks as $b)
                                    <tr>
                                        <td>{{ $b['first'] }}</td>
                                        <td>{{ $b['last'] }}</td>
                                        <td>{{ $b['next'] ?? '–' }}</td>
                                        <td>{{ number_format($b['remaining']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="st-row ac-block-add">
                    <x-ui.field label="First" name="first" for="f-block-first">
                        <x-ui.input id="f-block-first" name="first" form="series-form" class="st-ctl" value="{{ old('first') }}" inputmode="numeric" maxlength="20" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.field label="Last" name="last" for="f-block-last">
                        <x-ui.input id="f-block-last" name="last" form="series-form" class="st-ctl" value="{{ old('last') }}" inputmode="numeric" maxlength="20" autocomplete="off" />
                    </x-ui.field>
                    <x-ui.button type="submit" form="series-form">Add block</x-ui.button>
                </div>
            </div>
        @endif

        <p class="x-sr" aria-live="polite" x-text="status"></p>

        <div class="st-bar">
            <x-ui.button :href="route('accounts.show', $courier)">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
        <button type="submit" id="account-save-confirmed" hidden tabindex="-1" aria-hidden="true"></button>
    </form>
    @if($blocks !== null)
        <form id="series-form" method="POST" action="{{ route('accounts.series', $courier) }}" novalidate>@csrf</form>
    @endif
</section>
@endif
@endsection
