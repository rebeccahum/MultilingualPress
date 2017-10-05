<?php # -*- coding: utf-8 -*-

declare( strict_types = 1 );

namespace Inpsyde\MultilingualPress\Cache\Item;

use Inpsyde\MultilingualPress\Cache\Driver\CacheDriver;

/**
 * A complete multi-driver cache item.
 *
 * @package Inpsyde\MultilingualPress\Cache
 * @since   3.0.0
 */
final class WPCacheItem implements CacheItem {

	const DIRTY = 'dirty';
	const DIRTY_SHALLOW = 'shallow';
	const CLEAN = '';

	/**
	 * @var CacheDriver
	 */
	private $driver;

	/**
	 * @var string
	 */
	private $key;

	/**
	 * @var string
	 */
	private $group = '';

	/**
	 * @var mixed
	 */
	private $value = null;

	/**
	 * @var bool
	 */
	private $is_hit = false;

	/**
	 * @var string
	 */
	private $dirty_status = self::CLEAN;

	/**
	 * @var bool
	 */
	private $is_expired = null;

	/**
	 * @var int
	 */
	private $time_to_live = null;

	/**
	 * @var \DateTimeImmutable
	 */
	private $expire_date = null;

	/**
	 * @var \DateTimeImmutable
	 */
	private $last_save = null;

	/**
	 * @var bool
	 */
	private $shallow_update = false;

	/**
	 * Constructor, sets the key.
	 *
	 * @param CacheDriver $driver
	 * @param string      $key
	 * @param int|null    $time_to_live
	 */
	public function __construct( CacheDriver $driver, string $key, int $time_to_live = null ) {

		$this->driver       = $driver;
		$this->key          = $key;
		$this->time_to_live = $time_to_live;

		$this->value();
	}

	/**
	 * Before the object vanishes its storage its updated if needs to.
	 */
	public function __destruct() {

		if ( $this->dirty_status !== self::CLEAN ) {
			// Shallow update means no change will be done on "last save" property, so we don't prolong the TTL
			$this->shallow_update = $this->dirty_status !== self::DIRTY_SHALLOW;
			$this->update();
			$this->shallow_update = false;
		}
	}

	/**
	 * Cache item key
	 *
	 * @return string
	 */
	public function key(): string {

		return $this->key;
	}

	/**
	 * Sets the value for the cache item.
	 *
	 * @param mixed $value
	 *
	 * @return bool
	 */
	public function set( $value ): bool {

		$this->is_hit       = true;
		$this->value        = $value;
		$this->is_expired   = null;
		$this->dirty_status = self::DIRTY;

		return true;
	}

	/**
	 * Check if the cache item was a hit. Necessary to disguise null values stored in cache.
	 *
	 * @return bool
	 */
	public function is_hit(): bool {

		return $this->is_hit;
	}

	/**
	 * Check if the cache item is expired.
	 *
	 * @return bool
	 */
	public function is_expired(): bool {

		if ( ! $this->is_hit() ) {
			return false;
		}

		if ( isset( $this->is_expired ) ) {
			return $this->is_expired;
		}

		// If the value has a fixed expire date, let's keep it as expire timestamp
		$expiry_time_by_date = $this->expire_date ? $this->expire_date->getTimestamp() : null;

		// If we have a last save and a time to live, calculate an expired timestamp based on that
		$expiry_time_by_ttl = $this->last_save && is_int( $this->time_to_live )
			? $this->last_save->getTimestamp() + $this->time_to_live
			: null;

		// If we don't have and expiration date, nor we were able to calculate a expiration by TTL, let's just return
		if ( $expiry_time_by_date === null && $expiry_time_by_ttl === null ) {
			return false;
		}

		// Expire time is which occur first between expire date end expiration calculated via TTL
		switch ( true ) {
			case ( $expiry_time_by_date === null ) :
				$expiry = $expiry_time_by_ttl;
				break;
			case ( $expiry_time_by_ttl === null ) :
				$expiry = $expiry_time_by_date;
				break;
			default :
				$expiry = min( $expiry_time_by_date, $expiry_time_by_ttl );
				break;
		}

		$this->is_expired = $expiry < (int) $this->now( 'U' );

		return $this->is_expired;
	}

