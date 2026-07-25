<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait HasAutosaveBase
{
    // Client-readable mirror of shouldAutosave(). Locked so the browser cannot
    // re-enable autosave on a page that disabled it.
    #[Locked]
    public bool $autosaveEnabled = true;

    #[Locked]
    public string $autosaveSnapshotHash = '';

    #[Locked]
    public int $autosaveDebounceMs = 0;

    protected bool $isAutosaving = false;

    protected function shouldAutosave(): bool
    {
        return true;
    }

    /** Milliseconds; null inherits the plugin/config value. */
    protected function autosaveDebounce(): ?int
    {
        return null;
    }

    /** @return array<string> */
    protected function autosaveExcept(): array
    {
        return [];
    }

    public function isAutosaveEnabled(): bool
    {
        return $this->autosaveEnabled && $this->shouldAutosave();
    }

    public function getAutosaveDebounce(): int
    {
        $pageDebounce = $this->autosaveDebounce();

        if ($pageDebounce !== null && $pageDebounce > 0) {
            return $pageDebounce;
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
            ...$this->autosaveExcept(),
        ]));
    }

    protected function initializeAutosaveState(): bool
    {
        $this->autosaveEnabled = $this->shouldAutosave();

        return $this->autosaveEnabled;
    }

    protected function performAutosave(callable $persist): void
    {
        if (! $this->isAutosaveEnabled() || $this->isAutosaving) {
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

            $data = $this->enforceFieldOptionRules(
                $this->validateAutosaveFields($this->beforeAutosave($data))
            );

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
                timestamp: now()->isoFormat('LT'),
            );
        } catch (\Throwable $e) {
            // Type only: an exception message can interpolate sensitive field values.
            Log::warning('Autosave failed', ['exception' => $e::class]);

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

    /** @return array<string, mixed> raw form state; the Edit trait persists dehydrated state */
    protected function getAutosaveData(): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null) {
            return $this->stripFileUploads($this->data ?? []);
        }

        return $this->stripFileUploads($this->normalizeStateArray($form->getRawState()));
    }

    /** @return array<string, mixed> mirrors Schema::getStateSnapshot, minus validation */
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
     * Guards mass-assignment of injected columns; nested keys rely on $fillable.
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

    /** @return array<string, object> keyed by top-level state name */
    protected function getAutosaveFields(): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null || ! method_exists($form, 'getFlatFields')) {
            return [];
        }

        $fields = [];

        foreach ($form->getFlatFields(withHidden: true) as $key => $field) {
            $fields[explode('.', (string) $key, 2)[0]] ??= $field;
        }

        return $fields;
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
        return $this->dropPasswordFields(
            AutosaveManager::excludeFields(
                $this->stripFileUploads($data),
                $this->getAutosaveExcept(),
            )
        );
    }

    /**
     * Edit would commit a half-typed secret; Create drafts cache raw state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function dropPasswordFields(array $data): array
    {
        foreach ($this->getAutosaveFields() as $name => $field) {
            if (array_key_exists($name, $data)
                && method_exists($field, 'isPassword')
                && $field->isPassword()
            ) {
                unset($data[$name]);
            }
        }

        return $data;
    }

    /**
     * Autosave skips validation, so a crafted state could otherwise persist an
     * out-of-scope option value that a normal save rejects.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function enforceFieldOptionRules(array $data): array
    {
        foreach ($this->getAutosaveFields() as $name => $field) {
            if (! array_key_exists($name, $data) || ! method_exists($field, 'getInValidationRule')) {
                continue;
            }

            $rule = $field->getInValidationRule();

            if ($rule === null) {
                continue;
            }

            $value = $data[$name];

            // Array fields (CheckboxList, multiple Select) validate per element;
            // the flat rule against the whole array would reject valid selections.
            $rules = is_array($value)
                ? [$name => ['array'], "{$name}.*" => [$rule]]
                : [$name => [$rule]];

            if (Validator::make([$name => $value], $rules)->fails()) {
                unset($data[$name]);
            }
        }

        return $data;
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
