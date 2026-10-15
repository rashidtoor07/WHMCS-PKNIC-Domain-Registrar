<?php

declare(strict_types=1);

namespace PknicWeb;

final class MapLoader
{
    /** @var array<string, array> */
    private static array $cache = [];

    public static function defaultPath(): string
    {
        return dirname(__DIR__) . '/config/pknic_map.php';
    }

    public static function load(?string $file = null): array
    {
        $file ??= self::defaultPath();
        if (!isset(self::$cache[$file])) {
            if (!is_file($file)) {
                throw new MapException("Map file not found: $file");
            }
            $map = require $file;
            if (!is_array($map)) {
                throw new MapException("Map file must return an array: $file");
            }
            self::$cache[$file] = $map;
        }
        return self::$cache[$file];
    }

    /** True when a map value is set and contains no TODO placeholder anywhere. */
    public static function isFilled(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return false;
        }
        if (is_string($value)) {
            return stripos($value, 'TODO') === false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item instanceof \Closure) {
                    continue;
                }
                if (is_array($item) || is_string($item)) {
                    if (is_string($item) && $item === '') {
                        continue;
                    }
                    if (!self::isFilled($item)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    public static function has(string $key, ?array $map = null): bool
    {
        try {
            $map ??= self::load();
        } catch (MapException) {
            return false;
        }
        return self::isFilled($map[$key] ?? null);
    }

    /** List-type values (texts) with placeholder entries removed. */
    public static function textList(array $map, string ...$keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            foreach ((array) ($map[$key] ?? []) as $text) {
                if (is_string($text) && self::isFilled($text)) {
                    $out[] = $text;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
