@foreach($fields as $key => $meta)
    @php
        $name = $prefix.'.'.$key;
        $id = 'f-'.\Illuminate\Support\Str::slug($name, '-');
    @endphp
    <x-ui.field :label="$meta['label']" :name="$name" :required="$meta['required'] ?? false" :class="$meta['class'] ?? ''">
        <x-ui.input :type="$meta['type'] ?? 'text'" :id="$id" name="{{ $prefix }}[{{ $key }}]"
                    value="{{ old($name, $values[$key] ?? '') }}" maxlength="{{ $meta['max'] ?? 255 }}" :autocomplete="$meta['auto'] ?? 'off'"
                    :required="$meta['required'] ?? false" />
    </x-ui.field>
@endforeach
