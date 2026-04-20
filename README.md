# Filament Autosave

Google-Docs-style automatic saving for Filament v5. Works on Resource **Edit** pages, Resource **Create** pages, and custom Filament **Pages**. Ships with a visual status indicator (Unsaved → Saving → Saved) and one-step Undo on Edit pages.

- **Edit pages** — changes are written straight to the database after a debounce.
- **Create pages & custom pages** — unsubmitted changes are stored as a **draft** in Laravel Cache. When the user comes back, they get a banner to *Restore* or *Discard* the draft.

## Requirements

- PHP 8.2+
- Laravel 11+
- Filament v4 or v5
- Livewire v3 or v4

## Installation

```bash
composer require yousefaman/filament-autosave
php artisan filament:assets
```

> Re-run `php artisan filament:assets` after every upgrade of this package — the Alpine component and CSS live under `public/js/yousefaman/filament-autosave` and need to be republished.

Register the plugin in your panel provider:

```php
use YousefAman\FilamentAutosave\AutosavePlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(AutosavePlugin::make());
}
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag="filament-autosave-config"
```

Publish the translations (optional):

```bash
php artisan vendor:publish --tag="filament-autosave-translations"
```

## Usage

### Edit pages — save to DB

```php
use Filament\Resources\Pages\EditRecord;
use YousefAman\FilamentAutosave\HasAutosave;

class EditArticle extends EditRecord
{
    use HasAutosave;

    protected static string $resource = ArticleResource::class;
}
```

That's it. The form autosaves **1.5 s** after the last keystroke. When a save succeeds, an *Undo* button appears briefly so the user can revert that last write.

### Create pages — draft to cache

```php
use Filament\Resources\Pages\CreateRecord;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

class CreateArticle extends CreateRecord
{
    use HasAutosaveForCreate;

    protected static string $resource = ArticleResource::class;
}
```

No override of `mount()`, `create()`, or `afterCreate()` is needed — the trait wires itself up via Livewire's `mount{TraitName}` convention and cleans the draft after a successful `create()`.

### Custom Filament pages — draft to cache

For pages without an Eloquent record (e.g., a `UserPreferences` dashboard):

```php
use Filament\Pages\Page;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

class UserPreferences extends Page
{
    use HasAutosaveForCreate;

    public ?array $data = [];

    public function save(): void
    {
        $data = $this->form->getState();
        // ... persist however you like

        $this->clearAutosaveDraft();
    }
}
```

Call `$this->clearAutosaveDraft()` from your save handler to drop the draft once the user actually submits.

## Configuration

Every option can be set at three levels, merged in this order: **config → plugin → page** (the later wins, except `except` / `exceptPages` arrays which are unioned).

### Debounce

```php
AutosavePlugin::make()->debounce(2000);      // plugin-wide
protected int $autosaveDebounce = 2000;       // per-page
// config/filament-autosave.php
'debounce' => 1500,
```

### Exclude fields

```php
AutosavePlugin::make()->except(['password']);
protected array $autosaveExcept = ['password', 'password_confirmation'];
'except' => ['password'],
```

### Exclude pages from global mode

```php
AutosavePlugin::make()
    ->global()
    ->exceptPages([EditPayment::class]);
```

### Draft TTL (Create pages)

```php
AutosavePlugin::make()->cacheTtl(48);         // hours
'cache_ttl' => 24,
```

### Indicator

```php
AutosavePlugin::make()
    ->showTimestamp(false)            // hide "Saved at 3:14 PM"
    ->indicatorPosition('after');     // render after header actions instead of before
```

### Per-page disable

```php
class EditSensitive extends EditRecord
{
    use HasAutosave;

    public bool $autosaveEnabled = false;
}
```

## Lifecycle hooks

```php
protected function beforeAutosave(array $data): array
{
    // Mutate data before it's written.
    return $data;
}

protected function afterAutosave(object $record): void
{
    Cache::forget("user-{$record->id}");
}

/** @return array<string, mixed> */
protected function getAutosaveValidationRules(): array
{
    return [
        'slug' => ['required', 'string', 'max:120'],
    ];
}
```

`getAutosaveValidationRules()` is checked *per changed field only* — invalid fields are silently skipped so autosave never blocks the user. Full form validation still runs at submit time.

## Undo (Edit pages)

After a successful autosave, the indicator shows an **Undo** button for 5 seconds. Clicking it:

1. Reads the pre-save values from a short-lived cache entry (30 min by default, override via `protected function getUndoTtlMinutes(): int`).
2. Writes them back through `handleRecordUpdate()`.
3. Re-fills the form.
4. Clears the undo snapshot.

Undo values are round-tripped through JSON so JSON-cast columns are stored as arrays (not as encoded strings) and date columns survive the cache trip.

## Files and sensitive data

The trait automatically drops `TemporaryUploadedFile` instances from the autosave payload (you can't cache an in-flight upload). Anything you put in `$autosaveExcept` is removed from both the autosave write *and* the draft restore, so secrets can't round-trip through the cache.

## Translations

The package ships with English strings under the `filament-autosave` namespace:

```
unsaved, saving, saved, saved_at, undo, undone, error, draft_available, restore, discard, restored
```

Publish with `--tag="filament-autosave-translations"` to customize.

## How it works

1. An Alpine.js component watches `$wire.data` for changes.
2. After the debounce period, it calls the Livewire `autosave()` method.
3. Server takes a snapshot hash of the current form state (order-independent xxh128).
4. If the hash matches the last snapshot, the save is a no-op.
5. For **Edit** pages, the changed fields are passed to `handleRecordUpdate()`; previous values are cached under a short TTL to power Undo.
6. For **Create** pages, the filtered payload is stored in Laravel Cache under a user-scoped key (`filament-autosave:{user}:{page-class}`). On mount, if a draft exists, the Alpine component shows a **Restore / Discard** banner.

## Testing

```bash
composer test
```

The package ships with 41 Pest tests covering the save path, draft lifecycle, undo, hash collisions, and file-upload filtering.

## License

MIT
