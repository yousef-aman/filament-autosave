<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

use Closure;
use YousefAman\FilamentAutosave\HasAutosave;

/** Stand-in for an Edit page; captures what the trait writes. */
class FakeEditPage
{
    use HasAutosave;

    public object $form;

    public ?array $data = [];

    /** @var array<int, array<string, mixed>> */
    public array $dispatched = [];

    /** @var array<int, array<string, mixed>> */
    public array $updates = [];

    public int $refreshCount = 0;

    /** @var array<string> */
    public array $exceptFields = [];

    /** @var array<string, mixed> */
    private array $dbState;

    /**
     * @param  array<string, mixed>  $formState
     * @param  array<string, mixed>  $dbState
     */
    public function __construct(array $formState = [], array $dbState = [])
    {
        $this->dbState = $dbState;

        $this->form = new class($formState)
        {
            /** @param array<string, mixed> $state */
            public function __construct(private array $state) {}

            /** @return array<string, mixed> */
            public function getRawState(): array
            {
                return $this->state;
            }

            /** @param array<string, mixed> $data */
            public function fill(array $data): void
            {
                $this->state = $data;
            }

            /** @param array<string, mixed> $state */
            public function setState(array $state): void
            {
                $this->state = $state;
            }
        };
    }

    /** @return array<string> */
    protected function autosaveExcept(): array
    {
        return $this->exceptFields;
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
            /** @param array<string, mixed> $fields */
            public function __construct(public array $fields, public Closure $onRefresh) {}

            public function refresh(): static
            {
                ($this->onRefresh)();

                return $this;
            }

            /**
             * @param  array<int, string>  $keys
             * @return array<string, mixed>
             */
            public function only(array $keys): array
            {
                return array_intersect_key($this->fields, array_flip($keys));
            }

            public function getKey(): int
            {
                return 1;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handleRecordUpdate(object $record, array $data): object
    {
        $this->updates[] = $data;

        return $record;
    }
}
