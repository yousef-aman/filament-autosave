<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;

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
