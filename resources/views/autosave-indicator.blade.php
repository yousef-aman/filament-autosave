@php
    $debounce = $debounce ?? 1500;
    $showTimestamp = $showTimestamp ?? true;
    $mode = $mode ?? 'edit';
@endphp

<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('autosave', 'yousefaman/filament-autosave') }}"
    x-data="autosave({ debounce: {{ $debounce }}, mode: '{{ $mode }}' })"
    x-show="status !== 'idle'"
    x-transition:enter="fi-autosave-enter"
    x-transition:leave="fi-autosave-leave"
    class="fi-autosave-indicator"
    x-cloak
>
    <template x-if="status === 'draft_available'">
        <span class="fi-autosave-draft">
            <x-filament::icon
                icon="heroicon-m-document-text"
                class="fi-autosave-icon"
            />
            <span>{{ __('filament-autosave::autosave.draft_available') }}</span>
            <button
                type="button"
                x-on:click="restore()"
                class="fi-autosave-restore"
            >
                {{ __('filament-autosave::autosave.restore') }}
            </button>
            <button
                type="button"
                x-on:click="discard()"
                class="fi-autosave-discard"
            >
                {{ __('filament-autosave::autosave.discard') }}
            </button>
        </span>
    </template>

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
            @if ($mode === 'edit')
                <button
                    type="button"
                    x-on:click="undo()"
                    x-show="$wire.autosaveCanUndo"
                    class="fi-autosave-undo"
                >
                    {{ __('filament-autosave::autosave.undo') }}
                </button>
            @endif
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

    <template x-if="status === 'restored'">
        <span class="fi-autosave-restored">
            <x-filament::icon
                icon="heroicon-m-arrow-path"
                class="fi-autosave-icon"
            />
            <span>{{ __('filament-autosave::autosave.restored') }}</span>
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
