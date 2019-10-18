<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\EventSubscriber;

use Nyholm\Psr7\Uri;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Event\PreRequestEvent;
use Terminal42\Escargot\EventSubscriber\MaxDepthSubscriber;

class MaxDepthSubscriberTest extends AbstractSubscriberTest
{
    public function testMaxDepthIsHandledCorrectly(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->exactly(2))
            ->method('log')
            ->with(
                $this->equalTo(LogLevel::DEBUG),
                $this->equalTo('[URI: https://www.terminal.ch/ (Level: 2, Processed: no, Found on: root)] Will not crawl as max depth is reached!')
            );

        $escargot = $this->createEscargot($logger);

        $event = new PreRequestEvent($escargot, new CrawlUri(new Uri('https://www.terminal.ch'), 2));
        $subscriber = new MaxDepthSubscriber(1);
        $subscriber->onPreRequest($event);
        $this->assertTrue($event->wasRequestAborted());
        $this->assertTrue($event->isPropagationStopped());

        $event = new PreRequestEvent($escargot, new CrawlUri(new Uri('https://www.terminal.ch'), 2));
        $subscriber = new MaxDepthSubscriber(2);
        $subscriber->onPreRequest($event);
        $this->assertTrue($event->wasRequestAborted());
        $this->assertTrue($event->isPropagationStopped());

        $event = new PreRequestEvent($escargot, new CrawlUri(new Uri('https://www.terminal.ch'), 2));
        $subscriber = new MaxDepthSubscriber(3);
        $subscriber->onPreRequest($event);
        $this->assertFalse($event->wasRequestAborted());
        $this->assertFalse($event->isPropagationStopped());
    }
}
