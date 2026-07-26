<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveNestedCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosaveNestedEditFormComponent;

/**
 * Nested fields arrive as dotted keys (`settings.name`, `items.<uuid>.kind`). A
 * container is written only when its whole subtree survived: writing it
 * half-populated would wipe the skipped field's stored value.
 */
beforeEach(function () {
    Cache::flush();
});

test('edit autosave never writes a password nested under a group state path', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->mountHasAutosave();
    $component->data = [
        'title' => 'Hello',
        'secrets' => ['label' => 'Prod', 'api_key' => 'plain-secret'],
    ];

    $component->autosave();

    expect($component->written)->not->toHaveKey('secrets');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave skips a group holding a password whatever the child order', function () {
    $component = new class extends AutosaveNestedEditFormComponent
    {
        public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
        {
            return $schema->components([
                \Filament\Forms\Components\TextInput::make('title'),
                \Filament\Schemas\Components\Group::make([
                    \Filament\Forms\Components\TextInput::make('api_key')->password(),
                    \Filament\Forms\Components\TextInput::make('label'),
                ])->statePath('secrets'),
            ])->statePath('data');
        }
    };
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'secrets' => ['api_key' => 's', 'label' => 'Prod']];

    $component->autosave();

    expect($component->written)->not->toHaveKey('secrets');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave writes a group with no skipped field', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->mountHasAutosave();
    $component->data = [
        'title' => 'Hello',
        'settings' => ['name' => 'John', 'mode' => 'slow'],
    ];

    $component->autosave();

    expect($component->written['settings'] ?? [])->toBe(['name' => 'John', 'mode' => 'slow']);
});

test('edit autosave skips a group holding a select value outside the allowed options', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->mountHasAutosave();
    $component->data = [
        'title' => 'Hello',
        'settings' => ['name' => 'John', 'mode' => 'HACKED'],
    ];

    $component->autosave();

    expect($component->written)->not->toHaveKey('settings');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave drops client-injected keys nested inside a declared group', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->mountHasAutosave();
    $component->data = [
        'title' => 'Hello',
        'settings' => ['name' => 'John', 'mode' => 'fast', 'is_admin' => 1],
    ];

    $component->autosave();

    expect($component->written['settings'] ?? [])->toBe(['name' => 'John', 'mode' => 'fast']);
});

test('edit autosave never writes a repeater holding a password field', function () {
    $component = new AutosaveNestedEditFormComponent;
    // Rows must exist before the schema is first walked, as in a real request.
    $component->data = ['vault' => ['row1' => ['label' => 'First', 'secret' => '']]];
    $component->mountHasAutosave();

    $component->data['title'] = 'Hello';
    $component->data['vault']['row1']['label'] = 'Edited';
    $component->data['vault']['row1']['secret'] = 'plain-row-secret';

    $component->autosave();

    expect($component->written)->not->toHaveKey('vault');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave skips a repeater whose row holds a select value outside the allowed options', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->data = ['items' => ['row1' => ['label' => 'First', 'kind' => 'a']]];
    $component->mountHasAutosave();

    $component->data['title'] = 'Hello';
    $component->data['items']['row1']['kind'] = 'HACKED';

    $component->autosave();

    expect($component->written)->not->toHaveKey('items');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave writes a repeater whose rows are all valid', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->data = ['items' => ['row1' => ['label' => 'First', 'kind' => 'a']]];
    $component->mountHasAutosave();

    $component->data['title'] = 'Hello';
    $component->data['items']['row1']['label'] = 'Edited';

    $component->autosave();

    expect($component->written['items'] ?? [])->toBe([['label' => 'Edited', 'kind' => 'a']]);
});

test('create draft prunes only the nested password, keeping the rest of the group', function () {
    $component = new AutosaveNestedCreateFormComponent;
    $component->mount();
    $component->data = [
        'title' => 'Hello',
        'settings' => ['name' => 'John', 'api_key' => 'plain-secret'],
    ];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveNestedCreateFormComponent::class));

    // A draft is re-filled, never written to a column: keep the siblings.
    expect($draft['settings'] ?? [])->toBe(['name' => 'John']);
    expect($draft)->toHaveKey('title', 'Hello');
});
