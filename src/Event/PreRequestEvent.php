<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Event;

use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;

class PreRequestEvent extends AbstractEscargotEvent
{
    /**
     * @var CrawlUri
     */
    private $crawlUri;

    /**
     * @var bool
     */
    private $abortRequest = false;

    public function __construct(Escargot $crawler, CrawlUri $crawlUri)
    {
        parent::__construct($crawler);

        $this->crawlUri = $crawlUri;
    }

    public function getCrawlUri(): CrawlUri
    {
        return $this->crawlUri;
    }

    public function wasRequestAborted(): bool
    {
        return $this->abortRequest;
    }

    public function abortRequest(): self
    {
        $this->abortRequest = true;
        $this->stopPropagation();

        return $this;
    }
}
