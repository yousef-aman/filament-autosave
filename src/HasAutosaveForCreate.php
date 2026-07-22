<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Support\Facades\Log;

trait HasAutosaveForCreate
{
    use HasAutosaveBase;

    public bool $autosaveHasDraft = false;

    protected bool $autosaveRecordWasCreated = false;

    public function mountHasAutosaveForCreate(): void
    {
        if (! $this->autosaveEnabled) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();

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
                // Every field was cleared — drop any stale draft so it is not
                // offered for restore later.
                $this->clearAutosaveDraft();

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
                $this->dispatch('autosave-status', status: 'idle');

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
            Log::warning('Draft restore failed', ['exception' => $e::class]);

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

        $this->autosaveRecordWasCreated = false;

        parent::create($another);

        // "create another" nulls $this->record, so rely on the flag set in
        // rememberData(); getRecord() covers the ordinary create.
        if ($this->autosaveRecordWasCreated || $this->getRecord()?->exists) {
            $this->clearAutosaveDraft();
        }
    }

    // Filament calls rememberData() only after a successful create; unlike
    // handleRecordCreation, a page can't shadow it. create() resets the flag first.
    protected function rememberData(): void
    {
        $this->autosaveRecordWasCreated = true;

        if (method_exists(parent::class, 'rememberData')) {
            parent::rememberData();
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
