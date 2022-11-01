# Escargot - a Symfony HttpClient based Crawler framework

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="assets/logo/logo_neg.svg">
  <source media="(prefers-color-scheme: light)" srcset="assets/logo/logo.svg">
  <img alt="The Escargot logo which consists of an illustration of a snail shell" src="assets/logo/logo.svg" width="300">
</picture>

---

A library that provides everything you need to crawl anything based on HTTP and process the responses in whatever
way you prefer based on Symfony components.

### Why yet another crawler?

There are so many different implementations in so many programming languages, right?
Well, the ones I found in PHP did not really live up to my personal quality standards and also I wanted something
that's built on top of the [Symfony HttpClient][Symfony_HTTPClient] component and is not bound to crawl websites (HTML) only but can
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

In case you already do have a job ID because you have initiated crawling previously we do not need any base URI collection
anymore but the job ID instead (again `$client` is completely optional):

```php
<?php

use Symfony\Component\HttpClient\CurlHttpClient;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;

$queue = new InMemoryQueue();
        
$escargot = Escargot::createFromJobId($jobId, $queue);
```
   
#### The different queue implementations

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

#### Start crawling

After we have our `Escargot` instance, we can start crawling which we do by calling the `crawl()` method:

```php
<?php

$escargot->crawl();
```

#### Subscribers

You might be wondering how you can access the results of your crawl process. In `Escargot`, `crawl()` does not return
anything but instead, everything is passed on to subscribers which lets you decide exactly on what you want to do with the
results that are collected along the way.
The flow of every request executed by `Escargot` is as follows which maps to the corresponding methods in the subscribers:

1. Decide whether a request should be sent at all (if no subscriber requests the request, none is executed):
   
   `SubscriberInterface:shouldRequest()`
   
2. If a request was sent, wait for the first response chunk and decide whether the whole response body should be loaded:
   
   `SubscriberInterface:needsContent()`
   
3. If the body was requested, the data is passed on to the subscribers on the last response chunk that arrives:
   
   `SubscriberInterface:onLastChunk()`

Adding a subscriber is accomplished by implementing the `SubscriberInterface` and registering it
using `Escargot::addSubscriber()`:

```php
<?php

$escargot->addSubscriber(new MySubscriber());
```

According to the flow of every request, the `SubscriberInterface` asks you to implement `3` methods:

*   `shouldRequest(CrawlUri $crawlUri, string $currentDecision): string;`

    This method is called before a request is executed. Note that the logic is inverted: If none of the registered
    subscribers asks Escargot to execute the request, it's not going to be requested. That allows for a lot more
    flexibility. If it was the other way around, one subscriber could cancel the request and thus cause another
    subscriber to not get any results. You may return one of the following `3` constants:
    
    * `SubscriberInterface::DECISION_POSITIVE`
    
        Returning a positive decision will cause the request to be executed no matter what other subscribers return.
        It will also cause `needsContent()` to be called on this subscriber.
    
    * `SubscriberInterface::DECISION_ABSTAIN`
    
        Returning an abstain decision will not cause the request to be executed. However, if any other subscriber returns a positive
        decision, `needsContent()` will still be called on this subscriber.

    * `SubscriberInterface::DECISION_NEGATIVE`
    
        Returning a negative decision will make sure, `needsContent()` is not called on this subscriber, no matter whether
        another subscriber returns a positive decision thus causing the request to be executed.

*   `needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk, string $currentDecision): string;`

    This method is called on the first chunk of a request that arrives. You have access to all the headers now but the content
    of the response might not yet be loaded. Note that the logic is inverted: If none of the
    registered subscribers asks Escargot to provide the content, it's going to cancel the request and thus early abort.
    Again you may return one of the following `3` constants:
    
    * `SubscriberInterface::DECISION_POSITIVE`
        
        Returning a positive decision will cause the request to be finished (whole response content is loaded) no matter what
        other subscribers return.
        It will also cause `onLastChunk()` to be called on this subscriber.
         
    * `SubscriberInterface::DECISION_ABSTAIN`

        Returning an abstain decision will not cause the request to be finished. However, if any other subscriber returns a
        positive decision, `onLastChunk()` will still be called on this subscriber.
    
    * `SubscriberInterface::DECISION_NEGATIVE`
        
        Returning a negative decision will make sure, `onLastChunk()` is not called on this subscriber, no matter whether
        another subscriber returns a positive decision thus causing the request to be completed.

