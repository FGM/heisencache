<?php

namespace Drupal\heisencache\EventSubscriber;

/**
 * An interface for subscribers accepting a configurable list of events.
 *
 * Implementers MUST also implement one method for each subscribed event.
 *
 * @package Drupal\heisencache\EventSubscriber
 */
interface ConfigurableListenerInterface {

  const TAG = 'heisencache_listener';

  /**
   * Add an event to the service listening list.
   *
   * @param string $eventName
   */
  public function addEvent($eventName): void;

  /**
   * Return the events to which the service is listening.
   *
   * @return array
   */
  public function getSubscribedEvents(): array;

  /**
   * Remove an event from the service listening list.
   *
   * @param string $eventName
   */
  public function removeEvent($eventName): void;

}