@props(['wrapClass' => ''])
@aware(['fieldControlId' => null, 'fieldMessage' => null])

<div class="x-select-wrap {{ $wrapClass }}">
    <select {{ \App\View\Components\Ui\Field::control($attributes, $fieldControlId, $fieldMessage, 'x-input') }}>{{ $slot }}</select>
    <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
</div>
