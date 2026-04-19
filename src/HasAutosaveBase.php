<?php

namespace YousefAman\FilamentAutosave;

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

    /** @return array<string, mixed> */
    protected function getAutosaveData(): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form !== null) {
            return $this->stripFileUploads($form->getRawState());
        }

        return $this->stripFileUploads($this->data ?? []);
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
     * Normalize, filter, and exclude reserved fields from form state before persistence.
     *
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
     * Drop fields that would fail validation, keeping valid ones.
     *
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripFileUploads(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->containsFileUpload($value)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    protected function containsFileUpload(mixed $value): bool
    {
        if ($value instanceof TemporaryUploadedFile) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nested) {
            if ($this->containsFileUpload($nested)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveAutosaveForm(): ?object
    {
        try {
            $form = $this->form ?? null;
        } catch (\Throwable) {
            return null;
        }

        return (is_object($form) && method_exists($form, 'getRawState') && method_exists($form, 'fill'))
            ? $form
            : null;
    }
}
