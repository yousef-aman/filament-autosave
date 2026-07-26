<?php

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
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

test('the indicator renders once from the header hook, not again at the end of the page', function () {
    $plugin = autosavePlugin();
    $plugin->boot(Filament\Panel::make());

    $scopes = [AutosaveEditFormComponent::class];

    FilamentView::renderHook(PanelsRenderHook::PAGE_START, scopes: $scopes);
    $header = (string) FilamentView::renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, scopes: $scopes);
    $end = (string) FilamentView::renderHook(PanelsRenderHook::PAGE_END, scopes: $scopes);

    expect($header)->toContain('fi-autosave-indicator');
    expect($end)->toBe('');
});

test('the indicator falls back to the end of the page when no header is rendered', function () {
    $plugin = autosavePlugin();
    $plugin->boot(Filament\Panel::make());

    $scopes = [AutosaveEditFormComponent::class];

    // A page overriding getHeader() never fires the header-actions hook.
    FilamentView::renderHook(PanelsRenderHook::PAGE_START, scopes: $scopes);
    $end = (string) FilamentView::renderHook(PanelsRenderHook::PAGE_END, scopes: $scopes);

    expect($end)->toContain('fi-autosave-indicator');
});

test('the end-of-page fallback stays silent for pages without autosave', function () {
    $plugin = autosavePlugin();
    $plugin->boot(Filament\Panel::make());

    FilamentView::renderHook(PanelsRenderHook::PAGE_START, scopes: [stdClass::class]);
    $end = (string) FilamentView::renderHook(PanelsRenderHook::PAGE_END, scopes: [stdClass::class]);

    expect($end)->toBe('');
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
