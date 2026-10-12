<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Fixture;

use Psr\Log\LoggerAwareInterface;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\Subscriber\SubscriberInterface;

abstract class AbstractCompleteSubscriber implements SubscriberInterface, EscargotAwareInterface, LoggerAwareInterface
{
}
