@extends('layouts.app')

@section('title', 'Shipment statuses')

@section('actions')
    <x-ui.button variant="primary" :href="route('statuses.create')">Add status</x-ui.button>
@endsection

@section('content')
<section class="x-panel ss-page">
    @if($statuses->isEmpty())
        <x-ui.empty title="No shipment statuses">
            <x-slot:action><x-ui.button variant="primary" :href="route('statuses.create')">Add status</x-ui.button></x-slot:action>
        </x-ui.empty>
    @else
        <table class="ss-table">
            <thead>
                <tr><th scope="col">Status name</th><th scope="col">Enabled</th><th scope="col"><span class="x-sr">Actions</span></th></tr>
            </thead>
            <tbody>
                @foreach($statuses as $s)
                    <tr @class(['is-off' => ! $s->enabled])>
                        <td class="ss-name"><a class="ss-link" href="{{ route('statuses.edit', $s) }}">{{ $s->name }}</a></td>
                        <td class="ss-on">
                            <form method="POST" action="{{ route('statuses.toggle', $s) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="enabled" value="{{ $s->enabled ? 0 : 1 }}">
                                <label class="sh-switch">
                                    <input type="checkbox" class="sh-switch__input" role="switch" @checked($s->enabled) aria-label="{{ $s->name }} enabled" x-data x-on:change="$el.form.requestSubmit()">
                                    <span class="sh-switch__track" aria-hidden="true"></span>
                                </label>
                            </form>
                        </td>
                        <td class="ss-act"><x-ui.icon-link icon="pencil" :href="route('statuses.edit', $s)" :label="'Edit '.$s->name" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection
