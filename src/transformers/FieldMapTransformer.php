<?php

namespace craftyfm\typesenseintegration\transformers;

use Closure;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * Maps Typesense fields to element attributes or custom fields.
 *
 * ```php
 * 'transformer' => [
 *     'title' => 'title',               // element attribute
 *     'summary' => 'summary',           // custom field handle
 *     'author' => 'author.fullName',    // dot path; element queries resolve to their first element
 *     'categories' => 'categories',     // relation field, becomes a list of titles
 *     'url' => fn($element) => $element->getUrl(),
 * ],
 * ```
 */
class FieldMapTransformer implements TransformerInterface
{
    /**
     * @param array<string, string|Closure> $map
     */
    public function __construct(private readonly array $map)
    {
    }

    /**
     * @return array<string, string|Closure>
     */
    public function getMap(): array
    {
        return $this->map;
    }

    public function transform(ElementInterface $element): ?array
    {
        $document = [];

        foreach ($this->map as $field => $path) {
            $document[$field] = $path instanceof Closure
                ? $path($element)
                : $this->resolvePath($element, (string)$path);
        }

        return $document;
    }

    private function resolvePath(ElementInterface $element, string $path): mixed
    {
        $value = $element;

        foreach (explode('.', $path) as $segment) {
            if ($value instanceof ElementQueryInterface) {
                $value = $value->one();
            }

            if ($value === null) {
                return null;
            }

            if (is_array($value)) {
                $value = $value[$segment] ?? null;
            } elseif (is_object($value)) {
                $value = $this->readProperty($value, $segment);
            } else {
                return null;
            }
        }

        // A trailing element query is left intact so it can be normalized into a list.
        return $value;
    }

    private function readProperty(object $object, string $name): mixed
    {
        try {
            return $object->$name;
        } catch (\Throwable) {
            return null;
        }
    }
}