*   `onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void;`

    If one of the subscribers returned a positive decision during the `needsContent()` phase, all of the subscribers
    that either abstained or replied positively during the `needsContent()` phase, receive the content of the response.

There are `2` other interfaces which you might want to integrate but you don't have to:

*   `ExceptionSubscriberInterface`

    There are two methods to implement in this interface:
    
    `onTransportException(CrawlUri $crawlUri, ExceptionInterface $exception, ResponseInterface $response): void;`
    
    These type of exceptions are thrown if there's something wrong with the transport (timeouts etc.).

    `onHttpException(CrawlUri $crawlUri, ExceptionInterface $exception, ResponseInterface $response, ChunkInterface $chunk): void;`
    
    These type of exceptions are thrown if the status code was in the 300-599 range.
 
    For more information, also see the [Symfony HttpClient docs][Symfony_HTTPClient].

*   `FinishedCrawlingSubscriberInterface`

    There's only one method to implement in this interface:
    
    `finishedCrawling(): void;`

    Once crawling is finished (that does not mean there's no pending queue items, you may also have reached the maximum
    number of requests), all subscribers implementing this interface will be called.


#### Tags

Sometimes you may want to add meta information to any `CrawlUri`  instance so you can let other subscribers decide
what they want to do with this information or it may be relevant during another request.
The `RobotsSubscriber` for instance, tags `CrawlUri` instances when they contained a `<meta name="robots" content="nofollow">`
in the body or the corresponding `X-Robots-Tag` header was set. All the links found on this URI are then not followed
which happens during the next `shouldRequest()` call.

There may be use cases where a tag is not enough. Let's say you had a subscriber that wants to add information to a `CrawlUri`
instance it actually has to load from the filesystem or over HTTP again. Maybe no other subscriber ever uses that data? And
how would you store all that information in the queue anyway? 
That's why you can resolve tag values lazily.
Doing so can be done by calling `$escargot->resolveTagValue($tag)`. Escargot then asks all subscribers that implement the
`TagValueResolvingSubscriberInterface` for the resolved value.

So if you want to provide lazy loaded information in your subscriber, just add a regular tag - say `my-file-info` and
implement the `TagValueResolvingSubscriberInterface` that returns the real value once anybody asks for the value of
that `my-file-info` tag.

In other words, sometimes it's enough to only ask `$crawlUri->hasTag('foobar-tag')` and sometimes you may want to ask
`Escargot` to resolve the tag value using `$escargot->resolveTagValue('foobar-tag')`.
This totally depends on the subscriber.

#### Crawling websites (HTML crawler)

When people read the word «crawl» or «crawler» they usually immediately think of crawling websites. Granted, this is
also the main purpose of this library but if you think about it, nothing you have learnt about `Escargot` so far was
related to crawling websites or HTML. `Escargot` can crawl anything that's based on HTTP and you could write a subscriber
that extracts e.g. new URIs from JSON responses and continue from there.

Awesome isn't it?

To turn our `Escargot` instance into a proper web crawler, we can register the `2` following subscribers shipped
by default:
  
* The `RobotsSubscriber`

  This subscriber handles `robots.txt` content, the `X-Robots-Tag` header and the `<meta name="robots">` HTML tag.
  So it
  
  * Sets `CrawlUri` tags according to the `X-Robots-Tag` header.
  * Sets `CrawlUri` tags according to the `<meta name="robots">` HTML tag.
  * Analyzes `Sitemap` entries in the `robots.txt` and adds the URIs found there to the queue.
  * Handles disallowed paths based on the `robots.txt` content.
  
  This subscriber will never cause any requests to be executed because it doesn't care if anything is
  requested. But if it is, it adds meta information (tags) on all the found `CrawlUri` instances.

