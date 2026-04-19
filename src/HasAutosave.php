<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Support\Facades\Log;

trait HasAutosave
{
    use HasAutosaveBase;

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

            if (empty($data)) {
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            $this->handleRecordUpdate($this->getRecord(), $data);
            $this->getRecord()->refresh();

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                $this->prepareAutosavePayload($this->getAutosaveData())
            );

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

    protected function afterAutosave(object $record): void
    {
        //
    }
}
