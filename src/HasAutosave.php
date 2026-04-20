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

    public function autosave(): void
    {
        if (! $this->autosaveEnabled || $this->isAutosaving) {
            return;
        }

        $this->isAutosaving = true;

        try {
            if (method_exists($this, 'authorizeAccess')) {
                $this->authorizeAccess();
            }

            $data = $this->prepareAutosavePayload($this->getAutosaveData());

            if (AutosaveManager::snapshotHash($data) === $this->autosaveSnapshotHash) {
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            $data = $this->validateAutosaveFields($this->beforeAutosave($data));

            if (method_exists($this, 'mutateFormDataBeforeSave')) {
                $data = $this->mutateFormDataBeforeSave($data);
            }

            if (empty($data)) {
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            $this->storeUndoSnapshot(array_keys($data));

            $this->handleRecordUpdate($this->getRecord(), $data);
            $this->getRecord()->refresh();

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                $this->prepareAutosavePayload($this->getAutosaveData())
            );
            $this->autosaveCanUndo = true;

            $this->afterAutosave($this->getRecord());

            $this->dispatch(
                'autosave-status',
                status: 'saved',
                timestamp: now()->format('g:i A'),
            );
        } catch (\Throwable $e) {
            Log::warning('Autosave failed: '.$e->getMessage());

            $this->dispatch('autosave-status', status: 'error');
        } finally {
            $this->isAutosaving = false;
        }
    }

    public function undoAutosave(): void
    {
        try {
            if (method_exists($this, 'authorizeAccess')) {
                $this->authorizeAccess();
            }

            $snapshot = Cache::get($this->getUndoCacheKey());

            if (! is_array($snapshot) || empty($snapshot) || $this->snapshotHasUnsafeValues($snapshot)) {
                Cache::forget($this->getUndoCacheKey());
                $this->autosaveCanUndo = false;

                return;
            }

            $this->handleRecordUpdate($this->getRecord(), $snapshot);
            $this->getRecord()->refresh();

            // Re-fill via Filament's flow so `mutateFormDataBeforeFill`
            // can rehydrate virtual/derived form fields.
            if (method_exists($this, 'fillForm')) {
                $this->fillForm();
            } else {
                $this->fillAutosaveData($snapshot);
            }

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
     * Capture the current DB values for the fields about to be overwritten,
     * so `undoAutosave()` can restore them. Stored server-side only.
     *
     * @param  array<string>  $fieldKeys
     */
    protected function storeUndoSnapshot(array $fieldKeys): void
    {
        $record = $this->getRecord();

        if (! $record || empty($fieldKeys) || ! method_exists($record, 'only')) {
            return;
        }

        $previous = $this->normalizeUndoSnapshot($record->only($fieldKeys));

        if (empty($previous)) {
            return;
        }

        Cache::put(
            $this->getUndoCacheKey(),
            $previous,
            now()->addMinutes($this->getUndoTtlMinutes()),
        );
    }

    /**
     * Round-trip casted model values through JSON so they end up as
     * plain scalars/arrays. This keeps JSON-cast columns as arrays
     * (so Eloquent doesn't double-encode on undo) while converting
     * Carbon and other JsonSerializable objects to safe strings.
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
        $owner = auth()->id() ?? session()->getId();
        $record = $this->getRecord();
        $recordKey = (is_object($record) && method_exists($record, 'getKey'))
            ? $record->getKey()
            : 'default';

        return 'filament-autosave:undo:'.$owner.':'.static::class.':'.$recordKey;
    }

    protected function getUndoTtlMinutes(): int
    {
        return 30;
    }

    /**
     * Guard against snapshots that deserialized as objects (e.g. stale Carbon
     * instances persisted by an older version of the plugin). Passing these
     * back to Eloquent triggers errors like `preg_match` on `__PHP_Incomplete_Class`.
     */
    protected function snapshotHasUnsafeValues(array $snapshot): bool
    {
        foreach ($snapshot as $value) {
            if (is_object($value)) {
                return true;
            }

            if (is_array($value) && $this->snapshotHasUnsafeValues($value)) {
                return true;
            }
        }

        return false;
    }

    protected function afterAutosave(object $record): void
    {
        //
    }
}
