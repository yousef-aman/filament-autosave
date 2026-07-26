<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use YousefAman\FilamentAutosave\HasAutosave;
use YousefAman\FilamentAutosave\HasAutosaveBase;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\DisabledEditPage;
use YousefAman\FilamentAutosave\Tests\Fixtures\FakeEditPage;
use YousefAman\FilamentAutosave\Tests\Fixtures\OverridingEditPage;

beforeEach(function () {
    Cache::flush();
});

function makeEditPage(array $formState = [], array $dbState = []): FakeEditPage
{
    return new FakeEditPage($formState, $dbState);
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

test('getAutosaveDebounce honors the page-level autosaveDebounce() override', function () {
    config(['filament-autosave.debounce' => 1500]);

    expect((new OverridingEditPage)->getAutosaveDebounce())->toBe(3000);
});

test('getAutosaveExcept merges config and the page-level autosaveExcept() override', function () {
    config(['filament-autosave.except' => ['token']]);

    expect((new OverridingEditPage)->getAutosaveExcept())
        ->toContain('token')
        ->toContain('secret_note');
});

test('mountHasAutosave exposes the resolved debounce for the indicator', function () {
    config(['filament-autosave.debounce' => 1750]);

    $page = new OverridingEditPage;
    $page->mountHasAutosave();

    // Page-level override must reach the frontend, not just the plugin value.
    expect($page->autosaveDebounceMs)->toBe(3000);
});

test('shouldAutosave() false disables autosave and mirrors onto the client property', function () {
    $page = new DisabledEditPage;
    $page->mountHasAutosave();

    expect($page->autosaveEnabled)->toBeFalse()
        ->and($page->isAutosaveEnabled())->toBeFalse();

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    expect($page->updates)->toBeEmpty();
});

test('a page that disabled autosave cannot be re-enabled by tampering with the mirror property', function () {
    $page = new DisabledEditPage;
    $page->mountHasAutosave();

    // shouldAutosave() stays authoritative even past #[Locked].
    $page->autosaveEnabled = true;

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    expect($page->updates)->toBeEmpty();
});

test('a page class can declare its own autosave settings properties without a fatal trait conflict', function () {
    // PHP fatals if a class redeclares a trait property with a different default.
    $traitProperties = array_map(
        fn (ReflectionProperty $property) => $property->getName(),
        (new ReflectionClass(HasAutosaveBase::class))->getProperties(),
    );

    expect($traitProperties)
        ->not->toContain('autosaveDebounce')
        ->not->toContain('autosaveExcept');

    $page = new class extends FakeEditPage
    {
        protected int $autosaveDebounce = 4000;

        /** @var array<string> */
        protected array $autosaveExcept = ['nope'];
    };

    expect($page->getAutosaveDebounce())->toBeInt();
});

test('every public livewire property the traits add is locked against client updates', function (string $class, array $properties) {
    foreach ($properties as $property) {
        expect((new ReflectionProperty($class, $property))->getAttributes(Locked::class))
            ->not->toBeEmpty("{$class}::\${$property} must be #[Locked]");
    }
})->with([
    [FakeEditPage::class, ['autosaveEnabled', 'autosaveSnapshotHash', 'autosaveDebounceMs', 'autosaveCanUndo']],
    [AutosaveCreateFormComponent::class, ['autosaveEnabled', 'autosaveSnapshotHash', 'autosaveDebounceMs', 'autosaveHasDraft']],
]);

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
    $page = makeEditPage(['title' => 'A', 'secret_note' => 'secret']);
    $page->exceptFields = ['secret_note'];
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'B', 'secret_note' => 'newsecret']);
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

test('autosave records an undo snapshot of previous DB values', function () {
    $page = makeEditPage(['title' => 'Original'], ['title' => 'Original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    expect($page->autosaveCanUndo)->toBeTrue();
});

test('autosave does not offer undo when no previous values were captured', function () {
    $page = makeEditPage(['title' => 'Original']); // no dbState -> record->only() is empty
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    expect($page->updates)->toHaveCount(1);
    expect($page->autosaveCanUndo)->toBeFalse();
});

test('undoAutosave restores previous field values and clears snapshot', function () {
    $page = makeEditPage(['title' => 'Original'], ['title' => 'Original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    $page->undoAutosave();

    expect($page->updates)->toHaveCount(2);
    expect($page->updates[1])->toBe(['title' => 'Original']);
    expect($page->autosaveCanUndo)->toBeFalse();
    expect(end($page->dispatched)['params'])->toHaveKey('status', 'undone');
});

test('undoAutosave is a no-op when no snapshot exists', function () {
    $page = makeEditPage(['title' => 'Same'], ['title' => 'Same']);
    $page->mountHasAutosave();

    $page->undoAutosave();

    expect($page->updates)->toBeEmpty();
    expect($page->autosaveCanUndo)->toBeFalse();
});

test('an undo snapshot survives a value that cannot be JSON encoded', function () {
    $page = makeEditPage(['title' => 'Original'], ['title' => "\xB1\x31"]);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    expect($page->autosaveCanUndo)->toBeTrue();
});

test('undoAutosave refuses a snapshot this page instance did not create', function () {
    $page = makeEditPage(['title' => 'Current'], ['title' => 'Current']);
    $page->mountHasAutosave();

    // A stale snapshot could roll back an edit somebody else made since.
    Cache::put(
        (fn () => $this->getUndoCacheKey())->call($page),
        ['title' => 'Ancient'],
        now()->addMinutes(5),
    );

    $page->undoAutosave();

    expect($page->updates)->toBeEmpty();
    expect(end($page->dispatched)['params'])->toHaveKey('status', 'idle');
});

test('undoAutosave dispatches idle when no snapshot exists so the indicator does not hang', function () {
    $page = makeEditPage(['title' => 'Same'], ['title' => 'Same']);
    $page->mountHasAutosave();

    $page->undoAutosave();

    expect($page->autosaveCanUndo)->toBeFalse();
    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
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

test('undo snapshot captures only real record columns, not relationship-named fields', function () {
    $page = new class
    {
        use HasAutosave;

        public object $form;

        public ?array $data = [];

        public array $dispatched = [];

        public array $updates = [];

        public function __construct()
        {
            $this->form = new class
            {
                public array $state = ['title' => 'New', 'author' => 'x'];

                public function getRawState(): array
                {
                    return $this->state;
                }

                public function fill(array $data): void
                {
                    $this->state = $data;
                }
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
                public function getAttributes(): array
                {
                    // 'author' is a relationship, not a stored column.
                    return ['title' => 'Old'];
                }

                public function only(array $keys): array
                {
                    $all = ['title' => 'Old', 'author' => ['id' => 99]];

                    return array_intersect_key($all, array_flip($keys));
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

    $page->autosave();
    $page->undoAutosave();

    // The undo write restores 'title' only — the relationship-named key never
    // entered the snapshot.
    expect($page->updates[1])->toBe(['title' => 'Old']);
});

test('a failed autosave logs the exception type but never the field values', function () {
    Log::spy();

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
                    return ['title' => 'PII-LEAK-XYZ'];
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
                public function refresh(): static
                {
                    return $this;
                }

                public function only(array $keys): array
                {
                    return [];
                }

                public function getKey(): int
                {
                    return 1;
                }
            };
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            throw new RuntimeException('SQLSTATE[23000] value='.($data['title'] ?? ''));
        }
    };

    $page->autosave();

    expect($page->dispatched[0]['params'])->toHaveKey('status', 'error');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => ! str_contains($message.json_encode($context), 'PII-LEAK-XYZ'))
        ->once();
});
