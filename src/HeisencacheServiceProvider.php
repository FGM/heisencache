<?php

namespace Drupal\heisencache;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\heisencache\Cache\CacheInstrumentationPass;
use Drupal\heisencache\Cache\CacheSubscriptionPass;
use Drupal\heisencache\EventSubscriber\ConfigurableListenerInterface;
use Drupal\heisencache\EventSubscriber\EventSourceInterface;
use Drupal\heisencache\EventSubscriber\TerminateWriterInterface;
use Drupal\heisencache\Exception\ConfigurationException;
use Drupal\heisencache\Menu\LinksProvider;
use Drupal\heisencache\Routing\RouteProvider;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class HeisencacheServiceProvider defines the module services.
 *
 * @package Drupal\heisencache
 */
class HeisencacheServiceProvider implements ServiceProviderInterface, ServiceModifierInterface {

  const MODULE = 'heisencache';

  const NS = 'EventSubscriber';

  const FQNS = __NAMESPACE__ . '\\' . self::NS;

  // Generic service names.
  const LOGGER = 'logger.channel.' . self::MODULE;

  const HELP_PROVIDER = self::MODULE . '.help_provider';
  const LINKS_PROVIDER = self::MODULE . '.links_provider';
  const ROUTE_PROVIDER = self::MODULE . '.route_provider';

  /**
   * Obtain the listener/subscriber configuration from the container parameters.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container.
   *
   * @return array
   *   The parsed configuration.
   */
  protected function getConfigurationParameter(ContainerBuilder $container) : array {
    // Cannot access configuration during a container build, so use a parameter.
    $configuredServices = $container->getParameter('heisencache')['subscribers'];
    $result = [];
    foreach ($configuredServices as $baseName => $config) {
      $name = self::MODULE . ".subscriber.${baseName}";
      $result[$name] = $config;
    }

    return $result;
  }

  /**
   * Discover builtin and third-party subscribers.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container builder.
   *
   * @return array<string,\ReflectionClass>
   *   An array names of configurable subscriber services.
   *
   * @throws \Drupal\heisencache\Exception\ConfigurationException
   */
  protected function discoverSubscribers(ContainerBuilder $container) : array {
    $subscriberParameter = $this->getConfigurationParameter($container);

    $configuredSubscribers = [];
    foreach ($subscriberParameter as $serviceName => $events) {
      // "Discover" existing static (third-party) listeners.
      if ($container->hasDefinition($serviceName)) {
        $class = $container->getDefinition($serviceName)->getClass();
      }
      // Discover Heisencache dynamic listeners. They are known to be called:
      // heisencache.listener.<short name> so no need for pattern matching.
      else {
        $shortName = ucfirst(current(array_slice(explode('.', $serviceName, 3), 2)));
        $class = static::FQNS . '\\' . Container::camelize($shortName . 'Subscriber');
      }
      $reflectionClass = new \ReflectionClass($class);
      if (!$reflectionClass->isInstantiable()) {
        throw new ConfigurationException("Service $serviceName is not instantiable.");
      }
      if (!$reflectionClass->implementsInterface(ConfigurableListenerInterface::class)) {
        throw new ConfigurationException("Service $serviceName is not configurable.");
      }
      $configuredSubscribers[$serviceName] = [
        'events' => $events,
        'rc' => $reflectionClass,
      ];
    }

    return $configuredSubscribers;
  }

  /**
   * Register the generic providers.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container builder.
   */
  protected function registerGenericProviders(ContainerBuilder $container) {
    $definition = (new DefinitionDecorator('logger.channel_base'))
      ->addArgument(self::MODULE);
    $container->setDefinition(self::LOGGER, $definition);

    $container->register(self::HELP_PROVIDER, HelpProvider::class);
    $container->register(self::ROUTE_PROVIDER, RouteProvider::class)
      ->addTag('event_subscriber');
    $container->register(self::LINKS_PROVIDER, LinksProvider::class);
  }

  /**
   * Register the default Heisencache parameter configuration.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container in which to register parameters.
   */
  protected function registerParameters(ContainerBuilder $container) {
    $container->setParameter(self::MODULE, [
      'subscribers' => [],
    ]);
  }

  /**
   * Register a configured listener in the container.
   *
   * Since it runs at the AFTER_REMOVING step, all services are already
   * available, even though they are not yet fully configured, since this is
   * when we add the events they listen to.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container in which to register this listener service.
   * @param string $name
   *   The name under which to register the service.
   * @param array|null $events
   *   The events which the service listens to.
   * @param \ReflectionClass $rc
   *   The reflection class for the service.
   */
  protected function registerSubscriber(ContainerBuilder $container, string $name, $events, \ReflectionClass $rc) {
    $definition = $rc->implementsInterface(DescribedServiceInterface::class)
      // Auto-described service: use its own description.
      ? call_user_func([$rc->getName(), 'describe'])
      // Static service: use the existing container definition.
      : $container->getDefinition($name);

    foreach ((array) $events as $eventName) {
      $definition->addMethodCall('addEvent', [$eventName]);
    }

    $container->setDefinition($name, $definition);
  }

  /**
   * {@inheritdoc}
   *
   * - Add a pass decorating cache services (bins, backends) with Heisencache.
   * - Register link and route provider services.
   * - Do NOT register subscriber services: the parameter configuring them is
   *   not yet available at this step.
   *
   * @see \Drupal\heisencache\HeisencacheServiceProvider::alter()
   */
  public function register(ContainerBuilder $container) {
    // Add decorator services before optimization.
    $container->addCompilerPass(new CacheInstrumentationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    // But modify the event_dispatcher subscriptions after they have been setup
    // during RegisterEventSubscriberPass, which runs after removing.
    $container->addCompilerPass(new CacheSubscriptionPass(), PassConfig::TYPE_AFTER_REMOVING);

    $this->registerGenericProviders($container);
    $this->registerParameters($container);
  }

  /**
   * {@inheritdoc}
   *
   * Heisencache subscribers need to be registered during the container alter
   * phase since they are dynamic: the parameters are not yet available during
   * the register() phase: only the default configuration is available at this
   * point.
   */
  public function alter(ContainerBuilder $container) {
    $subscribers = $this->discoverSubscribers($container);
    foreach ($subscribers as $name => $info) {
      $this->registerSubscriber($container, $name, $info['events'], $info['rc']);
    }
  }

}
