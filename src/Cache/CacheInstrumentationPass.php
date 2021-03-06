<?php

namespace Drupal\heisencache\Cache;

use Drupal\heisencache\Exception\ConfigurationException;
use Drupal\heisencache\HeisencacheServiceProvider as H;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Class CacheInstrumentationPass decorates cache bins.
 *
 * @package Drupal\heisencache\Cache
 *
 * @see \Drupal\heisencache\HeisencacheServiceProvider::register()
 */
class CacheInstrumentationPass implements CompilerPassInterface {

  /**
   * A container reference to the event dispatcher service.
   *
   * @var \Symfony\Component\DependencyInjection\Reference
   */
  protected $dispatcher;

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container) {
    if (!$container->hasDefinition(H::DISPATCHER)) {
      throw new ConfigurationException('Event dispatcher service not found during Heisencache configuration.');
    }

    $bins = $container->getParameter('cache_bins');
    $this->dispatcher = new Reference(H::DISPATCHER);
    array_walk($bins, [$this, 'decorateBin'], $container);
  }

  /**
   * Decorate cache bin services with the Heisencache wrapper.
   *
   * @param string $bin
   *   The cache bin name.
   * @param string $serviceId
   *   The name of the service for the cache bin.
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
   *   The container.
   */
  protected function decorateBin(string $bin, string $serviceId, ContainerBuilder $container) {
    $decoratorName = "heisencache.decorating_{$serviceId}";
    $decoratedName = "{$decoratorName}.inner";

    $container->register($decoratorName, InstrumentedBin::class)
      ->setDecoratedService($serviceId)
      ->addArgument(new Reference($decoratedName))
      ->addArgument($bin)
      ->addArgument($this->dispatcher)
      ->setPublic(TRUE);
  }

}
