<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

trait HasAutosave
{
    use HasAutosaveBase;

    /** @var array<string, mixed> */
    public array $autosaveLastSnapshot = [];

    public function initializeAutosave(): void
    {
        if (! $this->autosaveEnabled) {
            return;
        }

        $this->autosaveSnapshotHash = AutosaveManager::snapshotHash($this->getAutosaveData());
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

            $except = $this->getAutosaveExcept();
            $data = AutosaveManager::excludeFields($this->getAutosaveData(), $except);

            $currentHash = AutosaveManager::snapshotHash($data);
            if ($currentHash === $this->autosaveSnapshotHash) {
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            $rawOldData = $this->getAutosaveOldData();
            $oldData = AutosaveManager::excludeFields($rawOldData, $except);
            $changedFields = AutosaveManager::getChangedFields($oldData, $data);

            if (empty($changedFields)) {
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            $changedFields = $this->beforeAutosave($changedFields);
            $validData = $this->validateAutosaveFields($changedFields);

            if (empty($validData)) {
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            $this->autosaveLastSnapshot = $rawOldData;

            $this->handleRecordUpdate($this->getRecord(), $validData);
            $this->getRecord()->refresh();

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                AutosaveManager::excludeFields($this->getAutosaveData(), $except)
            );
            $this->syncSavedDataHash();

            $this->afterAutosave($this->getRecord());

            $this->dispatch('autosave-status',
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
        if (empty($this->autosaveLastSnapshot)) {
            return;
        }

        try {
            if (method_exists($this, 'authorizeAccess')) {
                $this->authorizeAccess();
            }

            $this->handleRecordUpdate($this->getRecord(), $this->autosaveLastSnapshot);
            $this->getRecord()->refresh();

            $this->fillAutosaveData($this->autosaveLastSnapshot);

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                AutosaveManager::excludeFields($this->getAutosaveData(), $this->getAutosaveExcept())
            );
            $this->syncSavedDataHash();

            $this->autosaveLastSnapshot = [];

            $this->dispatch('autosave-status', status: 'undone');
        } catch (\Throwable $e) {
            Log::warning('Autosave undo failed: '.$e->getMessage());

            $this->dispatch('autosave-status', status: 'error');
        }
    }

    /** @return array<string, mixed> */
    protected function getAutosaveOldData(): array
    {
        return $this->getRecord()->fresh()->only(array_keys($this->getAutosaveData()));
    }

    protected function afterAutosave(Model $record): void
    {
        //
    }
}
