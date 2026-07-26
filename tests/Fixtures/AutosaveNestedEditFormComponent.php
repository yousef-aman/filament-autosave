<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use YousefAman\FilamentAutosave\HasAutosave;

/** Edit page whose schema nests fields under Group state paths and Repeaters. */
class AutosaveNestedEditFormComponent extends Component implements HasSchemas
{
    use HasAutosave;
    use InteractsWithSchemas;

    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $written = [];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title'),
                Group::make([
                    TextInput::make('name'),
                    Select::make('mode')->options(['fast' => 'Fast', 'slow' => 'Slow']),
                ])->statePath('settings'),
                Group::make([
                    TextInput::make('label'),
                    TextInput::make('api_key')->password(),
                ])->statePath('secrets'),
                Repeater::make('items')
                    ->schema([
                        TextInput::make('label'),
                        Select::make('kind')->options(['a' => 'A', 'b' => 'B']),
                    ]),
                Repeater::make('vault')
                    ->schema([
                        TextInput::make('label'),
                        TextInput::make('secret')->password(),
                    ]),
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

    /** @param array<string, mixed> $data */
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
