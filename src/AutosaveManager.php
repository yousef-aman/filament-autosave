<?php

namespace YousefAman\FilamentAutosave;

use Illuminate\Support\Facades\Cache;

class AutosaveManager
{
    /**
     * Build a deterministic hash of form state, independent of key order.
     *
     * @param  array<string, mixed>  $data
     */
    public static function snapshotHash(array $data): string
    {
        self::sortRecursive($data);

        return hash('xxh128', json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
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
        $owner = auth()->id() ?? session()->getId();

        return 'filament-autosave:'.$owner.':'.$pageClass;
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

    private static function sortRecursive(array &$data): void
    {
        ksort($data);

        foreach ($data as &$value) {
            if (is_array($value)) {
                self::sortRecursive($value);
            }
        }
    }
}
