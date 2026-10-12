# Upgrade from 1.x to 2.0

## Exception subscribers

`ExceptionSubscriberInterface::onTransportException()` now accepts `ResponseInterface|null`
for its response parameter. The response is `null` when the HTTP client throws before
returning a response object.

Update all implementations to accept `ResponseInterface|null` and handle `null` before
accessing the response. Implementations that still require `ResponseInterface` are
incompatible with the updated interface.

The signature of `onHttpException()` is unchanged and its response is always available.
