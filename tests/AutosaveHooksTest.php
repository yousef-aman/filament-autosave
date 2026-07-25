<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;

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
