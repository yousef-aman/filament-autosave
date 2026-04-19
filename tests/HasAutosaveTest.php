<?php

use YousefAman\FilamentAutosave\HasAutosave;

function makeEditPage(array $formState = [], array $dbState = []): object
{
    return new class($formState, $dbState)
    {
        use HasAutosave;

        public object $form;

        public ?array $data = [];

        /** @var array<string, mixed> */
        public array $dispatched = [];

        /** @var array<int, array<string, mixed>> */
        public array $updates = [];

        public int $refreshCount = 0;

        private array $dbState;

        public function __construct(array $formState, array $dbState)
        {
            $this->dbState = $dbState;

            $page = $this;
            $this->form = new class($page, $formState)
            {
                private object $page;

                private array $state;

                public function __construct(object $page, array $state)
                {
                    $this->page = $page;
                    $this->state = $state;
                }

                public function getRawState(): array
                {
                    return $this->state;
                }

                public function fill(array $data): void
                {
                    $this->state = $data;
                }

                public function setState(array $state): void
                {
                    $this->state = $state;
                }
            };
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function authorizeAccess(): void
        {
            //
        }

        public function getRecord(): object
        {
            return new class($this->dbState, function () {
                $this->refreshCount++;
            })
            {

                public function __construct(public array $fields, public Closure $onRefresh) {}

                public function refresh(): static
                {
                    ($this->onRefresh)();

                    return $this;
                }

                public function only(array $keys): array
                {
                    return array_intersect_key($this->fields, array_flip($keys));
                }
            };
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            $this->updates[] = $data;

            return $record;
        }
    };
}

test('isAutosaveEnabled defaults to true', function () {
    expect(makeEditPage()->isAutosaveEnabled())->toBeTrue();
});

test('autosave is skipped when disabled', function () {
    $page = makeEditPage(['title' => 'Original']);
    $page->autosaveEnabled = false;

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    expect($page->updates)->toBeEmpty();
});

test('getAutosaveDebounce falls back to config', function () {
    config(['filament-autosave.debounce' => 2000]);

    expect(makeEditPage()->getAutosaveDebounce())->toBe(2000);
});

test('getAutosaveDebounce honors page-level override', function () {
    config(['filament-autosave.debounce' => 1500]);

    $page = makeEditPage();
    (fn () => $this->autosaveDebounce = 3000)->call($page);

    expect($page->getAutosaveDebounce())->toBe(3000);
});

test('getAutosaveExcept merges config and page lists', function () {
    config(['filament-autosave.except' => ['token']]);

    $page = makeEditPage();
    (fn () => $this->autosaveExcept = ['password'])->call($page);

    expect($page->getAutosaveExcept())->toContain('token')->toContain('password');
});

test('mountHasAutosave sets snapshot hash', function () {
    $page = makeEditPage(['name' => 'John']);

    expect($page->autosaveSnapshotHash)->toBe('');

    $page->mountHasAutosave();

    expect($page->autosaveSnapshotHash)->not->toBe('');
});

test('autosave dispatches idle when nothing changed', function () {
    $page = makeEditPage(['name' => 'John']);

    $page->mountHasAutosave();
    $page->autosave();

    expect($page->updates)->toBeEmpty();
    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('autosave persists changed data via handleRecordUpdate', function () {
    $page = makeEditPage(['title' => 'Original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    expect($page->updates)->toHaveCount(1);
    expect($page->updates[0])->toBe(['title' => 'Updated']);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'saved');
});

test('autosave excludes reserved fields before persisting', function () {
    $page = makeEditPage(['title' => 'A', 'password' => 'secret']);
    (fn () => $this->autosaveExcept = ['password'])->call($page);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'B', 'password' => 'newsecret']);
    $page->autosave();

    expect($page->updates[0])->toBe(['title' => 'B']);
});

test('autosave snapshot hash updates after successful save', function () {
    $page = makeEditPage(['title' => 'A']);
    $page->mountHasAutosave();
    $originalHash = $page->autosaveSnapshotHash;

    $page->form->setState(['title' => 'B']);
    $page->autosave();

    expect($page->autosaveSnapshotHash)->not->toBe($originalHash);
});

test('autosave dispatches error on exception', function () {
    $page = new class
    {
        use HasAutosave;

        public ?array $data = [];

        public object $form;

        public array $dispatched = [];

        public function __construct()
        {
            $this->form = new class
            {
                public function getRawState(): array
                {
                    return ['title' => 'X'];
                }

                public function fill(array $data): void {}
            };
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function authorizeAccess(): void {}

        public function getRecord(): object
        {
            return new class
            {
                public function refresh()
                {
                    return $this;
                }
            };
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            throw new RuntimeException('db down');
        }
    };

    $page->autosave();

    expect($page->dispatched[0]['params'])->toHaveKey('status', 'error');
});
