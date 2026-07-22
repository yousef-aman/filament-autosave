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

            if (empty($data)) {
                return false;
            }

            $this->autosaveCanUndo = $this->storeUndoSnapshot(array_keys($data));
            $this->handleRecordUpdate($this->getRecord(), $data);
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

                return;
            }

            $this->handleRecordUpdate($this->getRecord(), $snapshot);
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
            Log::warning('Autosave undo failed: '.$e->getMessage());

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
}
