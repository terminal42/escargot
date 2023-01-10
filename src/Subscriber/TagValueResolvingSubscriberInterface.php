<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2023, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Subscriber;

interface TagValueResolvingSubscriberInterface
{
    /**
     * The first subscriber to not return null is
     * the one that's resolving the value of a tag.
     * It might be possible that a tag doesn't need
     * to be resolved at all and is totally optional.
     *
     * @return mixed|null
     */
    public function resolveTagValue(string $tag);
}
