<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

beforeEach(function () {
    Cache::flush();
});

function createPageStub(array $data = []): object
{
    return new class($data)
    {
        use HasAutosaveForCreate;

        public ?array $data;

        public array $dispatched = [];

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public function authorizeAccess(): void {}

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }
    };
}

test('autosave stores draft in cache', function () {
    $page = createPageStub(['title' => 'My Article', 'body' => 'Content']);
    $page->initializeAutosaveForCreate();

    $page->data = ['title' => 'Updated', 'body' => 'Content'];
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBe(['title' => 'Updated', 'body' => 'Content']);
});

test('restoreDraft fills data from cache', function () {
    $page = createPageStub(['title' => '', 'body' => '']);

    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Cached Title', 'body' => 'Cached Body'], 3600);

    $page->restoreDraft();

    expect($page->data)->toBe(['title' => 'Cached Title', 'body' => 'Cached Body']);
});

test('discardDraft clears cache', function () {
    $page = createPageStub(['title' => '', 'body' => '']);

    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Draft'], 3600);

    $page->discardDraft();

    expect(Cache::get($key))->toBeNull();
});

test('mountHasAutosaveForCreate sets hasDraft when draft exists', function () {
    $page = createPageStub(['title' => '', 'body' => '']);

    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Saved Draft'], 3600);

    $page->mountHasAutosaveForCreate();

    expect($page->autosaveHasDraft)->toBeTrue();
});

test('mountHasAutosaveForCreate does nothing when no draft exists', function () {
    $page = createPageStub(['title' => '', 'body' => '']);

    $page->mountHasAutosaveForCreate();

    expect($page->autosaveHasDraft)->toBeFalse();
});

test('autosave respects autosaveExcept fields', function () {
    $page = new class(['title' => 'Test', 'password' => 'secret'])
    {
        use HasAutosaveForCreate;

        public ?array $data;

        public array $dispatched = [];

        public function __construct(array $data)
        {
            $this->data = $data;
            $this->autosaveExcept = ['password'];
        }

        public function authorizeAccess(): void {}

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }
    };

    $page->initializeAutosaveForCreate();
    $page->data = ['title' => 'Updated', 'password' => 'newsecret'];
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    $cached = Cache::get($key);

    expect($cached)->toHaveKey('title', 'Updated');
    expect($cached)->not->toHaveKey('password');
});

test('autosave does not run when disabled', function () {
    $page = new class(['title' => 'Test'])
    {
        use HasAutosaveForCreate;

        public ?array $data;

        public array $dispatched = [];

        public function __construct(array $data)
        {
            $this->data = $data;
            $this->autosaveEnabled = false;
        }

        public function authorizeAccess(): void {}

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }
    };

    $page->data = ['title' => 'Changed'];
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBeNull();
});

test('autosave skips when data has not changed since snapshot', function () {
    $page = createPageStub(['title' => 'Same']);
    $page->initializeAutosaveForCreate();
    $page->autosave();

    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('sanitizeFormState removes top-level objects', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public function testSanitize(array $data): array
        {
            return $this->sanitizeFormState($data);
        }
    };

    $fakeFile = new \stdClass;
    $result = $page->testSanitize([
        'title' => 'hello',
        'file' => $fakeFile,
        'count' => 42,
    ]);

    expect($result)->toBe(['title' => 'hello', 'count' => 42]);
});

test('sanitizeFormState removes arrays containing objects recursively', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public function testSanitize(array $data): array
        {
            return $this->sanitizeFormState($data);
        }
    };

    $result = $page->testSanitize([
        'title' => 'hello',
        'items' => [
            ['name' => 'a', 'file' => new \stdClass],
            ['name' => 'b'],
        ],
    ]);

    expect($result)->toBe(['title' => 'hello']);
});

test('sanitizeFormState preserves scalars nulls booleans and nested scalar arrays', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public function testSanitize(array $data): array
        {
            return $this->sanitizeFormState($data);
        }
    };

    $input = [
        'title' => 'hello',
        'count' => 42,
        'is_active' => true,
        'nothing' => null,
        'tags' => ['php', 'laravel'],
        'metadata' => ['key' => 'value', 'nested' => ['a', 'b']],
    ];

    expect($page->testSanitize($input))->toBe($input);
});

