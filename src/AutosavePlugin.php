<?php

namespace YousefAman\FilamentAutosave;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

class AutosavePlugin implements Plugin
{
    use EvaluatesClosures;

    protected int|Closure|null $debounce = null;

    /** @var array<string> */
    protected array $except = [];

    /** @var array<class-string> */
    protected array $exceptPages = [];

    protected bool|Closure|null $showTimestamp = null;

    protected string $indicatorPosition = 'before';

    protected int|Closure|null $cacheTtl = null;

    /** @var array<class-string, 'edit'|'create'|null> */
    protected static array $modeCache = [];

    public function getId(): string
    {
        return 'filament-autosave';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }

    public static function tryGet(): ?static
    {
        try {
            return static::get();
        } catch (\Throwable) {
            return null;
        }
    }

    public function debounce(int|Closure $milliseconds): static
    {
        $this->debounce = $milliseconds;

        return $this;
    }

    /** @param  array<string>  $fields */
    public function except(array $fields): static
    {
        $this->except = $fields;

        return $this;
    }

    /** @param  array<class-string>  $pages */
    public function exceptPages(array $pages): static
    {
        $this->exceptPages = $pages;

        return $this;
    }

    public function showTimestamp(bool|Closure $condition = true): static
    {
        $this->showTimestamp = $condition;

        return $this;
    }

    public function indicatorPosition(string $position): static
    {
        $this->indicatorPosition = $position;

        return $this;
    }

    public function cacheTtl(int|Closure $hours): static
    {
        $this->cacheTtl = $hours;

        return $this;
    }

    public function getDebounce(): int
    {
        if ($this->debounce !== null) {
            return $this->evaluate($this->debounce);
        }

        return config('filament-autosave.debounce', 1500);
    }

    /** @return array<string> */
    public function getExcept(): array
    {
        return $this->except;
    }

    /** @return array<class-string> */
    public function getExceptPages(): array
    {
        return $this->exceptPages;
    }

    public function shouldShowTimestamp(): bool
    {
        if ($this->showTimestamp !== null) {
            return $this->evaluate($this->showTimestamp);
        }

        return (bool) config('filament-autosave.show_timestamp', true);
    }

    public function getIndicatorPosition(): string
    {
        return $this->indicatorPosition;
    }

    public function getCacheTtl(): int
    {
        if ($this->cacheTtl !== null) {
            return $this->evaluate($this->cacheTtl);
        }

        return config('filament-autosave.cache_ttl', 24);
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        $hookName = $this->indicatorPosition === 'after'
            ? PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER
            : PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE;

        FilamentView::registerRenderHook($hookName, function (array $scopes) {
            $mode = $this->resolveAutosaveMode($scopes);

            if ($mode === null) {
                return '';
            }

            return new HtmlString(
                view('filament-autosave::autosave-indicator', [
                    'debounce' => $this->getDebounce(),
                    'showTimestamp' => $this->shouldShowTimestamp(),
                    'mode' => $mode,
                ])->render()
            );
        });
    }

    /** @param  array<mixed>  $scopes */
    protected function resolveAutosaveMode(array $scopes): ?string
    {
        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! class_exists($scope)) {
                continue;
            }

            if (in_array($scope, $this->exceptPages, true)) {
                return null;
            }

            $mode = static::$modeCache[$scope]
                ??= $this->detectMode($scope);

            if ($mode !== null) {
                return $mode;
            }
        }

        return null;
    }

    protected function detectMode(string $class): ?string
    {
        $traits = class_uses_recursive($class);

        if (in_array(HasAutosaveForCreate::class, $traits, true)) {
            return 'create';
        }

        if (in_array(HasAutosave::class, $traits, true)) {
            return 'edit';
        }

        return null;
    }
}
