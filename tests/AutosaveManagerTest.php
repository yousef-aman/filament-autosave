<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;

test('it excludes specified fields from data', function () {
    $data = ['name' => 'John', 'password' => 'secret', 'email' => 'john@example.com'];

    $filtered = AutosaveManager::excludeFields($data, ['password']);

    expect($filtered)->toBe(['name' => 'John', 'email' => 'john@example.com']);
});

test('it excludes multiple fields', function () {
    $data = ['name' => 'John', 'password' => 'secret', 'password_confirmation' => 'secret', 'email' => 'john@example.com'];

    $filtered = AutosaveManager::excludeFields($data, ['password', 'password_confirmation']);

    expect($filtered)->toBe(['name' => 'John', 'email' => 'john@example.com']);
});

test('it returns original data when except list is empty', function () {
    $data = ['name' => 'John', 'email' => 'john@example.com'];

    expect(AutosaveManager::excludeFields($data, []))->toBe($data);
});

test('snapshotHash is deterministic for same data', function () {
    $data = ['name' => 'John', 'email' => 'john@example.com'];

    expect(AutosaveManager::snapshotHash($data))
        ->toBe(AutosaveManager::snapshotHash($data));
});

test('snapshotHash differs when values change', function () {
    expect(AutosaveManager::snapshotHash(['name' => 'John']))
        ->not->toBe(AutosaveManager::snapshotHash(['name' => 'Jane']));
});

test('snapshotHash is order-independent', function () {
    $a = ['name' => 'John', 'email' => 'john@example.com'];
    $b = ['email' => 'john@example.com', 'name' => 'John'];

    expect(AutosaveManager::snapshotHash($a))->toBe(AutosaveManager::snapshotHash($b));
});

test('snapshotHash does not collide on escaped slashes', function () {
    expect(AutosaveManager::snapshotHash(['value' => 'a\\b']))
        ->not->toBe(AutosaveManager::snapshotHash(['value' => 'ab']));
});

test('snapshotHash does not collide distinct states that fail JSON encoding', function () {
    // Invalid UTF-8 makes json_encode fail; two distinct un-encodable states
    // must still hash differently, or a real change could be judged "unchanged"
    // and silently skipped.
    $a = AutosaveManager::snapshotHash(['value' => "\xB1\x31"]);
    $b = AutosaveManager::snapshotHash(['value' => "\xC3\x28"]);

    expect($a)->not->toBe($b);
});

test('snapshotHash still distinguishes an encodable state from an un-encodable one', function () {
    expect(AutosaveManager::snapshotHash(['value' => 'ok']))
        ->not->toBe(AutosaveManager::snapshotHash(['value' => "\xB1\x31"]));
});

test('cacheKey generates correct format for authenticated users', function () {
    $key = AutosaveManager::cacheKey('App\\Some\\Page');
    $expectedOwner = auth()->id() ?? session()->getId();

    expect($key)->toBe('filament-autosave:'.$expectedOwner.':App\\Some\\Page');
});

test('cacheKey is scoped by the active tenant', function () {
    $tenant = new class extends Illuminate\Database\Eloquent\Model
    {
        protected $guarded = [];
    };
    $tenant->setAttribute($tenant->getKeyName(), 7);

    Filament::shouldReceive('getCurrentPanel')->andReturnNull();
    Filament::shouldReceive('getTenant')->andReturn($tenant);

    expect(AutosaveManager::cacheKey('App\\Some\\Page'))->toContain(':7:');
});

test('currentScope prefers the active panel guard user id over the default guard', function () {
    $guard = Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
    $guard->shouldReceive('id')->andReturn(42);

    $panel = Mockery::mock(\Filament\Panel::class);
    $panel->shouldReceive('auth')->andReturn($guard);

    Filament::shouldReceive('getCurrentPanel')->andReturn($panel);
    Filament::shouldReceive('getTenant')->andReturnNull();

    expect(AutosaveManager::currentScope())->toBe('42');
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

test('restoreDraft returns null when cached value is not an array', function () {
    $key = 'filament-autosave:test:string';
    Cache::put($key, 'not-an-array', 3600);

    expect(AutosaveManager::restoreDraft($key))->toBeNull();
});
