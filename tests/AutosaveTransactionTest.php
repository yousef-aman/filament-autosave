<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\HasAutosave;

beforeEach(function () {
    Cache::flush();
});

/**
 * An edit page exposing Filament's database-transaction helpers as spies, so we
 * can assert autosave writes are wrapped exactly like a normal Filament save.
 */
function makeTransactionalEditPage(bool $failWrite = false): object
{
    return new class($failWrite)
    {
        use HasAutosave;

        public object $form;

        public ?array $data = [];

        /** @var array<int, array<string, mixed>> */
        public array $dispatched = [];

        /** @var array<int, string> */
        public array $txLog = [];

        /** @var array<int, array<string, mixed>> */
        public array $updates = [];

        public bool $failWrite;

        public function __construct(bool $failWrite)
        {
            $this->failWrite = $failWrite;
            $this->form = new class
            {
                private array $state = [];

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

        public function beginDatabaseTransaction(): void
        {
            $this->txLog[] = 'begin';
        }

        public function commitDatabaseTransaction(): void
        {
            $this->txLog[] = 'commit';
        }

        public function rollBackDatabaseTransaction(): void
        {
            $this->txLog[] = 'rollback';
        }

        public function getRecord(): object
        {
            return new class
            {
                public array $attrs = ['title' => 'old'];

                public function only(array $keys): array
                {
                    return array_intersect_key($this->attrs, array_flip($keys));
                }

                public function getAttributes(): array
                {
                    return $this->attrs;
                }

                public function getKey(): int
                {
                    return 1;
                }

                public function refresh(): static
                {
                    return $this;
                }
            };
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            if ($this->failWrite) {
                throw new RuntimeException('write failed');
            }

            $this->updates[] = $data;

            return $record;
        }
    };
}

function lastStatus(object $page): ?string
{
    $last = end($page->dispatched);

    return $last['params']['status'] ?? null;
}

test('edit autosave wraps the record write in a database transaction', function () {
    $page = makeTransactionalEditPage();
    $page->form->setState(['title' => 'Changed']);

    $page->autosave();

    expect($page->txLog)->toBe(['begin', 'commit']);
    expect($page->updates)->toHaveCount(1);
    expect(lastStatus($page))->toBe('saved');
});

test('edit autosave rolls back and reports error when the record write throws', function () {
    $page = makeTransactionalEditPage(failWrite: true);
    $page->form->setState(['title' => 'Changed']);

    $page->autosave();

    expect($page->txLog)->toBe(['begin', 'rollback']);
    expect(lastStatus($page))->toBe('error');
});

test('undo wraps its restore write in a database transaction', function () {
    $page = makeTransactionalEditPage();

    $undoKey = (fn () => $this->getUndoCacheKey())->call($page);
    Cache::put($undoKey, ['title' => 'previous'], now()->addMinutes(30));

    $page->undoAutosave();

    expect($page->txLog)->toBe(['begin', 'commit']);
    expect($page->updates)->toHaveCount(1);
    expect($page->updates[0])->toBe(['title' => 'previous']);
});

test('a page without transaction helpers still autosaves (direct write)', function () {
    $page = new class
    {
        use HasAutosave;

        public object $form;

        public ?array $data = [];

        /** @var array<int, array<string, mixed>> */
        public array $dispatched = [];

        /** @var array<int, array<string, mixed>> */
        public array $updates = [];

        public function __construct()
        {
            $this->form = new class
            {
                private array $state = [];

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

        public function getRecord(): object
        {
            return new class
            {
                public function only(array $keys): array
                {
                    return [];
                }

                public function getAttributes(): array
                {
                    return ['title' => 'old'];
                }

                public function getKey(): int
                {
                    return 1;
                }

                public function refresh(): static
                {
                    return $this;
                }
            };
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            $this->updates[] = $data;

            return $record;
        }
    };

    $page->form->fill(['title' => 'Changed']);
    $page->autosave();

    expect($page->updates)->toHaveCount(1);
});
