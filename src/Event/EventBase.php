<?php

namespace Drupal\heisencache\Event;

use Drupal\heisencache\HeisencacheServiceProvider as H;
use Drupal\heisencache\HeisencacheServiceProvider;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\Event;

/**
 * Base Heisencache event.
 *
 * Unlike the base Symfony Event class, this one always contains a bin, which
 * may be empty on a few events only, like system shutdown. Events are marked
 * as "pre" by default on construction, and can be set to "post", by the
 * fluent setPost() method.
 *
 * @package Drupal\heisencache\Event
 */
abstract class EventBase extends Event implements EventInterface {

  /**
   * The event name.
   *
   * @var string
   *  "$name", although deprecated, still exists in Symfony Event class, so we
   *   may not overwrite it.
   */
  public $eventName = 'base';

  /**
   * The cache bin on which the event was triggered.
   *
   * @var string
   *   No need for getter/setter: no side effects.
   */
  public $bin;

  /**
   * Event data.
   *
   * @var mixed[]
   *   Whatever data a concrete event may want to store.
   */
  protected $data;

  /**
   * The event kind: pre or post.
   *
   * @var string
   *   EventInterface::PRE|POST
   */
  public $kind;

  /**
   * EventBase constructor.
   *
   * @param string $bin
   *   The name of the bin on which an event is being dispatched.
   * @param string $kind
   *   The kind of event: self::(PRE|IN\POST).
   * @param array $data
   *   Optional: event data.
   */
  public function __construct($bin, $kind = self::PRE, array $data = []) {
    if (isset($_SERVER['UNIQUE_ID'])) {
      $data += ['unique_id' => $_SERVER['UNIQUE_ID']];
    }
    $this->bin = $bin;
    $this->kind = $kind;
    $this->data = $data;
    // __CLASS__ would give this class, unlike get_class($this).
    $this->eventName = static::eventNameFromClass(get_class($this));
  }

  /**
   * Build a candidate handler name for an event name.
   *
   * @param string $eventName
   *   The name of the event for which to build a candidate method name.
   *
   * @return string
   *   The name of the candidate handler.
   */
  public static function callbackFromEventName(string $eventName) : string {
    $own = H::MODULE . '.';
    $pos = strpos($eventName, $own);
    $event = ($pos === 0) ? substr($eventName, strlen($own)) : "on.{$eventName}";
    $spaced = strtr($event, ['_' => ' ', '.' => ' ', '\\' => '_ ']);
    $callback = lcfirst(strtr(ucwords($spaced), [' ' => '']));
    return $callback;
  }

  /**
   * Build the name of the event class from the event name.
   *
   * @param string $eventName
   *   The event name.
   *
   * @return string
   *   The class name.
   */
  public static function classFromEventName(string $eventName) : string {
    $class = __NAMESPACE__ . "\\${$eventName}";
    return $class;
  }

  /**
   * Getter for the event data.
   *
   * @return array
   *   The data, as an array of opaque rows.
   */
  public function data() : array {
    return $this->data;
  }

  /**
   * Build an event name from a FQCN.
   *
   * @param string $class
   *   The complete class name.
   *
   * @return string
   *   The event name.
   */
  public static function eventNameFromClass(string $class) : string {
    $event_name = substr(strrchr($class, '\\'), 1);
    $event_name = Container::underscore($event_name);
    return H::MODULE . ".${event_name}";
  }

  /**
   * {@inheritdoc}
   */
  public function kind() {
    return $this->kind;
  }

  /**
   * {@inheritdoc}
   */
  public function name() {
    $baseName = $this->eventName;

    $name = (strpos($baseName, H::MODULE) === 0)
      ? preg_replace('/^(' . H::MODULE . ')\./', "\$1.{$this->kind}_", $baseName)
      : $baseName;

    return $name;
  }

  /**
   * Allow propagation (again) on event.
   *
   * Symfony Event does not allow this, hence the Reflection access override.
   */
  public function restartPropagation() {
    if ($this->isPropagationStopped()) {
      $rm = new \ReflectionProperty($this, 'propagationStopped');
      $rm->setAccessible(TRUE);
      $rm->setValue($this, FALSE);
    }

    return $this;
  }

  /**
   * Merge the passed data with the existing instance data.
   *
   * @param mixed[] $data
   *   The new data to merge.
   *
   * @return $this
   */
  public function setData(array $data) {
    $this->data += $data;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setPost() {
    $this->restartPropagation();
    $this->kind = static::POST;
    return $this;
  }

}
