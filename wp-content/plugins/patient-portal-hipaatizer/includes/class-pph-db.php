<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PPH_DB {
    public const DB_VERSION = '1.2.0';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'pph_submissions';
    }

    public static function contact_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'pph_contact_requests';
    }

    public static function save_contact_request( array $data ) {
        global $wpdb;
        return $wpdb->insert(
            self::contact_table(),
            array(
                'first_name' => (string) $data['first_name'],
                'last_name'  => (string) $data['last_name'],
                'email'      => (string) $data['email'],
                'phone'      => (string) $data['phone'],
                'service'    => (string) $data['service'],
                'submitted_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public static function get_contact_requests( int $limit = 100, array $filters = array() ): array {
        global $wpdb;
        $limit = max( 1, min( 500, $limit ) );
        $where = array( '1=1' );
        $values = array();

        $search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
            array_push( $values, $like, $like, $like, $like );
        }

        $service = isset( $filters['service'] ) ? trim( (string) $filters['service'] ) : '';
        if ( '' !== $service ) {
            $where[] = 'service = %s';
            $values[] = $service;
        }

        $date_from = isset( $filters['date_from'] ) ? (string) $filters['date_from'] : '';
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $where[] = 'submitted_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        $date_to = isset( $filters['date_to'] ) ? (string) $filters['date_to'] : '';
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $where[] = 'submitted_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $sql = 'SELECT * FROM ' . self::contact_table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
        $values[] = $limit;
        return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) ?: array();
    }

    public static function get_contact_request_services(): array {
        global $wpdb;
        return $wpdb->get_col( 'SELECT DISTINCT service FROM ' . self::contact_table() . " WHERE service <> '' ORDER BY service ASC" ) ?: array();
    }

    public static function delete_contact_request( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::contact_table(), array( 'id' => $id ), array( '%d' ) );
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            form_id varchar(191) NOT NULL,
            form_name varchar(191) NOT NULL DEFAULT '',
            external_submission_id varchar(191) NOT NULL,
            status varchar(40) NOT NULL DEFAULT 'submitted',
            event_name varchar(80) NOT NULL DEFAULT '',
            manual_override tinyint(1) NOT NULL DEFAULT 0,
            submitted_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            submission_url text NULL,
            pdf_url text NULL,
            csv_url text NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY form_submission (form_id, external_submission_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset};";

        dbDelta( $sql );

        $contact_table = self::contact_table();
        $contact_sql = "CREATE TABLE {$contact_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            first_name varchar(191) NOT NULL,
            last_name varchar(191) NOT NULL,
            email varchar(191) NOT NULL,
            phone varchar(80) NOT NULL,
            service varchar(191) NOT NULL,
            submitted_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY submitted_at (submitted_at),
            KEY email (email)
        ) {$charset};";
        dbDelta( $contact_sql );
        update_option( 'pph_db_version', self::DB_VERSION, false );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'pph_db_version' ) !== self::DB_VERSION ) {
            self::install();
        }
    }

    public static function upsert( array $data ) {
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql', true );

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %s AND external_submission_id = %s LIMIT 1",
                $data['form_id'],
                $data['external_submission_id']
            ),
            ARRAY_A
        );

        if ( $existing ) {
            $status = $data['status'];
            if ( ! empty( $existing['manual_override'] ) && empty( $data['force_status'] ) && 'voided' !== $status ) {
                $status = $existing['status'];
            }

            $updated = $wpdb->update(
                $table,
                array(
                    'user_id'      => (int) $data['user_id'],
                    'form_name'    => (string) $data['form_name'],
                    'status'       => $status,
                    'event_name'   => (string) $data['event_name'],
                    'submitted_at' => $data['submitted_at'],
                    'updated_at'    => $now,
                    'submission_url' => isset( $data['submission_url'] ) ? (string) $data['submission_url'] : (string) ( $existing['submission_url'] ?? '' ),
                    'pdf_url'       => isset( $data['pdf_url'] ) ? (string) $data['pdf_url'] : (string) ( $existing['pdf_url'] ?? '' ),
                    'csv_url'       => isset( $data['csv_url'] ) ? (string) $data['csv_url'] : (string) ( $existing['csv_url'] ?? '' ),
                ),
                array( 'id' => (int) $existing['id'] ),
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
            return false === $updated ? false : (int) $existing['id'];
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'user_id'               => (int) $data['user_id'],
                'form_id'               => (string) $data['form_id'],
                'form_name'             => (string) $data['form_name'],
                'external_submission_id'=> (string) $data['external_submission_id'],
                'status'                => (string) $data['status'],
                'event_name'            => (string) $data['event_name'],
                'manual_override'       => 0,
                'submitted_at'          => $data['submitted_at'],
                'created_at'            => $now,
                'updated_at'            => $now,
                'submission_url'        => isset( $data['submission_url'] ) ? (string) $data['submission_url'] : '',
                'pdf_url'               => isset( $data['pdf_url'] ) ? (string) $data['pdf_url'] : '',
                'csv_url'               => isset( $data['csv_url'] ) ? (string) $data['csv_url'] : '',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    public static function delete_submission( int $id ): bool {
        global $wpdb;
        if ( $id <= 0 ) {
            return false;
        }

        $deleted = $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
        return false !== $deleted && 0 < $deleted;
    }

    public static function for_user( int $user_id, int $limit = 100 ): array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY COALESCE(submitted_at, updated_at) DESC, id DESC LIMIT %d",
                $user_id,
                $limit
            ),
            ARRAY_A
        );
    }

    public static function latest_for_user_by_form( int $user_id ): array {
        $rows = self::for_user( $user_id, 200 );
        $out  = array();
        foreach ( $rows as $row ) {
            if ( ! isset( $out[ $row['form_id'] ] ) ) {
                $out[ $row['form_id'] ] = $row;
            }
        }
        return $out;
    }

    public static function filtered( array $filters = array(), int $per_page = 50, int $page = 1 ): array {
        global $wpdb;
        $table = self::table();
        $users = $wpdb->users;
        $where = array( '1=1' );
        $args  = array();

        if ( ! empty( $filters['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR s.form_name LIKE %s OR s.form_id LIKE %s OR s.external_submission_id LIKE %s)';
            array_push( $args, $like, $like, $like, $like, $like, $like );
        }

        if ( ! empty( $filters['user_id'] ) ) {
            $where[] = 's.user_id = %d';
            $args[] = (int) $filters['user_id'];
        }

        if ( ! empty( $filters['form_id'] ) ) {
            $where[] = 's.form_id = %s';
            $args[] = (string) $filters['form_id'];
        }

        if ( ! empty( $filters['status'] ) && isset( self::allowed_statuses()[ $filters['status'] ] ) ) {
            $where[] = 's.status = %s';
            $args[] = (string) $filters['status'];
        }

        if ( ! empty( $filters['date_from'] ) ) {
            $where[] = 'COALESCE(s.submitted_at, s.updated_at) >= %s';
            $args[] = (string) $filters['date_from'];
        }

        if ( ! empty( $filters['date_to'] ) ) {
            $where[] = 'COALESCE(s.submitted_at, s.updated_at) <= %s';
            $args[] = (string) $filters['date_to'];
        }

        $where_sql = implode( ' AND ', $where );
        $count_sql = "SELECT COUNT(*) FROM {$table} s LEFT JOIN {$users} u ON u.ID = s.user_id WHERE {$where_sql}";
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );

        $per_page = max( 10, min( 100, $per_page ) );
        $page     = max( 1, $page );
        $offset   = ( $page - 1 ) * $per_page;
        $sql = "SELECT s.*, u.display_name, u.user_email
                FROM {$table} s
                LEFT JOIN {$users} u ON u.ID = s.user_id
                WHERE {$where_sql}
                ORDER BY COALESCE(s.submitted_at, s.updated_at) DESC, s.id DESC
                LIMIT %d OFFSET %d";
        $query_args = array_merge( $args, array( $per_page, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A );

        return array(
            'rows'       => is_array( $rows ) ? $rows : array(),
            'total'      => $total,
            'per_page'   => $per_page,
            'page'       => $page,
            'total_pages'=> max( 1, (int) ceil( $total / $per_page ) ),
        );
    }

    public static function submission_user_ids(): array {
        global $wpdb;
        $ids = $wpdb->get_col( 'SELECT DISTINCT user_id FROM ' . self::table() . ' ORDER BY user_id ASC' );
        return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
    }

    public static function all( int $limit = 250 ): array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d", $limit ),
            ARRAY_A
        );
    }

    public static function update_status( int $id, string $status, bool $manual = true ): bool {
        global $wpdb;
        $updated = $wpdb->update(
            self::table(),
            array(
                'status'          => $status,
                'manual_override' => $manual ? 1 : 0,
                'updated_at'      => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%d', '%s' ),
            array( '%d' )
        );
        return false !== $updated;
    }

    public static function dashboard_data( int $days = 14 ): array {
        global $wpdb;
        $table = self::table();
        $days  = max( 7, min( 60, $days ) );

        $counts = array();
        foreach ( array_keys( self::allowed_statuses() ) as $status ) {
            $counts[ $status ] = 0;
        }

        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $status = isset( $row['status'] ) ? (string) $row['status'] : '';
            if ( isset( $counts[ $status ] ) ) {
                $counts[ $status ] = (int) $row['total'];
            }
        }

        $total = array_sum( $counts );
        $patients = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table}" );

        $today_start = gmdate( 'Y-m-d 00:00:00' );
        $today_end   = gmdate( 'Y-m-d 23:59:59' );
        $today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE COALESCE(submitted_at, created_at) BETWEEN %s AND %s",
                $today_start,
                $today_end
            )
        );

        $start_date = gmdate( 'Y-m-d 00:00:00', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
        $trend_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(COALESCE(submitted_at, created_at)) AS day, COUNT(*) AS total
                 FROM {$table}
                 WHERE COALESCE(submitted_at, created_at) >= %s
                 GROUP BY DATE(COALESCE(submitted_at, created_at))
                 ORDER BY day ASC",
                $start_date
            ),
            ARRAY_A
        );

        $trend_map = array();
        foreach ( (array) $trend_rows as $row ) {
            if ( ! empty( $row['day'] ) ) {
                $trend_map[ (string) $row['day'] ] = (int) $row['total'];
            }
        }

        $trend = array();
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $date = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
            $trend[] = array(
                'date'  => $date,
                'label' => gmdate( 'M j', strtotime( $date . ' 00:00:00 UTC' ) ),
                'count' => isset( $trend_map[ $date ] ) ? (int) $trend_map[ $date ] : 0,
            );
        }

        $form_rows = $wpdb->get_results(
            "SELECT CASE WHEN form_name = '' THEN form_id ELSE form_name END AS form_label, COUNT(*) AS total
             FROM {$table}
             GROUP BY CASE WHEN form_name = '' THEN form_id ELSE form_name END
             ORDER BY total DESC
             LIMIT 6",
            ARRAY_A
        );

        return array(
            'total'       => $total,
            'patients'    => $patients,
            'today'       => $today,
            'counts'      => $counts,
            'trend'       => $trend,
            'forms'       => is_array( $form_rows ) ? $form_rows : array(),
            'recent'      => self::all( 8 ),
        );
    }

    public static function allowed_statuses(): array {
        return array(
            'incomplete'    => 'Incomplete',
            'submitted'     => 'Submitted',
            'under_review'  => 'Processing',
            'action_needed' => 'Action Needed',
            'completed'     => 'Completed',
            'voided'        => 'Voided',
        );
    }

    /**
     * Statuses staff can manually assign from Submission History.
     * 'incomplete' is intentionally system-only for HIPAAtizer partial/save events.
     */
    public static function staff_statuses(): array {
        $statuses = self::allowed_statuses();
        unset( $statuses['incomplete'] );
        return $statuses;
    }
}
