<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveCheckboxListEditFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveMultiOptionCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveSelectEditFormComponent;

/**
 * Integration tests against a REAL Filament Schema (not a hand-rolled fake).
 *
 * The trait methods are driven directly rather than through Livewire::test(),
 * because a bare custom Livewire component rendered under Testbench (no panel,
 * no HTTP middleware) trips Livewire's validation render hook on a missing
 * shared error bag — an environment-only artifact that never occurs in a real
 * Filament panel. Calling the component methods directly still exercises the
 * exact autosave code path without a render.
 */
beforeEach(function () {
    Cache::flush();
});

// --- Edit pages: the payload is written to the database, so it must be dehydrated.

test('edit autosave writes dehydrated state to the record, not raw form state', function () {
    $component = new AutosaveEditFormComponent;
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'code' => 'abc'];

    $component->autosave();

    // dehydrateStateUsing() must be applied: 'abc' -> 'ABC'.
    expect($component->written)->toBe(['title' => 'Hello', 'code' => 'ABC']);
});

test('edit autosave never writes dehydrated(false) fields to the record', function () {
    $component = new AutosaveEditFormComponent;
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'secret' => 'top-secret'];

    $component->autosave();

    expect($component->written)->not->toHaveKey('secret');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave skips a required field that was left blank', function () {
    $component = new AutosaveEditFormComponent;
    $component->mountHasAutosave();

    // slug is required; clearing it must not write null to the NOT NULL column.
    $component->data = ['title' => 'Hello', 'slug' => ''];

    $component->autosave();

    expect($component->written)->not->toHaveKey('slug');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave drops a select value outside the field allowed options', function () {
    $component = new AutosaveSelectEditFormComponent;
    $component->mountHasAutosave();

    // A crafted state injecting an out-of-options (e.g. out-of-tenant) value.
    $component->data = ['title' => 'Hello', 'role' => 'superadmin'];

    $component->autosave();

    expect($component->written)->not->toHaveKey('role');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave keeps a select value that is a valid option', function () {
    $component = new AutosaveSelectEditFormComponent;
    $component->mountHasAutosave();

    $component->data = ['title' => 'Hello', 'role' => 'editor'];

    $component->autosave();

    expect($component->written)->toHaveKey('role', 'editor');
});

test('edit autosave keeps a valid CheckboxList selection (array-valued option field)', function () {
    $component = new AutosaveCheckboxListEditFormComponent;
    $component->mountHasAutosave();

    // A fully valid multi-selection must survive — the option rule is enforced
    // per element, not against the whole array.
    $component->data = ['title' => 'Hello', 'tags' => ['a', 'c']];

    $component->autosave();

    expect($component->written)->toHaveKey('tags', ['a', 'c']);
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave drops a CheckboxList value containing an out-of-options element', function () {
    $component = new AutosaveCheckboxListEditFormComponent;
    $component->mountHasAutosave();

    // 'zzz' is not an allowed option — the whole field is skipped, as a normal
    // save would reject it.
    $component->data = ['title' => 'Hello', 'tags' => ['a', 'zzz']];

    $component->autosave();

    expect($component->written)->not->toHaveKey('tags');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave drops client-injected keys that are not declared form fields', function () {
    $component = new AutosaveEditFormComponent;
    $component->mountHasAutosave();

    // Simulate a crafted Livewire request injecting a non-field column.
    $component->data = ['title' => 'Hello', 'is_admin' => 1];

    $component->autosave();

    expect($component->written)->not->toHaveKey('is_admin');
    expect($component->written)->toHaveKey('title', 'Hello');
});

// --- Create pages: the draft is re-filled into the live form, so it must stay raw
//     (dehydrating it would double-apply non-idempotent transforms on restore).

test('create autosave stores the raw typed values so drafts restore cleanly', function () {
    $component = new AutosaveCreateFormComponent;
    $component->mountHasAutosaveForCreate();
    $component->data = ['title' => 'Hello', 'code' => 'abc'];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveCreateFormComponent::class));

    // Raw value preserved (NOT dehydrated to 'ABC') — restore must round-trip.
    expect($draft)->toBe(['title' => 'Hello', 'code' => 'abc']);
});

test('create autosave keeps valid CheckboxList, multiple ToggleButtons and multiple Select selections in the draft', function () {
    $component = new AutosaveMultiOptionCreateFormComponent;
    $component->mount();

    $component->data = [
        'title' => 'Hello',
        'tags' => ['a', 'c'],
        'perms' => ['r', 'w'],
        'roles' => ['admin', 'editor'],
    ];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveMultiOptionCreateFormComponent::class));

    expect($draft)->toBe([
        'title' => 'Hello',
        'tags' => ['a', 'c'],
        'perms' => ['r', 'w'],
        'roles' => ['admin', 'editor'],
    ]);
});

test('create autosave drops an out-of-options element from an array-valued option field', function () {
    $component = new AutosaveMultiOptionCreateFormComponent;
    $component->mount();

    $component->data = [
        'title' => 'Hello',
        'tags' => ['a', 'nope'],
    ];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveMultiOptionCreateFormComponent::class));

    expect($draft)->toHaveKey('title', 'Hello');
    expect($draft)->not->toHaveKey('tags');
});
