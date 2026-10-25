<?php

namespace craftyfm\typesenseintegration\models;

use craftyfm\typesenseintegration\transformers\CallbackTransformer;
use craftyfm\typesenseintegration\transformers\FieldMapTransformer;
use craftyfm\typesenseintegration\transformers\TransformerInterface;
use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use yii\base\InvalidConfigException;

/**
 * One source of documents for a collection, e.g. "blog entries" or "Commerce products".
 */
class SourceConfig
{
    public readonly string $handle;

    /** @var class-string<ElementInterface> */
    public readonly string $elementType;

    /** @var RelatedTrigger[] */
    public readonly array $relatedTriggers;

    private array|Closure $criteria;
    private mixed $transformerConfig;
    private ?TransformerInterface $transformer = null;

    public function __construct(string $handle, array $config)
    {
        $elementType = $config['elementType'] ?? null;

        if (!is_string($elementType) || !is_subclass_of($elementType, ElementInterface::class)) {
            throw new InvalidConfigException("Typesense source “{$handle}” must define a valid `elementType`.");
        }

        if (!isset($config['transformer'])) {
            throw new InvalidConfigException("Typesense source “{$handle}” must define a `transformer`.");
        }

        $this->handle = $handle;
        $this->elementType = $elementType;
        $this->criteria = $config['criteria'] ?? [];
        $this->transformerConfig = $config['transformer'];
        $this->relatedTriggers = array_map(
            fn(array $triggerConfig) => new RelatedTrigger($triggerConfig),
            $config['relatedTriggers'] ?? [],
        );
    }

    /**
     * Returns a new element query with this source's criteria applied.
     */
    public function createQuery(): ElementQueryInterface
    {
        $query = $this->elementType::find();

        if ($this->criteria instanceof Closure) {
            $result = ($this->criteria)($query);
            if ($result instanceof ElementQueryInterface) {
                $query = $result;
            }
        } else {
            Craft::configure($query, $this->criteria);
        }

        return $query;
    }

    public function getElementTypeName(): string
    {
        return $this->elementType::displayName();
    }

    /**
     * Human-readable criteria for the control panel, or null when a closure builds the query.
     */
    public function describeCriteria(): ?array
    {
        return $this->criteria instanceof Closure ? null : $this->criteria;
    }

    /**
     * Describes the transformer for the control panel.
     *
     * @return array{type: string, class: string|null, map: array<string, string|null>|null}
     */
    public function describeTransformer(): array
    {
        $transformer = $this->getTransformer();

        return match (true) {
            $transformer instanceof FieldMapTransformer => [
                'type' => 'map',
                'class' => null,
                'map' => array_map(
                    fn($path) => $path instanceof Closure ? null : (string)$path,
                    $transformer->getMap(),
                ),
            ],
            $transformer instanceof CallbackTransformer => ['type' => 'closure', 'class' => null, 'map' => null],
            default => ['type' => 'class', 'class' => $transformer::class, 'map' => null],
        };
    }

    /**
     * @param class-string<ElementInterface> $elementType
     */
    public function supports(string $elementType): bool
    {
        return is_a($elementType, $this->elementType, true);
    }

    /**
     * This source's relatedTriggers whose `elementType` matches $element's class - a cheap
     * filter before a caller runs the more expensive matches() on each.
     *
     * @return RelatedTrigger[]
     */
    public function getTriggersFor(ElementInterface $element): array
    {
        return array_values(array_filter(
            $this->relatedTriggers,
            fn(RelatedTrigger $trigger) => $trigger->appliesTo($element::class),
        ));
    }

    public function getTransformer(): TransformerInterface
    {
        if ($this->transformer !== null) {
            return $this->transformer;
        }

        $config = $this->transformerConfig;

        $transformer = match (true) {
            $config instanceof TransformerInterface => $config,
            $config instanceof Closure => new CallbackTransformer($config),
            is_string($config) => Craft::createObject($config),
            is_array($config) && isset($config['class']) => Craft::createObject($config),
            is_array($config) => new FieldMapTransformer($config),
            default => null,
        };

        if (!$transformer instanceof TransformerInterface) {
            throw new InvalidConfigException(
                "The transformer for Typesense source “{$this->handle}” must be a closure, a field map array, or implement " . TransformerInterface::class . '.'
            );
        }

        return $this->transformer = $transformer;
    }
}
