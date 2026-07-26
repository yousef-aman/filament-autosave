<?php

use Livewire\Livewire;
use YousefAman\FilamentAutosave\AutosaveManager;
use YousefAman\FilamentAutosave\Tests\Fixtures\Integration\CreatePost;
use YousefAman\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use YousefAman\FilamentAutosave\Tests\Fixtures\Integration\Post;

test('an edit page inside a real panel autosaves to the database', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->assertSet('autosaveEnabled', true)
        ->set('data.title', 'Autosaved')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->refresh()->title)->toBe('Autosaved');
});

test('an edit page autosave never writes a password field', function () {
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Autosaved')
        ->set('data.vault_key', 'plain-secret')
        ->call('autosave');

    expect($post->refresh()->title)->toBe('Autosaved');
    expect($post->getAttributes())->not->toHaveKey('vault_key');
});

test('an edit page autosave writes a nested group state path as a whole column', function () {
    $post = Post::create(['title' => 'Original', 'settings' => ['theme' => 'light', 'mode' => 'fast']]);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.settings.theme', 'dark')
        ->call('autosave');

    expect($post->refresh()->settings)->toBe(['theme' => 'dark', 'mode' => 'fast']);
});

test('an edit page autosave refuses a nested select value outside the allowed options', function () {
    $post = Post::create(['title' => 'Original', 'settings' => ['theme' => 'light', 'mode' => 'fast']]);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.settings.mode', 'HACKED')
        ->call('autosave');

    expect($post->refresh()->settings)->toBe(['theme' => 'light', 'mode' => 'fast']);
});

test('an edit page autosave skips a blank required field instead of failing the write', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', '')
        ->set('data.slug', 'changed')
        ->call('autosave');

    $post->refresh();

    expect($post->title)->toBe('Original');
    expect($post->slug)->toBe('changed');
});

test('an edit page can undo the autosave it just made', function () {
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Autosaved')
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true)
        ->call('undoAutosave')
        ->assertDispatched('autosave-status', status: 'undone')
        ->assertSet('autosaveCanUndo', false);

    expect($post->refresh()->title)->toBe('Original');
});

test('a create page inside a real panel drafts to the cache and restores it', function () {
    Livewire::test(CreatePost::class)
        ->set('data.title', 'Drafted')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect(Post::count())->toBe(0);

    $draft = AutosaveManager::restoreDraft(AutosaveManager::cacheKey(CreatePost::class));
    expect($draft)->toHaveKey('title', 'Drafted');

    Livewire::test(CreatePost::class)
        ->assertSet('autosaveHasDraft', true)
        ->call('restoreDraft')
        ->assertSet('data.title', 'Drafted')
        ->assertSet('autosaveHasDraft', false);
});

test('a create page clears its draft once the record is created', function () {
    Livewire::test(CreatePost::class)
        ->set('data.title', 'Drafted')
        ->call('autosave')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Post::where('title', 'Drafted')->exists())->toBeTrue();
    expect(AutosaveManager::restoreDraft(AutosaveManager::cacheKey(CreatePost::class)))->toBeNull();
});

test('a create page keeps its draft when validation halts the create', function () {
    Livewire::test(CreatePost::class)
        ->set('data.slug', 'no-title-yet')
        ->call('autosave')
        ->call('create')
        ->assertHasFormErrors(['title']);

    expect(Post::count())->toBe(0);
    expect(AutosaveManager::restoreDraft(AutosaveManager::cacheKey(CreatePost::class)))
        ->toHaveKey('slug', 'no-title-yet');
});
