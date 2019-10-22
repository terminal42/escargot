# Escargot

[![](https://travis-ci.com/terminal42/escargot.svg?branch=master)](https://travis-ci.com/terminal42/escargot)

A library that provides everything you need to crawl anything based on HTTP and process the responses in whatever
way you prefer based on Symfony components.

### Why yet another crawler?

There are so many different implementations in so many programming languages, right?
Well, the ones I found in PHP did not really live up to my personal quality standards and also I wanted something
that's built on top of the Symfony HttpClient component and is not bound to crawl websites (HTML) only but can
be used as the foundation for anything you may want to crawl. Hence, yet another library.

### What about that name «Escargot»?

When I created this library I didn't want to name it «crawler» or «spider» or anything similar that's been used
hundreds of times before. So I started to think about things that actually crawl and one thing that came to my mind
immediately were snails. But «snail» doesn't really sound super beautiful and so I just went with the French translation
for it which is «escargot». There you go! Also French is a beautiful language anyway and in case you didn't know: tons of
libraries in the PHP ecosystem were invented and are still maintained by French people so it's also some kind of tribute
to the French PHP community (and Symfony one for that matter).

By the way: Thanks to the Symfony HttpClient `Escargot` is actually not slow at all ;-)

### Installation

```bash
composer require terminal42/escargot
```

### Usage

Everything in `Escargot` is assigned to a job ID. The reason for this design is that crawling huge amounts of URIs
can take very long and chances that you'll want to stop at some point and pick up where you left are pretty high.
For that matter, every `Escargot` instance also needs a queue plus a base URI collection as to where to start crawling.
Of course, because we execute requests, we can also provide an instance of `Symfony\Component\HttpClient\HttpClientInterface`
but that's completely optional. If you do not provide any client, `HttpClient::create()` will be used and the best
client is automatically chosen for you.

#### Instantiating Escargot

The factory method when you do not have a job ID yet has to be used as follows:

```php
<?php

use Nyholm\Psr7\Uri;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;

$baseUris = new BaseUriCollection();
$baseUris->add(new Uri('https://www.terminal42.ch'));
$queue = new InMemoryQueue();
        
$escargot = Escargot::create($baseUris, $queue);
```

If you want to use a special `HttpClientInterface` implementation, you can provide this as the third argument:

```php
<?php

use Nyholm\Psr7\Uri;
use Symfony\Component\HttpClient\CurlHttpClient;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;

$baseUris = new BaseUriCollection();
$baseUris->add(new Uri('https://www.terminal42.ch'));
$queue = new InMemoryQueue();
$client = new CurlHttpClient(['custom' => 'options']);
        
$escargot = Escargot::create($baseUris, $queue, $client);
```

In case you already do have a job ID because you have initiated crawling previously we do not need any base URI collection
anymore but the job ID instead (again `$client` is completely optional):

```php
<?php

use Symfony\Component\HttpClient\CurlHttpClient;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;

$queue = new InMemoryQueue();
$client = new CurlHttpClient(['custom' => 'options']); // optional
        
$escargot = Escargot::createFromJobId($jobId, $queue, $client);
```
   
### The different queue implementations

As explained before, the queue is an essential part of `Escargot` because it keeps track of all the URIs that have been
requested already but it is also responsible to pick up where one left based on a given job ID.
You can create your own queue and store the information wherever you like by implementing the `QueueInterface`.
This library ships with the following implementations for you to use:

* `InMemoryQueue` - an in-memory queue. Mostly useful for testing or CLI usages. Once the process ends, data will be lost.

* `DoctrineQueue` - a Doctrine DBAL queue. Stores the data in your Doctrine/PDO compatible database so it's persistent.

* `LazyQueue` - a queue that takes two `QueueInterface` implementations as arguments. It will try to work on the primary
  queue as long as possible and fall back to the second queue only if needed. The result can be transferred from the
  first queue to the second queue by using the `commit()` method. The use case is mainly to prevent e.g. the database
  from being hammered by using `$queue = new LazyQueue(new InMemoryQueue(), new DoctrineQueue())`. That way you get
  persistence (by calling `$queue->commit($jobId)` once done) combined with efficiency. 

### Start crawling

After we have our `Escargot` instance, we can start crawling which we do by calling the `crawl()` method:

```php
<?php

$escargot->crawl();
```

### Events

You might be wondering how you can access the results of your crawl process. In `Escargot`, `crawl()` does not return
anything but instead, everything is event based which lets you decide exactly on what you want to do with the results
that are collected along the way.
Currently there are `4` different events:

* `PreRequestEvent`

  This event is dispatched before a request is dispatched.
  You have access to `Escargot` itself and the `CrawlUri` which is about to be requested. You may abort this request
  by calling `$event->abortRequest()` which will also stop event propagation.
  
* `ResponseEvent`

  The probably most important event for you as it is dispatched whenever a response chunk (!) arrived.
  You have access to `Escargot` itself, the resulting instance of `ResponseInterface`, the current `ChunkInterface` and
  also the `CrawlUri` which  contains the URI that was crawled and the information on what level and on what URI it was found.

* `FinishedCrawlingEvent`

  This event is dispatched when crawling has finished because either the maximum configured requests have been reached 
  (see «Configuration») or the queue is empty, meaning there's nothing left to crawl.
  You have access to `Escargot` itself which e.g. allows you to ask for the total requests sent
  (`Escargot::getRequestsSent()`).
  
* `RequestExceptionEvent`

  This event is dispatched when the Symfony HttpClient emits an exception.
  Apart from `Escargot` itself you have access to the `ExceptionInterface` instance (so e.g. `TransportExceptionInterface`
  but also `ClientExceptionInterface` etc.) and the `ResponseInterface` instance if there is any (if the exception occurs
  during initiation of the request, there won't be any response).  

### Event subscribers

Listening to these events is accomplished using the Symfony EventDispatcher and its `EventSubscriberInterface`.
This listener class can then be registered using `Escargot::addSubscriber()`:

```php
<?php

$escargot->addSubscriber(new MySubscriber());
```

#### General subscribers

`Escargot` ships with two general subscribers that might be useful for you:

* The `MustMatchContentTypeSubscriber`

  This subscriber allows you to cancel responses that do not match a desired `Content-Type` header on the first chunk, so
  you don't have to wait for the body to arrive. So this subscriber listens to the `ResponseEvent`.
  You may use it to e.g. limit your crawler to `application/json` responses only:
  
  ```php
  <?php
  
  use Terminal42\Escargot\EventSubscriber\MustMatchContentTypeSubscriber;
  
  $escargot->addSubscriber(new MustMatchContentTypeSubscriber('application/json'));
  ```
  
* The `MaxDepthSubscriber`

  This subscriber allows you to not even send requests if a certain maximum depth is reached. For this to work, this
  subscriber listens to the `PreRequestEvent`.
  You may use it like this:
  
  ```php
  <?php
  
  use Terminal42\Escargot\EventSubscriber\MaxDepthSubscriber;
  
  $escargot->addSubscriber(new MaxDepthSubscriber(5)); // Will limit requests to level 5
  ```
  
#### Crawling websites (HTML crawler)

When people read the word «crawl» or «crawler» they usually immediately think of crawling websites. Granted, this is
also the main purpose of this library but if you think about it, nothing you have learnt about `Escargot` so far was
related to crawling websites or HTML. `Escargot` can crawl anything that's based on HTTP and you may use the core events
to extract e.g. new URIs from JSON responses and continue from there.

Awesome isn't it?

To turn our `Escargot` instance into a proper web crawler, we need to register additional subscribers to the events
which will e.g. extract links from the HTML content and add those to the queue:

* The `HtmlCrawlerSubscriber`

  This subscriber analyzes the HTML and then searches for links and adds those to the queue. For that to work, it
  listens to the `ResponseEvent`. Of course it doesn't just add those links blindly but instead follows quite a few
  rules:
  
  * The response may not be empty and `204 No Content` responses are irgnored.
  * The response is not processed if the response `X-Robots-Tag` header contains `nofollow`.
  * The response is not processed if the body contains a `<meta name="robots">` tag which contains `nofollow`.
  * Links not starting by either `http://` or `https://` are skipped.
  * Links with the attribute `rel="nofollow"` are skipped.
  * Links with the attribute `type` not equal to `text/html` are skipped.
  * Links that point to any host which is not part of the `BaseUriCollection` are skipped. 
  
  You may use it like this:
  
  ```php
  <?php
  
  use Terminal42\Escargot\EventSubscriber\HtmlCrawlerSubscriber;
  
  $escargot->addSubscriber(new HtmlCrawlerSubscriber());
  ```
  
* The `RobotsSubscriber`

  This subscriber early aborts responses with an `X-Robots-Tag` header that contains `noindex`. It also  analyzes the
  `robots.txt` and looks for `Sitemap` entries, requests those and adds all the found URIs to the queue:
    
  ```php
  <?php
  
  use Terminal42\Escargot\EventSubscriber\RobotsSubscriber;
  
  $escargot->addSubscriber(new RobotsSubscriber());
  ```
  
So to create a full-fledged web crawler, you need both of these subscribers plus the 
`MustMatchContentTypeSubscriber`:

```php
<?php

use Terminal42\Escargot\EventSubscriber\MustMatchContentTypeSubscriber;
use Terminal42\Escargot\EventSubscriber\RobotsSubscriber;
use Terminal42\Escargot\EventSubscriber\HtmlCrawlerSubscriber;

$escargot->addSubscriber(new MustMatchContentTypeSubscriber('text/html'));
$escargot->addSubscriber(new RobotsSubscriber());
$escargot->addSubscriber(new HtmlCrawlerSubscriber());
```

#### Configuration

There are different configurations you can apply to the `Escargot` instance:

* `Escargot::withMaxRequests(int $maxRequests)`

   Returns a clone of the `Escargot` instance with a maximum total requests that are going to be executed. It can be
   useful if you have limited resources and only want to execute e.g. `100` requests in this run and continue later on.
   
* `Escargot::withUserAgent(string $userAgent)`

   Returns a clone of the `Escargot` instance with a different `User-Agent` header. The header is sent with all the
   requests and by default configured to `terminal42/escargot`.

* `Escargot::withConcurrency(int $concurrency)`

   Returns a clone of the `Escargot` instance with a maximum concurrent requests that are going to be sent at a time.
   By default, this is configured to `10`.
   
* `Escargot::withRequestDelay(int $requestDelay)`

   Returns a clone of the `Escargot` instance with an added delay between requests in microseconds. By default, there's
   no extra  delay. It can be useful to make sure `Escargot` does not run into some (D)DOS protection or similar issues.
   
* `Escargot::withEventDispatcher(EventDispatcherInterface $eventDispatcher)`

   Returns a clone of the `Escargot` instance with your custom implementation of the `EventDispatcherInterface` in case
   you don't want to use the default `EventDispatcher`.

* `Escargot::setLogger(LoggerInterface $logger)`

   Provide a PSR-3 `Psr\Log\LoggerInterface` instance to gain more insight in what's happening in `Escargot`.
   
## Roadmap / Ideas

* This is just an alpha version so please expect things to break. I'm going to follow SemVer for this library
  which is why we have 0.x version numbers for now unit I personally find it to be stable enough to release 
  version 1.0.0.
  
* What about having `Escargot` interpret JavaScript before starting to crawl the content? Should be possible
  by having an `HttpClientInterface` implementation that bridges to `symfony/panther` or `facebook/webdriver`
  directly. PR's welcome!

* Maybe one day, some talented illustrator finds this library and enhances it with a nice logo? :-)
  
* I'm a core dev member of [Contao, an open source CMS](https://contao.org). I would like to integrate `Escargot` there
  in one of the upcoming versions to improve the way search indexing is achieved. I guess you should know that I will
  likely not be tagging it stable before I've finished the integration but we'll see.