* The `HtmlCrawlerSubscriber`

  This subscriber analyzes the HTML and then searches for links and adds those to the queue.
  It
    
  * Sets `CrawlUri` tags if the link contained the `rel="nofollow"` attribute.
  * Sets `CrawlUri` tags if the link contained the attribute `type` and the value was not equal to `text/html`.
  * Sets `CrawlUri` tags for every `data-*` attribute (e.g. `foobar-tag` for `data-foobar-tag`). Values are ignored.
 
  This subscriber will never cause any requests to be executed because it doesn't care if anything is
  requested.
  
  Note: You can use `data-escargot-ignore` on links if you want them to be ignored completely by the `HtmlCrawlerSubscriber`.
 
Using them is done like so:

```php
<?php

use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;

$escargot->addSubscriber(new RobotsSubscriber());
$escargot->addSubscriber(new HtmlCrawlerSubscriber());
```

These two subscribers will help us to build our crawler but we still need to add a subscriber that actually returns
a positive decision on `shouldRequest()`. Otherwise, no request will ever be executed.
This is where you jump in and where you can freely decide on whether you want to respect tags of previous subscribers
or not. A possible solution could look like this:

```php
<?php

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Terminal42\Escargot\Subscriber\Util;

class MyWebCrawler implements SubscriberInterface, EscargotAwareInterface
{
    use EscargotAwareTrait;

    public function shouldRequest(CrawlUri $crawlUri): string
    {
        // Check the original crawlUri to see if that one contained nofollow information
        if (null !== $crawlUri->getFoundOn() && ($originalCrawlUri = $this->getEscargot()->getCrawlUri($crawlUri->getFoundOn()))) {
            if ($originalCrawlUri->hasTag(RobotsSubscriber::TAG_NOFOLLOW)) {
                return SubscriberInterface::DECISION_NEGATIVE;
            }
        }
        
        // Skip links that were disallowed by the robots.txt
        if ($crawlUri->hasTag(RobotsSubscriber::TAG_DISALLOWED_ROBOTS_TXT)) {
            return SubscriberInterface::DECISION_NEGATIVE;
        }
    
        // Skip rel="nofollow" links
        if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_REL_NOFOLLOW)) {
            return SubscriberInterface::DECISION_NEGATIVE;
        }
        
        // Hint: All of the above are typical for HTML crawlers, so there's a helper for you
        // to simplify this:
        if (!Util::isAllowedToFollow($crawlUri, $this->getEscargot())) {
            return SubscriberInterface::DECISION_NEGATIVE;
        }
    
        // Skip the links that have the "type" attribute set and it's not text/html
        if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_NO_TEXT_HTML_TYPE)) {
            return SubscriberInterface::DECISION_NEGATIVE;
        }
    
        // Skip links that do not belong to our BaseUriCollection
        if ($this->escargot->getBaseUris()->containsHost($crawlUri->getUri()->getHost())) {
            return SubscriberInterface::DECISION_NEGATIVE;
        }

        return SubscriberInterface::DECISION_POSITIVE;
    }

    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): string
    {
        return 200 === $response->getStatusCode() && Util::isOfContentType($response, 'text/html') ? SubscriberInterface::DECISION_POSITIVE : SubscriberInterface::DECISION_NEGATIVE;
    }

    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
    {
        // Do something with the data
    }
}
```

You now have a full-fledged web crawler. It's up to you now to see which tags of the different subscribers you actually
want to respect or you don't care about and what you actually want to do with the results.

### Logging in subscribers

