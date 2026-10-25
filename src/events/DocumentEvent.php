<?php

namespace x2network\typesenseintegration\events;

use x2network\typesenseintegration\models\CollectionConfig;
use x2network\typesenseintegration\models\SourceConfig;
use craft\base\ElementInterface;
use craft\events\CancelableEvent;

/**
 * Triggered before a document is sent to Typesense.
 */
class DocumentEvent extends CancelableEvent
{
    public CollectionConfig $collection;
    public SourceConfig $source;
    public ElementInterface $element;

    /** The prepared document. Changes are sent to Typesense as-is. */
    public array $document = [];
}