	/**
	 * Cache item value.
	 *
	 * @return mixed Should be null when no value is stored in cache.
	 */
	public function value() {

		if ( $this->is_hit ) {
			return $this->value;
		}

		list( $cached, $found ) = $this->driver->read( $this->group, $this->key );

		$this->is_hit = $found && is_array( $cached ) && $cached;

		$value = $ttl = $expire_date = $last_save = null;

		if ( $this->is_hit ) {
			list( $value, $ttl, $expire_date, $last_save ) = $this->prepare_value( $cached );
		}

		$this->last_save = $last_save;

		if ( $this->value === null ) {
			$this->value = $value;
		}

		if ( $this->time_to_live === null ) {
			$this->time_to_live = is_int( $ttl ) ? $ttl : self::DEFAULT_TIME_TO_LIVE;
		}

		if ( $this->expire_date === null ) {
			$this->expire_date = $expire_date;
		}

		$current_ttl = is_null( $ttl ) ? self::DEFAULT_TIME_TO_LIVE : $ttl;

		$current_timestamp = $expire_date ? $expire_date->getTimestamp() : 0;
		$new_timestamp     = $this->expire_date ? $this->expire_date->getTimestamp() : 0;

		$this->dirty_status = self::CLEAN;
		if ( $this->value !== $value ) {
			$this->dirty_status = self::DIRTY;
		} elseif ( ( $current_ttl !== $this->time_to_live ) || ( $current_timestamp !== $new_timestamp ) ) {
			$this->dirty_status = self::DIRTY_SHALLOW;
		}

		return $this->value;
	}

	/**
	 * Delete the cache item from its storage and ensure that next value() call return null
	 * (unless added again to storage).
	 *
	 * @return bool
	 */
	public function delete(): bool {

		$this->value        = $this->time_to_live = $this->last_save = $this->is_expired = null;
		$this->is_hit       = false;
		$this->dirty_status = self::DIRTY;

		return true;
	}

	/**
	 * Sets a specific date of expiration of the item.
	 *
	 * @param \DateTimeInterface $expire_date
	 *
	 * @return CacheItem
	 */
	public function expires_on( \DateTimeInterface $expire_date ): CacheItem {

		// Let's ensure expire_date is immutable and it is in the GMT timezone
		$exp_offset  = $expire_date->getOffset();
		$timestamp   = $exp_offset === 0 ? $expire_date->getTimestamp() : $expire_date->getTimestamp() + $exp_offset;
		$expire_date = \DateTimeImmutable::createFromFormat( 'U', (string) $timestamp, new \DateTimeZone( 'GMT' ) );

		$this->expire_date = $expire_date;

		if ( $this->is_hit ) {
			// Temporarily mark as not hit and call value to update dirty status if necessary.
			$this->is_hit = false;
			$this->value();
			$this->is_hit = true;
		}

		$this->is_expired = null;

		return $this;
	}

	/**
	 * Expiration the item after a given number of seconds.
	 *
	 * @param int $time_to_live
	 *
	 * @return CacheItem
	 */
	public function expires_after( int $time_to_live ): CacheItem {

		$now = $this->now();

		return $this->expires_on( $now->setTimestamp( $now->getTimestamp() + $time_to_live ) );
	}

	/**
	 * @return bool
	 */
	private function update(): bool {

		if ( $this->is_hit ) {

			$this->driver->write( $this->group, $this->key, $this->prepare_value() );
			$this->is_expired = null;

			return true;
		}

		$this->delete();

		return true;
	}

	/**
	 * @param string|null $format
	 *
	 * @return \DateTimeImmutable|string
	 */
	private function now( string $format = null ) {

		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'GMT' ) );

		return $format ? $now->format( $format ) : $now;
	}

	/**
	 * Compact to and explode from storage a value
	 *
	 * @param array $compact_value
	 *
	 * @return array
	 */
	private function prepare_value( array $compact_value = null ): array {

		if ( $compact_value === null ) {

			// When doing a shallow update, we don't update last save time, unless value was never saved before
			$last_save = ( ! $this->shallow_update || ! $this->last_save ) ? $this->now() : $this->last_save;

			return [
				'V' => $this->value,
				'T' => (int) $this->time_to_live ?: self::DEFAULT_TIME_TO_LIVE,
				'E' => $this->expire_date ? $this->serialize_date( $this->expire_date ) : '',
				'S' => $this->serialize_date( $last_save ),
			];
		}

		$value       = $compact_value['V'] ?? null;
		$ttl         = $compact_value['T'] ?? null;
		$expire_date = ( $compact_value['E'] ?? null );
		$last_save   = ( $compact_value['S'] ?? null );

		return [
			$value,
			$ttl === null ? null : (int) $ttl,
			$expire_date === null ? null : $this->unserialize_date( (string) $expire_date ),
			$last_save === null ? null : $this->unserialize_date( (string) $last_save ),
		];
	}

	/**
	 * @param \DateTimeInterface $date
	 *
	 * @return string
	 */
	private function serialize_date( \DateTimeInterface $date ): string {

		return $date->format( 'c' );
	}

	/**
	 * @param string $date
	 *
	 * @return \DateTimeImmutable|null
	 */
	private function unserialize_date( string $date ) {

		if ( ! $date || ! is_string( $date ) ) {
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat( 'U', (string) strtotime( $date ) );

		return $date ?: null;
	}
}