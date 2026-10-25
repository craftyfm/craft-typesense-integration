<?php

namespace x2network\typesenseintegration\transformers;

use Closure;
use craft\base\ElementInterface;

/**
 * Wraps a closure: `fn(ElementInterface $element): ?array`.
 */
class CallbackTransformer implements TransformerInterface
{
    public function __construct(private readonly Closure $callback)
    {
    }

    public function transform(ElementInterface $element): ?array
    {
        return ($this->callback)($element);
    }
}
