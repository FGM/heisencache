<?php

namespace Drupal\heisencache\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * WatchdogWriterSubscriber class: accumulate events, write them at end of page.
 *
 * @package Drupal\heisencache\EventSubscriber
 */
class WatchdogWriter extends BaseWriter {

  /**
   * The logger.channel.heisencache service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  public function __construct(array $events = [], LoggerInterface $logger) {
    parent::__construct($events);
    $this->logger = $logger;
  }

  public static function describe(): Definition {
    $def = parent::describe()
      ->addArgument(new Reference('logger.channel.heisencache'));
    return $def;
  }

  public function onShutdown(): void {
    if (!empty($this->history)) {
      $this->logger->debug('Cache events: @events', [
        '@events' => serialize($this->history),
      ]);
    }
  }
}