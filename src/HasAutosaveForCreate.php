<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Database\Eloquent\Model;
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
        // handleRecordCreation(); getRecord() covers the ordinary create.
        if ($this->autosaveRecordWasCreated || $this->getRecord()?->exists) {
            $this->clearAutosaveDraft();
        }
    }

    /**
     * Flags a successful create (Octane-safe; works on all Filament v4/v5).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $this->autosaveRecordWasCreated = true;

        return parent::handleRecordCreation($data);
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
