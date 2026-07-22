<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait HasAutosave
{
    use HasAutosaveBase;

    public bool $autosaveCanUndo = false;

    public function mountHasAutosave(): void
    {
        if (! $this->autosaveEnabled) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();

        $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
            $this->prepareAutosavePayload($this->getAutosaveData())
        );
    }

    /**
     * Edit pages write to the database, so persist dehydrated state.
     *
     * @return array<string, mixed>
     */
    protected function getAutosaveData(): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null) {
            return $this->stripFileUploads($this->data ?? []);
        }

        return $this->stripFileUploads($this->dehydrateAutosaveState($form));
    }

    public function autosave(): void
    {
        $this->performAutosave(function (array $data): bool {
            if (method_exists($this, 'mutateFormDataBeforeSave')) {
                $data = $this->mutateFormDataBeforeSave($data);
            }

            $data = $this->dropBlankRequiredAutosaveFields($data);

            if (empty($data)) {
                return false;
            }

            $this->autosaveCanUndo = $this->storeUndoSnapshot(array_keys($data));

            $this->autosaveWithinTransaction(
                fn () => $this->handleRecordUpdate($this->getRecord(), $data)
            );

            $this->getRecord()->refresh();

            $this->afterAutosave($this->getRecord());

            return true;
        });
    }

    public function undoAutosave(): void
    {
        try {
            if (method_exists($this, 'authorizeAccess')) {
                $this->authorizeAccess();
            }

            $snapshot = Cache::get($this->getUndoCacheKey());

            if (! is_array($snapshot) || empty($snapshot)) {
                $this->autosaveCanUndo = false;
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            $this->autosaveWithinTransaction(
                fn () => $this->handleRecordUpdate($this->getRecord(), $snapshot)
            );

            $this->getRecord()->refresh();

            method_exists($this, 'fillForm')
                ? $this->fillForm()
                : $this->fillAutosaveData($snapshot);

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                $this->prepareAutosavePayload($this->getAutosaveData())
            );

            Cache::forget($this->getUndoCacheKey());
            $this->autosaveCanUndo = false;

            $this->dispatch('autosave-status', status: 'undone');
        } catch (\Throwable $e) {
            Log::warning('Autosave undo failed', ['exception' => $e::class]);

            $this->dispatch('autosave-status', status: 'error');
        }
    }

    /**
     * @param  array<string>  $fieldKeys
     * @return bool whether a snapshot was stored (i.e. undo is possible)
     */
    protected function storeUndoSnapshot(array $fieldKeys): bool
    {
        $record = $this->getRecord();

        if (! $record || empty($fieldKeys)) {
            return false;
        }

        // Restrict to real stored columns: a field name that collides with a
        // relationship or accessor must not trigger a lazy load or snapshot a
        // non-column value that would break the undo write.
        if (method_exists($record, 'getAttributes')) {
            $fieldKeys = array_values(array_intersect($fieldKeys, array_keys($record->getAttributes())));

            if (empty($fieldKeys)) {
                return false;
            }
        }

        $previous = $this->normalizeUndoSnapshot($record->only($fieldKeys));

        if (empty($previous)) {
            return false;
        }

        Cache::put(
            $this->getUndoCacheKey(),
            $previous,
            now()->addMinutes($this->getUndoTtlMinutes()),
        );

        return true;
    }

    /**
     * JSON round-trip so Carbon/Enums become scalars and JSON-cast
     * columns stay arrays (prevents Eloquent double-encoding on undo).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeUndoSnapshot(array $data): array
    {
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return json_decode($encoded, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function getUndoCacheKey(): string
    {
        $recordKey = $this->getRecord()?->getKey() ?? 'default';

        return 'filament-autosave:undo:'.AutosaveManager::currentScope().':'.static::class.':'.$recordKey;
    }

    protected function getUndoTtlMinutes(): int
    {
        return 30;
    }

    protected function afterAutosave(object $record): void
    {
        //
    }

    // Wrap the write in a transaction like Filament's save() so a mid-write
    // failure rolls back; direct write when the page lacks the helpers.
    protected function autosaveWithinTransaction(callable $write): void
    {
        if (! method_exists($this, 'beginDatabaseTransaction')
            || ! method_exists($this, 'commitDatabaseTransaction')
            || ! method_exists($this, 'rollBackDatabaseTransaction')
        ) {
            $write();

            return;
        }

        try {
            $this->beginDatabaseTransaction();
            $write();
            $this->commitDatabaseTransaction();
        } catch (\Throwable $e) {
            $this->rollBackDatabaseTransaction();

            throw $e;
        }
    }

    /**
     * Never write a blank value to a required field — it would violate a NOT NULL
     * column and fail the whole save. Autosave keeps the last valid value until
     * the user fills the field and submits.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function dropBlankRequiredAutosaveFields(array $data): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null || ! method_exists($form, 'getFlatFields')) {
            return $data;
        }

        foreach ($form->getFlatFields(withHidden: true) as $key => $field) {
            $name = explode('.', (string) $key, 2)[0];

            if (array_key_exists($name, $data)
                && blank($data[$name])
                && method_exists($field, 'isRequired')
                && $field->isRequired()
            ) {
                unset($data[$name]);
            }
        }

        return $data;
    }
}
