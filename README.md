# Escargot

[![](https://travis-ci.com/terminal42/escargot.svg?branch=master)](https://travis-ci.com/terminal42/escargot)

A library that provides everything you need to crawl your website and process the responses in whatever way you
prefer based on Symfony components.

### Why another crawler?

There are so many different implementations in so many programming languages, right?
Well, the ones I found in PHP did not really live up to my personal quality standards and also I wanted something
that's built on top of the Symfony HttpClient component. Hence, yet another library.

### What about that name «Escargot»?

When I created this library I didn't want to name it «crawler» or «spider» or anything similar that's been used
hundreds of times before. So I started to think about things that actually crawl and one thing that came to my mind
immediately were snails. But «snail» doesn't really sound super beautiful and so I just went with the French translation
for it which is «escargot». There you go! Also French is a beautiful language anyway and if you didn't know: tons of
libraries in the PHP ecosystem were invented and are still maintained by French people so it's also some kind of tribute
to the French PHP (and Symfony for that matter) community.

By the way: Thanks to the Symfony HttpClient Escargot is actually not slow as a snail at all ;-)

### Installation

```bash
composer require terminal42/escargot
```

### Usage

Everything in Escargot is assigned to a job ID. The reason for this design is that crawling huge sites can take very
long and chances that you'll want to stop at some point and pick up where you left are pretty high.
For that matter, every `Escargot` instance also needs a queue plus a base URI as to where to start crawling.
Of course, because we execute requests, we can also provide an instance of `Symfony\Component\HttpClient\HttpClientInterface`
but that's completely optional. If you do not provide any client, `HttpClient::create()` will be used and the best
client is automatically chosen for you.

#### Instantiating Escargot

The factory method when you do not have a job ID yet has to be used as follows:

```php
<?php

use Nyholm\Psr7\Uri;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;

$baseUri = new Uri('https://www.terminal42.ch');
$queue = new InMemoryQueue();
        
$escargot = Escargot::createWithNewJobId($baseUri, $queue);
```

If you want to use a special `HttpClientInterface` implementation, you can provide this as third parameter
like so:

```php
<?php

use Nyholm\Psr7\Uri;
use Symfony\Component\HttpClient\CurlHttpClient;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;

$baseUri = new Uri('https://www.terminal42.ch');
$queue = new InMemoryQueue();
$client = new CurlHttpClient(['custom' => 'options']);
        
$escargot = Escargot::createWithNewJobId($baseUri, $queue, $client);
```

In case you already do have a job ID because you have initiated crawling previously we do not need any base URI
anymore but the job ID instead (again `$client` is completely optional):

```php
<?php

use Symfony\Component\HttpClient\CurlHttpClient;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;

$queue = new InMemoryQueue();
$client = new CurlHttpClient(['custom' => 'options']); // optional
        
$escargot = Escargot::createFromExistingJobId($jobId, $queue, $client);
```
   
### The different queue implementations

As explained before, the queue is an essential part of Escargot because it keeps track of all the URIs that have been
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

#### Events

You might be wondering how you can access the results of your crawl process. In Escargot, `crawl()` does not return
anything but instead, everything is event based which lets you decide exactly on what you want to do with the results
that are collected along the way.
Currently there are `4` different core events:

* `SuccessfulResponseEvent`

  The probably most important event for you as it is dispatched whenever a response arrived successfully.
  You have access to `Escargot` itself, the resulting instance of `ResponseInterface` and also the `CrawlUri` which
  contains the URI that was crawled and the information on what level and on what URI it was found.

* `UnsuccessfulResponseEvent`

  This event is dispatched when a response was not successful. Not successful in that case means any response that
  did not return the HTTP status code `200`. It's a good place to e.g. log dead links and more.
  You have access to `Escargot` itself, the resulting instance of `ResponseInterface` and also the `CrawlUri` which
  contains the URI that was crawled and the information on what level and on what URI it was found.

* `FinishedCrawlingEvent`

  This event is dispatched when crawling has finished because either the maximum configured requests have been reached 
  (see «Configuration») or the queue is empty, meaning there's nothing left to crawl.
  You have access to `Escargot` itself which e.g. allows you to ask for the total requests sent
  (`Escargot::getRequestsSent()`).
  
* `RequestExceptionEvent`

  This event is dispatched when the Symfony HttpClient emits an exception.
  Compared to the `UnsuccessfulResponseEvent` you probably do not even have a `ResponseInterface` with an HTTP response
  status code other than `200`.
  Apart from `Escargot` itself you have access to the `ExceptionInterface` instance (so e.g. `TransportExceptionInterface`
  but also `ClientExceptionInterface` etc.) and the `ResponseInterface` instance if there is any (if the exception occurs
  during initiation of the request, there won't be any response).  

In addition to these events, there are `2` events that are dispatched when links were found on a site but Escargot did
not follow them for different reasons:

* `ExcludedByRobotsMetaTagEvent`

  This event is dispatched when there were links on a site but following them was disallowed due to the
  `<meta type="robots" content="nofollow">` meta tag being present. You have access to `Escargot` itself plus the
  `UriInterface` instance of the link that could've been followed to if it hadn't been disallowed by the meta tag.
  
* `ExcludedByUriFilterEvent`

  This event is dispatched when there were links on a site but following them was disallowed due to the configured
  `UriFilterInterface` instance (see «Configuration»). You have access to `Escargot` itself plus the
  `UriInterface` instance and the `\DomNode` instance of the link that could've been followed to if it hadn't been
   disallowed by the URI filter.
 

Listening to these events is accomplished using the Symfony EventDispatcher and its `EventSubscriberInterface`.
This listener class can then be registered using `Escargot::addSubscriber()`:

```php
<?php

$escargot->addSubscriber(new MySubscriber());
```

You can check out the `LoggerSubscriber` shipped with this library which implements very simple PSR-6 logging as a
reference.
  
#### Configuration

There are different configurations you can apply to the `Escargot` instance:

* `Escargot::setMaxRequests(int $maxRequests)`

   Lets you allow the maximum total requests that are going to be executed. It can be useful if you have limited
   resources and only want to execute e.g. `100` requests in this run and continue later on.

* `Escargot::setConcurrency(int $concurrency)`

   Lets you configure the maximum concurrent requests that are being sent. By default, this is configured to `10`.
   
* `Escargot::setMaxDepth(int $maxDepth)`

   Lets you configure the maximum depth Escargot will crawl. Your base URI equals to level `0` and from there on
   the level is increased.   
   
* `Escargot::setRequestDelay(int $requestDelay)`

   Lets you configure the delay between requests in microseconds. By default, it's `0` so there's no extra
   delay. It can be useful to make sure Escargot does not run into some (D)DOS protection or similar issues.

* `Escargot::setUriFilter(UriFilterInterface $uriFilter)`

   By default, Escargot is instantiated using the `DefaultUriFilter` which will ensure we follow only links that
   fulfil the following requirements:
   
    * Either `http` or `https` schema
    * The node does not have `rel="nofollow"` set
    * The node does not have the type attribute set or it is set and the value equals to `text/html`
    * The URI is allowed by the configured allowed hosts (by default just the same host as the base URI)
    * The URI is allowed by the `robots.txt` of that URI
    
   By providing your own implementation of the `UriFilterInterface` you can completely customize the filtering
   to your needs.
   
## Roadmap / Ideas

* This is just an alpha version so please expect things to break. I'm going to follow SemVer for this library
  which is why we have 0.x version numbers for now unit I personally find it to be stable enough to release 
  version 1.0.0.
  
* What about having Escargot interpret JavaScript before starting to crawl the content? Should be possible
  by having an `HttpClientInterface` implementation that bridges to `symfony/panther` or `facebook/webdriver`
  directly. PR's welcome!

* Maybe one day, some talented illustrator finds this library and enhances it with a nice logo? :-)
  
* I'm a core dev member of [Contao, an open source CMS](https://contao.org). I would like to integrate Escargot there
  in one of the upcoming versions to improve the way search indexing is achieved. I guess you should know that I will
  likely not be tagging it stable before I've finished the integration but we'll see.
