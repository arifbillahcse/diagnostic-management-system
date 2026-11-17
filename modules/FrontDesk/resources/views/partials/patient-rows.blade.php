{{-- The dropdown of patients under the find box or the mobile box. $rows, $list ("search" or "mobile"). --}}
<ul class="dd" x-show="open.{{ $list }}" x-cloak x-ref="{{ $list }}List" role="listbox">
    @foreach ($rows as $i => $row)
        <li role="option"
            data-id="{{ $row['id'] }}"
            :class="{ on: active.{{ $list }} === {{ $i }} }"
            @mouseenter="active.{{ $list }} = {{ $i }}"
            @mousedown.prevent="$wire.usePatient({{ $row['id'] }})">
            <span class="pid">{{ $row['code'] }}</span>
            <b>{{ $row['name'] }}</b>
            <span class="meta">{{ $row['age'] }} · {{ $row['sex'] }} · {{ $row['phone'] }}</span>
        </li>
    @endforeach
</ul>
