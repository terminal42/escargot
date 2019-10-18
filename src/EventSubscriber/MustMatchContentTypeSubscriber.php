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
use Terminal42\Escargot\Event\ResponseEvent;

class MustMatchContentTypeSubscriber implements EventSubscriberInterface
{
    /**
     * @var string
     */
    private $contentType;

    public function __construct(string $contentType)
    {
        $this->contentType = $contentType;
    }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $header = $response->getHeaders()['content-type'][0] ?? 'none provided';

        if (false === strpos($header, $this->contentType)) {
            $response->cancel();

            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage(
                    sprintf('Early abort response as the Content-Type header does not match (expected: "%s" got: "%s").',
                        $this->contentType,
                        $header
                    )
                )
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ResponseEvent::class => 'onResponse',
        ];
    }
}
