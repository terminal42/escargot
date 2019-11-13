<?php

require_once 'vendor/autoload.php';

use Nyholm\Psr7\Uri;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;

$baseUris = new BaseUriCollection([new Uri('https://contao.org')]);

$escargot = Escargot::create($baseUris, new InMemoryQueue());
$escargot = $escargot->withMaxRequests(5000);
$escargot->addSubscriber(new HtmlCrawlerSubscriber());

$escargot->crawl();

var_dump($escargot->getRequestsSent());