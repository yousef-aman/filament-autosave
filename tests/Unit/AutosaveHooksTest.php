<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosavePasswordEditFormComponent;

beforeEach(function () {
    Cache::flush();
});

test('validateAutosaveFields drops fields that fail their rules', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveValidationRules(): array
        {
            return ['name' => ['max:3']];
        }
    };

    $result = (fn ($f) => $this->validateAutosaveFields($f))->call($page, [
        'name' => 'toolong',
        'title' => 'kept',
    ]);

    expect($result)->toBe(['title' => 'kept']);
});

test('validateAutosaveFields keeps fields that pass their rules', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveValidationRules(): array
        {
            return ['name' => ['max:10']];
        }
    };

    $result = (fn ($f) => $this->validateAutosaveFields($f))->call($page, ['name' => 'ok']);

    expect($result)->toBe(['name' => 'ok']);
});

test('validateAutosaveFields honours a nested rule key', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveValidationRules(): array
        {
            return ['items.*.qty' => ['integer', 'min:1']];
        }
    };

    $result = (fn ($f) => $this->validateAutosaveFields($f))->call($page, [
        'items' => [['qty' => 0]],
        'title' => 'kept',
    ]);

    expect($result)->toBe(['title' => 'kept']);
});

test('validateAutosaveFields keeps a nested payload that satisfies its rule', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveValidationRules(): array
        {
            return ['items.*.qty' => ['integer', 'min:1']];
        }
    };

    $result = (fn ($f) => $this->validateAutosaveFields($f))->call($page, [
        'items' => [['qty' => 3]],
    ]);

    expect($result)->toBe(['items' => [['qty' => 3]]]);
});

test('beforeAutosave can transform the payload before it is persisted', function () {
    $page = new class extends AutosaveCreateFormComponent
    {
        protected function beforeAutosave(array $data): array
        {
            return [...$data, 'title' => strtoupper($data['title'] ?? '')];
        }
    };

    $page->mountHasAutosaveForCreate();
    $page->data = ['title' => 'hello'];
    $page->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey($page::class));

    expect($draft)->toHaveKey('title', 'HELLO');
});

test('afterAutosave runs after a successful edit save', function () {
    $page = new class extends AutosaveEditFormComponent
    {
        public bool $afterRan = false;

        protected function afterAutosave(object $record): void
        {
            $this->afterRan = true;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed'];
    $page->autosave();

    expect($page->afterRan)->toBeTrue();
});

test('an edit autosave re-baselines Filament unsaved-changes tracking', function () {
    $page = new class extends AutosaveEditFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed'];
    $page->autosave();

    expect($page->rememberedCount)->toBe(1);
});

test('an edit autosave leaves unsaved-changes tracking alone when a filled field was skipped', function () {
    $page = new class extends AutosavePasswordEditFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosave();
    // Re-baselining here would report the typed secret as saved.
    $page->data = ['title' => 'Changed', 'vault_key' => 'typed-secret'];
    $page->autosave();

    expect($page->written)->toHaveKey('title', 'Changed');
    expect($page->rememberedCount)->toBe(0);
});

test('an edit autosave re-baselines when the skipped field was left blank', function () {
    $page = new class extends AutosavePasswordEditFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed', 'vault_key' => ''];
    $page->autosave();

    expect($page->rememberedCount)->toBe(1);
});

test('an undo re-baselines Filament unsaved-changes tracking', function () {
    $page = new class extends AutosaveEditFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosave();
    Cache::put(
        (fn () => $this->getUndoCacheKey())->call($page),
        ['title' => 'Original'],
        now()->addMinutes(5),
    );
    $page->autosaveCanUndo = true;

    $page->undoAutosave();

    expect($page->rememberedCount)->toBe(1);
});

test('a create-page draft autosave does not re-baseline unsaved-changes tracking', function () {
    $page = new class extends AutosaveCreateFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosaveForCreate();
    $page->data = ['title' => 'Drafted'];
    $page->autosave();

    // Nothing was persisted, so the form really does still have unsaved changes.
    expect($page->rememberedCount)->toBe(0);
});
