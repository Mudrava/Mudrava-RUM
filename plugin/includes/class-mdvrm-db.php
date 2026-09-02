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

		return $wpdb->insert_id;
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
		$total = (int) $wpdb->get_var( 'SELECT COUNT(id) FROM ' . MDVRM_TABLE );
		if ( $total <= $limit ) {
			return;
		}

		$excess = $total - $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a plugin constant, safe to inline.
				'DELETE FROM ' . MDVRM_TABLE . ' ORDER BY event_time ASC LIMIT %d',
				$excess
			)
		);
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
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a plugin constant, safe to inline.
				'DELETE FROM ' . MDVRM_TABLE . ' WHERE event_time < %s',
				$cutoff
			)
		);
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
			$params[]        = substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $args['session_id'] ), 0, 64 ) . '%';
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

		$total_sql    = 'SELECT COUNT(id) FROM ' . MDVRM_TABLE . ' ' . $where;
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

		list( $where, $params ) = self::build_filters( $args );

		$sql = 'SELECT
			COUNT(id) as count,
			AVG(NULLIF(ttfb, 0)) as avg_ttfb,
			AVG(NULLIF(lcp, 0)) as avg_lcp,
			AVG(NULLIF(server_time, 0)) as avg_server,
			AVG(NULLIF(total_load, 0)) as avg_load
			FROM ' . MDVRM_TABLE . ' ' . $where;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$stats = is_array( $stats ) ? $stats : array();
		$count = isset( $stats['count'] ) ? (int) $stats['count'] : 0;

		$p75_lcp = 0;
		if ( $count > 0 ) {
			$offset_p75 = (int) floor( $count * 0.75 );
			$p75_where  = $where ? $where . ' AND lcp > 0' : 'WHERE lcp > 0';
			$p75_sql    = 'SELECT lcp FROM ' . MDVRM_TABLE . ' ' . $p75_where . ' ORDER BY lcp ASC LIMIT 1 OFFSET %d';
			$p75_params = array_merge( $params, array( $offset_p75 ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$p75_lcp = $wpdb->get_var( $wpdb->prepare( $p75_sql, $p75_params ) );
		}

		$stats['count']      = $count;
		$stats['p75_lcp']    = $p75_lcp ? round( floatval( $p75_lcp ), 3 ) : 0;
		$stats['avg_ttfb']   = round( floatval( $stats['avg_ttfb'] ?? 0 ), 3 );
		$stats['avg_lcp']    = round( floatval( $stats['avg_lcp'] ?? 0 ), 3 );
		$stats['avg_server'] = round( floatval( $stats['avg_server'] ?? 0 ), 3 );
		$stats['avg_load']   = round( floatval( $stats['avg_load'] ?? 0 ), 3 );

		$slowest_lcp_sql = 'SELECT url, AVG(NULLIF(lcp, 0)) as avg_lcp, COUNT(id) as count FROM ' . MDVRM_TABLE . ' ' . $where . ' GROUP BY url HAVING count >= 2 ORDER BY avg_lcp DESC LIMIT 5';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats['slowest_lcp'] = $wpdb->get_results( $wpdb->prepare( $slowest_lcp_sql, $params ), ARRAY_A );
		$stats['slowest_lcp'] = $stats['slowest_lcp'] ? $stats['slowest_lcp'] : array();

		$slowest_srv_sql = 'SELECT url, AVG(NULLIF(server_time, 0)) as avg_srv, COUNT(id) as count FROM ' . MDVRM_TABLE . ' ' . $where . ' GROUP BY url HAVING count >= 2 ORDER BY avg_srv DESC LIMIT 5';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats['slowest_srv'] = $wpdb->get_results( $wpdb->prepare( $slowest_srv_sql, $params ), ARRAY_A );
		$stats['slowest_srv'] = $stats['slowest_srv'] ? $stats['slowest_srv'] : array();

		$device_sql = 'SELECT device, COUNT(id) as count FROM ' . MDVRM_TABLE . ' ' . $where . ' GROUP BY device ORDER BY count DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats['devices'] = $wpdb->get_results( $wpdb->prepare( $device_sql, $params ), ARRAY_A );
		$stats['devices'] = $stats['devices'] ? $stats['devices'] : array();

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

		list( $where, $params ) = self::build_filters( $args );

		$sql = 'SELECT DATE(event_time) as day, COUNT(id) as count, AVG(NULLIF(ttfb, 0)) as avg_ttfb, AVG(NULLIF(lcp, 0)) as avg_lcp FROM ' . MDVRM_TABLE . ' ' . $where . ' GROUP BY day ORDER BY day ASC LIMIT 31';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['count']    = (int) $row['count'];
			$row['avg_ttfb'] = round( floatval( $row['avg_ttfb'] ), 3 );
			$row['avg_lcp']  = round( floatval( $row['avg_lcp'] ), 3 );
		}
		unset( $row );

		return $rows;
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
		$url = substr( $url, 0, 2048 );

		$event_time = isset( $row['event_time'] ) ? $row['event_time'] : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $event_time ) ) {
			$event_time = gmdate( 'Y-m-d H:i:s' );
		}

		$session_id = isset( $row['session_id'] ) ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $row['session_id'] ) : '';
		$session_id = substr( $session_id, 0, 64 );

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
			'user_role'   => isset( $row['user_role'] ) ? substr( sanitize_text_field( $row['user_role'] ), 0, 50 ) : '',
			'meta'        => wp_json_encode( (object) $meta ),
		);
	}
}
