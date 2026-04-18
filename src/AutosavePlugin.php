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

    protected bool|Closure $isGlobal = false;

    protected int|Closure|null $debounce = null;

    protected array $except = [];

    protected array $exceptPages = [];

    protected bool|Closure $showTimestamp = true;

    protected string $indicatorPosition = 'before';

    protected int|Closure|null $cacheTtl = null;

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

    public function global(bool|Closure $condition = true): static
    {
        $this->isGlobal = $condition;

        return $this;
    }

    public function debounce(int|Closure $milliseconds): static
    {
        $this->debounce = $milliseconds;

        return $this;
    }

    public function except(array $fields): static
    {
        $this->except = $fields;

        return $this;
    }

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

    public function isGlobal(): bool
    {
        return $this->evaluate($this->isGlobal);
    }

    public function getDebounce(): int
    {
        if ($this->debounce !== null) {
            return $this->evaluate($this->debounce);
        }

        return config('filament-autosave.debounce', 1500);
    }

    public function getExcept(): array
    {
        return $this->except;
    }

    public function getExceptPages(): array
    {
        return $this->exceptPages;
    }

    public function shouldShowTimestamp(): bool
    {
        return $this->evaluate($this->showTimestamp);
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
            $pageClass = collect($scopes)->first(
                fn ($scope) => is_string($scope)
                    && class_exists($scope)
                    && (in_array(HasAutosave::class, class_uses_recursive($scope))
                        || in_array(HasAutosaveForCreate::class, class_uses_recursive($scope)))
            );

            if (! $pageClass || in_array($pageClass, $this->exceptPages)) {
                return '';
            }

            $mode = in_array(HasAutosaveForCreate::class, class_uses_recursive($pageClass))
                ? 'create'
                : 'edit';

            return new HtmlString(
                view('filament-autosave::autosave-indicator', [
                    'debounce' => $this->getDebounce(),
                    'enabled' => true,
                    'showTimestamp' => $this->shouldShowTimestamp(),
                    'mode' => $mode,
                ])->render()
            );
        });
    }
}
