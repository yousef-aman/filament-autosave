@php
    $debounce = $debounce ?? 1500;
    $enabled = $enabled ?? true;
    $showTimestamp = $showTimestamp ?? true;
@endphp

<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('autosave', 'yousefaman/filament-autosave') }}"
    x-data="autosave({ debounce: {{ $debounce }}, enabled: {{ $enabled ? 'true' : 'false' }} })"
    x-show="status !== 'idle'"
    x-transition:enter="fi-autosave-enter"
    x-transition:leave="fi-autosave-leave"
    class="fi-autosave-indicator"
    x-cloak
>
    <template x-if="status === 'unsaved'">
        <span class="fi-autosave-unsaved">
            <x-filament::icon
                icon="heroicon-m-pencil-square"
                class="fi-autosave-icon"
            />
            <span>{{ __('filament-autosave::autosave.unsaved') }}</span>
        </span>
    </template>

    <template x-if="status === 'saving'">
        <span class="fi-autosave-saving">
            <x-filament::loading-indicator class="fi-autosave-icon" />
            <span>{{ __('filament-autosave::autosave.saving') }}</span>
        </span>
    </template>

    <template x-if="status === 'saved'">
        <span class="fi-autosave-saved">
            <x-filament::icon
                icon="heroicon-m-check-circle"
                class="fi-autosave-icon"
            />
            @if($showTimestamp)
                <span x-text="'{{ __('filament-autosave::autosave.saved_at') }} ' + timestamp"></span>
            @else
                <span>{{ __('filament-autosave::autosave.saved') }}</span>
            @endif
            <button
                type="button"
                x-on:click="undo()"
                class="fi-autosave-undo"
            >
                {{ __('filament-autosave::autosave.undo') }}
            </button>
        </span>
    </template>

    <template x-if="status === 'undone'">
        <span class="fi-autosave-undone">
            <x-filament::icon
                icon="heroicon-m-arrow-uturn-left"
                class="fi-autosave-icon"
            />
            <span>{{ __('filament-autosave::autosave.undone') }}</span>
        </span>
    </template>

    <template x-if="status === 'error'">
        <span class="fi-autosave-error">
            <x-filament::icon
                icon="heroicon-m-x-circle"
                class="fi-autosave-icon"
            />
            <span>{{ __('filament-autosave::autosave.error') }}</span>
        </span>
    </template>
</div>
