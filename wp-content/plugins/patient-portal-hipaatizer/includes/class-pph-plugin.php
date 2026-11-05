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
        // Keep custom capabilities synchronized for existing roles without requiring plugin reactivation/version bumps.
        self::ensure_staff_roles();
        self::ensure_provider_role();

        // Give Patient Portal Managers limited page-editing access and expose only Elementor's editor menu.
        // Consolidate any Elementor menu entries after Elementor and White Label register their menus.
        add_action( 'admin_menu', array( $this, 'consolidate_elementor_menu_for_portal_manager' ), PHP_INT_MAX );
        add_action( 'admin_menu', array( $this, 'adjust_portal_manager_admin_menus' ), PHP_INT_MAX );
        add_filter( 'user_has_cap', array( $this, 'allow_elementor_editor_for_portal_manager' ), 99, 4 );
        add_filter( 'rest_endpoints', array( $this, 'filter_elementor_submission_endpoints' ), 444444444 );
        add_action( 'admin_head', array( $this, 'style_portal_manager_stripe_payments' ), 99 );
        add_action( 'admin_init', array( $this, 'restrict_portal_manager_stripe_tabs' ), 1 );
        add_action( 'wp_ajax_espd_delete_payment', array( $this, 'block_portal_manager_payment_deletion' ), 0 );

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
                    'edit_pages'             => true,
                    'edit_published_pages'   => true,
                    'edit_others_pages'      => true,
                    'publish_pages'           => true,
                    'read_private_pages'     => true,
                )
            );
        } elseif ( $manager instanceof WP_Role ) {
            $manager->add_cap( 'read' );
            $manager->add_cap( 'upload_files' );
            $manager->add_cap( 'manage_patient_portal' );
            // Keep page editing limited to editing/publishing pages; no delete or user-management capabilities.
            $manager->add_cap( 'edit_pages' );
            $manager->add_cap( 'edit_published_pages' );
            $manager->add_cap( 'edit_others_pages' );
            $manager->add_cap( 'publish_pages' );
            $manager->add_cap( 'read_private_pages' );
        }

        // Read-only Elementor Pro Form Submissions access.
        if ( $manager instanceof WP_Role ) {
            $manager->add_cap( 'etor_submissions_read' );
            $manager->add_cap( 'pph_view_stripe_payments' );
        }
    }

    /**
     * Keep a single Elementor sidebar parent for Patient Portal Managers.
     * Elementor and White Label may register menus at different priorities;
     * run last, remove duplicate parents, and attach Editor + Submissions to
     * the one surviving parent.
     */
    public function consolidate_elementor_menu_for_portal_manager(): void {
        $user = wp_get_current_user();
        if ( ! $user instanceof WP_User || ! in_array( 'patient_portal_manager', (array) $user->roles, true ) || ! current_user_can( 'edit_pages' ) ) {
            return;
        }

        global $menu, $submenu;
        if ( ! is_array( $menu ) ) {
            $menu = array();
        }

        $elementor_indexes = array();
        $native_index      = null;
        foreach ( $menu as $index => $item ) {
            $title = isset( $item[0] ) ? trim( strtolower( wp_strip_all_tags( (string) $item[0] ) ) ) : '';
            $slug  = isset( $item[2] ) ? (string) $item[2] : '';
            if ( 'elementor' === $slug || 'pph-elementor-editor' === $slug || 'elementor' === $title ) {
                $elementor_indexes[] = $index;
                if ( 'elementor' === $slug ) {
                    $native_index = $index;
                }
            }
        }

        // Prefer Elementor's native parent when it exists; otherwise create one PPH parent.
        $parent_slug = 'elementor';
        if ( null !== $native_index ) {
            $parent_index = $native_index;
        } else {
            $parent_index = null;
            foreach ( $elementor_indexes as $index ) {
                if ( isset( $menu[ $index ][2] ) && 'pph-elementor-editor' === $menu[ $index ][2] ) {
                    $parent_index = $index;
                    break;
                }
            }
        }

        if ( null === $parent_index ) {
            add_menu_page(
                'Elementor',
                'Elementor',
                'edit_pages',
                'pph-elementor-editor',
                static function () {
                    wp_safe_redirect( admin_url( 'edit.php?post_type=page' ) );
                    exit;
                },
                'dashicons-edit',
                58
            );
            $parent_slug = 'pph-elementor-editor';
            // add_menu_page appends the item to the global menu immediately.
            foreach ( (array) $menu as $index => $item ) {
                if ( isset( $item[2] ) && 'pph-elementor-editor' === $item[2] ) {
                    $parent_index = $index;
                    break;
                }
            }
        } elseif ( isset( $menu[ $parent_index ] ) ) {
            $parent_slug = (string) $menu[ $parent_index ][2];
        }

        // Remove every other Elementor parent so the sidebar contains only one.
        foreach ( $elementor_indexes as $index ) {
            if ( $index !== $parent_index ) {
                unset( $menu[ $index ] );
            }
        }
        if ( isset( $menu[ $parent_index ] ) ) {
            $menu[ $parent_index ][1] = 'edit_pages';
        }

        // Remove the old fallback's submenu bucket if it is not the selected parent.
        foreach ( array( 'elementor', 'pph-elementor-editor' ) as $candidate_parent ) {
            if ( $candidate_parent !== $parent_slug && isset( $submenu[ $candidate_parent ] ) ) {
                unset( $submenu[ $candidate_parent ] );
            }
        }

        // Rebuild these two entries through WordPress APIs so their admin-page
        // hooks are registered as well as their visible sidebar links.
        $submenu[ $parent_slug ] = array();
        add_submenu_page(
            $parent_slug,
            'Editor',
            'Editor',
            'edit_pages',
            'edit.php?post_type=page'
        );
        add_submenu_page(
            $parent_slug,
            'Submissions',
            'Submissions',
            'etor_submissions_read',
            'e-form-submissions',
            static function () {
                echo '<div class="wrap"><div id="e-form-submissions"></div></div>';
            }
        );

        $role = get_role( 'patient_portal_manager' );
        if ( $role instanceof WP_Role ) {
            $role->add_cap( 'etor_submissions_read' );
        }
    }

    /**
     * Expose a safe Elementor entry point to Patient Portal Managers.
     *
     * If Elementor registers its own menu, adjust its capability. If Elementor
     * omits the menu for this role, provide an Elementor-labeled shortcut to
     * Pages so the user can open an individual page with Elementor.
     */
    public function adjust_elementor_menu_for_portal_manager(): void {
        $user = wp_get_current_user();

        if ( ! $user instanceof WP_User || ! in_array( 'patient_portal_manager', (array) $user->roles, true ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_pages' ) ) {
            return;
        }

        global $menu, $submenu;
        $elementor_menu_found = false;

        if ( is_array( $menu ) ) {
            foreach ( $menu as &$item ) {
                if ( isset( $item[2] ) && 'elementor' === $item[2] ) {
                    $item[1] = 'edit_pages';
                    $elementor_menu_found = true;
                }
            }
            unset( $item );
        }

        if ( isset( $submenu['elementor'] ) && is_array( $submenu['elementor'] ) ) {
            foreach ( $submenu['elementor'] as &$item ) {
                $slug = isset( $item[2] ) ? $item[2] : '';
                if ( 'elementor' === $slug ) {
                    $item[1] = 'edit_pages';
                } else {
                    // Keep Elementor settings and other administrative screens restricted.
                    $item[1] = 'do_not_allow';
                }
            }
            unset( $item );
        }

        if ( ! $elementor_menu_found ) {
            // Elementor may omit its menu for roles without administrator-level access.
            // This shortcut opens Pages, where the user can select "Edit with Elementor".
            add_menu_page(
                'Elementor Editor',
                'Elementor',
                'edit_pages',
                'pph-elementor-editor',
                static function () {
                    wp_safe_redirect( admin_url( 'edit.php?post_type=page' ) );
                    exit;
                },
                'dashicons-edit',
                58
            );

            add_submenu_page(
                'pph-elementor-editor',
                'Editor',
                'Editor',
                'edit_pages',
                'edit.php?post_type=page'
            );
        }
    }

    /**
     * Register Elementor Pro Submissions as a visible submenu for Patient Portal Managers.
     * The dedicated capability is granted by ensure_staff_roles(); this avoids
     * depending on Elementor Pro's administrator-only menu registration.
     */
    public function add_elementor_submissions_menu_for_portal_manager(): void {
        $user = wp_get_current_user();
        if ( ! $user instanceof WP_User || ! in_array( 'patient_portal_manager', (array) $user->roles, true ) ) {
            return;
        }

        $role = get_role( 'patient_portal_manager' );
        if ( $role && ! $role->has_cap( 'etor_submissions_read' ) ) {
            $role->add_cap( 'etor_submissions_read' );
        }

        global $menu, $submenu;
        $elementor_parent_slug = '';
        foreach ( (array) $menu as $menu_item ) {
            $menu_title = isset( $menu_item[0] ) ? strtolower( wp_strip_all_tags( (string) $menu_item[0] ) ) : '';
            $menu_slug  = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
            if ( 'elementor' === $menu_slug || 'pph-elementor-editor' === $menu_slug || 'elementor' === trim( $menu_title ) ) {
                $elementor_parent_slug = $menu_slug;
                break;
            }
        }

        if ( '' === $elementor_parent_slug ) {
            return;
        }

        // If Elementor Pro already registered this page, make its menu capability
        // available to this role rather than returning with an inaccessible item.
        if ( isset( $submenu[ $elementor_parent_slug ] ) && is_array( $submenu[ $elementor_parent_slug ] ) ) {
            foreach ( $submenu[ $elementor_parent_slug ] as &$item ) {
                if ( isset( $item[2] ) && 'e-form-submissions' === $item[2] ) {
                    $item[1] = 'etor_submissions_read';
                    unset( $item );
                    return;
                }
            }
            unset( $item );
        }

        add_submenu_page(
            $elementor_parent_slug,
            'Submissions',
            'Submissions',
            'etor_submissions_read',
            'e-form-submissions',
            static function () {
                echo '<div class="wrap"><div id="e-form-submissions"></div></div>';
            }
        );
    }

    /**
     * Adjust admin navigation for Patient Portal Managers.
     * Hide Login Security and expose only Stripe Payments > Payments.
     */
    public function adjust_portal_manager_admin_menus(): void {
        $user = wp_get_current_user();
        if ( ! $user instanceof WP_User || ! in_array( 'patient_portal_manager', (array) $user->roles, true ) ) {
            return;
        }

        global $menu, $submenu;
        if ( ! is_array( $menu ) ) {
            return;
        }

        foreach ( $menu as $index => $item ) {
            $title = isset( $item[0] ) ? wp_strip_all_tags( (string) $item[0] ) : '';
            $slug  = isset( $item[2] ) ? (string) $item[2] : '';
            $haystack = strtolower( $title . ' ' . $slug );

            if ( false !== strpos( $haystack, 'login security' ) || false !== strpos( $haystack, 'login-security' ) ) {
                unset( $menu[ $index ] );
                continue;
            }

            if ( 'espd_main' === $slug || false !== strpos( $haystack, 'stripe' ) ) {
                $menu[ $index ][1] = 'pph_view_stripe_payments';
            }
        }

        // Keep unrelated menus untouched. Easy Stripe Payments registers all of
        // its submenu pages with manage_options, so its Payments entry is not
        // reliably available to this role. Rebuild only this role's Stripe
        // submenu after the plugin and White Label have registered their menus.
        if ( isset( $submenu['espd_main'] ) ) {
            $submenu['espd_main'] = array();
        }

        // Register the native Payments screen with the dedicated capability.
        // The original Stripe callback renders the Payments tab from this slug.
        if ( isset( $menu ) && function_exists( 'espd_render_admin_page' ) && isset( $submenu ) ) {
            add_submenu_page(
                'espd_main',
                'Payments',
                'Payments',
                'pph_view_stripe_payments',
                'espd_main&tab=payments',
                'espd_render_admin_page'
            );
        }
    }

    /**
     * Keep the Stripe Payments screen limited to viewing and CSV export.
     */
    public function style_portal_manager_stripe_payments(): void {
        if ( ! $this->is_portal_manager_user() || ! isset( $_GET['page'], $_GET['tab'] ) ) {
            return;
        }
        if ( 'espd_main' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || 'payments' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
            return;
        }
        echo '<style>
            .espad-admin-tabs .nav-tab-wrapper .nav-tab:not([href*="tab=payments"]) { display:none !important; }
            #espad-table-stripe-payments th:last-child,
            #espad-table-stripe-payments td:last-child { display:none !important; }
        </style>';
    }

    /** Prevent direct navigation to Stripe tabs other than Payments. */
    public function restrict_portal_manager_stripe_tabs(): void {
        if ( ! $this->is_portal_manager_user() || ! is_admin() || ! isset( $_GET['page'] ) ) {
            return;
        }
        if ( 'espd_main' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
            return;
        }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        if ( '' === $tab ) {
            wp_safe_redirect( admin_url( 'admin.php?page=espd_main&tab=payments' ) );
            exit;
        }
        if ( 'payments' !== $tab ) {
            wp_die( esc_html__( 'You do not have permission to access this Stripe Payments page.', 'patient-portal-hipaatizer' ), '', array( 'response' => 403 ) );
        }
    }

    /** Block payment deletion server-side for Patient Portal Managers. */
    public function block_portal_manager_payment_deletion(): void {
        if ( $this->is_portal_manager_user() ) {
            wp_send_json_error( array( 'message' => 'Your role has view and export access only. Payment deletion is restricted.' ), 403 );
        }
    }

    private function is_portal_manager_user(): bool {
        $user = wp_get_current_user();
        return $user instanceof WP_User && in_array( 'patient_portal_manager', (array) $user->roles, true );
    }

    /**
     * Grant read-only GET access to Elementor Pro Form Submissions endpoints.
     * All non-GET methods and all other users retain the original restrictions.
     */
    public function filter_elementor_submission_endpoints( $endpoints ) {
        foreach ( $endpoints as $route => $handlers ) {
            if ( ! preg_match( '#^/elementor/v1/form(?:-submission)?s(?:$|/)#', $route ) ) {
                continue;
            }

            foreach ( $handlers as $key => $handler ) {
                if ( ! is_array( $handler ) || ! isset( $handler['permission_callback'] ) ) {
                    continue;
                }
                $original = $handler['permission_callback'];
                $callback = function ( $request ) use ( $original ) {
                    $user = wp_get_current_user();
                    if ( $user instanceof WP_User && in_array( 'patient_portal_manager', (array) $user->roles, true ) ) {
                        if ( 'GET' !== strtoupper( $request->get_method() ) ) {
                            return new WP_Error( 'rest_forbidden', 'This role has read-only access to form submissions.', array( 'status' => 403 ) );
                        }
                        return true;
                    }
                    return is_callable( $original ) ? call_user_func( $original, $request ) : new WP_Error( 'rest_forbidden', 'Access denied.', array( 'status' => 403 ) );
                };

                if ( 'callback' === $key || 'permission_callback' === $key ) {
                    $endpoints[ $route ][ $key ] = $callback;
                } else {
                    $endpoints[ $route ][ $key ]['permission_callback'] = $callback;
                }
            }
        }
        return $endpoints;
    }

    /**
     * Allow the Elementor editor's manage_options check only for a page the
     * current Patient Portal Manager is already permitted to edit.
     *
     * This does not grant persistent administrator capabilities or access to
     * Elementor settings. It is limited to post.php?action=elementor.
     */
    public function allow_elementor_editor_for_portal_manager( $allcaps, $caps, $args, $user ) {
        if ( ! $user instanceof WP_User || ! in_array( 'patient_portal_manager', (array) $user->roles, true ) ) {
            return $allcaps;
        }

        if ( ! empty( $args[0] ) && 'pph_view_stripe_payments' === $args[0] ) {
            $allcaps['pph_view_stripe_payments'] = true;
        }

        // Easy Stripe Payments registers its menu only for manage_options users.
        // Satisfy that check only while its own menu-registration function runs.
        if ( ! empty( $args[0] ) && 'manage_options' === $args[0] && is_admin() && doing_action( 'admin_menu' ) ) {
            $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );
            foreach ( $trace as $frame ) {
                if ( isset( $frame['function'] ) && 'espd_register_admin_menu' === $frame['function'] ) {
                    $allcaps['manage_options'] = true;
                    break;
                }
            }
        }

        // Elementor's submissions screen performs a manage_options check.
        if ( ! empty( $args[0] ) && 'manage_options' === $args[0] && is_admin() && isset( $_GET['page'] ) && 'e-form-submissions' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
            $allcaps['manage_options'] = true;
        }

        // Easy Stripe Payments renders all tabs through espd_main. Grant its
        // internal capability check only on the Payments tab for this role.
        if ( ! empty( $args[0] ) && 'manage_options' === $args[0] && is_admin()
            && isset( $_GET['page'], $_GET['tab'] )
            && 'espd_main' === sanitize_key( wp_unslash( $_GET['page'] ) )
            && 'payments' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
            $allcaps['manage_options'] = true;
        }

        /*
         * Elementor's editor makes several read-only REST requests that check
         * edit_posts even when the user is editing a Page. Do not add edit_posts
         * to the role permanently: allow it only for these specific GET routes,
         * and only when this user already has Page-editing access.
         */
        if ( ! empty( $allcaps['edit_pages'] ) && isset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ) && 'GET' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
            $request_path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
            $read_only_elementor_routes = array(
                '/wp-json/elementor/v1/events/api/flags',
                '/wp-json/elementor/v1/global-widget/templates',
                '/wp-json/elementor/v1/site-navigation/recent-posts',
                '/wp-json/elementor/v1/kit-elements-defaults',
                '/wp-json/elementor/v1/globals',
            );

            foreach ( $read_only_elementor_routes as $route ) {
                if ( $request_path === $route || 0 === strpos( $request_path, $route . '/' ) ) {
                    $allcaps['edit_posts'] = true;
                    break;
                }
            }
        }

        if ( ! is_admin() ) {
            return $allcaps;
        }

        if (
            ! isset( $_GET['action'] ) ||
            'elementor' !== sanitize_key( wp_unslash( $_GET['action'] ) ) ||
            ! isset( $_GET['post'] ) ||
            empty( $args[0] ) ||
            'manage_options' !== $args[0]
        ) {
            return $allcaps;
        }

        $post_id = absint( wp_unslash( $_GET['post'] ) );

        if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
            $allcaps['manage_options'] = true;
        }

        return $allcaps;
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
