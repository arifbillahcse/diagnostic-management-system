<x-filament-panels::page>
    <x-filament::section>
        <div class="dms-stack">
            <p class="dms-muted">
                Your data is yours. Download everything as one zip of CSV files, or one table at a time. CSV files open in Excel.
                The files contain patient information, so keep them somewhere safe. Every download is recorded in the audit log.
            </p>
            <div>
                <x-filament::button tag="a" :href="route('export.all')">Download everything (zip)</x-filament::button>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="One table at a time">
        <div class="dms-scroll">
            <table class="dms-table">
                <thead><tr><th>Data</th><th class="dms-num">Rows (about)</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->datasets as $dataset)
                        <tr>
                            <td>{{ $dataset['label'] }}</td>
                            <td class="dms-num">{{ number_format($dataset['rows']) }}</td>
                            <td class="dms-num"><a class="dms-link" href="{{ route('export.table', $dataset['table']) }}">Download CSV</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
