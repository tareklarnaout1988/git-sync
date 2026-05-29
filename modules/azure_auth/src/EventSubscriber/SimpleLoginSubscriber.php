<?php

declare(strict_types=1);

namespace Drupal\azure_auth\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @todo Add description for this subscriber.
 */
final class SimpleLoginSubscriber implements EventSubscriberInterface
{


  protected MessengerInterface $messenger;

  public function __construct(MessengerInterface $messenger)
  {
    $this->messenger = $messenger;
  }
  /**
   * Kernel request event handler.
   */
  public function onKernelRequest(RequestEvent $event): void
  {
    // @todo Place your code here.
    $request = $event->getRequest();
    // dd($request->getPathInfo() === 'user/login/rdts/backend' && $request->isMethod('POST'));

    if ($request->getPathInfo() === '/user/login/rdts/backend' && $request->isMethod('POST')) {
      $email = $request->get('name');
      // Only proceed if email is provided
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $forbidden_domains = ['afdb.org']; // Add your blocked domains here
        $domain = substr(strrchr($email, "@"), 1);
        if (in_array($domain, $forbidden_domains)) {
          $this->messenger->addError('Email domain not allowed for login.');
          $event->setResponse(new RedirectResponse(Url::fromRoute('<front>')->toString()));
        }
      }
    }
  }

  /**
   * Kernel response event handler.
   */
  public function onKernelResponse(ResponseEvent $event): void
  {
    // @todo Place your code here.
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      KernelEvents::REQUEST => ['onKernelRequest'],
      KernelEvents::RESPONSE => ['onKernelResponse'],
    ];
  }
}
