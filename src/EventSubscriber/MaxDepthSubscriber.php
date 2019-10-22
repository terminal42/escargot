<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\EventSubscriber;

use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Terminal42\Escargot\Event\PreRequestEvent;

final class MaxDepthSubscriber implements EventSubscriberInterface
{
    /**
     * @var int
     */
    private $maxDepth;

    public function __construct(int $maxDepth)
    {
        if ($maxDepth < 1) {
            throw new \InvalidArgumentException('If you do not want to limit the depth, do not register this subscriber at all.');
        }

        $this->maxDepth = $maxDepth;
    }

    public function onPreRequest(PreRequestEvent $event): void
    {
        // Stop crawling if we have reached max depth
        if ($this->maxDepth <= $event->getCrawlUri()->getLevel()) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage('Will not crawl as max depth is reached!')
            );

            $event->abortRequest();
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PreRequestEvent::class => 'onPreRequest',
        ];
    }
}
