<?php
/**
 * Prime Cache - Redis Object Cache Backend
 *
 * Requires: PhpRedis extension (Redis class)
 *
 * Supported constants for configuration:
 *   WP_REDIS_HOST       (default: '127.0.0.1')
 *   WP_REDIS_PORT       (default: 6379)
 *   WP_REDIS_PASSWORD   (default: '')
 *   WP_REDIS_DATABASE   (default: 0)
 *   WP_REDIS_TIMEOUT    (default: 1)
 *   WP_REDIS_PREFIX     (default: WP_CACHE_KEY_SALT or '')
 *   WP_REDIS_MAXTTL     (default: 0, unlimited)
 */

if ( ! class_exists( 'Redis' ) ) :
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

	private $redis;
	private $connected = false;
	private $cache = array();
	private $global_groups = array();
	private $non_persistent_groups = array();
	private $blog_prefix;
	private $key_prefix;
	private $max_ttl;

	public $cache_hits = 0;
	public $cache_misses = 0;

	public function __construct() {
		global $blog_id;

		$this->blog_prefix = is_multisite() ? (int) $blog_id . ':' : '';
		$this->key_prefix  = defined( 'WP_REDIS_PREFIX' ) ? WP_REDIS_PREFIX : ( defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '' );
		$this->max_ttl     = defined( 'WP_REDIS_MAXTTL' ) ? (int) WP_REDIS_MAXTTL : 0;

		$this->connect();
	}

	private function connect() {
		$host     = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
		$port     = defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379;
		$timeout  = defined( 'WP_REDIS_TIMEOUT' ) ? (float) WP_REDIS_TIMEOUT : 1.0;
		$password = defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : '';
		$database = defined( 'WP_REDIS_DATABASE' ) ? (int) WP_REDIS_DATABASE : 0;

		try {
			$this->redis = new Redis();
			$this->connected = $this->redis->connect( $host, $port, $timeout );

			if ( $this->connected && '' !== $password ) {
				$this->connected = $this->redis->auth( $password );
			}

			if ( $this->connected && 0 !== $database ) {
				$this->connected = $this->redis->select( $database );
			}

			if ( $this->connected ) {
				$this->redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
			}
		} catch ( Exception $e ) {
			$this->connected = false;
		}
	}

	/** @var array Group version cache. */
	private $group_versions = array();
	private $global_version = null;

	private function derive_key( $key, $group = 'default' ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}
		$prefix = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix;
		$gv     = $this->get_global_version();
		$grpv   = $this->get_group_version( $group );
		return $this->key_prefix . $prefix . $gv . ':' . $group . ':' . $grpv . ':' . $key;
	}

	private function get_global_version() {
		if ( null !== $this->global_version ) {
			return $this->global_version;
		}
		$salt = defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '';
		try {
			$ver = $this->redis->get( $salt . 'prime_cache_global_ver' );
			$this->global_version = $ver ?: 0;
		} catch ( Exception $e ) {
			$this->global_version = 0;
		}
		return $this->global_version;
	}

	private function get_group_version( $group ) {
		if ( isset( $this->group_versions[ $group ] ) ) {
			return $this->group_versions[ $group ];
		}
		$salt = defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '';
		try {
			$ver = $this->redis->get( $salt . 'prime_cache_gv:' . $group );
			$this->group_versions[ $group ] = $ver ?: 0;
		} catch ( Exception $e ) {
			$this->group_versions[ $group ] = 0;
		}
		return $this->group_versions[ $group ];
	}

	private function enforce_ttl( $expire ) {
		$expire = (int) $expire;
		if ( $this->max_ttl > 0 && ( 0 === $expire || $expire > $this->max_ttl ) ) {
			$expire = $this->max_ttl;
		}
		return $expire;
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			$derived = $this->derive_key( $key, $group );
			if ( isset( $this->cache[ $derived ] ) ) {
				return false;
			}
			$this->cache[ $derived ] = $data;
			return true;
		}

		if ( ! $this->connected ) {
			return false;
		}

		$derived = $this->derive_key( $key, $group );
		if ( $this->redis->exists( $derived ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, $expire );
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$derived = $this->derive_key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			$this->cache[ $derived ] = $data;
			return true;
		}

		if ( ! $this->connected ) {
			return false;
		}

		$expire = $this->enforce_ttl( $expire );

		try {
			if ( $expire > 0 ) {
				$result = $this->redis->setex( $derived, $expire, $data );
			} else {
				$result = $this->redis->set( $derived, $data );
			}
		} catch ( Exception $e ) {
			return false;
		}

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

		if ( ! $this->connected ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}

		try {
			$data = $this->redis->get( $derived );
		} catch ( Exception $e ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}

		if ( false === $data ) {
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

		if ( ! $this->connected ) {
			return false;
		}

		try {
			return (bool) $this->redis->del( $derived );
		} catch ( Exception $e ) {
			return false;
		}
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->connected && ! in_array( $group, $this->non_persistent_groups, true ) ) {
			return false;
		}

		$derived = $this->derive_key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			if ( ! isset( $this->cache[ $derived ] ) ) {
				return false;
			}
		} else {
			if ( ! $this->redis->exists( $derived ) ) {
				return false;
			}
		}

		return $this->set( $key, $data, $group, $expire );
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

		if ( ! $this->connected ) {
			return false;
		}

		try {
			$value = $this->redis->incrBy( $derived, $offset );
			$this->cache[ $derived ] = $value;
			return $value;
		} catch ( Exception $e ) {
			return false;
		}
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

		if ( ! $this->connected ) {
			return false;
		}

		try {
			$value = $this->redis->decrBy( $derived, $offset );
			if ( $value < 0 ) {
				$value = 0;
				$this->redis->set( $derived, $value );
			}
			$this->cache[ $derived ] = $value;
			return $value;
		} catch ( Exception $e ) {
			return false;
		}
	}

	public function flush() {
		$this->cache = array();
		if ( ! $this->connected ) {
			return false;
		}

		// Increment global version instead of flushDb().
		try {
			$ver_key = ( defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '' ) . 'prime_cache_global_ver';
			$this->global_version = $this->redis->incr( $ver_key );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	public function flush_runtime() {
		$this->cache = array();
		return true;
	}

	public function flush_group( $group ) {
		if ( ! $this->connected ) {
			return false;
		}

		// Use group versioning instead of SCAN+DEL (O(1) vs O(n)).
		$salt = defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '';
		$ver_key = $salt . 'prime_cache_gv:' . $group;
		try {
			$this->group_versions[ $group ] = $this->redis->incr( $ver_key );
		} catch ( Exception $e ) {
			return false;
		}

		// Clear local runtime cache for this group.
		foreach ( array_keys( $this->cache ) as $cached_key ) {
			if ( false !== strpos( $cached_key, ':' . $group . ':' ) ) {
				unset( $this->cache[ $cached_key ] );
			}
		}

		return true;
	}

	public function close() {
		if ( $this->connected && $this->redis ) {
			try {
				$this->redis->close();
			} catch ( Exception $e ) {
				// Ignore close errors.
			}
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

	public function is_connected() {
		return $this->connected;
	}

	public function stats() {
		$total = $this->cache_hits + $this->cache_misses;
		$rate  = $total > 0 ? round( ( $this->cache_hits / $total ) * 100, 1 ) : 0;
		echo '<p><strong>Cache Hits:</strong> ' . esc_html( $this->cache_hits ) . '</p>';
		echo '<p><strong>Cache Misses:</strong> ' . esc_html( $this->cache_misses ) . '</p>';
		echo '<p><strong>Hit Rate:</strong> ' . esc_html( $rate ) . '%</p>';
		echo '<p><strong>Connected:</strong> ' . ( $this->connected ? 'Yes' : 'No' ) . '</p>';
	}
}
