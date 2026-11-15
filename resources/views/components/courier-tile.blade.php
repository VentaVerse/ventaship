@props(['courier', 'logo' => false])

@php $logoFile = $logo ? \App\Fulfillment\Couriers::logo($courier) : null; @endphp
@if($logoFile)
    <span {{ $attributes->merge(['class' => 'x-courier x-courier--logo x-courier--' . $courier]) }} aria-hidden="true"><img src="{{ asset($logoFile) }}" alt="" width="34" height="34" decoding="async"></span>
@else
    <span {{ $attributes->merge(['class' => 'x-courier x-courier--' . $courier]) }} aria-hidden="true">{{ \App\Fulfillment\Couriers::code($courier) }}</span>
@endif
