<?php
/**
 * Prime Cache - Memcached Object Cache Backend
 *
 * Requires: Memcached PHP extension (PECL memcached)
 *
 * Supported constants for configuration:
 *   WP_MEMCACHED_SERVERS  (default: array( '127.0.0.1:11211' ))
 */

if ( ! class_exists( 'Memcached' ) ) :
	define( 'PRIME_CACHE_OBJECT_BACKEND_MISSING', true );
	return;
endif;

function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_delete( $key, $group = 'default' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group );
}

function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_incr( $key, $offset = 1, $group = 'default' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = 'default' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

function wp_cache_flush_runtime() {
	global $wp_object_cache;
	return $wp_object_cache->flush_runtime();
}

function wp_cache_flush_group( $group ) {
	global $wp_object_cache;
	return $wp_object_cache->flush_group( $group );
}

function wp_cache_supports( $feature ) {
	return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ), true );
}

function wp_cache_init() {
	global $wp_object_cache;
	$wp_object_cache = new WP_Object_Cache();
}

function wp_cache_close() {
	global $wp_object_cache;
	return $wp_object_cache->close();
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	$wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_get_multiple( $keys, $group = 'default', $force = false ) {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple( $keys, $group, $force );
}

function wp_cache_set_multiple( $data, $group = 'default', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set_multiple( $data, $group, $expire );
}

function wp_cache_add_multiple( $data, $group = 'default', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add_multiple( $data, $group, $expire );
}

function wp_cache_delete_multiple( $keys, $group = 'default' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete_multiple( $keys, $group );
}

class WP_Object_Cache {

	private $mc;
	private $cache = array();
	private $global_groups = array();
	private $non_persistent_groups = array();
	private $blog_prefix;
	private $key_prefix;

	public $cache_hits = 0;
	public $cache_misses = 0;

	public function __construct() {
		global $blog_id;

		$this->blog_prefix = is_multisite() ? (int) $blog_id . ':' : '';
		$this->key_prefix  = defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '';

		$this->mc = new Memcached();

		// Only add servers if none exist (persistent connections).
		if ( empty( $this->mc->getServerList() ) ) {
			$servers = defined( 'WP_MEMCACHED_SERVERS' ) ? WP_MEMCACHED_SERVERS : array( '127.0.0.1:11211' );
			foreach ( (array) $servers as $server ) {
				$parts = explode( ':', $server );
				$host  = $parts[0];
				$port  = isset( $parts[1] ) ? (int) $parts[1] : 11211;
				$this->mc->addServer( $host, $port );
			}
		}
	}

	/** @var array Group version cache. */
	private $group_versions = array();

	private $global_version = null;

	private function derive_key( $key, $group = 'default' ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}
		$prefix  = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix;
		$gv      = $this->get_global_version();
		$version = $this->get_group_version( $group );
		return $this->key_prefix . $prefix . $gv . ':' . $group . ':' . $version . ':' . $key;
	}

	private function get_global_version() {
		if ( null !== $this->global_version ) {
			return $this->global_version;
		}
		$ver_key = $this->key_prefix . 'prime_cache_global_ver';
		$ver = $this->mc->get( $ver_key );
		$this->global_version = ( Memcached::RES_NOTFOUND !== $this->mc->getResultCode() ) ? $ver : 0;
		return $this->global_version;
	}

	private function get_group_version( $group ) {
		if ( isset( $this->group_versions[ $group ] ) ) {
			return $this->group_versions[ $group ];
		}
		$version_key = $this->key_prefix . 'prime_cache_gv:' . $group;
		$version = $this->mc->get( $version_key );
		if ( Memcached::RES_NOTFOUND === $this->mc->getResultCode() ) {
			$version = 1;
			$this->mc->set( $version_key, $version );
		}
		$this->group_versions[ $group ] = $version;
		return $version;
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		$derived = $this->derive_key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			if ( isset( $this->cache[ $derived ] ) ) {
				return false;
			}
			$this->cache[ $derived ] = $data;
			return true;
		}

		$result = $this->mc->add( $derived, $data, $expire );
		if ( $result ) {
			$this->cache[ $derived ] = $data;
		}
		return $result;
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$derived = $this->derive_key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			$this->cache[ $derived ] = $data;
			return true;
		}

		$result = $this->mc->set( $derived, $data, $expire );
		if ( $result ) {
			$this->cache[ $derived ] = $data;
		}
		return $result;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$derived = $this->derive_key( $key, $group );

		if ( ! $force && isset( $this->cache[ $derived ] ) ) {
			$found = true;
			$this->cache_hits++;
			return is_object( $this->cache[ $derived ] ) ? clone $this->cache[ $derived ] : $this->cache[ $derived ];
		}

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}

		$data = $this->mc->get( $derived );

		if ( Memcached::RES_NOTFOUND === $this->mc->getResultCode() ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}

		$found = true;
		$this->cache_hits++;
		$this->cache[ $derived ] = $data;
		return is_object( $data ) ? clone $data : $data;
	}

	public function delete( $key, $group = 'default' ) {
		$derived = $this->derive_key( $key, $group );
		unset( $this->cache[ $derived ] );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			return true;
		}

		return $this->mc->delete( $derived );
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		$derived = $this->derive_key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			if ( ! isset( $this->cache[ $derived ] ) ) {
				return false;
			}
			$this->cache[ $derived ] = $data;
			return true;
		}

		$result = $this->mc->replace( $derived, $data, $expire );
		if ( $result ) {
			$this->cache[ $derived ] = $data;
		}
		return $result;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$derived = $this->derive_key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			if ( ! isset( $this->cache[ $derived ] ) ) {
				return false;
			}
			$this->cache[ $derived ] = max( 0, (int) $this->cache[ $derived ] + $offset );
			return $this->cache[ $derived ];
		}

		$value = $this->mc->increment( $derived, $offset );
		if ( false !== $value ) {
			$this->cache[ $derived ] = $value;
		}
		return $value;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		$derived = $this->derive_key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			if ( ! isset( $this->cache[ $derived ] ) ) {
				return false;
			}
			$this->cache[ $derived ] = max( 0, (int) $this->cache[ $derived ] - $offset );
			return $this->cache[ $derived ];
		}

		$value = $this->mc->decrement( $derived, $offset );
		if ( false !== $value ) {
			$this->cache[ $derived ] = $value;
		}
		return $value;
	}

	public function flush() {
		$this->cache = array();
		$this->group_versions = array();
		$ver_key = $this->key_prefix . 'prime_cache_global_ver';
		$new_ver = $this->mc->increment( $ver_key );
		if ( false === $new_ver ) {
			$this->mc->set( $ver_key, 1 );
			$new_ver = 1;
		}
		$this->global_version = $new_ver;
		return true;
	}

	public function flush_runtime() {
		$this->cache = array();
		return true;
	}

	public function flush_group( $group ) {
		// Increment the group version so all existing keys become unreachable.
		$version_key = $this->key_prefix . 'prime_cache_gv:' . $group;
		$new_version = $this->mc->increment( $version_key );
		if ( false === $new_version ) {
			// Key doesn't exist yet — create with add() then increment.
			$this->mc->add( $version_key, 0 );
			$new_version = $this->mc->increment( $version_key );
			if ( false === $new_version ) {
				$new_version = 1;
				$this->mc->set( $version_key, $new_version );
			}
		}
		$this->group_versions[ $group ] = $new_version;

		// Clear local in-memory cache for this group.
		foreach ( array_keys( $this->cache ) as $cached_key ) {
			if ( false !== strpos( $cached_key, $group . ':' ) ) {
				unset( $this->cache[ $cached_key ] );
			}
		}
		return true;
	}

	public function close() {
		if ( $this->mc ) {
			$this->mc->quit();
		}
		return true;
	}

	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = $this->get( $key, $group, $force );
		}
		return $result;
	}

	public function set_multiple( $data, $group = 'default', $expire = 0 ) {
		$result = array();
		foreach ( $data as $key => $value ) {
			$result[ $key ] = $this->set( $key, $value, $group, $expire );
		}
		return $result;
	}

	public function add_multiple( $data, $group = 'default', $expire = 0 ) {
		$result = array();
		foreach ( $data as $key => $value ) {
			$result[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $result;
	}

	public function delete_multiple( $keys, $group = 'default' ) {
		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = $this->delete( $key, $group );
		}
		return $result;
	}

	public function add_global_groups( $groups ) {
		$groups = (array) $groups;
		$this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
	}

	public function add_non_persistent_groups( $groups ) {
		$groups = (array) $groups;
		$this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, $groups ) );
	}

	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = is_multisite() ? (int) $blog_id . ':' : '';
	}

	public function stats() {
		$total = $this->cache_hits + $this->cache_misses;
		$rate  = $total > 0 ? round( ( $this->cache_hits / $total ) * 100, 1 ) : 0;
		echo '<p><strong>Cache Hits:</strong> ' . esc_html( $this->cache_hits ) . '</p>';
		echo '<p><strong>Cache Misses:</strong> ' . esc_html( $this->cache_misses ) . '</p>';
		echo '<p><strong>Hit Rate:</strong> ' . esc_html( $rate ) . '%</p>';
	}
}
