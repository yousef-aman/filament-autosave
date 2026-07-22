<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal stand-in for Filament's CreateRecord that reproduces the behaviour the
 * autosave trait's create() override relies on:
 *
 *  - on a successful create, handleRecordCreation() is called *before* any
 *    cleanup (exactly like Filament);
 *  - on "create & create another" the record is then anonymised
 *    ($this->record = null), so getRecord() can no longer confirm the create.
 */
class FakeCreateRecordBase
{
    public ?Model $record = null;

    /** Toggle to simulate a validation/authorization Halt (no record created). */
    public bool $shouldHalt = false;

    public function create(bool $another = false): void
    {
        if ($this->shouldHalt) {
            return;
        }

        $this->record = $this->handleRecordCreation(['title' => 'created']);

        if ($another) {
            // Filament anonymises the record when creating another.
            $this->record = null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = new class extends Model
        {
            protected $guarded = [];
        };

        $record->exists = true;
        $record->setAttribute($record->getKeyName(), 1);

        return $record;
    }

    public function getRecord(): ?Model
    {
        return $this->record;
    }
}