Of course you can always use Dependency Injection and inject whatever logger service you want to use in your subscriber.
However, there's also a general logger you can pass to `Escargot` using `Escargot::withLogger()`.
By having all the subscribers log to this central logger (only or in addition to another one you injected yourself)
makes sure, `Escargot` has one central place where all subscribers log their information.
In 99% of all use cases, we want to know

* Which subscriber logged the message
* What `CrawlUri` instance was handled at that time

This is why `Escargot` automatically passes on the logger instance you configured using `Escargot::withLogger()` on
to every subscriber that implements the PSR-6 `LoggerAwareInterface`. It internally decorates it so the concerning
subscriber is already known and you don't have to deal with that:

```php
<?php

use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;

class MyWebCrawler implements SubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function shouldRequest(CrawlUri $crawlUri): string
    {
        if (null !== $this->logger) {
            $this->logger->log(LogLevel::DEBUG, 'My log message');
        }
    }
}
```

Because the logger is decorated automatically, it will eventually end up in the logger you configured using
`Escargot::withLogger()` together with the PSR-6 `$context` that will contain `['source' => 'MyWebCrawler']`.

To make things easy when you want to also make sure the `CrawlUri` instance is passed along in the PSR-6 `$context`
array, use the `SubscriberLoggerTrait` as follows:

```php
<?php

use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\SubscriberLoggerTrait;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;

class MyWebCrawler implements SubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use SubscriberLoggerTrait;

    public function shouldRequest(CrawlUri $crawlUri): string
    {
        // No need to check for $this->logger being null, this is handled by the trait
        $this->logWithCrawlUri($crawlUri, LogLevel::DEBUG, 'My log message');
    }
}
```

### Configuration

There are different configurations you can apply to the `Escargot` instance:

* `Escargot::withHttpClient(HttpClientInterface $client): Escargot`

   You can provide an instance of `Symfony\Component\HttpClient\HttpClientInterface` if you want to use a specific
   implementation or configure it with your custom options.
   If you do not provide any client, `HttpClient::create()` will be used and thus, the best client is being
   automatically chosen for you.

* `Escargot::withMaxRequests(int $maxRequests): Escargot`

   Returns a clone of the `Escargot` instance with a maximum total requests that are going to be executed. It can be
   useful if you have limited resources and only want to execute e.g. `100` requests in this run and continue later on.
   
* `Escargot::withUserAgent(string $userAgent): Escargot`

   Returns a clone of the `Escargot` instance with a different `User-Agent` header. The header is sent with all the
   requests and by default configured to `terminal42/escargot`. Note: This only applies if you did not configure
   your own instance of `HttpClientInterface` using `Escargot::withHttpClient()`.

* `Escargot::withConcurrency(int $concurrency): Escargot`

   Returns a clone of the `Escargot` instance with a maximum concurrent requests that are going to be sent at a time.
   By default, this is configured to `10`.
   
* `Escargot::withRequestDelay(int $requestDelay): Escargot`

   Returns a clone of the `Escargot` instance with an added delay between requests in microseconds. By default, there's
   no extra  delay. It can be useful to make sure `Escargot` does not run into some (D)DOS protection or similar issues.

* `Escargot::withLogger(LoggerInterface $logger): Escargot`

   Returns a clone of the `Escargot` instance with your PSR-3 `Psr\Log\LoggerInterface` instance to gain more insight
   in what's happening in `Escargot`.
   
### Projects that use Escargot

* [Contao, an open source CMS](https://contao.org) uses `Escargot` as of
  version 4.9, and it greatly improved the way search indexing works.

### Attributions

* The logo was designed by the awesome @fkaminski, thank you!

### Roadmap / Ideas

* What about having `Escargot` interpret JavaScript before starting to crawl the content? Should be possible
  by having an `HttpClientInterface` implementation that bridges to `symfony/panther` or `facebook/webdriver`
  directly. PR's welcome!

[Symfony_HTTPClient]: https://symfony.com/doc/current/components/http_client.html
