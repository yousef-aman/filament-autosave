<?php

use YousefAman\FilamentAutosave\AutosavePlugin;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;

function autosavePlugin(): AutosavePlugin
{
    return AutosavePlugin::make();
}

test('detectMode identifies edit pages', function () {
    $mode = (fn ($c) => $this->detectMode($c))->call(autosavePlugin(), AutosaveEditFormComponent::class);

    expect($mode)->toBe('edit');
});

test('detectMode identifies create pages', function () {
    $mode = (fn ($c) => $this->detectMode($c))->call(autosavePlugin(), AutosaveCreateFormComponent::class);

    expect($mode)->toBe('create');
});

test('detectMode returns null for unrelated classes', function () {
    $mode = (fn ($c) => $this->detectMode($c))->call(autosavePlugin(), stdClass::class);

    expect($mode)->toBeNull();
});

test('resolveAutosaveMode skips excepted pages', function () {
    $plugin = autosavePlugin()->exceptPages([AutosaveEditFormComponent::class]);

    $mode = (fn ($s) => $this->resolveAutosaveMode($s))->call($plugin, [AutosaveEditFormComponent::class]);

    expect($mode)->toBeNull();
});

test('resolveAutosaveMode returns the first matching mode', function () {
    $mode = (fn ($s) => $this->resolveAutosaveMode($s))
        ->call(autosavePlugin(), [stdClass::class, AutosaveCreateFormComponent::class]);

    expect($mode)->toBe('create');
});

test('getDebounce and getCacheTtl fall back to config', function () {
    config(['filament-autosave.debounce' => 2500, 'filament-autosave.cache_ttl' => 48]);

    expect(autosavePlugin()->getDebounce())->toBe(2500)
        ->and(autosavePlugin()->getCacheTtl())->toBe(48);
});

test('shouldShowTimestamp honors the plugin override then config', function () {
    config(['filament-autosave.show_timestamp' => false]);

    expect(autosavePlugin()->shouldShowTimestamp())->toBeFalse()
        ->and(autosavePlugin()->showTimestamp(true)->shouldShowTimestamp())->toBeTrue();
});
