<?php

namespace craftyfm\typesenseintegration\transformers;

use craft\base\ElementInterface;

/**
 * Turns an element into a Typesense document.
 *
 * Return an array keyed by Typesense field name. Values are cast to the field
 * types declared in the collection schema, so dates, element queries, money
 * and option field values can be returned as-is. Return `null` to skip the element.
 */
interface TransformerInterface
{
    public function transform(ElementInterface $element): ?array;
}
