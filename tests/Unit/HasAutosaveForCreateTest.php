<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;

beforeEach(function () {
    Cache::flush();
});

function makeCreatePage(array $formState = []): object
{
    return new class($formState)
    {
        use HasAutosaveForCreate;

        public object $form;

        public ?array $data = [];

        /** @var array<int, array<string, mixed>> */
        public array $dispatched = [];

        /** @var array<string> */
        public array $exceptFields = [];

        public function __construct(array $formState)
        {
            $this->form = new class($formState)
            {
                private array $state;

                public function __construct(array $state)
                {
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

        /** @return array<string> */
        protected function autosaveExcept(): array
        {
            return $this->exceptFields;
        }
    };
}

test('autosave stores draft in cache', function () {
    $page = makeCreatePage(['title' => 'Original', 'body' => 'Content']);
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'Updated', 'body' => 'Content']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBe(['title' => 'Updated', 'body' => 'Content']);
});

test('autosave is skipped when disabled', function () {
    $page = makeCreatePage(['title' => 'Any']);
    $page->autosaveEnabled = false;

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBeNull();
});

test('autosave skips persistence when data unchanged', function () {
    $page = makeCreatePage(['title' => 'Same']);
    $page->mountHasAutosaveForCreate();

    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBeNull();
    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('autosave excludes reserved fields', function () {
    $page = makeCreatePage(['title' => 'A', 'secret_note' => 'secret']);
    $page->exceptFields = ['secret_note'];
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'B', 'secret_note' => 'newsecret']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    $cached = Cache::get($key);

    expect($cached)->toHaveKey('title', 'B');
    expect($cached)->not->toHaveKey('secret_note');
});

test('autosave strips empty values from payload', function () {
    $page = makeCreatePage(['title' => 'A']);
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'B', 'note' => '', 'tags' => []]);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBe(['title' => 'B']);
});

test('mountHasAutosaveForCreate sets hasDraft when draft exists', function () {
    $page = makeCreatePage();
    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Saved Draft'], 3600);

    $page->mountHasAutosaveForCreate();

    expect($page->autosaveHasDraft)->toBeTrue();
});

test('mountHasAutosaveForCreate leaves hasDraft false when no draft', function () {
    $page = makeCreatePage();

    $page->mountHasAutosaveForCreate();

    expect($page->autosaveHasDraft)->toBeFalse();
});

test('restoreDraft fills the form and flips hasDraft', function () {
    $page = makeCreatePage(['title' => '']);
    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Draft Title'], 3600);
    $page->autosaveHasDraft = true;

    $page->restoreDraft();

    expect($page->form->getRawState())->toBe(['title' => 'Draft Title']);
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('restoreDraft dispatches idle when no draft exists so the indicator does not hang', function () {
    $page = makeCreatePage(['title' => 'Empty']);

    $page->restoreDraft();

    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('restoreDraft excludes reserved fields from cached data', function () {
    $page = makeCreatePage();
    $page->exceptFields = ['secret_note'];
    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Hello', 'secret_note' => 'leaked'], 3600);

    $page->restoreDraft();

    expect($page->form->getRawState())->toBe(['title' => 'Hello']);
});

test('discardDraft is refused when the page denies access', function () {
    $page = new class extends AutosaveCreateFormComponent
    {
        public function authorizeAccess(): void
        {
            throw new \RuntimeException('denied');
        }
    };
    $page->mount();

    $key = (fn () => $this->getAutosaveCacheKey())->call($page);
    AutosaveManager::storeDraft($key, ['title' => 'Draft'], 1);

    expect(fn () => $page->discardDraft())->toThrow(\RuntimeException::class);
    expect(AutosaveManager::restoreDraft($key))->not->toBeNull();
});

test('discardDraft clears cache and flips hasDraft', function () {
    $page = makeCreatePage();
    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Draft'], 3600);
    $page->autosaveHasDraft = true;

    $page->discardDraft();

    expect(Cache::get($key))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('stripFileUploads removes upload instances but keeps their siblings', function () {
    $page = makeCreatePage();

    $upload = Mockery::mock(TemporaryUploadedFile::class);

    $result = (fn (array $d) => $this->stripFileUploads($d))->call($page, [
        'title' => 'Hello',
        'avatar' => $upload,
        'attachments' => [$upload],
        'count' => 5,
    ]);

    // The scalar upload key is dropped; a pure-upload list collapses to [];
    // unrelated fields are untouched.
    expect($result)->toBe(['title' => 'Hello', 'attachments' => [], 'count' => 5]);
});

test('stripFileUploads keeps sibling fields inside a repeater row that has an upload', function () {
    $page = makeCreatePage();

    $upload = Mockery::mock(TemporaryUploadedFile::class);

    $result = (fn (array $d) => $this->stripFileUploads($d))->call($page, [
        'title' => 'Post',
        'items' => [
            ['name' => 'a', 'photo' => $upload],
            ['name' => 'b'],
        ],
    ]);

    // Only the nested upload is removed — the whole 'items' container and its
    // sibling fields must survive.
    expect($result)->toBe([
        'title' => 'Post',
        'items' => [
            ['name' => 'a'],
            ['name' => 'b'],
        ],
    ]);
});

test('stripFileUploads preserves scalars and non-upload objects', function () {
    $page = makeCreatePage();

    $carbon = Carbon::parse('2026-04-20');

    $result = (fn (array $d) => $this->stripFileUploads($d))->call($page, [
        'title' => 'Hello',
        'count' => 42,
        'active' => true,
        'tags' => ['a', 'b'],
        'at' => $carbon,
    ]);

    expect($result)->toHaveKey('title', 'Hello');
    expect($result)->toHaveKey('count', 42);
    expect($result)->toHaveKey('active', true);
    expect($result)->toHaveKey('tags');
    expect($result)->toHaveKey('at');
});

test('autosave clears an existing draft when every field is cleared', function () {
    $page = makeCreatePage(['title' => 'Initial']);
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'Something']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->not->toBeNull();

    // Clearing every field must not leave a stale draft behind for restore.
    $page->form->setState(['title' => '']);
    $page->autosave();

    expect(Cache::get($key))->toBeNull();
});

test('autosave does not re-save unchanged data on subsequent ticks', function () {
    $page = makeCreatePage(['title' => 'Initial']);
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    $firstWrite = Cache::get($key);
    Cache::forget($key);

    $page->autosave();

    expect(Cache::get($key))->toBeNull();
    expect($firstWrite)->toBe(['title' => 'Changed']);
});
