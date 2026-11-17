<x-filament-panels::page>
    <x-filament::section>
        <p class="dms-muted">{{ $this->gatewayNote }}</p>
    </x-filament::section>

    <form wire:submit="save" class="dms-stack">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Save settings</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
