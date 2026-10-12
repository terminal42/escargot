# Upgrade from 1.x to 2.0

## Exception subscribers

`ExceptionSubscriberInterface::onTransportException()` now accepts `ResponseInterface|null`
for its response parameter. The response is `null` when the HTTP client throws before
returning a response object.

Update all implementations to accept `ResponseInterface|null` and handle `null` before
accessing the response. Implementations that still require `ResponseInterface` are
incompatible with the updated interface.

The signature of `onHttpException()` is unchanged and its response is always available.

## Doctrine queue schema

The Doctrine queue now requires a nullable `redirected_to` text column to store the final redirect destination.
Update existing queue tables before crawling with 2.0. Call `DoctrineQueue::createSchema()` to migrate the
queue table, or include `DoctrineQueue::getTableSchema()` in your application's schema management.

Custom queue implementations must also persist and restore `CrawlUri::getRedirectedTo()` when storing
or loading a crawl URI, including updates to already processed entries.
