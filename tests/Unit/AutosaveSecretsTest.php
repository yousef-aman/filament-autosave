<?php

use Illuminate\Support\Facades\Cache;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosavePasswordCreateFormComponent;
use YousefAman\FilamentAutosave\Tests\Fixtures\AutosavePasswordEditFormComponent;

beforeEach(function () {
    Cache::flush();
});

test('a password input is never written by an edit autosave', function () {
    $component = new AutosavePasswordEditFormComponent;
    $component->form->fill();
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'vault_key' => 'half-typed-secr'];

    $component->autosave();

    expect($component->written)
        ->toBe(['title' => 'Hello']);
});

test('a password input is never stored in a create-page draft', function () {
    $component = new AutosavePasswordCreateFormComponent;
    $component->form->fill();
    $component->mountHasAutosaveForCreate();
    $component->data = ['title' => 'Hello', 'vault_key' => 'super-secret'];

    $component->autosave();

    $draft = AutosaveManager::restoreDraft(
        AutosaveManager::cacheKey(AutosavePasswordCreateFormComponent::class)
    );

    expect($draft)->toBe(['title' => 'Hello']);
});

test('changing only a password input does not trigger a write at all', function () {
    $component = new AutosavePasswordEditFormComponent;
    $component->form->fill(['title' => 'Hello']);
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'vault_key' => 'typing'];

    $component->autosave();

    expect($component->written)->toBe([]);
});

test('a field typed as a password is treated as a secret even without password()', function () {
    $component = new class extends AutosavePasswordEditFormComponent
    {
        public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
        {
            return $schema->components([
                \Filament\Forms\Components\TextInput::make('title'),
                \Filament\Forms\Components\TextInput::make('vault_key')->type('password'),
            ])->statePath('data');
        }
    };
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'vault_key' => 'typed-secret'];

    $component->autosave();

    expect($component->written)->not->toHaveKey('vault_key');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('the shipped config keeps common credential field names out of autosave', function () {
    expect(config('filament-autosave.except'))
        ->toContain('password')
        ->toContain('password_confirmation')
        ->toContain('current_password');
});
