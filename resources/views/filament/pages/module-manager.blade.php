<x-filament-panels::page>
    <p class="dms-muted">
        Switch off anything this clinic does not use. Switching a module off hides its screens and keeps all of its data.
    </p>

    @php
        $plan = app(\App\Core\Tenancy\PlanLimits::class)->plan();
    @endphp
    @if ($plan)
        <p class="dms-small">Your plan: <strong>{{ $plan->name }}</strong>@if ($plan->description) · {{ $plan->description }}@endif · {{ $plan->branchLimitLabel() }}. Modules marked "Not in your plan" need a plan change; contact your provider.</p>
    @endif

    @if (count($this->modules) === 0)
        <x-filament::section>No modules are installed in this system yet.</x-filament::section>
    @endif

    <div class="dms-module-grid">
        @foreach ($this->modules as $row)
            @php
                $manifest = $row['manifest'];
                $enabled = $row['status'] === 'enabled';
            @endphp

            <x-filament::section>
                <div class="dms-module">
                    <div class="dms-module-head">
                        <h2 class="dms-module-name">{{ $manifest->name }}</h2>

                        @if ($enabled)
                            <x-filament::badge color="success">On</x-filament::badge>
                        @elseif (! $row['entitled'])
                            <x-filament::badge color="warning">Not in your plan</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">Off</x-filament::badge>
                        @endif
                    </div>

                    <p class="dms-muted">{{ $manifest->description }}</p>

                    <p class="dms-small">
                        Version {{ $manifest->version }}
                        @if ($manifest->requires !== [])
                            &middot; Needs: {{ implode(', ', $manifest->requires) }}
                        @endif
                    </p>

                    @if ($enabled && $row['dependents'] !== [])
                        <p class="dms-small">In use by: {{ implode(', ', $row['dependents']) }}. Switch those off first.</p>
                    @elseif (! $enabled && $row['entitled'] && $row['missing'] !== [])
                        <p class="dms-small">Switch on first: {{ implode(', ', $row['missing']) }}.</p>
                    @endif

                    <div>
                        @if ($enabled)
                            <x-filament::button
                                color="gray"
                                wire:click="disable('{{ $manifest->key }}')"
                                wire:confirm="Switch off {{ $manifest->name }}? Its screens will be hidden. No data is deleted."
                                :disabled="$row['dependents'] !== []"
                            >
                                Switch off
                            </x-filament::button>
                        @else
                            <x-filament::button
                                wire:click="enable('{{ $manifest->key }}')"
                                :disabled="! $row['entitled'] || $row['missing'] !== []"
                            >
                                Switch on
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
