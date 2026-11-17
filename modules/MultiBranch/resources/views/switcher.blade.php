{{-- The branch switcher in the top bar, for staff who work in more than one branch. --}}
<form method="post" action="{{ route('multibranch.switch') }}" class="dms-branch-switch" style="display:flex;align-items:center;gap:6px;margin-inline-end:8px">
    @csrf
    <label for="dms-branch" style="font-size:12px;color:#5b6862">Branch</label>
    <x-filament::input.wrapper>
        <x-filament::input.select id="dms-branch" name="branch_id" onchange="this.form.submit()" aria-label="Working in branch">
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected($branch->id === $current->id)>{{ $branch->name }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
    <noscript><button type="submit">Change</button></noscript>
</form>
