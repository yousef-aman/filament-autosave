<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Support\Facades\Validator;

trait HasAutosaveBase
{
    protected bool $autosaveEnabled = true;

    protected int $autosaveDebounce = 0;

    protected array $autosaveExcept = [];

    protected bool $isAutosaving = false;

    public string $autosaveSnapshotHash = '';

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
            $this->savedDataHash = AutosaveManager::snapshotHash($this->getAutosaveData());
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

    /** @return array<string> */
    protected function getAutosaveForms(): array
    {
        return ['form'];
    }

    /** @return array<string, mixed> */
    protected function getAutosaveData(): array
    {
        foreach ($this->getAutosaveForms() as $formName) {
            try {
                if (! method_exists($this, $formName)) {
                    continue;
                }

                $form = $this->{$formName}();

                if ($form && method_exists($form, 'getRawState')) {
                    return $this->sanitizeFormState($form->getRawState());
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->data ?? [];
    }

    /** @param array<string, mixed> $draft */
    protected function fillAutosaveData(array $draft): void
    {
        foreach ($this->getAutosaveForms() as $formName) {
            try {
                if (! method_exists($this, $formName)) {
                    continue;
                }

                $form = $this->{$formName}();

                if ($form && method_exists($form, 'fill')) {
                    $form->fill($draft);

                    return;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $this->data = array_merge($this->data ?? [], $draft);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sanitizeFormState(array $data): array
    {
        return array_filter($data, fn ($value) => $this->isSerializable($value));
    }

    protected function isSerializable(mixed $value): bool
    {
        if (is_object($value)) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                if (! $this->isSerializable($nested)) {
                    return false;
                }
            }
        }

        return true;
    }
}
