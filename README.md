# Filament Autosave

Automatic saving for Filament Edit Pages. Changes are saved to the database automatically after a debounce period — like Google Docs, Notion, and Linear.

## Requirements

- PHP 8.2+
- Filament v4 or v5
- Laravel 11+

## Installation

```bash
composer require yousefaman/filament-autosave
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag="filament-autosave-config"
```

## Quick Start

Add the trait to any Edit Page:

```php
use YousefAman\FilamentAutosave\HasAutosave;

class EditUser extends EditRecord
{
    use HasAutosave;
}
```

Register the plugin in your Panel Provider:

```php
use YousefAman\FilamentAutosave\AutosavePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(AutosavePlugin::make());
}
```

That's it! The form will now autosave 1.5 seconds after the last change.

## Global Mode

Enable autosave on all Edit Pages:

```php
AutosavePlugin::make()
    ->global()
```

Disable on specific pages:

```php
class EditPayment extends EditRecord
{
    use HasAutosave;

    protected bool $autosaveEnabled = false;
}
```

## Configuration

### Debounce

```php
// Plugin level
AutosavePlugin::make()->debounce(2000)

// Page level
protected int $autosaveDebounce = 2000;

// Config level (config/filament-autosave.php)
'debounce' => 1500,
```

### Exclude Fields

```php
// Plugin level
AutosavePlugin::make()->except(['password'])

// Page level
protected array $autosaveExcept = ['password', 'password_confirmation'];

// Config level
'except' => ['password'],
```

All levels are merged together.

### Exclude Pages

```php
AutosavePlugin::make()
    ->exceptPages([EditSensitiveResource::class])
```

### Lifecycle Hooks

```php
protected function beforeAutosave(array $data): array
{
    // Modify data before saving
    return $data;
}

protected function afterAutosave(Model $record): void
{
    Cache::forget("user-{$record->id}");
}
```

### Status Indicator

```php
AutosavePlugin::make()
    ->showTimestamp(false)         // Hide timestamp
    ->indicatorPosition('after')  // After header actions
```

## How It Works

1. Alpine.js watches `$wire.data` for changes
2. After debounce period (default 1500ms), calls `$wire.autosave()`
3. Server compares current data against last snapshot
4. Validates only changed fields
5. Saves valid fields, skips invalid ones silently
6. Updates the "unsaved changes" hash so Filament won't show browser alerts
7. Returns status to Alpine for the visual indicator

## License

MIT
