<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Support\Facades\Log;

trait HasAutosaveForCreate
{
    use HasAutosaveBase;

    public bool $autosaveHasDraft = false;

    public function mountHasAutosaveForCreate(): void
    {
        if (! $this->autosaveEnabled) {
            return;
        }

        $draft = AutosaveManager::restoreDraft($this->getAutosaveCacheKey());

        if ($draft) {
            $this->autosaveHasDraft = true;
        }

        $this->initializeAutosaveForCreate();
    }

    public function initializeAutosaveForCreate(): void
    {
        if (! $this->autosaveEnabled) {
            return;
        }

        $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
            AutosaveManager::excludeFields($this->getAutosaveData(), $this->getAutosaveExcept())
        );
    }

    public function create(bool $another = false): void
    {
        $parent = get_parent_class($this);

        if ($parent && method_exists($parent, 'create')) {
            parent::create($another);
            $this->clearDraftAfterCreate();
        }
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

            $data = $this->beforeAutosave($data);
            $validData = $this->validateAutosaveFields($data);

            $validData = array_filter($validData, fn ($value) => $value !== null && $value !== '' && $value !== []);

            if (empty($validData)) {
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            AutosaveManager::storeDraft(
                $this->getAutosaveCacheKey(),
                $validData,
                $this->getAutosaveCacheTtl(),
            );

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash($validData);
            $this->syncSavedDataHash();

            $this->dispatch('autosave-status',
                status: 'saved',
                timestamp: now()->format('g:i A'),
            );
        } catch (\Throwable $e) {
            Log::warning('Autosave draft failed: '.$e->getMessage());

            $this->dispatch('autosave-status', status: 'error');
        } finally {
            $this->isAutosaving = false;
        }
    }

    public function restoreDraft(): void
    {
        try {
            if (method_exists($this, 'authorizeAccess')) {
                $this->authorizeAccess();
            }

            $draft = AutosaveManager::restoreDraft($this->getAutosaveCacheKey());

            if (! $draft) {
                return;
            }

            $this->fillAutosaveData($draft);

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                AutosaveManager::excludeFields($this->getAutosaveData(), $this->getAutosaveExcept())
            );
            $this->syncSavedDataHash();
            $this->autosaveHasDraft = false;

            $this->dispatch('autosave-status', status: 'restored');
        } catch (\Throwable $e) {
            Log::warning('Draft restore failed: '.$e->getMessage());

            $this->dispatch('autosave-status', status: 'error');
        }
    }

    public function discardDraft(): void
    {
        AutosaveManager::clearDraft($this->getAutosaveCacheKey());
        $this->autosaveHasDraft = false;

        $this->dispatch('autosave-status', status: 'idle');
    }

    protected function clearDraftAfterCreate(): void
    {
        AutosaveManager::clearDraft($this->getAutosaveCacheKey());
    }

    protected function getAutosaveCacheKey(): string
    {
        return AutosaveManager::cacheKey(static::class);
    }

    protected function getAutosaveCacheTtl(): int
    {
        try {
            return AutosavePlugin::get()->getCacheTtl();
        } catch (\Throwable) {
            return config('filament-autosave.cache_ttl', 24);
        }
    }
}
