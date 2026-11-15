@extends('layouts.app')

@section('title', $status ? $status->name : 'Add status')
@section('crumb')<x-ui.back :href="route('statuses.index')">Shipment statuses</x-ui.back>@endsection

@section('content')
<section class="x-panel addr-page">
    <form method="POST" action="{{ $status ? route('statuses.update', $status) : route('statuses.store') }}" novalidate>
        @csrf
        @if($status)@method('PUT')@endif
        <div class="st-sec">
            <div class="st-row">
                <x-ui.field label="Status name" name="name" required>
                    <x-ui.input id="f-name" name="name" class="st-ctl" value="{{ old('name', $status?->name ?? '') }}" maxlength="40" autocomplete="off" />
                </x-ui.field>
            </div>
            <div class="st-row">
                <div class="fm-field">
                    <span class="fm-label" id="f-enabled-label">Enabled</span>
                    <label class="sh-switch">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" class="sh-switch__input" role="switch" id="f-enabled" name="enabled" value="1" aria-labelledby="f-enabled-label" @checked(old('enabled', $status?->enabled ?? true))>
                        <span class="sh-switch__track" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
        </div>
        <div class="st-bar">
            @if($status)
                <x-ui.button class="addr-delete" x-on:click="$dispatch('confirm', {{ Js::from(['title' => 'Delete '.$status->name.'?', 'confirm' => 'Delete status', 'dismiss' => 'Keep status', 'form' => 'delete-status', 'tone' => 'danger']) }})">Delete</x-ui.button>
            @endif
            <x-ui.button variant="primary" type="submit">Save</x-ui.button>
        </div>
    </form>
    @if($status)
        <form id="delete-status" method="POST" action="{{ route('statuses.destroy', $status) }}">@csrf @method('DELETE')</form>
    @endif
</section>
@endsection
