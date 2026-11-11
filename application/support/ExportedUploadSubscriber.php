<?php

declare(strict_types=1);

namespace Drupack\Support;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers a public file to a request Tome makes while it exports. Tome copies a file it finds
 * under the docroot and asks Drupal for any other path, and public files live in Site data,
 * where Drupal has no route for them. Tome copies the file of a file response into the export.
 */
final class ExportedUploadSubscriber implements EventSubscriberInterface {

  /**
   * @param string $exportRequestKey
   *   The request attribute Tome sets on the requests it makes.
   */
  public function __construct(private readonly string $exportRequestKey) {}

  public static function getSubscribedEvents(): array {
    // Ahead of the router, which answers 404 for a public file.
    return [KernelEvents::REQUEST => ['onRequest', 100]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    // Tome sets the attribute in process, so no request from the network carries it.
    if (!$request->attributes->has($this->exportRequestKey)) {
      return;
    }
    $prefix = '/' . SiteDataPublicStream::ADDRESS . '/';
    $path = rawurldecode($request->getPathInfo());
    if (!str_starts_with($path, $prefix)) {
      return;
    }
    // SiteDataPublicStream refuses a target that resolves outside public storage.
    $uri = 'public://' . substr($path, strlen($prefix));
    if (is_file($uri)) {
      $event->setResponse(new BinaryFileResponse($uri));
    }
  }

}
