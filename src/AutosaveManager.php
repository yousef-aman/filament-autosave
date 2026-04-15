<?php

namespace YousefAman\FilamentAutosave;

class AutosaveManager
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>
     */
    public static function getChangedFields(array $old, array $new): array
    {
        $changed = [];

        foreach ($new as $key => $value) {
            if (! array_key_exists($key, $old) || $old[$key] !== $value) {
                $changed[$key] = $value;
            }
        }

        return $changed;
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

    public static function snapshotHash(array $data): string
    {
        return md5((string) str(json_encode($data, JSON_UNESCAPED_UNICODE))->replace('\\', ''));
    }
}
