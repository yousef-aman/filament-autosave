<?php

use YousefAman\FilamentAutosave\AutosaveManager;

test('it detects changed fields between snapshots', function () {
    $old = ['name' => 'John', 'email' => 'john@example.com', 'bio' => 'Hello'];
    $new = ['name' => 'Jane', 'email' => 'john@example.com', 'bio' => 'Updated'];

    $changed = AutosaveManager::getChangedFields($old, $new);

    expect($changed)->toBe(['name' => 'Jane', 'bio' => 'Updated']);
});

test('it returns empty array when nothing changed', function () {
    $data = ['name' => 'John', 'email' => 'john@example.com'];

    $changed = AutosaveManager::getChangedFields($data, $data);

    expect($changed)->toBe([]);
});

test('it detects changes in nested arrays', function () {
    $old = ['name' => 'John', 'address' => ['city' => 'Riyadh', 'zip' => '12345']];
    $new = ['name' => 'John', 'address' => ['city' => 'Jeddah', 'zip' => '12345']];

    $changed = AutosaveManager::getChangedFields($old, $new);

    expect($changed)->toBe(['address' => ['city' => 'Jeddah', 'zip' => '12345']]);
});

test('it excludes specified fields from data', function () {
    $data = ['name' => 'John', 'password' => 'secret', 'email' => 'john@example.com'];
    $except = ['password'];

    $filtered = AutosaveManager::excludeFields($data, $except);

    expect($filtered)->toBe(['name' => 'John', 'email' => 'john@example.com']);
});

test('it excludes multiple fields', function () {
    $data = ['name' => 'John', 'password' => 'secret', 'password_confirmation' => 'secret', 'email' => 'john@example.com'];
    $except = ['password', 'password_confirmation'];

    $filtered = AutosaveManager::excludeFields($data, $except);

    expect($filtered)->toBe(['name' => 'John', 'email' => 'john@example.com']);
});

test('it returns original data when except list is empty', function () {
    $data = ['name' => 'John', 'email' => 'john@example.com'];

    $filtered = AutosaveManager::excludeFields($data, []);

    expect($filtered)->toBe($data);
});

test('it creates a deterministic snapshot hash', function () {
    $data = ['name' => 'John', 'email' => 'john@example.com'];

    $hash1 = AutosaveManager::snapshotHash($data);
    $hash2 = AutosaveManager::snapshotHash($data);

    expect($hash1)->toBe($hash2);
});

test('it creates different hashes for different data', function () {
    $data1 = ['name' => 'John'];
    $data2 = ['name' => 'Jane'];

    expect(AutosaveManager::snapshotHash($data1))
        ->not->toBe(AutosaveManager::snapshotHash($data2));
});

test('cacheKey generates correct format', function () {
    $key = AutosaveManager::cacheKey('App\\Filament\\Resources\\Articles\\Pages\\CreateArticle');

    $expectedUserId = auth()->id() ?? session()->getId();

    expect($key)->toBe('filament-autosave:' . $expectedUserId . ':App\\Filament\\Resources\\Articles\\Pages\\CreateArticle');
});

test('cacheKey falls back to session id for guests', function () {
    auth()->logout();

    $key = AutosaveManager::cacheKey('App\\Some\\Page');

    expect($key)->toContain('filament-autosave:');
    expect($key)->not->toBe('filament-autosave::App\\Some\\Page');
});

test('storeDraft and restoreDraft round-trip', function () {
    $key = 'filament-autosave:test:draft';
    $data = ['title' => 'My Article', 'body' => 'Content here'];

    AutosaveManager::storeDraft($key, $data, 1);

    expect(AutosaveManager::restoreDraft($key))->toBe($data);
});

test('clearDraft removes cached data', function () {
    $key = 'filament-autosave:test:clear';
    AutosaveManager::storeDraft($key, ['title' => 'Test'], 1);

    AutosaveManager::clearDraft($key);

    expect(AutosaveManager::restoreDraft($key))->toBeNull();
});

test('restoreDraft returns null when no draft exists', function () {
    expect(AutosaveManager::restoreDraft('filament-autosave:nonexistent:key'))->toBeNull();
});
