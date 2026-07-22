<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\Tests\Fixtures\FakeCreateRecordBase;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

beforeEach(function () {
    Cache::flush();
});

function makeCreateRecordPage(): object
{
    return new class extends FakeCreateRecordBase
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        /** @var array<int, array<string, mixed>> */
        public array $dispatched = [];

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }
    };
}

function seedDraft(object $page): void
{
    Cache::put(AutosaveManager::cacheKey(get_class($page)), ['title' => 'Draft'], 3600);
    $page->autosaveHasDraft = true;
}

function pageDraft(object $page): ?array
{
    return Cache::get(AutosaveManager::cacheKey(get_class($page)));
}

test('create clears the draft on a normal successful create', function () {
    $page = makeCreateRecordPage();
    seedDraft($page);

    $page->create();

    expect(pageDraft($page))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('create clears the draft on "create & create another"', function () {
    $page = makeCreateRecordPage();
    seedDraft($page);

    $page->create(another: true);

    expect(pageDraft($page))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('create keeps the draft when creation halts', function () {
    $page = makeCreateRecordPage();
    $page->shouldHalt = true;
    seedDraft($page);

    $page->create(another: true);

    expect(pageDraft($page))->toBe(['title' => 'Draft']);
});

test('create clears the draft even when the page defines its own afterCreate', function () {
    // A page-level afterCreate() shadows any trait hook of the same name, so
    // draft clearing must not depend on it. The RecordCreated event still fires.
    $page = new class extends FakeCreateRecordBase
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        public bool $ownAfterCreateRan = false;

        public function dispatch(string $event, ...$params): void {}

        protected function afterCreate(): void
        {
            $this->ownAfterCreateRan = true;
        }
    };

    seedDraft($page);

    $page->create(another: true);

    expect(pageDraft($page))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});
