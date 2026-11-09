<section class="x-panel api-panel">
    <div class="api-bar">
        <nav class="api-seg" aria-label="Show">
            @foreach(['all' => 'All', 'failed' => 'Failed', 'api' => 'API calls', 'webhooks' => 'Webhooks'] as $value => $text)
                <x-ui.button size="sm" :variant="$type === $value ? 'primary' : 'default'" :href="route('developers.index', ['tab' => 'logs', 'type' => $value])" :aria-current="$type === $value ? 'true' : null">
                    {{ $text }}@if($value === 'failed')<span class="api-seg__count x-num">{{ number_format($failedCount) }}</span>@endif
                </x-ui.button>
            @endforeach
        </nav>
    </div>

    @if($logs->isEmpty())
        <x-ui.empty title="No activity" />
    @else
        <table class="api-table api-table--logs">
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">Type</th>
                    <th scope="col">Detail</th>
                    @if($showKey)<th scope="col">Key</th>@endif
                    <th scope="col">Result</th>
                    <th scope="col"><span class="x-sr">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $row)
                    <tr>
                        <td class="x-num api-sub" data-label="Time">{{ $row['at']?->format('j M H:i:s') }}</td>
                        <td data-label="Type">{{ $row['kind'] === 'api' ? 'API call' : 'Webhook' }}</td>
                        <td class="api-detail" data-label="Detail">
                            @if($row['kind'] === 'api')
                                <span class="api-mono">{{ $row['call']->method }} {{ $row['call']->path }}</span>
                            @else
                                <span class="api-mono">{{ $row['event']->type }}</span>
                                @if($row['shipment_ref'] !== null)
                                    <span class="api-sub">·</span> <a class="api-mono" href="{{ route('shipments.show', $row['event']->shipment) }}">{{ $row['shipment_ref'] }}</a>
                                @endif
                            @endif
                            @if($row['error'] !== null)
                                <p class="api-why">{{ $row['error'] }}</p>
                            @endif
                        </td>
                        @if($showKey)<td class="api-sub" data-label="Key">{{ $row['app'] ?? 'None' }}</td>@endif
                        <td data-label="Result"><x-ui.badge :tone="$row['badge'][1]">{{ $row['badge'][0] }}</x-ui.badge></td>
                        <td class="api-act">
                            @if($row['resendable'] ?? false)
                                <x-ui.button size="sm" type="submit" :form="'resend-' . $row['event']->public_id" :aria-label="'Resend ' . $row['event']->type">Resend</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <x-ui.pager :paginator="$logs" class="api-pager" />
    @endif
</section>

@push('forms')
    @foreach($logs as $row)
        @if($row['resendable'] ?? false)
            <form id="resend-{{ $row['event']->public_id }}" method="POST" action="{{ route('developers.events.resend', $row['event']) }}">@csrf</form>
        @endif
    @endforeach
@endpush
