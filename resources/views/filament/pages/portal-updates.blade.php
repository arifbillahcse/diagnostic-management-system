<x-filament-panels::page>
    <x-filament::section>
        <div class="dms-stack">
            <p class="dms-muted">
                After the software is updated on the server, its database may need new columns or tables. They are listed here.
                Press <strong>Apply updates</strong> to make them. This is a temporary page for the trial period.
            </p>
            <p class="dms-muted">
                It updates the platform database and this clinic's database. Another clinic on the same server is updated
                with <code>php artisan clinic:migrate</code>.
            </p>
        </div>
    </x-filament::section>

    @if ($this->platformPending === [] && $this->clinicPending === [])
        <x-filament::section>
            <p>Everything is up to date. There is nothing to apply.</p>
        </x-filament::section>
    @else
        @foreach (['Platform database' => $this->platformPending, 'This clinic\'s database' => $this->clinicPending] as $heading => $pending)
            @if ($pending !== [])
                <x-filament::section :heading="$heading">
                    <div class="dms-scroll">
                        <table class="dms-table">
                            <thead><tr><th>Update</th><th>File</th></tr></thead>
                            <tbody>
                                @foreach ($pending as $migration)
                                    <tr><td>{{ $this->label($migration) }}</td><td class="dms-muted">{{ $migration }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @endforeach
    @endif
</x-filament-panels::page>
