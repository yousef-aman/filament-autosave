# Filament Autosave

Automatic form saving for Filament v4 and v5 with a visual status indicator and one-step Undo on Edit pages.

- **Edit pages** — changes are written to the database after a debounce.
- **Create and custom pages** — unsubmitted changes are stored as a draft in Laravel Cache. When the user returns, they can *Restore* or *Discard* the draft.

## Requirements

- PHP 8.2+
- Laravel 11+
- Filament v4 or v5
- Livewire v3 or v4

## Installation

```bash
composer require yousefaman/filament-autosave
```

Register the plugin in your panel provider:

```php
use YousefAman\FilamentAutosave\AutosavePlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(AutosavePlugin::make());
}
```

The status indicator ships its own CSS/JS assets. If you copy Filament's assets
into `public/` for production, re-run:

```bash
php artisan filament:assets
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

### Edit pages

```php
use Filament\Resources\Pages\EditRecord;
use YousefAman\FilamentAutosave\HasAutosave;

class EditArticle extends EditRecord
{
    use HasAutosave;

    protected static string $resource = ArticleResource::class;
}
```

The form autosaves 1.5 s after the last keystroke. After each save, an **Undo** button appears briefly to revert that write.

Autosave persists the form's **dehydrated** state — the same values Filament would
write on a normal save (`dehydrateStateUsing()` transforms and casts applied,
`dehydrated(false)` fields skipped) — but without running validation, so an
incomplete form never blocks the save. Relationship fields (Repeater,
`BelongsToMany`, etc.) are `dehydrated(false)` and are therefore **not** autosaved;
they persist on explicit form submit.

### Create pages

```php
use Filament\Resources\Pages\CreateRecord;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

class CreateArticle extends CreateRecord
{
    use HasAutosaveForCreate;

    protected static string $resource = ArticleResource::class;
}
```

### Custom Filament pages

For pages without an Eloquent record. The form must use the default `data`
state path (`->statePath('data')`), which is what the status indicator watches:

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

Call `$this->clearAutosaveDraft()` from your save handler to drop the draft once the user submits.

## Configuration

Every option can be set at three levels, merged in this order: **config → plugin → page** (later wins, except `except` / `exceptPages` arrays which are unioned).

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

### Exclude specific pages

Suppress the indicator on individual pages that use one of the traits (an
alternative to setting `$autosaveEnabled = false` on the page itself):

```php
AutosavePlugin::make()
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
    ->showTimestamp(false)
    ->indicatorPosition('after');
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

## Files and sensitive data

`TemporaryUploadedFile` instances are automatically dropped from the autosave payload. Anything listed in `$autosaveExcept` is removed from both the autosave write and the draft restore. Client-submitted keys that don't map to a declared form field are also discarded, so autosave can only ever write real form fields.

`$autosaveExcept` matches **top-level** field names only; it does not descend into
nested keys (e.g. a secret inside a Repeater row). Keep sensitive values out of the
form, or mark such fields `->dehydrated(false)` to exclude them from autosave.

## Translations

Publish translations with `--tag="filament-autosave-translations"` to customize any of the indicator labels (`unsaved`, `saving`, `saved`, `saved_at`, `undo`, `undone`, `error`, `draft_available`, `restore`, `discard`, `restored`).

## Testing

```bash
composer test
```

## License

MIT
