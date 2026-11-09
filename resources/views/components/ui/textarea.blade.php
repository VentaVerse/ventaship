@props(['value' => ''])
@aware(['fieldControlId' => null, 'fieldMessage' => null])

<textarea {{ \App\View\Components\Ui\Field::control($attributes, $fieldControlId, $fieldMessage, 'x-input') }}>{{ $value }}</textarea>
