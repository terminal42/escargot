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

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Terminal42\Escargot\Event\ExcludedByRobotsMetaTagEvent;
use Terminal42\Escargot\Event\ExcludedByUriFilterEvent;
use Terminal42\Escargot\Event\FinishedCrawlingEvent;
use Terminal42\Escargot\Event\RequestExceptionEvent;
use Terminal42\Escargot\Event\SuccessfulResponseEvent;

class LoggerSubscriber implements EventSubscriberInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function onSuccessfulResponse(SuccessfulResponseEvent $event): void
    {
        $this->logger->debug(sprintf('Successful request! %s',
            (string) $event->getCrawlUri()
        ));
    }

    public function onFinishedCrawling(FinishedCrawlingEvent $event): void
    {
        $this->logger->debug(sprintf('Finished crawling! Sent %s request(s).',
            $event->getEscargot()->getRequestsSent()
        ));
    }

    public function onRequestException(RequestExceptionEvent $event): void
    {
        $this->logger->error('An error occured during a request.', ['exception' => $event->getException()]);
    }

    public function onExcludedByUriFilter(ExcludedByUriFilterEvent $event): void
    {
        $this->logger->debug(sprintf('Excluded an URI because of the configured UriFilter: %s',
            (string) $event->getUri()
        ));
    }

    public function onExcludedByRobotsMetaTag(ExcludedByRobotsMetaTagEvent $event): void
    {
        $this->logger->debug(sprintf('Excluded an URI because of the <meta name="robots"> containing "nofollow": %s',
            (string) $event->getUri()
        ));
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            SuccessfulResponseEvent::class => 'onSuccessfulResponse',
            FinishedCrawlingEvent::class => 'onFinishedCrawling',
            RequestExceptionEvent::class => 'onRequestException',
            ExcludedByUriFilterEvent::class => 'onExcludedByUriFilter',
            ExcludedByRobotsMetaTagEvent::class => 'onExcludedByRobotsMetaTag',
        ];
    }
}
