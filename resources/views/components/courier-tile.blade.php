@props(['courier'])

<span {{ $attributes->merge(['class' => 'x-courier x-courier--' . $courier]) }} aria-hidden="true">{{ \App\Fulfillment\Couriers::code($courier) }}</span>
