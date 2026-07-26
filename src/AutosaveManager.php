<?php

namespace YousefAman\FilamentAutosave;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

class AutosaveManager
{
    /**
     * Build a deterministic hash of form state, independent of top-level key order.
     *
     * @param  array<string, mixed>  $data
     */
    public static function snapshotHash(array $data): string
    {
        // Nested order is data (repeater row order) and must stay significant.
        ksort($data);

        try {
            $encoded = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException) {
            // Un-encodable state (invalid UTF-8, NAN/INF): fall back so distinct
            // states don't collide on the empty-string hash.
            $encoded = serialize($data);
        }

        return hash('xxh128', $encoded);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string>  $except
     * @return array<string, mixed>
     */
    public static function excludeFields(array $data, array $except): array
    {
        return array_diff_key($data, array_flip($except));
    }

    public static function cacheKey(string $pageClass): string
    {
        return 'filament-autosave:'.self::currentScope().':'.$pageClass;
    }

    /** Tenant + owner scope so drafts never leak across users or tenants. */
    public static function currentScope(): string
    {
        // Hashed: a verbatim session id in a cache key is a hijacking primitive.
        $owner = self::resolveOwnerId() ?? 'guest-'.hash('xxh128', session()->getId());
        $tenant = Filament::getTenant()?->getKey();

        return ($tenant !== null ? $tenant.':' : '').$owner;
    }

    /** Prefer the active panel's guard so custom-guard panels key by user, not session. */
    private static function resolveOwnerId(): ?string
    {
        $panel = Filament::getCurrentPanel();

        $id = $panel?->auth()?->id() ?? auth()->id();

        if ($id === null) {
            return null;
        }

        // Two panels can authenticate different people under the same id.
        $guard = $panel?->getAuthGuard() ?? config('auth.defaults.guard');

        return $guard.':'.$id;
    }

    /** @param  array<string, mixed>  $data */
    public static function storeDraft(string $key, array $data, int $ttlHours): void
    {
        Cache::put($key, $data, now()->addHours($ttlHours));
    }

    /** @return array<string, mixed>|null */
    public static function restoreDraft(string $key): ?array
    {
        $draft = Cache::get($key);

        return is_array($draft) ? $draft : null;
    }

    public static function clearDraft(string $key): void
    {
        Cache::forget($key);
    }

}
