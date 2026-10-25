<?php

namespace craftyfm\typesenseintegration\helpers;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\MoneyHelper;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Money\Money;
use Traversable;

/**
 * Converts transformer output into values Typesense accepts.
 */
class DocumentHelper
{
    private const SCALAR_TYPES = ['string', 'int32', 'int64', 'float', 'bool'];

    /**
     * Normalizes every value and casts it to the type declared in the schema.
     * Null values are removed so optional fields can be left empty.
     *
     * @param array<string, string> $fieldTypes
     */
    public static function prepare(array $document, array $fieldTypes): array
    {
        $prepared = [];

        foreach ($document as $field => $value) {
            $value = self::normalize($value);

            if (isset($fieldTypes[$field])) {
                $value = self::cast($value, $fieldTypes[$field]);
            }

            if ($value !== null) {
                $prepared[$field] = $value;
            }
        }

        return $prepared;
    }

    /**
     * Turns Craft/PHP objects into scalars or arrays.
     */
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            $value instanceof Money => (float)MoneyHelper::toDecimal($value),
            $value instanceof ElementQueryInterface => array_map(
                fn($element) => self::normalize($element),
                $value->all(),
            ),
            $value instanceof ElementInterface => (string)$value,
            $value instanceof Collection => self::normalize($value->all()),
            is_array($value) => array_map(fn($item) => self::normalize($item), $value),
            $value instanceof \Stringable => (string)$value,
            $value instanceof Traversable => self::normalize(iterator_to_array($value, false)),
            $value instanceof \JsonSerializable => self::normalize($value->jsonSerialize()),
            default => null,
        };
    }

    /**
     * Casts a normalized value to a Typesense field type. Unknown types (object, geopoint, auto, …) are left alone.
     */
    public static function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        if (str_ends_with($type, '[]') && !in_array($type, ['object[]', 'geopoint[]'], true)) {
            $itemType = substr($type, 0, -2);
            $items = is_array($value) ? self::flatten($value) : [$value];
            $items = array_map(fn($item) => self::castScalar($item, $itemType), $items);

            return array_values(array_filter($items, fn($item) => $item !== null && $item !== ''));
        }

        if (is_array($value) && in_array($type, self::SCALAR_TYPES, true)) {
            $value = $type === 'string'
                ? implode(', ', self::flatten($value))
                : (self::flatten($value)[0] ?? null);
        }

        return self::castScalar($value, $type);
    }

    private static function castScalar(mixed $value, string $type): mixed
    {
        if ($value === null || is_array($value)) {
            return in_array($type, self::SCALAR_TYPES, true) ? null : $value;
        }

        return match ($type) {
            'string' => (string)$value,
            'int32', 'int64' => is_numeric($value) ? (int)$value : null,
            'float' => is_numeric($value) ? (float)$value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool)$value,
            default => $value,
        };
    }

    private static function flatten(array $values): array
    {
        $flat = [];
        array_walk_recursive($values, function($item) use (&$flat) {
            $flat[] = $item;
        });

        return $flat;
    }
}
