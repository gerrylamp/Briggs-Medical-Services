<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PPH_Plugin {
    private static $instance = null;

    public static function instance(): PPH_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function init(): void {
        PPH_DB::maybe_upgrade();
        self::maybe_upgrade_plugin();
        self::ensure_provider_role();

        add_filter( 'login_redirect', array( $this, 'client_login_redirect' ), 20, 3 );
        add_action( 'admin_init', array( $this, 'client_admin_landing_redirect' ) );

        new PPH_Webhook();
        new PPH_Frontend();
        if ( is_admin() ) {
            new PPH_Admin();
        }
    }

    public static function activate(): void {
        self::ensure_patient_role();
        self::ensure_staff_roles();
        self::repair_legacy_patient_accounts();

        PPH_DB::install();
        self::create_default_pages();

        $current = get_option( 'pph_settings', array() );
        update_option( 'pph_settings', wp_parse_args( $current, self::default_settings() ), false );

        if ( false === get_option( 'pph_forms', false ) ) {
            add_option( 'pph_forms', array(), '', false );
        }

        update_option( 'pph_plugin_version', PPH_VERSION, false );
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function default_settings(): array {
        return array(
            'portal_page_id'              => (int) get_option( 'pph_portal_page_id', 0 ),
            'login_page_id'               => (int) get_option( 'pph_login_page_id', 0 ),
            'registration_page_id'        => (int) get_option( 'pph_registration_page_id', 0 ),
            'allow_registration'          => 1,
            'accent_color'                => '#2563eb',
            'hide_admin_bar'              => 1,
            'block_patient_admin'         => 1,
            'webhook_auth_mode'           => 'hmac',
            'webhook_hmac_algo'           => 'sha256',
            'webhook_secret'              => '',
            'webhook_bearer_token'        => '',
            'delete_on_uninstall'         => 0,
            'client_dashboard_user_ids'   => array(),
            'client_redirect_enabled'     => 1,
            'client_hide_wp_dashboard'    => 1,
            'client_dashboard_period'     => 14,
        );
    }

    private static function create_default_pages(): void {
        $login_id = (int) get_option( 'pph_login_page_id', 0 );
        if ( ! $login_id || 'trash' === get_post_status( $login_id ) || ! get_post( $login_id ) ) {
            $login_id = wp_insert_post(
                array(
                    'post_title'   => 'Patient Login',
                    'post_name'    => 'patient-login',
                    'post_content' => '[patient_login]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ),
                true
            );
            if ( ! is_wp_error( $login_id ) ) {
                update_option( 'pph_login_page_id', (int) $login_id, false );
            }
        }

        $registration_id = (int) get_option( 'pph_registration_page_id', 0 );
        if ( ! $registration_id || 'trash' === get_post_status( $registration_id ) || ! get_post( $registration_id ) ) {
            $registration_id = wp_insert_post(
                array(
                    'post_title'   => 'Create Patient Account',
                    'post_name'    => 'patient-register',
                    'post_content' => '[patient_register]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ),
                true
            );
            if ( ! is_wp_error( $registration_id ) ) {
                update_option( 'pph_registration_page_id', (int) $registration_id, false );
            }
        }

        $portal_id = (int) get_option( 'pph_portal_page_id', 0 );
        if ( ! $portal_id || 'trash' === get_post_status( $portal_id ) || ! get_post( $portal_id ) ) {
            $portal_id = wp_insert_post(
                array(
                    'post_title'   => 'Patient Portal',
                    'post_name'    => 'patient-portal',
                    'post_content' => '[patient_portal_dashboard]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ),
                true
            );
            if ( ! is_wp_error( $portal_id ) ) {
                update_option( 'pph_portal_page_id', (int) $portal_id, false );
            }
        }
    }

    public static function settings(): array {
        $settings = wp_parse_args( get_option( 'pph_settings', array() ), self::default_settings() );
        $settings['client_dashboard_user_ids'] = isset( $settings['client_dashboard_user_ids'] ) && is_array( $settings['client_dashboard_user_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', $settings['client_dashboard_user_ids'] ) ) ) )
            : array();
        return $settings;
    }

    private static function ensure_patient_role(): void {
        if ( ! get_role( 'patient_portal_patient' ) ) {
            add_role(
                'patient_portal_patient',
                'Patient',
                array(
                    'read' => true,
                )
            );
        }
    }

    private static function ensure_provider_role(): void {
        if ( ! get_role( 'patient_portal_provider' ) ) {
            add_role(
                'patient_portal_provider',
                'Provider',
                array(
                    'read' => true,
                )
            );
        }
    }

    private static function ensure_staff_roles(): void {
        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( 'manage_patient_portal' ) ) {
            $admin->add_cap( 'manage_patient_portal' );
        }

        $manager = get_role( 'patient_portal_manager' );
        if ( ! $manager ) {
            $manager = add_role(
                'patient_portal_manager',
                'Patient Portal Manager',
                array(
                    'read'                  => true,
                    'upload_files'          => true,
                    'manage_patient_portal' => true,
                )
            );
        } elseif ( $manager instanceof WP_Role ) {
            $manager->add_cap( 'read' );
            $manager->add_cap( 'upload_files' );
            $manager->add_cap( 'manage_patient_portal' );
        }
    }

    private static function maybe_upgrade_plugin(): void {
        if ( (string) get_option( 'pph_plugin_version', '' ) === PPH_VERSION ) {
            return;
        }

        self::ensure_patient_role();
        self::ensure_staff_roles();
        self::repair_legacy_patient_accounts();
        self::create_default_pages();

        update_option( 'pph_settings', wp_parse_args( get_option( 'pph_settings', array() ), self::default_settings() ), false );
        update_option( 'pph_plugin_version', PPH_VERSION, false );
    }

    public static function email_is_verified( $user = null ): bool {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return false;
        }
        // Existing/manual patient accounts pre-dating v1.0.2 have no flag and remain trusted.
        return '0' !== (string) get_user_meta( (int) $user->ID, '_pph_email_verified', true );
    }

    public static function forms(): array {
        $forms = get_option( 'pph_forms', array() );
        return is_array( $forms ) ? $forms : array();
    }

    /**
     * Normal storefront/customer accounts may become portal patients when they
     * deliberately authenticate through the Patient Login form. Privileged
     * WordPress staff accounts are never converted automatically.
     */
    public static function can_grant_patient_access( $user ): bool {
        if ( ! $user instanceof WP_User || ! $user->ID ) {
            return false;
        }

        if ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_users' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'edit_others_posts' ) ) {
            return false;
        }

        $eligible_roles = apply_filters(
            'pph_patient_eligible_roles',
            array( 'customer', 'subscriber', 'patient_portal_patient' ),
            $user
        );

        $roles = (array) $user->roles;
        if ( empty( $roles ) ) {
            return true;
        }

        return (bool) array_intersect( $roles, (array) $eligible_roles );
    }

    public static function grant_patient_access( $user ): bool {
        if ( ! $user instanceof WP_User || ! $user->ID ) {
            return false;
        }

        if ( self::is_patient( $user ) ) {
            return true;
        }

        if ( ! self::can_grant_patient_access( $user ) ) {
            return false;
        }

        self::ensure_patient_role();
        update_user_meta( (int) $user->ID, '_pph_patient_account', '1' );

        if ( ! in_array( 'patient_portal_patient', (array) $user->roles, true ) ) {
            $user->add_role( 'patient_portal_patient' );
        }

        clean_user_cache( (int) $user->ID );
        return true;
    }

    public static function is_provider( ?WP_User $user = null ): bool {
        $user = $user ?: wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return false;
        }

        return in_array( 'patient_portal_provider', (array) $user->roles, true );
    }

    public static function is_patient( ?WP_User $user = null ): bool {
        $user = $user ?: wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return false;
        }

        if ( in_array( 'patient_portal_patient', (array) $user->roles, true ) ) {
            return true;
        }

        if ( '1' === (string) get_user_meta( (int) $user->ID, '_pph_patient_account', true ) ) {
            return true;
        }

        if ( metadata_exists( 'user', (int) $user->ID, '_pph_email_verified' ) ) {
            update_user_meta( (int) $user->ID, '_pph_patient_account', '1' );
            if ( ! in_array( 'patient_portal_patient', (array) $user->roles, true ) ) {
                $user->add_role( 'patient_portal_patient' );
            }
            return true;
        }

        return false;
    }

    private static function repair_legacy_patient_accounts(): void {
        $users = get_users(
            array(
                'meta_key'     => '_pph_email_verified',
                'meta_compare' => 'EXISTS',
                'fields'       => 'all',
            )
        );

        foreach ( $users as $user ) {
            if ( ! $user instanceof WP_User ) {
                continue;
            }
            update_user_meta( (int) $user->ID, '_pph_patient_account', '1' );
            if ( ! in_array( 'patient_portal_patient', (array) $user->roles, true ) ) {
                $user->add_role( 'patient_portal_patient' );
            }
        }
    }

    public static function is_client_dashboard_user( $user = null ): bool {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return false;
        }

        $settings = self::settings();
        $ids      = (array) $settings['client_dashboard_user_ids'];
        $is_client = in_array( (int) $user->ID, array_map( 'intval', $ids ), true );

        return (bool) apply_filters( 'pph_is_client_dashboard_user', $is_client, $user );
    }

    public function client_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( $user instanceof WP_User && self::is_client_dashboard_user( $user ) && user_can( $user, 'manage_patient_portal' ) ) {
            $settings = self::settings();
            if ( ! empty( $settings['client_redirect_enabled'] ) ) {
                return admin_url( 'admin.php?page=pph-dashboard' );
            }
        }
        return $redirect_to;
    }

    public function client_admin_landing_redirect(): void {
        if ( ! is_user_logged_in() || wp_doing_ajax() || ! self::is_client_dashboard_user() ) {
            return;
        }

        $settings = self::settings();
        if ( empty( $settings['client_redirect_enabled'] ) ) {
            return;
        }

        global $pagenow;
        if ( 'index.php' === $pagenow ) {
            wp_safe_redirect( admin_url( 'admin.php?page=pph-dashboard' ) );
            exit;
        }
    }

    public static function portal_url(): string {
        $settings = self::settings();
        $page_id  = (int) $settings['portal_page_id'];
        return $page_id ? (string) get_permalink( $page_id ) : home_url( '/patient-portal/' );
    }

    public static function login_url(): string {
        $settings = self::settings();
        $page_id  = (int) $settings['login_page_id'];
        return $page_id ? (string) get_permalink( $page_id ) : home_url( '/patient-login/' );
    }

    public static function registration_url(): string {
        $settings = self::settings();
        $page_id  = (int) $settings['registration_page_id'];
        return $page_id ? (string) get_permalink( $page_id ) : home_url( '/patient-register/' );
    }
}
