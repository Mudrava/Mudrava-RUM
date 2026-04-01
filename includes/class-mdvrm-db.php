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
			url varchar(2048) NOT NULL,
			server_time float NOT NULL DEFAULT 0,
			ttfb float NOT NULL DEFAULT 0,
			lcp float NOT NULL DEFAULT 0,
			total_load float NOT NULL DEFAULT 0,
			memory_peak bigint(20) unsigned NOT NULL DEFAULT 0,
			device varchar(10) NOT NULL DEFAULT '',
			net varchar(20) NOT NULL DEFAULT '',
			country varchar(3) NOT NULL DEFAULT '',
			session_id varchar(64) NOT NULL DEFAULT '',
			user_role varchar(50) NOT NULL DEFAULT '',
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY event_time (event_time)
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
			error_log( 'Mudrava RUM: insert failed — ' . $wpdb->last_error );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i', MDVRM_TABLE ) );
		if ( $total <= $limit ) {
			return;
		}

		$offset = $total - $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY event_time ASC LIMIT %d', MDVRM_TABLE, $offset ) );
		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( MDVRM_TABLE ), $ids ) ) );
		}
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

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_time < %s', MDVRM_TABLE, $cutoff ) );
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
		$order_by = isset( $args['order_by'] ) ? sanitize_text_field( $args['order_by'] ) : 'event_time';
		$order    = ( isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ) ? 'ASC' : 'DESC';

		$allowed_order = array( 'event_time', 'ttfb', 'lcp', 'total_load', 'server_time' );
		if ( ! in_array( $order_by, $allowed_order, true ) ) {
			$order_by = 'event_time';
		}

		$where_clauses = array();
		$params        = array();

		// Filters.
		if ( ! empty( $args['session_id'] ) ) {
			$where_clauses[] = 'session_id = %s';
			$params[]        = sanitize_text_field( $args['session_id'] );
		}
		if ( ! empty( $args['url'] ) ) {
			$where_clauses[] = 'url LIKE %s';
			$params[]        = '%' . $wpdb->esc_like( sanitize_text_field( $args['url'] ) ) . '%';
		}
		if ( ! empty( $args['device'] ) ) {
			$where_clauses[] = 'device = %s';
			$params[]        = sanitize_text_field( $args['device'] );
		}
		if ( ! empty( $args['net'] ) ) {
			$where_clauses[] = 'net = %s';
			$params[]        = sanitize_text_field( $args['net'] );
		}

		$where = '';
		if ( ! empty( $where_clauses ) ) {
			$where = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		$total_sql    = 'SELECT COUNT(id) FROM %i ' . $where;
		$total_params = array_merge( array( MDVRM_TABLE ), $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $total_params ) );

		$data_sql    = 'SELECT id, event_time, url, server_time, ttfb, lcp, total_load, memory_peak, device, net, country, session_id, user_role FROM %i ' . $where . " ORDER BY %i {$order} LIMIT %d OFFSET %d";
		$data_params = array_merge( array( MDVRM_TABLE ), $params, array( $order_by, $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$data = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A );

		return array(
			'data'  => $data,
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

		$where_clauses = array();
		$params        = array();

		if ( ! empty( $args['session_id'] ) ) {
			$where_clauses[] = 'session_id = %s';
			$params[]        = sanitize_text_field( $args['session_id'] );
		}
		if ( ! empty( $args['url'] ) ) {
			$where_clauses[] = 'url LIKE %s';
			$params[]        = '%' . $wpdb->esc_like( sanitize_text_field( $args['url'] ) ) . '%';
		}
		if ( ! empty( $args['device'] ) ) {
			$where_clauses[] = 'device = %s';
			$params[]        = sanitize_text_field( $args['device'] );
		}
		if ( ! empty( $args['net'] ) ) {
			$where_clauses[] = 'net = %s';
			$params[]        = sanitize_text_field( $args['net'] );
		}

		$where = '';
		if ( ! empty( $where_clauses ) ) {
			$where = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		// Averages and counts.
		$sql = "SELECT 
			COUNT(id) as count,
			AVG(NULLIF(ttfb, 0)) as avg_ttfb,
			AVG(NULLIF(lcp, 0)) as avg_lcp,
			AVG(NULLIF(server_time, 0)) as avg_server,
			AVG(NULLIF(total_load, 0)) as avg_load
			FROM %i {$where}";

		$all_params = array_merge( array( MDVRM_TABLE ), $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats = $wpdb->get_row( $wpdb->prepare( $sql, $all_params ), ARRAY_A );

		// P75 LCP via OFFSET (MySQL 5.7+ / MariaDB compatible).
		$p75_lcp = 0;
		if ( $stats['count'] > 0 ) {
			$offset_p75 = floor( $stats['count'] * 0.75 );
			$p75_sql    = "SELECT lcp FROM %i {$where} ORDER BY lcp ASC LIMIT 1 OFFSET %d";
			$p75_params = array_merge( array( MDVRM_TABLE ), $params, array( $offset_p75 ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$p75_lcp = $wpdb->get_var( $wpdb->prepare( $p75_sql, $p75_params ) );
		}

		$stats['p75_lcp'] = $p75_lcp ? round( floatval( $p75_lcp ), 3 ) : 0;

		// Formatting.
		$stats['avg_ttfb']   = round( floatval( $stats['avg_ttfb'] ), 3 );
		$stats['avg_lcp']    = round( floatval( $stats['avg_lcp'] ), 3 );
		$stats['avg_server'] = round( floatval( $stats['avg_server'] ), 3 );
		$stats['avg_load']   = round( floatval( $stats['avg_load'] ), 3 );

		// Top 5 Slowest URLs by LCP.
		$slowest_lcp_sql = "SELECT url, AVG(lcp) as avg_lcp, COUNT(id) as count FROM %i {$where} GROUP BY url HAVING count >= 2 ORDER BY avg_lcp DESC LIMIT 5";
		$lcp_params      = array_merge( array( MDVRM_TABLE ), $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats['slowest_lcp'] = $wpdb->get_results( $wpdb->prepare( $slowest_lcp_sql, $lcp_params ), ARRAY_A );

		// Top 5 Heavy Server Generation.
		$slowest_srv_sql = "SELECT url, AVG(server_time) as avg_srv, COUNT(id) as count FROM %i {$where} GROUP BY url HAVING count >= 2 ORDER BY avg_srv DESC LIMIT 5";
		$srv_params      = array_merge( array( MDVRM_TABLE ), $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$stats['slowest_srv'] = $wpdb->get_results( $wpdb->prepare( $slowest_srv_sql, $srv_params ), ARRAY_A );

		return $stats;
	}

	/**
	 * Sanitize payload for insert.
	 *
	 * @param array $row Row data.
	 * @return array
	 */
	protected static function sanitize_row( array $row ): array {
		$sanitized = array(
			'event_time'  => isset( $row['event_time'] ) ? sanitize_text_field( $row['event_time'] ) : current_time( 'mysql', true ),
			'url'         => isset( $row['url'] ) ? sanitize_text_field( $row['url'] ) : '',
			'server_time' => isset( $row['server_time'] ) ? floatval( $row['server_time'] ) : 0,
			'ttfb'        => isset( $row['ttfb'] ) ? floatval( $row['ttfb'] ) : 0,
			'lcp'         => isset( $row['lcp'] ) ? floatval( $row['lcp'] ) : 0,
			'total_load'  => isset( $row['total_load'] ) ? floatval( $row['total_load'] ) : 0,
			'memory_peak' => isset( $row['memory_peak'] ) ? absint( $row['memory_peak'] ) : 0,
			'device'      => isset( $row['device'] ) ? sanitize_text_field( $row['device'] ) : '',
			'net'         => isset( $row['net'] ) ? sanitize_text_field( $row['net'] ) : '',
			'country'     => isset( $row['country'] ) ? sanitize_text_field( $row['country'] ) : '',
			'session_id'  => isset( $row['session_id'] ) ? sanitize_text_field( $row['session_id'] ) : '',
			'user_role'   => isset( $row['user_role'] ) ? sanitize_text_field( $row['user_role'] ) : '',
			'meta'        => isset( $row['meta'] ) ? wp_json_encode( $row['meta'] ) : '{}',
		);

		return $sanitized;
	}
}
