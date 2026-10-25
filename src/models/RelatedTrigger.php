<?php

namespace craftyfm\typesenseintegration\models;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use yii\base\InvalidConfigException;

/**
 * Declares that saving, restoring or deleting an element of `elementType` (matching `criteria`)
 * should resync every element of the owning source currently related to it - e.g. a Category
 * being renamed resyncs every product that lists it, so a denormalized title doesn't go stale.
 *
 * One hop only: resyncing a related element re-transforms and re-imports it, but never saves it,
 * so there is no cascade back into this trigger.
 */
final class RelatedTrigger
{
    /** @var class-string<ElementInterface> */
    public readonly string $elementType;

    /** Element query params (array) or a closure that receives (and may return) the query. */
    private array|Closure $criteria;

    /** Relation field handle to scope `relatedTo()` to, or null for "any field". */
    public readonly ?string $field;

    public function __construct(array $config)
    {
        $elementType = $config['elementType'] ?? null;

        if (!is_string($elementType) || !is_subclass_of($elementType, ElementInterface::class)) {
            throw new InvalidConfigException('A Typesense relatedTrigger must define a valid `elementType`.');
        }

        $this->elementType = $elementType;
        $this->criteria = $config['criteria'] ?? [];
        $this->field = $config['field'] ?? null;
    }

    /**
     * Cheap type check only, no query - lets a caller skip matches() for triggers that can never
     * apply to this element's class.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public function appliesTo(string $elementType): bool
    {
        return is_a($elementType, $this->elementType, true);
    }

    /**
     * Whether the saved/restored/deleted element actually satisfies this trigger.
     */
    public function matches(ElementInterface $element): bool
    {
        if (!$this->appliesTo($element::class)) {
            return false;
        }

        $query = $this->elementType::find();

        // Same convention as SourceConfig::createQuery(): a closure narrows the query itself,
        // consistent with how criteria is written in config/typesense-integration.php.
        if ($this->criteria instanceof Closure) {
            $result = ($this->criteria)($query);
            if ($result instanceof ElementQueryInterface) {
                $query = $result;
            }
        } else {
            Craft::configure($query, $this->criteria);
        }

        return $query->id($element->id)->siteId($element->siteId)->status(null)->trashed(null)->exists();
    }

    /**
     * The owning source's own query, narrowed to elements currently related to $triggerElement.
     */
    public function buildRelatedQuery(SourceConfig $source, ElementInterface $triggerElement): ElementQueryInterface
    {
        $relatedTo = $this->field !== null
            ? ['targetElement' => $triggerElement, 'field' => $this->field]
            : $triggerElement;

        return $source->createQuery()->relatedTo($relatedTo);
    }
}
