<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

trait HasAutosave
{
    protected bool $autosaveEnabled = true;

    protected int $autosaveDebounce = 0;

    protected array $autosaveExcept = [];

    protected bool $isAutosaving = false;

    public string $autosaveSnapshotHash = '';

    /** @var array<string, mixed> */
    public array $autosaveLastSnapshot = [];

    public function initializeAutosave(): void
    {
        if (! $this->autosaveEnabled) {
            return;
        }

        $this->autosaveSnapshotHash = AutosaveManager::snapshotHash($this->data ?? []);
    }

    public function getAutosaveDebounce(): int
    {
        if ($this->autosaveDebounce > 0) {
            return $this->autosaveDebounce;
        }

        try {
            return AutosavePlugin::get()->getDebounce();
        } catch (\Throwable) {
            return config('filament-autosave.debounce', 1500);
        }
    }

    public function getAutosaveExcept(): array
    {
        $pageExcept = $this->autosaveExcept;
        $configExcept = config('filament-autosave.except', []);

        try {
            $pluginExcept = AutosavePlugin::get()->getExcept();
        } catch (\Throwable) {
            $pluginExcept = [];
        }

        return array_values(array_unique(array_merge($configExcept, $pluginExcept, $pageExcept)));
    }

    public function isAutosaveEnabled(): bool
    {
        return $this->autosaveEnabled;
    }

    public function autosave(): void
    {
        if (! $this->autosaveEnabled || $this->isAutosaving) {
            return;
        }

        $this->isAutosaving = true;

        try {
            $this->authorizeAccess();

            $except = $this->getAutosaveExcept();
            $data = AutosaveManager::excludeFields($this->data ?? [], $except);

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
                AutosaveManager::excludeFields($this->data ?? [], $except)
            );
            $this->syncSavedDataHash();

            $this->afterAutosave($this->getRecord());

            $this->dispatch('autosave-status',
                status: 'saved',
                timestamp: now()->format('g:i A'),
            );
        } catch (\Throwable $e) {
            Log::warning('Autosave failed: ' . $e->getMessage());

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
            $this->authorizeAccess();

            $this->handleRecordUpdate($this->getRecord(), $this->autosaveLastSnapshot);
            $this->getRecord()->refresh();

            $this->data = array_merge($this->data, $this->autosaveLastSnapshot);

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                AutosaveManager::excludeFields($this->data ?? [], $this->getAutosaveExcept())
            );
            $this->syncSavedDataHash();

            $this->autosaveLastSnapshot = [];

            $this->dispatch('autosave-status', status: 'undone');
        } catch (\Throwable $e) {
            Log::warning('Autosave undo failed: ' . $e->getMessage());

            $this->dispatch('autosave-status', status: 'error');
        }
    }

    /** @return array<string, mixed> */
    protected function getAutosaveOldData(): array
    {
        return $this->getRecord()->fresh()->only(array_keys($this->data ?? []));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function validateAutosaveFields(array $fields): array
    {
        $allRules = $this->getAutosaveValidationRules();

        if (empty($allRules)) {
            return $fields;
        }

        $rules = array_intersect_key($allRules, $fields);

        if (empty($rules)) {
            return $fields;
        }

        $validator = Validator::make($fields, $rules);

        if ($validator->fails()) {
            $failedKeys = array_keys($validator->failed());

            return array_diff_key($fields, array_flip($failedKeys));
        }

        return $fields;
    }

    protected function syncSavedDataHash(): void
    {
        if (property_exists($this, 'savedDataHash')) {
            $this->savedDataHash = AutosaveManager::snapshotHash($this->data ?? []);
        }
    }

    /** @return array<string, mixed> */
    protected function getAutosaveValidationRules(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function beforeAutosave(array $data): array
    {
        return $data;
    }

    protected function afterAutosave(Model $record): void
    {
        //
    }
}
