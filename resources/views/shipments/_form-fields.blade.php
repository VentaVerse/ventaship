@foreach($fields as $key => $meta)
    @php
        $name = $prefix.'.'.$key;
        $id = \App\View\Components\Ui\Field::controlId($name);
    @endphp
    <x-ui.field :label="$meta['label']" :name="$name" :required="$meta['required'] ?? false" :class="$meta['class'] ?? ''">
        @if(($meta['type'] ?? '') === 'phone')
            <x-ui.phone :id="$id" name="{{ $prefix }}[{{ $key }}]" value="{{ old($name, $values[$key] ?? '') }}" :required="$meta['required'] ?? false" />
        @else
        <x-ui.input :type="$meta['type'] ?? 'text'" :id="$id" name="{{ $prefix }}[{{ $key }}]"
                    value="{{ old($name, $values[$key] ?? '') }}" maxlength="{{ $meta['max'] ?? 255 }}" :autocomplete="$meta['auto'] ?? 'off'"
                    :required="$meta['required'] ?? false" />
        @endif
    </x-ui.field>
@endforeach
