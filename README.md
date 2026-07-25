# Filament Autosave

Automatic form saving for Filament v4 and v5 with a visual status indicator and one-step Undo on Edit pages.

- **Edit pages** — changes are written to the database after a debounce.
- **Create and custom pages** — unsubmitted changes are stored as a draft in Laravel Cache. When the user returns, they can *Restore* or *Discard* the draft.

## Requirements

- PHP 8.2+
- Filament v4 or v5
- Laravel 11, 12 or 13
- Livewire v3 (with Filament v4) or v4 (with Filament v5)

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

Publish the indicator view to customize its markup (optional):

```bash
php artisan vendor:publish --tag="filament-autosave-views"
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
incomplete form never blocks the save. Relationship-backed fields — anything using
`->relationship()`, a multiple `Select`, `BelongsToMany`, etc. — are
`dehydrated(false)` and are therefore **not** autosaved; they persist on explicit
form submit. A plain (non-relationship) `Repeater` or `CheckboxList` stores to its
own column, so it *is* dehydrated and *is* autosaved.

Because validation is skipped, field-level rules (`minLength`, `maxValue`,
`in:`, etc.) are **not** enforced during autosave — only on explicit submit. A
`required` field left blank is skipped rather than written (so it never violates a
`NOT NULL` column), and you can enforce specific rules non-blockingly via
`getAutosaveValidationRules()` (see below).

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

Each option supports the levels listed below, merged so the **later** wins
(`config → plugin → page`), except `except`, which is unioned across all levels.

| Option | config | plugin | page |
| --- | :---: | :---: | :---: |
| `debounce` | ✓ | ✓ | ✓ |
| `except` | ✓ | ✓ | ✓ |
| `cache_ttl` | ✓ | ✓ | — |
| `show_timestamp` | ✓ | ✓ | — |
| `indicatorPosition` | — | ✓ | — |
| `exceptPages` | — | ✓ | — |

> Page-level options are **methods**. Do not redeclare a property the trait
> already defines (`$autosaveEnabled`, `$autosaveSnapshotHash`,
> `$autosaveDebounceMs`, `$autosaveCanUndo`, `$autosaveHasDraft`): PHP treats a
> redeclaration with a different default as an incompatible trait composition and
> raises a fatal error.

### Debounce

```php
AutosavePlugin::make()->debounce(2000);       // plugin-wide

// per-page
protected function autosaveDebounce(): ?int
{
    return 2000;
}

// config/filament-autosave.php
'debounce' => 1500,
```

### Exclude fields

```php
AutosavePlugin::make()->except(['internal_notes']);

// per-page — unioned with the plugin and config lists
protected function autosaveExcept(): array
{
    return ['internal_notes'];
}

// config/filament-autosave.php
'except' => ['password', 'password_confirmation'],
```

### Exclude specific pages

Suppress the indicator on individual pages that use one of the traits (an
alternative to `shouldAutosave()` on the page itself):

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

    protected function shouldAutosave(): bool
    {
        return false;
    }
}
```

`shouldAutosave()` is authoritative on the server, so a disabled page cannot be
switched back on from the browser.

## Lifecycle hooks

`beforeAutosave()` and `getAutosaveValidationRules()` apply to both Edit and
Create/custom pages. `afterAutosave()` runs on **Edit pages only** — it receives
the saved record, which Create pages don't have until submit.

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

Autosave runs `mutateFormDataBeforeSave()` and writes inside a database
transaction, matching Filament's `save()`. It deliberately does **not** fire
Filament's `beforeSave`/`afterSave` hooks or the `RecordUpdated`/`RecordSaved`
events — use `afterAutosave()` for work that should run on every autosave. It does
re-baseline Filament's unsaved-changes tracking, so a panel using
`->unsavedChangesAlerts()` won't warn about changes autosave already wrote.

## Undo (Edit pages)

After a successful autosave, the indicator shows an **Undo** button for 5 seconds. Clicking it:

1. Reads the pre-save values from a short-lived cache entry (30 min by default, override via `protected function getUndoTtlMinutes(): int`).
2. Writes them back through `handleRecordUpdate()`.
3. Re-fills the form.
4. Clears the undo snapshot.

## Files and sensitive data

Dropped from every autosave payload automatically:

- `TemporaryUploadedFile` instances (pending file uploads).
- Any `TextInput` marked `->password()`. On an Edit page autosave would otherwise
  commit a half-typed secret; on a Create page it would sit in the draft cache in
  plain text.
- The `except` list (config + plugin + page), applied to both the autosave write
  and the draft restore.
- Client-submitted keys that don't map to a declared form field, so autosave can
  only ever write real form fields.

`except` matches **top-level** field names only; it does not descend into nested
keys (e.g. a secret inside a Repeater row).

`->dehydrated(false)` keeps a field out of **Edit-page writes**, but *not* out of
Create-page drafts — those are built from raw form state precisely so the user
gets back exactly what they typed. For a secret on a Create page, use `except` (or
`->password()`, which is handled for you).

## Translations

Publish translations with `--tag="filament-autosave-translations"` to customize any of the indicator labels (`unsaved`, `saving`, `saved`, `saved_at`, `undo`, `undone`, `error`, `draft_available`, `restore`, `discard`, `restored`).

## Testing

```bash
composer test
```

## License

MIT
