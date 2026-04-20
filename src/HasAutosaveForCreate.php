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

        $this->autosaveHasDraft = AutosaveManager::restoreDraft($this->getAutosaveCacheKey()) !== null;

        $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
            $this->prepareAutosavePayload($this->getAutosaveData())
        );
    }

    public function autosave(): void
    {
        $this->performAutosave(function (array $data): bool {
            $payload = array_filter(
                $data,
                static fn ($value) => $value !== null && $value !== '' && $value !== [],
            );

            if (empty($payload)) {
                return false;
            }

            AutosaveManager::storeDraft(
                $this->getAutosaveCacheKey(),
                $payload,
                $this->getAutosaveCacheTtl(),
            );

            return true;
        });
    }

    public function restoreDraft(): void
    {
        try {
            if (method_exists($this, 'authorizeAccess')) {
                $this->authorizeAccess();
            }

            $draft = AutosaveManager::restoreDraft($this->getAutosaveCacheKey());

            if ($draft === null) {
                return;
            }

            $this->fillAutosaveData(
                AutosaveManager::excludeFields($this->stripFileUploads($draft), $this->getAutosaveExcept())
            );

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                $this->prepareAutosavePayload($this->getAutosaveData())
            );
            $this->autosaveHasDraft = false;

            $this->dispatch('autosave-status', status: 'restored');
        } catch (\Throwable $e) {
            Log::warning('Draft restore failed: '.$e->getMessage());

            $this->dispatch('autosave-status', status: 'error');
        }
    }

    public function discardDraft(): void
    {
        $this->clearAutosaveDraft();

        $this->dispatch('autosave-status', status: 'idle');
    }

    public function clearAutosaveDraft(): void
    {
        AutosaveManager::clearDraft($this->getAutosaveCacheKey());
        $this->autosaveHasDraft = false;
    }

    public function create(bool $another = false): void
    {
        if (! method_exists(parent::class, 'create')) {
            return;
        }

        parent::create($another);

        if ($this->getRecord()?->exists) {
            $this->clearAutosaveDraft();
        }
    }

    protected function getAutosaveCacheKey(): string
    {
        return AutosaveManager::cacheKey(static::class);
    }

    protected function getAutosaveCacheTtl(): int
    {
        return AutosavePlugin::tryGet()?->getCacheTtl()
            ?? config('filament-autosave.cache_ttl', 24);
    }
}
