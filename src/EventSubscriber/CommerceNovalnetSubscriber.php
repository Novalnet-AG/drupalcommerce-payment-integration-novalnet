<?php
/**
 * @file
 * This file that handles the Novalnet affilite request related process.
 * 
 * @category   PHP
 * @package    commerce_novalnet
 * @author     Novalnet AG
 * @copyright  Copyright by Novalnet
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 * @version    1.0.1
 */
namespace Drupal\commerce_novalnet\EventSubscriber;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * This file that handles the Novalnet affilite request related process.
 */
class CommerceNovalnetSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public function checkRequest(GetResponseEvent $event) {

    if (!empty($_POST['tid']) && empty(\Drupal::request()->getSession()->all()) && empty($_POST['sess_lost'])
        && strpos( \Drupal::service('path.current')->getPath(), '/commerce_novalnet/callback') === FALSE) {
        $_POST['sess_lost'] = 1;

        $return_url = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getRequestUri();
        $params = http_build_query($_POST);
        $REDIRECT_URL = str_replace(' ', '', $return_url.'?'.$params );
        $event->setResponse(new RedirectResponse($REDIRECT_URL));
    }

    if ($event->getRequest()->query->get('nn_aff_id')) {
      \Drupal::service('session')->set('nn_aff_id', $event->getRequest()->query->get('nn_aff_id'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['checkRequest'];
    return $events;
  }

}
