<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

use Filament\Resources\Events\RecordCreated;
use Illuminate\Support\Facades\Event;

/**
 * Minimal stand-in for Filament's CreateRecord that reproduces the behaviour the
 * autosave trait's create() override relies on:
 *
 *  - on a successful create the RecordCreated event is dispatched (as a string
 *    event + payload, exactly like Filament) *before* any cleanup;
 *  - on "create & create another" the record is then anonymised
 *    ($this->record = null), so getRecord() can no longer confirm the create.
 */
class FakeCreateRecordBase
{
    public ?object $record = null;

    /** Toggle to simulate a validation/authorization Halt (no record created). */
    public bool $shouldHalt = false;

    public function create(bool $another = false): void
    {
        if ($this->shouldHalt) {
            return;
        }

        $this->record = new class
        {
            public bool $exists = true;

            public function getKey(): int
            {
                return 1;
            }
        };

        Event::dispatch(RecordCreated::class, [
            'record' => $this->record,
            'data' => [],
            'page' => $this,
        ]);

        if ($another) {
            // Filament anonymises the record when creating another.
            $this->record = null;
        }
    }

    public function getRecord(): ?object
    {
        return $this->record;
    }
}
