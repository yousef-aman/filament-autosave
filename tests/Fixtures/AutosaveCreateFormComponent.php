<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

/**
 * A real Livewire component backed by a real Filament Schema, used to exercise
 * the autosave traits against genuine form dehydration (not a hand-rolled fake).
 */
class AutosaveCreateFormComponent extends Component implements HasSchemas
{
    use HasAutosaveForCreate;
    use InteractsWithSchemas;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
        $this->mountHasAutosaveForCreate();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title'),
                TextInput::make('code')
                    ->dehydrateStateUsing(fn ($state) => strtoupper((string) $state)),
                TextInput::make('secret')
                    ->dehydrated(false),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div></div>
        BLADE;
    }
}
