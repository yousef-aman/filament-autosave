<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait HasAutosaveBase
{
    public bool $autosaveEnabled = true;

    public string $autosaveSnapshotHash = '';

    protected int $autosaveDebounce = 0;

    /** @var array<string> */
    protected array $autosaveExcept = [];

    protected bool $isAutosaving = false;

    public function isAutosaveEnabled(): bool
    {
        return $this->autosaveEnabled;
    }

    public function getAutosaveDebounce(): int
    {
        if ($this->autosaveDebounce > 0) {
            return $this->autosaveDebounce;
        }

        return AutosavePlugin::tryGet()?->getDebounce()
            ?? config('filament-autosave.debounce', 1500);
    }

    /** @return array<string> */
    public function getAutosaveExcept(): array
    {
        return array_values(array_unique([
            ...config('filament-autosave.except', []),
            ...(AutosavePlugin::tryGet()?->getExcept() ?? []),
            ...$this->autosaveExcept,
        ]));
    }

    protected function performAutosave(callable $persist): void
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

            if (empty($data) || $persist($data) === false) {
                $this->dispatch('autosave-status', status: 'idle');

                return;
            }

            $this->autosaveSnapshotHash = AutosaveManager::snapshotHash(
                $this->prepareAutosavePayload($this->getAutosaveData())
            );

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function beforeAutosave(array $data): array
    {
        return $data;
    }

    /** @return array<string, mixed> */
    protected function getAutosaveValidationRules(): array
    {
        return [];
    }

    /**
     * Raw form state (what the user typed). The Edit trait overrides this to
     * persist dehydrated state instead.
     *
     * @return array<string, mixed>
     */
    protected function getAutosaveData(): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null) {
            return $this->stripFileUploads($this->data ?? []);
        }

        return $this->stripFileUploads($this->normalizeStateArray($form->getRawState()));
    }

    /**
     * Dehydrated form state without validation: mirrors Schema::getStateSnapshot,
     * applying casts/dehydrateStateUsing and dropping dehydrated(false) fields.
     *
     * @return array<string, mixed>
     */
    protected function dehydrateAutosaveState(object $form): array
    {
        if (! method_exists($form, 'dehydrateState')) {
            return $this->normalizeStateArray($form->getRawState());
        }

        $statePath = method_exists($form, 'getStatePath') ? $form->getStatePath() : null;
        $raw = $this->normalizeStateArray($form->getRawState());

        $state = [];

        if (filled($statePath)) {
            data_set($state, $statePath, $raw);
        } else {
            $state = $raw;
        }

        $form->dehydrateState($state);

        if (method_exists($form, 'mutateDehydratedState')) {
            $form->mutateDehydratedState($state);
        }

        $dehydrated = filled($statePath)
            ? $this->normalizeStateArray(data_get($state, $statePath))
            : $state;

        return $this->pruneToDeclaredFields($form, $dehydrated);
    }

    /**
     * Drop top-level keys not backed by a declared field (guards mass-assignment
     * of injected columns). Nested keys rely on the model's $fillable/$guarded.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function pruneToDeclaredFields(object $form, array $state): array
    {
        if (! method_exists($form, 'getFlatFields')) {
            return $state;
        }

        $allowed = [];

        foreach (array_keys($form->getFlatFields(withHidden: true)) as $key) {
            $allowed[explode('.', (string) $key, 2)[0]] = true;
        }

        return array_intersect_key($state, $allowed);
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeStateArray(mixed $state): array
    {
        if ($state instanceof Arrayable) {
            $state = $state->toArray();
        }

        return is_array($state) ? $state : [];
    }

    /** @param  array<string, mixed>  $draft */
    protected function fillAutosaveData(array $draft): void
    {
        $form = $this->resolveAutosaveForm();

        if ($form !== null) {
            $form->fill($draft);

            return;
        }

        $this->data = array_merge($this->data ?? [], $draft);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareAutosavePayload(array $data): array
    {
        return AutosaveManager::excludeFields(
            $this->stripFileUploads($data),
            $this->getAutosaveExcept(),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function validateAutosaveFields(array $fields): array
    {
        $rules = array_intersect_key($this->getAutosaveValidationRules(), $fields);

        if (empty($rules)) {
            return $fields;
        }

        $validator = Validator::make($fields, $rules);

        if (! $validator->fails()) {
            return $fields;
        }

        return array_diff_key($fields, array_flip(array_keys($validator->failed())));
    }

    /**
     * Recursively drop TemporaryUploadedFile leaves, keeping their siblings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripFileUploads(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof TemporaryUploadedFile) {
                unset($data[$key]);

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->stripFileUploads($value);
            }
        }

        return $data;
    }

    protected function resolveAutosaveForm(): ?object
    {
        $form = $this->form ?? null;

        return (is_object($form) && method_exists($form, 'getRawState') && method_exists($form, 'fill'))
            ? $form
            : null;
    }
}
