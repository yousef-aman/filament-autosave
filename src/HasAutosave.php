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

            $data = $this->dropIncompleteAutosaveContainers(
                $this->dropBlankRequiredAutosaveFields($data)
            );

            if (empty($data)) {
                return false;
            }

            $this->autosaveCanUndo = $this->storeUndoSnapshot(array_keys($data));

            $this->autosaveWithinTransaction(
                fn () => $this->handleRecordUpdate($this->getRecord(), $data)
            );

            $this->getRecord()->refresh();

            $this->rememberAutosavedData($data);

            $this->afterAutosave($this->getRecord());

            return true;
        });
    }

    /**
     * Re-baselines Filament's unsaved-changes hash like save() does. rememberData()
     * hashes the whole form state, not the payload, so it may only run when the
     * write covered every filled field — otherwise a deliberately skipped field
     * would be reported as saved and lost on navigation.
     *
     * @param  array<string, mixed>|null  $written  null re-baselines unconditionally
     */
    protected function rememberAutosavedData(?array $written = null): void
    {
        if (! method_exists($this, 'rememberData')) {
            return;
        }

        if ($written !== null && ! $this->autosaveWasLossless($written)) {
            return;
        }

        $this->rememberData();
    }

    /** @param  array<string, mixed>  $written */
    protected function autosaveWasLossless(array $written): bool
    {
        $state = $this->stripFileUploads($this->data ?? []);

        foreach (array_keys($this->getAutosaveFields()) as $path) {
            foreach ($this->matchAutosavePaths($state, $path) as $match) {
                if (blank(data_get($state, $match))) {
                    continue;
                }

                // Compare the pattern: dehydration renumbers row keys.
                if (! $this->autosavePathIsComplete($written, $path)) {
                    return false;
                }

                break;
            }
        }

        return true;
    }

    public function undoAutosave(): void
    {
        try {
            if (method_exists($this, 'authorizeAccess')) {
                $this->authorizeAccess();
            }

            // #[Locked] and only set by the autosave that created the snapshot, so
            // a stale entry can't be replayed from a later page load.
            $snapshot = $this->autosaveCanUndo ? Cache::get($this->getUndoCacheKey()) : null;

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
            return $data;
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
        foreach ($this->getAutosaveFields() as $path => $fields) {
            if (! $this->anyAutosaveField($fields, 'isRequired')) {
                continue;
            }

            foreach ($this->matchAutosavePaths($data, $path) as $match) {
                if (blank(data_get($data, $match))) {
                    $this->forgetAutosavePath($data, $match);
                }
            }
        }

        return $data;
    }

    /**
     * A nested state path (a Group's `statePath()`, a Repeater) is written as one
     * whole column value, so persisting it after a nested field was skipped would
     * destroy the stored value of that field. Skip the container instead.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function dropIncompleteAutosaveContainers(array $data): array
    {
        foreach (array_keys($this->getAutosaveFields()) as $path) {
            if (! str_contains($path, '.')) {
                continue;
            }

            $top = explode('.', $path, 2)[0];

            if (array_key_exists($top, $data) && ! $this->autosavePathIsComplete($data, $path)) {
                unset($data[$top]);
            }
        }

        return $data;
    }
}
