@extends('layouts.app')

@section('width', 'form')
@section('title', 'Add platform')
@section('crumb')<a href="{{ route('apps.index') }}">Connected platforms</a>@endsection

@section('content')
<section class="x-panel pf-page">
    <form method="POST" action="{{ route('apps.store') }}" novalidate>
        @csrf
        <div class="st-sec">
            <div class="st-row">
                <x-ui.field label="Name" name="name" required>
                    <x-ui.input id="f-name" name="name" class="st-ctl" value="{{ old('name') }}" maxlength="255" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <x-ui.field label="Webhook URL" name="webhook_url">
                    <x-ui.input type="url" id="f-webhook-url" name="webhook_url" class="st-ctl" value="{{ old('webhook_url') }}" maxlength="2048" autocomplete="off" />
                </x-ui.field>
            </div>
        </div>
        <div class="st-bar">
            <x-ui.button :href="route('apps.index')">Discard</x-ui.button>
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
</section>
@endsection
