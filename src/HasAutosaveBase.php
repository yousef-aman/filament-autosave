<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
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
     * Guards mass-assignment of injected keys at every depth. A declared field
     * with no declared children is opaque (KeyValue, a plain array column) and
     * kept verbatim.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function pruneToDeclaredFields(object $form, array $state): array
    {
        if (! method_exists($form, 'getFlatFields')) {
            return $state;
        }

        return $this->pruneStateToFieldTree(
            $state,
            $this->buildAutosaveFieldTree(array_keys($this->getAutosaveFields())),
        );
    }

    /**
     * Declared fields keyed by full state path, with repeater/builder row keys
     * collapsed to `*` (dehydration renumbers them).
     *
     * @return array<string, array<object>> path pattern => component instances
     */
    protected function getAutosaveFields(): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null || ! method_exists($form, 'getFlatFields')) {
            return [];
        }

        $flat = $form->getFlatFields(withHidden: true);
        $declared = array_fill_keys(array_map(strval(...), array_keys($flat)), true);

        $fields = [];

        foreach ($flat as $key => $field) {
            $fields[$this->normalizeAutosaveFieldPath((string) $key, $declared)][] = $field;
        }

        return $fields;
    }

    /** @param  array<string, true>  $declared */
    protected function normalizeAutosaveFieldPath(string $key, array $declared): string
    {
        $segments = explode('.', $key);
        $prefix = [];
        $pattern = [];

        foreach ($segments as $segment) {
            // A level nested inside another field is a row key, not a field name.
            $pattern[] = ($prefix !== [] && isset($declared[implode('.', $prefix)]))
                ? '*'
                : $segment;

            $prefix[] = $segment;
        }

        return implode('.', $pattern);
    }

    /**
     * @param  array<string>  $patterns
     * @return array<string, mixed>
     */
    protected function buildAutosaveFieldTree(array $patterns): array
    {
        $tree = [];

        foreach ($patterns as $pattern) {
            $branch = &$tree;

            foreach (explode('.', $pattern) as $segment) {
                $branch[$segment] ??= [];
                $branch = &$branch[$segment];
            }

            unset($branch);
        }

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    protected function pruneStateToFieldTree(array $state, array $tree): array
    {
        if ($tree === []) {
            return $state;
        }

        $pruned = [];

        foreach ($state as $key => $value) {
            $branch = $tree[$key] ?? $tree['*'] ?? null;

            if ($branch === null) {
                continue;
            }

            $pruned[$key] = is_array($value)
                ? $this->pruneStateToFieldTree($value, $branch)
                : $value;
        }

        return $pruned;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string> concrete paths in $data matching a wildcarded pattern
     */
    protected function matchAutosavePaths(array $data, string $pattern): array
    {
        $paths = [''];

        foreach (explode('.', $pattern) as $segment) {
            $matched = [];

            foreach ($paths as $prefix) {
                $parent = $prefix === '' ? $data : data_get($data, $prefix);

                if (! is_array($parent)) {
                    continue;
                }

                $keys = $segment === '*'
                    ? array_keys($parent)
                    : (array_key_exists($segment, $parent) ? [$segment] : []);

                foreach ($keys as $key) {
                    $matched[] = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                }
            }

            if ($matched === []) {
                return [];
            }

            $paths = $matched;
        }

        return $paths;
    }

    /** Whether every array level along a pattern still holds the field. */
    protected function autosavePathIsComplete(mixed $value, string $pattern): bool
    {
        $segments = explode('.', $pattern);

        while ($segments !== []) {
            $segment = array_shift($segments);

            if (! is_array($value)) {
                return false;
            }

            if ($segment === '*') {
                $remaining = implode('.', $segments);

                foreach ($value as $item) {
                    if (! $this->autosavePathIsComplete($item, $remaining)) {
                        return false;
                    }
                }

                return true;
            }

            if (! array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Removes a path and any container it just emptied, so a group that held only
     * skipped fields is never written back as an empty value.
     *
     * @param  array<string, mixed>  $data
     */
    protected function forgetAutosavePath(array &$data, string $path): void
    {
        Arr::forget($data, $path);

        $segments = explode('.', $path);
        array_pop($segments);

        while ($segments !== []) {
            $parent = implode('.', $segments);

            if (data_get($data, $parent) !== []) {
                return;
            }

            Arr::forget($data, $parent);
            array_pop($segments);
        }
    }

    /**
     * getType() covers both `password()` and a bare `type('password')`.
     *
     * @param  array<object>  $fields
     */
    protected function isAutosaveSecretField(array $fields): bool
    {
        foreach ($fields as $field) {
            if (method_exists($field, 'isPassword') && $field->isPassword()) {
                return true;
            }

            if (method_exists($field, 'getType') && $field->getType() === 'password') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<object>  $fields
     */
    protected function anyAutosaveField(array $fields, string $method): bool
    {
        foreach ($fields as $field) {
            if (method_exists($field, $method) && $field->{$method}()) {
                return true;
            }
        }

        return false;
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
        foreach ($this->getAutosaveFields() as $path => $fields) {
            if (! $this->isAutosaveSecretField($fields)) {
                continue;
            }

            foreach ($this->matchAutosavePaths($data, $path) as $match) {
                $this->forgetAutosavePath($data, $match);
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
        foreach ($this->getAutosaveFields() as $path => $fields) {
            $rules = [];

            foreach ($fields as $field) {
                if (! method_exists($field, 'getInValidationRule')) {
                    continue;
                }

                if (($rule = $field->getInValidationRule()) !== null) {
                    $rules[] = $rule;
                }
            }

            if ($rules === []) {
                continue;
            }

            foreach ($this->matchAutosavePaths($data, $path) as $match) {
                $value = data_get($data, $match);

                if (blank($value) || $this->passesOptionRules($value, $rules)) {
                    continue;
                }

                $this->forgetAutosavePath($data, $match);
            }
        }

        return $data;
    }

    /** @param  array<object>  $rules */
    protected function passesOptionRules(mixed $value, array $rules): bool
    {
        // Array fields (CheckboxList, multiple Select) validate per element;
        // the flat rule against the whole array would reject valid selections.
        $constraints = is_array($value)
            ? ['value' => ['array'], 'value.*' => $rules]
            : ['value' => $rules];

        return ! Validator::make(['value' => $value], $constraints)->fails();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function validateAutosaveFields(array $fields): array
    {
        $rules = [];

        // A rule may target a nested path ('items.*.qty').
        foreach ($this->getAutosaveValidationRules() as $key => $rule) {
            if (array_key_exists(explode('.', (string) $key, 2)[0], $fields)) {
                $rules[$key] = $rule;
            }
        }

        if (empty($rules)) {
            return $fields;
        }

        $validator = Validator::make($fields, $rules);

        if (! $validator->fails()) {
            return $fields;
        }

        foreach (array_keys($validator->failed()) as $failed) {
            // Whole container: a partial write would destroy the skipped value.
            unset($fields[explode('.', (string) $failed, 2)[0]]);
        }

        return $fields;
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
