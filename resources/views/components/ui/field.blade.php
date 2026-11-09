<div {{ $attributes->merge(['class' => 'fm-field'.($wide ? ' fm-field--wide' : '').($fieldMessage !== null ? ' fm-field--invalid' : '')]) }}>
    @if($label)
        <label class="fm-label" @if($fieldControlId) for="{{ $fieldControlId }}" @endif>
            {{ $label }}
            @if($required)<span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if($fieldMessage !== null)<div class="fm-error" @if($fieldControlId) id="{{ $fieldControlId }}-error" @endif>{{ $fieldMessage }}</div>@endif
</div>
