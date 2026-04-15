<?php

use YousefAman\FilamentAutosave\HasAutosave;

test('trait can be used', function () {
    $class = new class
    {
        use HasAutosave;

        public ?array $data = [];

        public string $savedDataHash = '';

        public function authorizeAccess(): void
        {
            // stub
        }

        public function dispatch(string $event, ...$params): void
        {
            // stub
        }

        public function getRecord()
        {
            return new class
            {
                public function only(array $keys): array
                {
                    return [];
                }

                public function fresh()
                {
                    return $this;
                }

                public function refresh()
                {
                    return $this;
                }
            };
        }

        public function handleRecordUpdate($record, array $data)
        {
            return $record;
        }
    };

    expect($class->isAutosaveEnabled())->toBeTrue();
});

test('autosave can be disabled', function () {
    $class = new class
    {
        use HasAutosave;

        public ?array $data = [];

        public function disableAutosave(): void
        {
            $this->autosaveEnabled = false;
        }
    };

    $class->disableAutosave();

    expect($class->isAutosaveEnabled())->toBeFalse();
});

test('getAutosaveDebounce returns config default', function () {
    config(['filament-autosave.debounce' => 2000]);

    $class = new class
    {
        use HasAutosave;

        public ?array $data = [];
    };

    expect($class->getAutosaveDebounce())->toBe(2000);
});

test('getAutosaveDebounce returns page-level override', function () {
    config(['filament-autosave.debounce' => 1500]);

    $class = new class
    {
        use HasAutosave;

        public ?array $data = [];

        public function setDebounce(int $value): void
        {
            $this->autosaveDebounce = $value;
        }
    };

    $class->setDebounce(3000);

    expect($class->getAutosaveDebounce())->toBe(3000);
});

test('getAutosaveExcept merges config and page arrays', function () {
    config(['filament-autosave.except' => ['token']]);

    $class = new class
    {
        use HasAutosave;

        public ?array $data = [];

        public function setExcept(array $fields): void
        {
            $this->autosaveExcept = $fields;
        }
    };

    $class->setExcept(['password']);

    expect($class->getAutosaveExcept())->toBe(['token', 'password']);
});

test('autosave does not run when autosaveEnabled is false', function () {
    $class = new class
    {
        use HasAutosave;

        public ?array $data = ['title' => 'Original'];

        public bool $autosaveCalled = false;

        public function disableAutosave(): void
        {
            $this->autosaveEnabled = false;
        }

        public function authorizeAccess(): void
        {
            // stub
        }

        public function dispatch(string $event, ...$params): void
        {
            // stub
        }

        public function getRecord()
        {
            return new class
            {
                public function only(array $keys): array
                {
                    return ['title' => 'Original'];
                }

                public function fresh()
                {
                    return $this;
                }

                public function refresh()
                {
                    return $this;
                }
            };
        }

        public function handleRecordUpdate($record, array $data)
        {
            $this->autosaveCalled = true;

            return $record;
        }
    };

    $class->disableAutosave();
    $class->data = ['title' => 'Changed'];
    $class->autosave();

    expect($class->autosaveCalled)->toBeFalse();
});

test('autosave merges global and page-level except lists', function () {
    config(['filament-autosave.except' => ['slug']]);

    $class = new class
    {
        use HasAutosave;

        public ?array $data = [];

        public function setExcept(array $fields): void
        {
            $this->autosaveExcept = $fields;
        }
    };

    $class->setExcept(['password']);

    $except = $class->getAutosaveExcept();

    expect($except)->toContain('slug');
    expect($except)->toContain('password');
});

test('initializeAutosave sets snapshot hash', function () {
    $class = new class
    {
        use HasAutosave;

        public ?array $data = ['name' => 'John'];

        public function getSnapshotHash(): string
        {
            return $this->autosaveSnapshotHash;
        }
    };

    expect($class->getSnapshotHash())->toBe('');

    $class->initializeAutosave();

    expect($class->getSnapshotHash())->not->toBe('');
});

test('autosave skips when data has not changed since snapshot', function () {
    $class = new class
    {
        use HasAutosave;

        public ?array $data = ['name' => 'John'];

        public array $dispatched = [];

        public function authorizeAccess(): void
        {
            // stub
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function getRecord()
        {
            return new class
            {
                public function only(array $keys): array
                {
                    return ['name' => 'John'];
                }

                public function fresh()
                {
                    return $this;
                }

                public function refresh()
                {
                    return $this;
                }
            };
        }

        public function handleRecordUpdate($record, array $data)
        {
            return $record;
        }
    };

    $class->initializeAutosave();
    $class->autosave();

    // Should dispatch 'idle' since nothing changed
    expect($class->dispatched)->toHaveCount(1);
    expect($class->dispatched[0]['event'])->toBe('autosave-status');
});
