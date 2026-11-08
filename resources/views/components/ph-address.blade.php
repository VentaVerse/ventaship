@props(['prefix', 'values' => []])

@php
    use Illuminate\Support\Str;

    $ph = app(\App\Support\PhAddress::class);
    $dot = str_replace(['[', ']'], ['.', ''], $prefix);
    $slug = Str::slug($dot, '-');
    $v = is_array($values) ? $values : [];
    $codes = (array) ($v['codes'] ?? []);
    $text = fn (string $k): string => trim((string) ($v[$k] ?? ''));

    // Resolve each level by code, else by name within its parent. A level that cannot be resolved stays free text,
    // and so does everything below it (no parent code to list from).
    $sel = ['state' => '', 'city' => '', 'district' => '', 'barangay' => ''];
    $free = ['state' => false, 'city' => false, 'district' => false, 'barangay' => false];
    $lists = ['cities' => [], 'districts' => [], 'barangays' => []];
    $broken = false;

    $pick = function (string $level, string $parent, string $code, string $name) use ($ph): ?array {
        if ($code !== '') {
            $row = $ph->find($level, $code);
            if ($row !== null && ($level === 'provinces' || $row['parent'] === $parent)) {
                return $row;
            }
        }

        return $ph->matchName($level, $parent, $name);
    };

    $state = $pick('provinces', '', (string) ($codes['state'] ?? ''), $text('state'));
    if ($state) {
        $sel['state'] = $state['code'];
        $lists['cities'] = $ph->cities($state['code']);
        $city = $pick('cities', $state['code'], (string) ($codes['city'] ?? ''), $text('city'));
    } else {
        $city = null;
        $free['state'] = $text('state') !== '';
    }
    if ($city) {
        $sel['city'] = $city['code'];
        $lists['districts'] = $ph->districts($city['code']);
        $parent = $city['code'];
        if ($lists['districts'] !== []) {
            $district = $pick('districts', $city['code'], '', $text('district'));
            $parent = $district['code'] ?? null;
            if ($district) {
                $sel['district'] = $district['code'];
            } elseif ($text('district') !== '') {
                $free['district'] = true;
            }
        }
        if ($parent !== null) {
            $lists['barangays'] = $ph->barangays($parent);
            $brgy = $pick('barangays', $parent, (string) ($codes['barangay'] ?? ''), $text('barangay'));
            if ($brgy) {
                $sel['barangay'] = $brgy['code'];
            } else {
                $free['barangay'] = $text('barangay') !== '';
            }
        } else {
            $free['barangay'] = $text('barangay') !== '';
        }
    } else {
        $free['city'] = $text('city') !== '';
        $free['district'] = $text('district') !== '';
        $free['barangay'] = $text('barangay') !== '';
    }

    $config = [
        'url' => url('/ph'),
        'sel' => $sel,
        'free' => $free,
        'text' => ['state' => $text('state'), 'city' => $text('city'), 'district' => $text('district'), 'barangay' => $text('barangay')],
        'postcode' => (string) ($v['postcode'] ?? ''),
        'lists' => ['provinces' => $ph->provinces()] + $lists,
    ];
    $fields = [
        'state' => ['Province', 'state'],
        'city' => ['City or municipality', 'city'],
        'district' => ['District', 'district'],
        'barangay' => ['Barangay', 'barangay'],
    ];
@endphp

<div class="ph-addr" x-data="phAddress(@js($config))">
    @foreach($fields as $level => [$label, $key])
        @php $id = 'ph-'.$slug.'-'.$level; @endphp
        <x-ui.field :label="$label" :for="$id" :error-key="$dot.'.'.$level" class="ph-addr__{{ $level }}" x-show="show('{{ $level }}')" x-cloak>
            <div x-show="!isFree('{{ $level }}')">
                <x-ui.select :id="$id" x-model="sel.{{ $level }}" x-on:change="changed('{{ $level }}')" x-bind:disabled="!enabled('{{ $level }}')">
                    <option value="">Select</option>
                    <template x-for="o in options('{{ $level }}')" :key="o.code">
                        <option :value="o.code" :selected="o.code === sel.{{ $level }}" x-text="o.name"></option>
                    </template>
                </x-ui.select>
            </div>
            <div class="ph-addr__free" x-show="isFree('{{ $level }}')">
                <x-ui.input :id="$id.'-text'" :aria-label="$label" x-model="text.{{ $level }}" maxlength="255" autocomplete="off" />
                <x-ui.button size="sm" x-show="canChoose('{{ $level }}')" x-on:click="choose('{{ $level }}')">Choose from list</x-ui.button>
            </div>
        </x-ui.field>
    @endforeach

    <x-ui.field label="Postcode" :for="'ph-'.$slug.'-postcode'" :error-key="$dot.'.postcode'" class="ph-addr__postcode">
        <x-ui.input :id="'ph-'.$slug.'-postcode'" :name="$prefix.'[postcode]'" :value="$config['postcode']" x-model="postcode"
                    maxlength="16" inputmode="numeric" autocomplete="postal-code" />
    </x-ui.field>

    <input type="hidden" name="{{ $prefix }}[state]" x-bind:value="name('state')" value="{{ $text('state') }}">
    <input type="hidden" name="{{ $prefix }}[city]" x-bind:value="name('city')" value="{{ $text('city') }}">
    <input type="hidden" name="{{ $prefix }}[district]" x-bind:value="name('district')" value="{{ $text('district') }}">
    <input type="hidden" name="{{ $prefix }}[barangay]" x-bind:value="name('barangay')" value="{{ $text('barangay') }}">
    <input type="hidden" name="{{ $prefix }}[codes][state]" x-bind:value="code('state')" value="{{ $sel['state'] }}">
    <input type="hidden" name="{{ $prefix }}[codes][city]" x-bind:value="code('city')" value="{{ $sel['city'] }}">
    <input type="hidden" name="{{ $prefix }}[codes][barangay]" x-bind:value="code('barangay')" value="{{ $sel['barangay'] }}">
</div>
