@extends('layouts.app')

@section('width', 'form')
@section('title', $app->name)

@section('title_after')
    <span class="pf-title-badges">
        <x-ui.badge :tone="$app->status === 'active' ? 'good' : 'mute'">{{ $app->status === 'active' ? 'Active' : 'Disabled' }}</x-ui.badge>
    </span>
@endsection

@section('crumb')<a href="{{ route('apps.index') }}">Connected platforms</a>@endsection

@section('content')
<section class="x-panel pf-page">
    <form method="POST" action="{{ route('apps.update', $app) }}" novalidate>
        @csrf
        @method('PUT')
        <div class="st-sec" id="general">
            <h2 class="st-h">General</h2>
            <div class="st-row">
                <x-ui.field label="Name" name="name" required>
                    <x-ui.input id="f-name" name="name" class="st-ctl" value="{{ old('name', $app->name) }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Webhook URL" name="webhook_url">
                    <x-ui.input type="url" id="f-webhook-url" name="webhook_url" class="st-ctl" value="{{ old('webhook_url', $app->webhook_url) }}" maxlength="2048" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row st-row--switch">
                <label class="st-switch" for="f-active">
                    <span class="st-switch__label">Active</span>
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" class="st-switch__input" role="switch" id="f-active" name="active" value="1" @checked(old('active', $app->status === 'active' ? '1' : '0') === '1')>
                    <span class="st-switch__track" aria-hidden="true"></span>
                </label>
            </div>
        </div>
        <div class="st-bar">
            <x-ui.button :href="route('apps.show', $app)">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
</section>
@endsection
