@foreach($fields as $key => $meta)
    @php
        $name = $prefix.'.'.$key;
        $id = 'f-'.\Illuminate\Support\Str::slug($name, '-');
    @endphp
    <div class="st-row">
        <x-ui.field :label="$meta['label']" :name="$name" :required="$meta['required'] ?? false">
            <x-ui.input :type="$meta['type'] ?? 'text'" :id="$id" name="{{ $prefix }}[{{ $key }}]" class="st-ctl {{ $meta['class'] ?? '' }}"
                        value="{{ old($name, $values[$key] ?? '') }}" maxlength="{{ $meta['max'] ?? 255 }}" autocomplete="off"
                        @required($meta['required'] ?? false) />
        </x-ui.field>
    </div>
@endforeach
