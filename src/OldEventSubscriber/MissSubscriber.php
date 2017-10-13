<?php
/**
 * @file
 * A subscriber on cache miss events.
 *
 * @author: Frederic G. MARAND <fgm@osinet.fr>
 *
 * @copyright (c) 2013-2014 Ouest Systèmes Informatiques (OSInet).
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\heisencache\EventSubscriber;


use Drupal\heisencache\Event\EventSourceInterface;

class MissSubscriber extends BaseEventSubscriber implements EventSourceInterface {

  use EventSourceTrait;

  const NAME = "misses";

  protected static $subscribedEvents = [
    'afterGet' =>  1,
    'afterGetMultiple' => 1,
    'beforeGetMultiple' => 1,
  ];

  protected static $emittedEvents = [
    'miss',
    'missMultiple',
  ];

  protected $multipleCids = [];

  /**
   * @param string $channel
   * @param string $cid
   * @param mixed $value
   *
   * @return array
   */
  public function afterGet($channel, $cid, $value) {
    if ($value === FALSE) {
      $missInfo = array(
        'subscriber' => static::NAME,
        'op' => 'get',
        'bin' => $channel,
        'requested' => array($cid),
        'misses' => array($cid),
      );

      $this->emitter->emit('miss', $channel, $missInfo);
    }
    else {
      $missInfo = array();
    }

    return $missInfo;
  }

  /**
   * @param string $channel
   * @param string[] $missed_cids
   *
   * @return array
   */
  public function afterGetMultiple($channel, $missed_cids) {
    $requested = $this->multipleCids;
    $this->multipleCids = array();

    if (!empty($missed_cids)) {
      $missInfo = array(
        'subscriber' => static::NAME,
        'op' => 'get_multiple',
        'bin' => $channel,
        'requested' => $requested,
        'misses' => $missed_cids,
      );
      $missInfo['full_miss'] = ($missed_cids == $requested);

      $this->emitter->emit('missMultiple', $channel, $missInfo);
    }
    else {
      $missInfo = array();
    }

    return $missInfo;
  }

  public function beforeGetMultiple($channel, $cids) {
    $this->multipleCids = $cids;
  }

}
