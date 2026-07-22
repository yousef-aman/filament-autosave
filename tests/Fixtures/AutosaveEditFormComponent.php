<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use YousefAman\FilamentAutosave\HasAutosave;

/**
 * A real Livewire component backed by a real Filament Schema, wired like an Edit
 * page: it captures whatever the autosave trait hands to handleRecordUpdate() so
 * tests can assert the *dehydrated* payload actually written to the "database".
 */
class AutosaveEditFormComponent extends Component implements HasSchemas
{
    use HasAutosave;
    use InteractsWithSchemas;

    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $written = [];

    public function mount(): void
    {
        $this->form->fill();
        $this->mountHasAutosave();
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
                TextInput::make('slug')
                    ->required(),
            ])
            ->statePath('data');
    }

    public function getRecord(): object
    {
        return new class
        {
            public bool $exists = true;

            /** @param array<int, string> $keys @return array<string, mixed> */
            public function only(array $keys): array
            {
                return [];
            }

            public function getKey(): int
            {
                return 1;
            }

            public function refresh(): static
            {
                return $this;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handleRecordUpdate(object $record, array $data): object
    {
        $this->written = $data;

        return $record;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div></div>
            BLADE;
    }
}
