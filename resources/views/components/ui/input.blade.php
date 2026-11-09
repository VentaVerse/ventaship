@props(['type' => 'text'])
@aware(['fieldControlId' => null, 'fieldMessage' => null])

<input type="{{ $type }}" {{ \App\View\Components\Ui\Field::control($attributes, $fieldControlId, $fieldMessage, 'x-input') }}>
