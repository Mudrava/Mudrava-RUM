<?php
/**
 * Database helper for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database helper class for Mudrava RUM.
 */
class MDVRM_DB {

	/**
	 * Allowed device values.
	 *
	 * @var string[]
	 */
	const DEVICES = array( 'mobile', 'tablet', 'desktop' );

	/**
	 * Allowed network values.
	 *
	 * @var string[]
	 */
	const NETWORKS = array( 'slow-2g', '2g', '3g', '4g', 'offline' );

	/**
	 * Maximum accepted metric value in seconds.
	 */
	const MAX_SECONDS = 600.0;

	/**
	 * Maximum rows removed in one cleanup statement.
	 */
	const DELETE_BATCH = 1000;

	/**
	 * Create or update plugin table.
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = MDVRM_TABLE;

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_time datetime NOT NULL,
			url varchar(2048) NOT NULL DEFAULT '',
			server_time float NOT NULL DEFAULT 0,
			ttfb float NOT NULL DEFAULT 0,
			lcp float NOT NULL DEFAULT 0,
			total_load float NOT NULL DEFAULT 0,
			memory_peak bigint(20) unsigned NOT NULL DEFAULT 0,
			device varchar(10) NOT NULL DEFAULT '',
			net varchar(20) NOT NULL DEFAULT '',
			country varchar(2) NOT NULL DEFAULT '',
			session_id varchar(64) NOT NULL DEFAULT '',
			user_role varchar(50) NOT NULL DEFAULT '',
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY event_time (event_time),
			KEY ttfb (ttfb),
			KEY lcp (lcp),
			KEY total_load (total_load),
			KEY server_time (server_time),
			KEY session_id (session_id),
			KEY device (device),
			KEY net (net)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert new log entry.
	 *
	 * @param array $row Data payload.
	 * @return int|false
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$row = self::sanitize_row( $row );

		/**
		 * Filter log data before database insertion.
		 *
		 * @param array $row Sanitized row data.
		 */
		$row = apply_filters( 'mdvrm_before_insert', $row );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			MDVRM_TABLE,
			$row,
			array(
				'%s', // event_time.
				'%s', // url.
				'%f', // server_time.
				'%f', // ttfb.
				'%f', // lcp.
				'%f', // total_load.
				'%d', // memory_peak.
				'%s', // device.
				'%s', // net.
				'%s', // country.
				'%s', // session_id.
				'%s', // user_role.
				'%s', // meta.
			)
		);

		if ( $wpdb->last_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Mudrava RUM: log insert failed.' );
			return false;
		}

		$insert_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a tracked event has been stored.
		 *
		 * @param int   $insert_id Inserted log row ID.
		 * @param array $row       Normalized event data.
		 */
		do_action( 'mdvrm_log_inserted', $insert_id, $row );

		return $insert_id;
	}

	/**
	 * Remove rows beyond limit using FIFO policy.
	 *
	 * @param int $limit Maximum number of rows.
	 */
	public static function enforce_limit( int $limit ): void {
		global $wpdb;

		$limit = absint( $limit );
		if ( $limit < 1 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MDVRM_TABLE );
		if ( $total <= $limit ) {
			return;
		}

		$excess  = $total - $limit;
		$deleted = 0;
		while ( $deleted < $excess ) {
			$batch = min( self::DELETE_BATCH, $excess - $deleted );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a plugin constant, safe to inline.
					'DELETE FROM ' . MDVRM_TABLE . ' ORDER BY event_time ASC, id ASC LIMIT %d',
					$batch
				)
			);
			if ( $affected < 1 ) {
				break;
			}
			$deleted += (int) $affected;
		}
		self::bump_stats_cache();
	}

	/**
	 * Delete rows older than N days.
	 *
	 * @param int $days Days to keep.
	 */
	public static function purge_older_than( int $days ): void {
		global $wpdb;

		$days = absint( $days );
		if ( $days < 1 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$total  = 0;
		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a plugin constant, safe to inline.
					'DELETE FROM ' . MDVRM_TABLE . ' WHERE event_time < %s ORDER BY event_time ASC, id ASC LIMIT %d',
					array( $cutoff, self::DELETE_BATCH )
				)
			);
			if ( $affected < 1 ) {
				break;
			}
			$total += (int) $affected;
			if ( $affected < self::DELETE_BATCH ) {
				break;
			}
		}

		if ( $total ) {
			self::bump_stats_cache();
		}
	}

	/**
	 * Invalidate the short-lived dashboard stats cache.
	 */
	public static function bump_stats_cache(): void {
		$version = (int) get_option( 'mdvrm_stats_cache_version', 0 );
		update_option( 'mdvrm_stats_cache_version', $version + 1, false );
	}

	/**
	 * Build a cache key for aggregated dashboard queries.
	 *
	 * @param string $prefix Cache namespace.
	 * @param array  $args   Query filters.
	 * @return string
	 */
	protected static function build_cache_key( string $prefix, array $args ): string {
		$args = array_intersect_key(
			$args,
			array_fill_keys( array( 'days', 'session_id', 'url', 'device', 'net' ), '' )
		);
		ksort( $args );

		return 'mdvrm_' . $prefix . '_' . md5( wp_json_encode( $args ) );
	}

	/**
	 * Current dashboard cache epoch.
	 *
	 * @return int
	 */
	protected static function cache_version(): int {
		return (int) get_option( 'mdvrm_stats_cache_version', 0 );
	}

	/**
	 * Build shared WHERE clause and params from filter args.
	 *
	 * @param array $args Filter args.
	 * @return array{0: string, 1: array}
	 */
	protected static function build_filters( array $args ): array {
		global $wpdb;

		$where_clauses = array();
		$params        = array();

		if ( ! empty( $args['session_id'] ) ) {
			$where_clauses[] = 'session_id LIKE %s';
			$params[]        = mb_substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $args['session_id'] ), 0, 64 ) . '%';
		}
		if ( ! empty( $args['url'] ) ) {
			$where_clauses[] = 'url LIKE %s';
			$params[]        = '%' . $wpdb->esc_like( sanitize_text_field( $args['url'] ) ) . '%';
		}
		if ( ! empty( $args['device'] ) && in_array( $args['device'], self::DEVICES, true ) ) {
			$where_clauses[] = 'device = %s';
			$params[]        = $args['device'];
		}
		if ( ! empty( $args['net'] ) && in_array( $args['net'], self::NETWORKS, true ) ) {
			$where_clauses[] = 'net = %s';
			$params[]        = $args['net'];
		}
		if ( ! empty( $args['days'] ) ) {
			$days            = min( 365, absint( $args['days'] ) );
			$where_clauses[] = 'event_time >= %s';
			$params[]        = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		}

		$where = '';
		if ( $where_clauses ) {
			$where = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		return array( $where, $params );
	}

	/**
	 * Fetch logs with pagination and optional filters.
	 *
	 * @param array $args Query args.
	 * @return array{data: array, total: int}
	 */
	public static function query_logs( array $args ): array {
		global $wpdb;

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 200, absint( $args['per_page'] ?? 50 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$order_by = isset( $args['order_by'] ) ? $args['order_by'] : 'event_time';
		$order    = ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		$allowed_order = array( 'event_time', 'ttfb', 'lcp', 'total_load', 'server_time' );
		if ( ! in_array( $order_by, $allowed_order, true ) ) {
			$order_by = 'event_time';
		}

		list( $where, $params ) = self::build_filters( $args );

		$total_sql    = 'SELECT COUNT(*) FROM ' . MDVRM_TABLE . ' ' . $where;
		$total_params = $params;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $total_params ) );

		$data_sql    = 'SELECT id, event_time, url, server_time, ttfb, lcp, total_load, memory_peak, device, net, country, session_id, user_role FROM ' . MDVRM_TABLE . ' ' . $where . ' ORDER BY ' . $order_by . ' ' . $order . ' LIMIT %d OFFSET %d';
		$data_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$data = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A );

		return array(
			'data'  => $data ? $data : array(),
			'total' => $total,
		);
	}

	/**
	 * Get aggregate statistics.
	 *
	 * @param array $args Filter args.
	 * @return array
	 */
	public static function get_stats( array $args ): array {
		global $wpdb;

		$cache_key = self::build_cache_key( 'stats', $args );
		$version   = self::cache_version();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['data'] ) && $cached['version'] === $version && is_array( $cached['data'] ) ) {
			return $cached['data'];
		}

		list( $where, $params ) = self::build_filters( $args );

		$max = self::MAX_SECONDS;

		$sql        = 'SELECT
			COUNT(*) as count,
			AVG(CASE WHEN ttfb > 0 AND ttfb <= %f THEN ttfb ELSE NULL END) as avg_ttfb,
			AVG(CASE WHEN lcp > 0 AND lcp <= %f THEN lcp ELSE NULL END) as avg_lcp,
			AVG(CASE WHEN server_time > 0 AND server_time <= %f THEN server_time ELSE NULL END) as avg_server,
			AVG(CASE WHEN total_load > 0 AND total_load <= %f THEN total_load ELSE NULL END) as avg_load
			FROM ' . MDVRM_TABLE . ' ' . $where;
		$sql_params = array_merge( array( $max, $max, $max, $max ), $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats = $wpdb->get_row( $wpdb->prepare( $sql, $sql_params ), ARRAY_A );

		$stats = is_array( $stats ) ? $stats : array();
		$count = isset( $stats['count'] ) ? (int) $stats['count'] : 0;

		$p75_lcp = null;
		if ( $count > 0 ) {
			$p75_condition = 'lcp > 0 AND lcp <= %f';
			$p75_where     = $where ? $where . ' AND ' . $p75_condition : 'WHERE ' . $p75_condition;
			$p75_count_sql = 'SELECT COUNT(*) FROM ' . MDVRM_TABLE . ' ' . $p75_where;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$p75_count = $wpdb->get_var( $wpdb->prepare( $p75_count_sql, array_merge( $params, array( $max ) ) ) );
			$p75_count = $p75_count ? (int) $p75_count : 0;

			if ( $p75_count > 0 ) {
				$offset_p75 = (int) floor( $p75_count * 0.75 );
				$p75_sql    = 'SELECT lcp FROM ' . MDVRM_TABLE . ' ' . $p75_where . ' ORDER BY lcp ASC LIMIT 1 OFFSET %d';
				$p75_params = array_merge( $params, array( $max, $offset_p75 ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$p75_lcp = $wpdb->get_var( $wpdb->prepare( $p75_sql, $p75_params ) );
			}
		}

		$round_metric        = static function ( $value ) {
			return null === $value ? null : round( floatval( $value ), 3 );
		};
		$stats['count']      = $count;
		$stats['p75_lcp']    = $round_metric( $p75_lcp );
		$stats['avg_ttfb']   = $round_metric( $stats['avg_ttfb'] ?? null );
		$stats['avg_lcp']    = $round_metric( $stats['avg_lcp'] ?? null );
		$stats['avg_server'] = $round_metric( $stats['avg_server'] ?? null );
		$stats['avg_load']   = $round_metric( $stats['avg_load'] ?? null );

		$slowest_lcp_sql    = 'SELECT MIN(url) AS url,
			AVG(CASE WHEN lcp > 0 AND lcp <= %f THEN lcp ELSE NULL END) as avg_lcp,
			AVG(CASE WHEN ttfb > 0 AND ttfb <= %f THEN ttfb ELSE NULL END) as avg_ttfb,
			COUNT(*) as count
			FROM ' . MDVRM_TABLE . ' ' . $where . ' GROUP BY MD5(url) HAVING count >= 2 ORDER BY avg_lcp DESC LIMIT 5';
		$slowest_lcp_params = array_merge( array( $max, $max ), $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats['slowest_lcp'] = $wpdb->get_results( $wpdb->prepare( $slowest_lcp_sql, $slowest_lcp_params ), ARRAY_A );
		$stats['slowest_lcp'] = $stats['slowest_lcp'] ? $stats['slowest_lcp'] : array();

		$slowest_srv_sql    = 'SELECT MIN(url) AS url,
			AVG(CASE WHEN server_time > 0 AND server_time <= %f THEN server_time ELSE NULL END) as avg_srv,
			COUNT(*) as count
			FROM ' . MDVRM_TABLE . ' ' . $where . ' GROUP BY MD5(url) HAVING count >= 2 ORDER BY avg_srv DESC LIMIT 5';
		$slowest_srv_params = array_merge( array( $max ), $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats['slowest_srv'] = $wpdb->get_results( $wpdb->prepare( $slowest_srv_sql, $slowest_srv_params ), ARRAY_A );
		$stats['slowest_srv'] = $stats['slowest_srv'] ? $stats['slowest_srv'] : array();

		$device_sql = 'SELECT device, COUNT(*) as count FROM ' . MDVRM_TABLE . ' ' . $where . ' GROUP BY device ORDER BY count DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats['devices'] = $wpdb->get_results( $wpdb->prepare( $device_sql, $params ), ARRAY_A );
		$stats['devices'] = $stats['devices'] ? $stats['devices'] : array();

		set_transient(
			$cache_key,
			array(
				'version' => $version,
				'data'    => $stats,
			),
			MINUTE_IN_SECONDS
		);

		return $stats;
	}

	/**
	 * Daily aggregates for trend chart.
	 *
	 * @param array $args Filter args (days, device, net...).
	 * @return array List of {date, count, avg_ttfb, avg_lcp, p95_load}.
	 */
	public static function get_trend( array $args ): array {
		global $wpdb;

		$cache_key = self::build_cache_key( 'trend', $args );
		$version   = self::cache_version();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['data'] ) && $cached['version'] === $version && is_array( $cached['data'] ) ) {
			return $cached['data'];
		}

		list( $where, $params ) = self::build_filters( $args );

		$sql         = 'SELECT event_time, ttfb, lcp, total_load FROM ' . MDVRM_TABLE . ' ' . $where . ' ORDER BY event_time DESC LIMIT %d';
		$data_params = array_merge( $params, array( 10000 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$raw = $wpdb->get_results( $wpdb->prepare( $sql, $data_params ), ARRAY_A );

		if ( ! $raw ) {
			return array();
		}

		$raw = array_reverse( $raw );

		$max     = self::MAX_SECONDS;
		$buckets = array();
		foreach ( $raw as $row ) {
			$day = self::local_day( $row['event_time'] );
			if ( '' === $day ) {
				continue;
			}

			if ( ! isset( $buckets[ $day ] ) ) {
				$buckets[ $day ] = array(
					'count'      => 0,
					'ttfb_sum'   => 0.0,
					'ttfb_count' => 0,
					'lcp_sum'    => 0.0,
					'lcp_count'  => 0,
					'loads'      => array(),
				);
			}

			$buckets[ $day ]['count'] += 1;

			$ttfb = floatval( $row['ttfb'] );
			if ( $ttfb > 0 && $ttfb <= $max ) {
				$buckets[ $day ]['ttfb_sum']   += $ttfb;
				$buckets[ $day ]['ttfb_count'] += 1;
			}
			$lcp = floatval( $row['lcp'] );
			if ( $lcp > 0 && $lcp <= $max ) {
				$buckets[ $day ]['lcp_sum']   += $lcp;
				$buckets[ $day ]['lcp_count'] += 1;
			}
			$load = floatval( $row['total_load'] );
			if ( $load > 0 && $load <= $max ) {
				$buckets[ $day ]['loads'][] = $load;
			}
		}

		ksort( $buckets );

		$out = array();
		foreach ( $buckets as $day => $bucket ) {
			$count = (int) $bucket['count'];
			if ( ! $count ) {
				continue;
			}
			sort( $bucket['loads'] );

			$out[] = array(
				'date'     => $day,
				'count'    => $count,
				'avg_ttfb' => $bucket['ttfb_count'] > 0 ? round( $bucket['ttfb_sum'] / $bucket['ttfb_count'], 3 ) : null,
				'avg_lcp'  => $bucket['lcp_count'] > 0 ? round( $bucket['lcp_sum'] / $bucket['lcp_count'], 3 ) : null,
				'p95_load' => $bucket['loads'] ? self::percentile( $bucket['loads'], 0.95 ) : null,
			);
		}

		set_transient(
			$cache_key,
			array(
				'version' => $version,
				'data'    => $out,
			),
			MINUTE_IN_SECONDS
		);

		return $out;
	}

	/**
	 * Map a UTC datetime string to a site-local calendar date.
	 *
	 * @param string $event_time UTC DATETIME string.
	 * @return string Local Y-m-d, or '' on invalid input.
	 */
	protected static function local_day( string $event_time ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $event_time, $parts ) ) {
			return '';
		}

		$timestamp = gmmktime(
			(int) $parts[4],
			(int) $parts[5],
			(int) $parts[6],
			(int) $parts[2],
			(int) $parts[3],
			(int) $parts[1]
		);
		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d', $timestamp + self::timezone_offset( $timestamp ) );
	}

	/**
	 * Resolve the site-timezone offset for a UTC timestamp.
	 *
	 * @param int $timestamp UTC Unix timestamp.
	 * @return int Offset in seconds.
	 */
	protected static function timezone_offset( int $timestamp ): int {
		static $cache = array();

		$timezone = wp_timezone();
		$name     = $timezone->getName();

		if ( isset( $cache[ $name ] ) ) {
			return self::offset_at( $cache[ $name ], $timestamp );
		}

		$transitions = $timezone->getTransitions();
		if ( is_array( $transitions ) && $transitions ) {
			$cache[ $name ] = array( 'transitions' => $transitions );
			return self::offset_at( $cache[ $name ], $timestamp );
		}

		$offset         = $timezone->getOffset( new DateTimeImmutable( '@' . $timestamp ) );
		$cache[ $name ] = array( 'offset' => $offset );

		return $offset;
	}

	/**
	 * Find the active timezone offset from transition boundaries.
	 *
	 * @param array $timezone_data Timezone transitions or cached offsets.
	 * @param int   $timestamp     UTC Unix timestamp.
	 * @return int Offset in seconds.
	 */
	protected static function offset_at( array $timezone_data, int $timestamp ): int {
		if ( isset( $timezone_data['offset'] ) ) {
			return (int) $timezone_data['offset'];
		}

		$transitions = isset( $timezone_data['transitions'] ) ? $timezone_data['transitions'] : $timezone_data;
		$offset      = 0;
		foreach ( $transitions as $transition ) {
			if ( isset( $transition['ts'] ) && $transition['ts'] <= $timestamp ) {
				$offset = (int) $transition['offset'];
			} else {
				break;
			}
		}

		return $offset;
	}

	/**
	 * Linear-interpolated percentile of a sorted numeric array.
	 *
	 * @param array $values Sorted values.
	 * @param float $q      Quantile 0..1.
	 * @return float
	 */
	protected static function percentile( array $values, float $q ): float {
		$count = count( $values );
		if ( ! $count ) {
			return 0.0;
		}
		if ( 1 === $count ) {
			return floatval( $values[0] );
		}

		$pos  = ( $count - 1 ) * max( 0.0, min( 1.0, $q ) );
		$low  = (int) floor( $pos );
		$high = (int) ceil( $pos );
		if ( $low === $high ) {
			return floatval( $values[ $low ] );
		}

		$frac = $pos - $low;
		return (float) ( $values[ $low ] + ( $values[ $high ] - $values[ $low ] ) * $frac );
	}

	/**
	 * Sanitize payload for insert.
	 *
	 * @param array $row Row data.
	 * @return array
	 */
	protected static function sanitize_row( array $row ): array {
		$clamp_metric = static function ( $value ) {
			$value = floatval( $value );
			if ( $value < 0 || $value > self::MAX_SECONDS ) {
				return 0.0;
			}
			return round( $value, 4 );
		};

		$device  = isset( $row['device'] ) ? sanitize_text_field( $row['device'] ) : '';
		$net     = isset( $row['net'] ) ? sanitize_text_field( $row['net'] ) : '';
		$country = isset( $row['country'] ) ? strtoupper( sanitize_text_field( $row['country'] ) ) : '';

		$url = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = '';
		}
		$url = mb_substr( $url, 0, 2048 );

		$event_time = isset( $row['event_time'] ) ? $row['event_time'] : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $event_time ) ) {
			$event_time = gmdate( 'Y-m-d H:i:s' );
		}

		$session_id = isset( $row['session_id'] ) ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $row['session_id'] ) : '';
		$session_id = mb_substr( $session_id, 0, 64 );

		$meta = array();
		if ( isset( $row['meta'] ) ) {
			$meta = is_array( $row['meta'] ) ? $row['meta'] : array();
		}
		$meta = array_slice( $meta, 0, 10, true );
		$meta = array_map( 'sanitize_text_field', $meta );

		return array(
			'event_time'  => $event_time,
			'url'         => $url,
			'server_time' => $clamp_metric( $row['server_time'] ?? 0 ),
			'ttfb'        => $clamp_metric( $row['ttfb'] ?? 0 ),
			'lcp'         => $clamp_metric( $row['lcp'] ?? 0 ),
			'total_load'  => $clamp_metric( $row['total_load'] ?? 0 ),
			'memory_peak' => isset( $row['memory_peak'] ) ? min( PHP_INT_MAX, absint( $row['memory_peak'] ) ) : 0,
			'device'      => in_array( $device, self::DEVICES, true ) ? $device : '',
			'net'         => in_array( $net, self::NETWORKS, true ) ? $net : '',
			'country'     => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'session_id'  => $session_id,
			'user_role'   => isset( $row['user_role'] ) ? mb_substr( sanitize_text_field( $row['user_role'] ), 0, 50 ) : '',
			'meta'        => wp_json_encode( (object) $meta ),
		);
	}
}