test('getAutosaveData uses form getRawState when form method exists', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public ?array $data = ['ignored' => 'should not be used'];

        public function form(): object
        {
            return new class
            {
                public function getRawState(): array
                {
                    return ['title' => 'from-form', 'nested' => ['key' => 'value']];
                }
            };
        }

        public function testGet(): array
        {
            return $this->getAutosaveData();
        }
    };

    expect($page->testGet())->toBe(['title' => 'from-form', 'nested' => ['key' => 'value']]);
});

test('getAutosaveData falls back to data when no form method', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public ?array $data = ['title' => 'from-data'];

        public function testGet(): array
        {
            return $this->getAutosaveData();
        }
    };

    expect($page->testGet())->toBe(['title' => 'from-data']);
});

test('getAutosaveData falls back when form method throws', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public ?array $data = ['title' => 'from-data'];

        public function form(): object
        {
            throw new \RuntimeException('form unavailable');
        }

        public function testGet(): array
        {
            return $this->getAutosaveData();
        }
    };

    expect($page->testGet())->toBe(['title' => 'from-data']);
});

test('getAutosaveData falls back when getRawState throws', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public ?array $data = ['title' => 'from-data'];

        public function form(): object
        {
            return new class
            {
                public function getRawState(): array
                {
                    throw new \RuntimeException('state unavailable');
                }
            };
        }

        public function testGet(): array
        {
            return $this->getAutosaveData();
        }
    };

    expect($page->testGet())->toBe(['title' => 'from-data']);
});

test('fillAutosaveData calls form fill when available', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public ?array $data = [];

        public array $filledWith = [];

        public function form(): object
        {
            $page = $this;

            return new class($page)
            {
                public function __construct(public object $page) {}

                public function fill(array $data): void
                {
                    $this->page->filledWith = $data;
                }
            };
        }

        public function testFill(array $draft): void
        {
            $this->fillAutosaveData($draft);
        }
    };

    $page->testFill(['title' => 'restored']);

    expect($page->filledWith)->toBe(['title' => 'restored']);
});

test('fillAutosaveData falls back to data merge when no form', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public ?array $data = ['existing' => 'keep'];

        public function testFill(array $draft): void
        {
            $this->fillAutosaveData($draft);
        }
    };

    $page->testFill(['new' => 'value']);

    expect($page->data)->toBe(['existing' => 'keep', 'new' => 'value']);
});

test('getAutosaveForms override allows custom form name', function () {
    $page = new class
    {
        use \YousefAman\FilamentAutosave\HasAutosaveForCreate;

        public ?array $data = [];

        public function editForm(): object
        {
            return new class
            {
                public function getRawState(): array
                {
                    return ['title' => 'from-custom-form'];
                }
            };
        }

        protected function getAutosaveForms(): array
        {
            return ['editForm'];
        }

        public function testGet(): array
        {
            return $this->getAutosaveData();
        }
    };

    expect($page->testGet())->toBe(['title' => 'from-custom-form']);
});

test('autosave saves form getRawState data including custom fields', function () {
    $formStateHolder = new class
    {
        public array $state = ['title' => 'initial'];
    };

    $page = new class($formStateHolder)
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        public array $dispatched = [];

        public object $stateHolder;

        public function __construct(object $stateHolder)
        {
            $this->stateHolder = $stateHolder;
        }

        public function authorizeAccess(): void {}

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function form(): object
        {
            $holder = $this->stateHolder;

            return new class($holder)
            {
                public function __construct(public object $holder) {}

                public function getRawState(): array
                {
                    return $this->holder->state;
                }

                public function fill(array $data): void
                {
                    $this->holder->state = $data;
                }
            };
        }
    };

    $page->initializeAutosaveForCreate();

    $formStateHolder->state = [
        'title' => 'from-form',
        'custom_field' => 'not-in-data',
        'nested' => ['key' => 'value'],
    ];

    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    $cached = Cache::get($key);

    expect($cached)->toHaveKey('custom_field', 'not-in-data');
    expect($cached)->toHaveKey('title', 'from-form');
    expect($cached)->toHaveKey('nested');
});
