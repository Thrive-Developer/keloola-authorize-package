<?php

namespace Keloola\KeloolaSsoAuthorize\Support;

class PayloadNormalizer
{
    public static function toArray(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'toArray'], $value);
        }

        if (is_object($value)) {
            if (get_class($value) === '__PHP_Incomplete_Class') {
                return [];
            }

            return self::toArray(get_object_vars($value));
        }

        return $value;
    }

    public static function toObject(mixed $value): mixed
    {
        if (is_array($value)) {
            $items = array_map([self::class, 'toObject'], $value);

            return self::isList($value) ? $items : (object) $items;
        }

        if (is_object($value)) {
            if (get_class($value) === '__PHP_Incomplete_Class') {
                return null;
            }

            return self::toObject(get_object_vars($value));
        }

        return $value;
    }

    public static function containsIncompleteObject(mixed $value): bool
    {
        if (is_object($value)) {
            if (get_class($value) === '__PHP_Incomplete_Class') {
                return true;
            }

            return self::containsIncompleteObject(get_object_vars($value));
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsIncompleteObject($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
