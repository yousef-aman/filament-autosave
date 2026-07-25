<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

trait HasAutosave
{
    use HasAutosaveBase;

    #[Locked]
    public bool $autosaveCanUndo = false;

    public function mountHasAutosave(): void
    {
        if (! $this->initializeAutosaveState()) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();

        $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
            $this->prepareAutosavePayload($this->getAutosaveData())
        );
    }

    /** @return array<string, mixed> */
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

            $this->rememberAutosavedData();

            $this->afterAutosave($this->getRecord());

            return true;
        });
    }

    // Re-baseline Filament's unsaved-changes hash like save() does, so
    // ->unsavedChangesAlerts() doesn't warn about already-written changes.
    protected function rememberAutosavedData(): void
    {
        if (method_exists($this, 'rememberData')) {
            $this->rememberData();
        }
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

            $this->rememberAutosavedData();

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

        // Real columns only: a relationship/accessor name would break the undo write.
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
     * JSON round-trip: scalarises Carbon/Enums without double-encoding JSON casts.
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

    // Transaction like Filament's save(); direct write if the page lacks the helpers.
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
     * A blank required field would violate NOT NULL and fail the whole write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function dropBlankRequiredAutosaveFields(array $data): array
    {
        foreach ($this->getAutosaveFields() as $name => $field) {
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
