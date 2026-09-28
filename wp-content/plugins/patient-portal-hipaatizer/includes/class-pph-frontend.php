<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PPH_Frontend {
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'admin_post_nopriv_pph_login', array( $this, 'handle_login' ) );
        add_action( 'admin_post_pph_login', array( $this, 'handle_login' ) );
        add_action( 'admin_post_nopriv_pph_register', array( $this, 'handle_register' ) );
        add_action( 'admin_post_nopriv_pph_verify', array( $this, 'handle_verify' ) );
        add_action( 'admin_post_pph_verify', array( $this, 'handle_verify' ) );
        add_action( 'admin_post_nopriv_pph_resend_verification', array( $this, 'handle_resend_verification' ) );
        add_action( 'admin_post_pph_resend_verification', array( $this, 'handle_resend_verification' ) );
        add_filter( 'wp_authenticate_user', array( $this, 'require_verified_patient_email' ), 20, 2 );
        add_action( 'admin_init', array( $this, 'block_patient_admin' ) );
        add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );
        add_action( 'template_redirect', array( $this, 'prevent_portal_cache' ), 0 );

        add_shortcode( 'patient_login', array( $this, 'shortcode_login' ) );
        add_shortcode( 'patient_register', array( $this, 'shortcode_register' ) );
        add_shortcode( 'patient_logout', array( $this, 'shortcode_logout' ) );
        add_shortcode( 'patient_account', array( $this, 'shortcode_account' ) );
        add_shortcode( 'patient_portal_dashboard', array( $this, 'shortcode_dashboard' ) );
        add_shortcode( 'patient_submission_statuses', array( $this, 'shortcode_statuses' ) );
        add_shortcode( 'patient_form', array( $this, 'shortcode_form' ) );
    }

    public function register_assets(): void {
        wp_register_style( 'pph-portal', PPH_URL . 'assets/css/patient-portal.css', array(), PPH_VERSION );
    }

    private function enqueue_assets(): void {
        wp_enqueue_style( 'pph-portal' );
        $settings = PPH_Plugin::settings();
        $accent   = sanitize_hex_color( (string) $settings['accent_color'] ) ?: '#2563eb';
        wp_add_inline_style( 'pph-portal', ':root{--pph-accent:' . esc_attr( $accent ) . ';}' );
    }

    public function handle_login(): void {
        if ( ! isset( $_POST['pph_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pph_login_nonce'] ) ), 'pph_login' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'patient-portal-hipaatizer' ) );
        }

        $username = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
        $password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
        $remember = ! empty( $_POST['rememberme'] );

        $user = wp_signon(
            array(
                'user_login'    => $username,
                'user_password' => $password,
                'remember'      => $remember,
            ),
            is_ssl()
        );

        if ( is_wp_error( $user ) ) {
            if ( in_array( 'pph_email_unverified', $user->get_error_codes(), true ) ) {
                wp_safe_redirect( add_query_arg( 'pph_register', 'verify_required', PPH_Plugin::registration_url() ) );
            } else {
                wp_safe_redirect( add_query_arg( 'pph_login', 'failed', PPH_Plugin::login_url() ) );
            }
            exit;
        }

        // A normal WooCommerce Customer/WordPress Subscriber who deliberately
        // signs in through Patient Login is granted Patient Portal access here.
        // Privileged staff accounts are explicitly excluded by grant_patient_access().
        if ( ! PPH_Plugin::is_patient( $user ) && ! PPH_Plugin::is_provider( $user ) && ! PPH_Plugin::grant_patient_access( $user ) ) {
            wp_logout();
            wp_safe_redirect( add_query_arg( 'pph_login', 'not_patient', PPH_Plugin::login_url() ) );
            exit;
        }

        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : PPH_Plugin::portal_url();
        wp_safe_redirect( wp_validate_redirect( $redirect, PPH_Plugin::portal_url() ) );
        exit;
    }

    public function require_verified_patient_email( $user, $password ) {
        if ( $user instanceof WP_User && ! PPH_Plugin::is_provider( $user ) && PPH_Plugin::is_patient( $user ) && ! PPH_Plugin::email_is_verified( $user ) ) {
            return new WP_Error( 'pph_email_unverified', 'Please verify your email address before signing in to the patient portal.' );
        }
        return $user;
    }

    public function handle_register(): void {
        $settings = PPH_Plugin::settings();
        if ( empty( $settings['allow_registration'] ) ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'disabled', PPH_Plugin::registration_url() ) );
            exit;
        }
        if ( ! isset( $_POST['pph_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pph_register_nonce'] ) ), 'pph_register' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'patient-portal-hipaatizer' ) );
        }
        if ( ! empty( $_POST['website'] ) ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'invalid', PPH_Plugin::registration_url() ) );
            exit;
        }

        $first    = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last     = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
        $confirm  = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';

        if ( '' === $first || '' === $last || ! is_email( $email ) ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'invalid', PPH_Plugin::registration_url() ) );
            exit;
        }
        if ( strlen( $password ) < 8 ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'weak_password', PPH_Plugin::registration_url() ) );
            exit;
        }
        if ( ! hash_equals( $password, $confirm ) ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'password_mismatch', PPH_Plugin::registration_url() ) );
            exit;
        }
        if ( email_exists( $email ) ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'exists', PPH_Plugin::registration_url() ) );
            exit;
        }

        $display  = trim( $first . ' ' . $last );
        $local    = strstr( $email, '@', true );
        $base     = sanitize_user( $local ? $local : 'patient', true );
        $base     = $base ? substr( $base, 0, 45 ) : 'patient';
        $username = $base;
        $counter  = 2;
        while ( username_exists( $username ) ) {
            $username = substr( $base, 0, 50 ) . '-' . $counter;
            $counter++;
        }
        $user_id = wp_insert_user(
            array(
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => $password,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => $display,
                'role'         => 'patient_portal_patient',
            )
        );

        if ( is_wp_error( $user_id ) ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'error', PPH_Plugin::registration_url() ) );
            exit;
        }

        // Mark this as a portal-created patient account independently of its
        // primary WordPress/WooCommerce role, then explicitly attach Patient access.
        update_user_meta( (int) $user_id, '_pph_patient_account', '1' );
        update_user_meta( (int) $user_id, '_pph_email_verified', '0' );
        $created_user = get_user_by( 'id', (int) $user_id );
        if ( $created_user instanceof WP_User && ! in_array( 'patient_portal_patient', (array) $created_user->roles, true ) ) {
            $created_user->add_role( 'patient_portal_patient' );
        }

        $sent = $this->send_verification_email( (int) $user_id );
        wp_safe_redirect( add_query_arg( 'pph_register', $sent ? 'created' : 'email_failed', PPH_Plugin::registration_url() ) );
        exit;
    }

    public function handle_verify(): void {
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $user    = $user_id ? get_user_by( 'id', $user_id ) : false;

        if ( ! $user instanceof WP_User || ! PPH_Plugin::is_patient( $user ) || '' === $token ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'bad_link', PPH_Plugin::registration_url() ) );
            exit;
        }

        $stored  = (string) get_user_meta( $user_id, '_pph_verify_token', true );
        $expires = (int) get_user_meta( $user_id, '_pph_verify_expires', true );
        $check   = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
        if ( '' === $stored || $expires < time() || ! hash_equals( $stored, $check ) ) {
            wp_safe_redirect( add_query_arg( 'pph_register', 'bad_link', PPH_Plugin::registration_url() ) );
            exit;
        }

        update_user_meta( $user_id, '_pph_email_verified', '1' );
        delete_user_meta( $user_id, '_pph_verify_token' );
        delete_user_meta( $user_id, '_pph_verify_expires' );
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true, is_ssl() );
        do_action( 'wp_login', $user->user_login, $user );
        wp_safe_redirect( add_query_arg( 'pph_verified', '1', PPH_Plugin::portal_url() ) );
        exit;
    }

    public function handle_resend_verification(): void {
        if ( ! isset( $_POST['pph_resend_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pph_resend_nonce'] ) ), 'pph_resend_verification' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'patient-portal-hipaatizer' ) );
        }
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $user  = $email ? get_user_by( 'email', $email ) : false;
        if ( $user instanceof WP_User && PPH_Plugin::is_patient( $user ) && ! PPH_Plugin::email_is_verified( $user ) ) {
            $this->send_verification_email( (int) $user->ID );
        }
        wp_safe_redirect( add_query_arg( 'pph_register', 'resent', PPH_Plugin::registration_url() ) );
        exit;
    }

    private function send_verification_email( int $user_id ): bool {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof WP_User ) {
            return false;
        }
        $token = wp_generate_password( 40, false, false );
        update_user_meta( $user_id, '_pph_verify_token', hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) );
        update_user_meta( $user_id, '_pph_verify_expires', time() + DAY_IN_SECONDS );
        $url = add_query_arg(
            array(
                'action'  => 'pph_verify',
                'user_id' => $user_id,
                'token'   => $token,
            ),
            admin_url( 'admin-post.php' )
        );
        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $subject   = sprintf( '[%s] Verify your patient portal account', $site_name );
        $message   = "Hello " . ( $user->first_name ? $user->first_name : $user->display_name ) . ",\n\n";
        $message  .= "Please verify your email address to activate your patient portal account:\n\n" . $url . "\n\n";
        $message  .= "This verification link expires in 24 hours. If you did not create this account, you can ignore this email.\n";
        return (bool) wp_mail( $user->user_email, $subject, $message );
    }

    public function prevent_portal_cache(): void {
        $settings = PPH_Plugin::settings();
        $page_ids = array_filter(
            array(
                (int) $settings['portal_page_id'],
                (int) $settings['login_page_id'],
                (int) $settings['registration_page_id'],
            )
        );

        if ( ! is_page( $page_ids ) && ! is_page( array( 'patient-portal', 'patient-login', 'patient-register' ) ) ) {
            return;
        }

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
    }

    public function block_patient_admin(): void {
        if ( ! is_user_logged_in() || ! PPH_Plugin::is_patient() ) {
            return;
        }
        $settings = PPH_Plugin::settings();
        if ( empty( $settings['block_patient_admin'] ) ) {
            return;
        }
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }
        global $pagenow;
        if ( 'admin-post.php' === $pagenow ) {
            return;
        }
        wp_safe_redirect( PPH_Plugin::portal_url() );
        exit;
    }

    public function maybe_hide_admin_bar( bool $show ): bool {
        $settings = PPH_Plugin::settings();
        if ( ! empty( $settings['hide_admin_bar'] ) && PPH_Plugin::is_patient() ) {
            return false;
        }
        return $show;
    }

    public function shortcode_login(): string {
        $this->enqueue_assets();
        if ( is_user_logged_in() ) {
            $current = wp_get_current_user();
            if ( ! PPH_Plugin::is_patient( $current ) && ! PPH_Plugin::is_provider( $current ) ) {
                PPH_Plugin::grant_patient_access( $current );
            }
            if ( PPH_Plugin::is_patient( $current ) || PPH_Plugin::is_provider( $current ) ) {
                return '<div class="pph-card pph-login-card"><p>You are already signed in.</p><p><a class="pph-button" href="' . esc_url( PPH_Plugin::portal_url() ) . '">Open Patient Portal</a></p></div>';
            }
            return '<div class="pph-card pph-login-card"><h2>Patient Login</h2><div class="pph-alert pph-alert-error">This account cannot be used for the Patient Portal. Please log out and use a patient or provider account.</div><p><a class="pph-button pph-button-secondary" href="' . esc_url( wp_logout_url( PPH_Plugin::login_url() ) ) . '">Log Out</a></p></div>';
        }

        $login_notice = isset( $_GET['pph_login'] ) ? sanitize_key( wp_unslash( $_GET['pph_login'] ) ) : '';
        $error = 'failed' === $login_notice;
        $not_patient = 'not_patient' === $login_notice;
        ob_start();
        ?>
        <div class="pph-card pph-login-card">
            <h2>Patient Login</h2>
            <?php if ( $error ) : ?>
                <div class="pph-alert pph-alert-error">The login details were not recognized. Please try again.</div>
            <?php elseif ( $not_patient ) : ?>
                <div class="pph-alert pph-alert-error">This account cannot be used for the Patient Portal. Please use a patient or provider account.</div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pph-form">
                <input type="hidden" name="action" value="pph_login">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( PPH_Plugin::portal_url() ); ?>">
                <?php wp_nonce_field( 'pph_login', 'pph_login_nonce' ); ?>
                <label>
                    <span>Email or Username</span>
                    <input type="text" name="log" autocomplete="username" required>
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="pwd" autocomplete="current-password" required>
                </label>
                <label class="pph-check"><input type="checkbox" name="rememberme" value="1"> <span>Keep me signed in on this device</span></label>
                <button class="pph-button" type="submit">Sign In</button>
            </form>
            <p class="pph-muted"><a href="<?php echo esc_url( wp_lostpassword_url( PPH_Plugin::login_url() ) ); ?>">Forgot your password?</a></p>
            <?php $settings = PPH_Plugin::settings(); if ( ! empty( $settings['allow_registration'] ) ) : ?>
                <div class="pph-login-divider"><span>New patient?</span></div>
                <a class="pph-button pph-button-secondary pph-button-full" href="<?php echo esc_url( PPH_Plugin::registration_url() ); ?>">Create New Account</a>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_register(): string {
        $this->enqueue_assets();
        $logged_unverified = false;
        if ( is_user_logged_in() ) {
            $current = wp_get_current_user();
            if ( PPH_Plugin::is_patient( $current ) && ! PPH_Plugin::email_is_verified( $current ) ) {
                $logged_unverified = true;
            } else {
                return '<div class="pph-card pph-login-card"><p>You are already signed in.</p><p><a class="pph-button" href="' . esc_url( PPH_Plugin::portal_url() ) . '">Open Patient Portal</a></p></div>';
            }
        }
        $settings = PPH_Plugin::settings();
        if ( empty( $settings['allow_registration'] ) ) {
            return '<div class="pph-card pph-login-card"><h2>Patient Registration</h2><div class="pph-alert pph-alert-error">New patient registration is currently disabled.</div><a class="pph-button" href="' . esc_url( PPH_Plugin::login_url() ) . '">Back to Patient Login</a></div>';
        }

        $notice = isset( $_GET['pph_register'] ) ? sanitize_key( wp_unslash( $_GET['pph_register'] ) ) : '';
        if ( $logged_unverified && ! $notice ) {
            $notice = 'verify_required';
        }
        $messages = array(
            'invalid'           => array( 'error', 'Please enter your first name, last name, and a valid email address.' ),
            'weak_password'     => array( 'error', 'Please use a password with at least 8 characters.' ),
            'password_mismatch' => array( 'error', 'The two passwords do not match.' ),
            'exists'            => array( 'error', 'An account already exists for that email address. Please sign in or reset your password.' ),
            'error'             => array( 'error', 'The account could not be created. Please try again.' ),
            'created'           => array( 'success', 'Account created. Check your email and click the verification link before signing in.' ),
            'email_failed'      => array( 'error', 'Your account was created, but the verification email could not be sent. Try resending it below or contact the site administrator.' ),
            'verify_required'   => array( 'error', 'Please verify your email address before signing in.' ),
            'bad_link'          => array( 'error', 'That verification link is invalid or has expired. Request a new link below.' ),
            'resent'            => array( 'success', 'If an unverified patient account exists for that email, a new verification link has been sent.' ),
            'disabled'          => array( 'error', 'New patient registration is currently disabled.' ),
        );
        ob_start();
        ?>
        <div class="pph-card pph-login-card">
            <h2>Create Patient Account</h2>
            <p class="pph-muted">Create your secure portal login. Medical information is not requested on this registration screen.</p>
            <?php if ( isset( $messages[ $notice ] ) ) : ?>
                <div class="pph-alert pph-alert-<?php echo esc_attr( $messages[ $notice ][0] ); ?>"><?php echo esc_html( $messages[ $notice ][1] ); ?></div>
            <?php endif; ?>
            <?php if ( ! $logged_unverified && ! in_array( $notice, array( 'created', 'resent' ), true ) ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pph-form">
                    <input type="hidden" name="action" value="pph_register">
                    <?php wp_nonce_field( 'pph_register', 'pph_register_nonce' ); ?>
                    <div class="pph-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <div class="pph-two-col">
                        <label><span>First Name</span><input type="text" name="first_name" autocomplete="given-name" required></label>
                        <label><span>Last Name</span><input type="text" name="last_name" autocomplete="family-name" required></label>
                    </div>
                    <label><span>Email Address</span><input type="email" name="email" autocomplete="email" required></label>
                    <label><span>Password</span><input type="password" name="password" autocomplete="new-password" minlength="8" required><small>Use at least 8 characters.</small></label>
                    <label><span>Confirm Password</span><input type="password" name="password_confirm" autocomplete="new-password" minlength="8" required></label>
                    <button class="pph-button pph-button-full" type="submit">Create Account</button>
                </form>
            <?php endif; ?>
            <?php if ( in_array( $notice, array( 'created', 'email_failed', 'verify_required', 'bad_link', 'resent' ), true ) ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pph-form pph-resend-form">
                    <input type="hidden" name="action" value="pph_resend_verification">
                    <?php wp_nonce_field( 'pph_resend_verification', 'pph_resend_nonce' ); ?>
                    <label><span>Need a new verification link?</span><input type="email" name="email" placeholder="Your email address" autocomplete="email" value="<?php echo $logged_unverified ? esc_attr( wp_get_current_user()->user_email ) : ''; ?>" required></label>
                    <button class="pph-button pph-button-secondary" type="submit">Resend Verification Email</button>
                </form>
            <?php endif; ?>
            <div class="pph-login-divider"><span>Already registered?</span></div>
            <a class="pph-button pph-button-secondary pph-button-full" href="<?php echo esc_url( PPH_Plugin::login_url() ); ?>">Back to Patient Login</a>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_logout(): string {
        $this->enqueue_assets();
        if ( ! is_user_logged_in() ) {
            return '';
        }
        return '<a class="pph-button pph-button-secondary" href="' . esc_url( wp_logout_url( PPH_Plugin::login_url() ) ) . '">Log Out</a>';
    }

    public function shortcode_account(): string {
        $this->enqueue_assets();
        if ( ! is_user_logged_in() ) {
            return $this->login_required();
        }
        if ( ! PPH_Plugin::is_patient() && ! PPH_Plugin::is_provider() ) {
            return $this->patient_account_required();
        }
        if ( ! PPH_Plugin::is_provider() && ! PPH_Plugin::email_is_verified() ) {
            return $this->verification_required();
        }
        $user = wp_get_current_user();
        ob_start();
        ?>
        <div class="pph-card">
            <h3>My Account</h3>
            <dl class="pph-account-list">
                <div><dt>Name</dt><dd><?php echo esc_html( $user->display_name ); ?></dd></div>
                <div><dt>Email</dt><dd><?php echo esc_html( $user->user_email ); ?></dd></div>
            </dl>
            <p><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Reset password</a></p>
            <?php echo do_shortcode( '[patient_logout]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_dashboard(): string {
        $this->enqueue_assets();
        if ( ! is_user_logged_in() ) {
            return $this->login_required();
        }
        if ( ! PPH_Plugin::is_patient() && ! PPH_Plugin::is_provider() ) {
            return $this->patient_account_required();
        }
        if ( ! PPH_Plugin::is_provider() && ! PPH_Plugin::email_is_verified() ) {
            return $this->verification_required();
        }

        $requested_key = isset( $_GET['pph_form'] ) ? sanitize_key( wp_unslash( $_GET['pph_form'] ) ) : '';
        if ( $requested_key ) {
            if ( PPH_Plugin::is_provider() && 'patient-intake' === $requested_key ) {
                return '<div class="pph-card"><h3>Service Forms</h3><p>Providers can access the available service forms directly from the Patient Portal.</p><a class="pph-button" href="' . esc_url( PPH_Plugin::portal_url() ) . '">Back to Patient Portal</a></div>';
            }
            return $this->render_form( $requested_key, true );
        }

        $user       = wp_get_current_user();
        $forms      = PPH_Plugin::forms();
        $latest     = PPH_DB::latest_for_user_by_form( (int) $user->ID );
        $portal_url = PPH_Plugin::portal_url();

        ob_start();
        ?>
        <div class="pph-portal">
            <?php if ( isset( $_GET['pph_verified'] ) && '1' === sanitize_key( wp_unslash( $_GET['pph_verified'] ) ) ) : ?>
                <div class="pph-alert pph-alert-success">Your email has been verified. Your patient portal account is ready.</div>
            <?php endif; ?>
            <div class="pph-hero">
                <div>
                    <span class="pph-eyebrow">Patient Portal</span>
                    <h2>Welcome, <?php echo esc_html( $user->display_name ); ?></h2>
                    <p>Complete your available forms and check their current portal status.</p>
                </div>
                <div><?php echo do_shortcode( '[patient_logout]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            </div>

            <div class="pph-choice-grid">

                <!-- Online Service Forms -->
                <section class="pph-choice-card">
                    <h3><?php echo PPH_Plugin::is_provider() ? 'Access your service forms online' : 'Prefer to fill out your service forms online?'; ?></h3>

                    <img
                        class="pph-choice-image"
                        src="/wp-content/uploads/2026/09/87117b39-2439-42c7-b678-a3212d755b81.webp"
                        alt="Patient completing medical forms online"
                    >

                    <p class="pph-choice-text">
                        <?php echo PPH_Plugin::is_provider() ? 'Select the service form you need to complete.' : 'Complete your service forms securely online from the convenience of your computer or mobile device.'; ?>
                    </p>

                    <?php if ( PPH_Plugin::is_provider() ) : ?>
                        <div class="pph-provider-services">
                            <?php foreach ( PPH_Plugin::forms() as $service_key => $service_form ) : ?>
                                <?php if ( empty( $service_form['active'] ) || 'patient-intake' === $service_key ) { continue; } ?>
                                <a class="pph-button pph-button-full" href="<?php echo esc_url( add_query_arg( 'pph_form', $service_key, $portal_url ) ); ?>">
                                    <?php echo esc_html( (string) $service_form['title'] ); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <?php $intake_form_key = 'patient-intake'; ?>
                        <a
                            class="pph-button"
                            href="<?php echo esc_url( add_query_arg( 'pph_form', $intake_form_key, $portal_url ) ); ?>"
                        >
                            Get Started
                        </a>
                    <?php endif; ?>
                </section>


                <!-- In-Person Service Forms -->
                <section class="pph-choice-card">

                    <h3>Prefer to fill out your service forms in person?</h3>

                    <img
                        class="pph-choice-image"
                        src="/wp-content/uploads/2026/09/f8eec8eb-8294-4410-be19-8c1d9a1fb80f.webp"
                        alt="Patient speaking with medical office staff"
                    >

                    <p class="pph-choice-text">
                        Let us know below, and we'll get in contact with you.
                    </p>

                    <form
                        class="pph-form pph-contact-form"
                        method="post"
                        action=""
                    >

                        <div class="pph-two-col">

                            <label>
                                <span>First Name</span>
                                <input
                                    type="text"
                                    name="first_name"
                                    autocomplete="given-name"
                                    required
                                >
                            </label>

                            <label>
                                <span>Last Name</span>
                                <input
                                    type="text"
                                    name="last_name"
                                    autocomplete="family-name"
                                    required
                                >
                            </label>

                        </div>

                        <label>
                            <span>Email</span>
                            <input
                                type="email"
                                name="email"
                                autocomplete="email"
                                required
                            >
                        </label>

                        <label>
                            <span>Phone</span>
                            <input
                                type="tel"
                                name="phone"
                                autocomplete="tel"
                                required
                            >
                        </label>

                        <label>
                            <span>Service</span>
                            <select name="service" required>
                                <option value="">Select a service</option>
                                <option value="Phlebotomy Services">Phlebotomy Services</option>
                                <option value="Corporate and Facility Services">Corporate and Facility Services</option>
                                <option value="Drug and Alcohol Testing">Drug and Alcohol Testing</option>
                                <option value="IV Hydration and Wellness Treatments">IV Hydration and Wellness Treatments</option>
                                <option value="Gender Reveal and DNA Testing">Gender Reveal and DNA Testing</option>
                                <option value="Immigration Vaccination Services">Immigration Vaccination Services</option>
                            </select>
                        </label>

                        <button class="pph-button" type="submit">
                            Submit
                        </button>
                    </form>
                </section>
            </div>

            <div class="pph-section">
                <h3>Submission History</h3>
                <?php echo $this->render_status_table(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function shortcode_statuses(): string {
        $this->enqueue_assets();
        if ( ! is_user_logged_in() ) {
            return $this->login_required();
        }
        if ( ! PPH_Plugin::is_patient() && ! PPH_Plugin::is_provider() ) {
            return $this->patient_account_required();
        }
        if ( ! PPH_Plugin::is_provider() && ! PPH_Plugin::email_is_verified() ) {
            return $this->verification_required();
        }
        return $this->render_status_table();
    }

    public function shortcode_form( array $atts ): string {
        $this->enqueue_assets();
        if ( ! is_user_logged_in() ) {
            return $this->login_required();
        }
        if ( ! PPH_Plugin::is_patient() && ! PPH_Plugin::is_provider() ) {
            return $this->patient_account_required();
        }
        if ( ! PPH_Plugin::is_provider() && ! PPH_Plugin::email_is_verified() ) {
            return $this->verification_required();
        }
        $atts = shortcode_atts( array( 'key' => '' ), $atts, 'patient_form' );
        return $this->render_form( sanitize_key( (string) $atts['key'] ), false );
    }

    private function render_form( string $key, bool $back_link ): string {
        $forms = PPH_Plugin::forms();
        if ( ! isset( $forms[ $key ] ) || empty( $forms[ $key ]['active'] ) ) {
            return '<div class="pph-alert pph-alert-error">This form is not available.</div>';
        }

        $form  = $forms[ $key ];
        $embed = trim( (string) ( $form['embed'] ?? '' ) );
        ob_start();
        ?>
        <div class="pph-portal pph-form-view">
            <?php if ( $back_link ) : ?>
                <p><a class="pph-back" href="<?php echo esc_url( PPH_Plugin::portal_url() ); ?>">&larr; Back to Patient Portal</a></p>
            <?php endif; ?>
            <div class="pph-card">
                <h2><?php echo esc_html( (string) $form['title'] ); ?></h2>
                <?php if ( ! empty( $form['instructions'] ) ) : ?><p><?php echo esc_html( (string) $form['instructions'] ); ?></p><?php endif; ?>
            </div>
            <div class="pph-embed-wrap">
                <?php echo $this->render_embed( $embed, $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_embed( string $embed, array $form = array() ): string {
        if ( '' === $embed ) {
            return '<div class="pph-card"><p>The HIPAAtizer embed has not been configured for this form.</p></div>';
        }

        $prefill = $this->hipaatizer_prefill_values( $form );

        if ( 0 === strpos( ltrim( $embed ), '[' ) ) {
            // HIPAAtizer documents that embedded forms can capture values from the
            // parent page URL when the URL parameter matches a form field Unique Name.
            // Add the value client-side before the HIPAAtizer embed executes, then also
            // rewrite iframe URLs when the official shortcode returns an iframe directly.
            $output = do_shortcode( $embed );
            $output = $this->add_prefill_to_iframe_sources( $output, $prefill );
            return $this->prefill_parent_url_script( $prefill ) . $output;
        }

        if ( wp_http_validate_url( $embed ) ) {
            $url = $this->add_query_args_preserving_url( $embed, $prefill );
            return '<iframe class="pph-iframe" src="' . esc_url( $url ) . '" title="Patient form" loading="eager" referrerpolicy="strict-origin-when-cross-origin"></iframe>';
        }

        return '<div class="pph-card"><p>Invalid form embed. Add the official HIPAAtizer WordPress shortcode or an HTTPS form URL in Patient Portal &rarr; Forms.</p></div>';
    }

    private function hipaatizer_prefill_values( array $form ): array {
        if ( isset( $form['auto_prefill_email'] ) && empty( $form['auto_prefill_email'] ) ) {
            return array();
        }

        $user = wp_get_current_user();
        if ( ! $user instanceof WP_User || ! $user->ID || ! is_email( $user->user_email ) ) {
            return array();
        }

        $field_name = isset( $form['email_field_unique_name'] ) ? (string) $form['email_field_unique_name'] : 'email';
        $field_name = preg_replace( '/[^A-Za-z0-9_.-]/', '', trim( $field_name ) );
        if ( ! is_string( $field_name ) || '' === $field_name ) {
            $field_name = 'email';
        }

        return array( $field_name => (string) $user->user_email );
    }

    private function add_query_args_preserving_url( string $url, array $args ): string {
        if ( empty( $args ) ) {
            return $url;
        }
        foreach ( $args as $key => $value ) {
            $url = add_query_arg( (string) $key, (string) $value, $url );
        }
        return $url;
    }

    private function add_prefill_to_iframe_sources( string $html, array $prefill ): string {
        if ( '' === $html || empty( $prefill ) || false === stripos( $html, '<iframe' ) ) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/(<iframe\b[^>]*\bsrc\s*=\s*)([\"\'])([^\"\']+)(\2)/i',
            function ( $matches ) use ( $prefill ) {
                $url = html_entity_decode( (string) $matches[3], ENT_QUOTES, 'UTF-8' );
                if ( ! wp_http_validate_url( $url ) ) {
                    return $matches[0];
                }
                $url = $this->add_query_args_preserving_url( $url, $prefill );
                return $matches[1] . $matches[2] . esc_url( $url ) . $matches[4];
            },
            $html
        );
    }

    private function prefill_parent_url_script( array $prefill ): string {
        if ( empty( $prefill ) ) {
            return '';
        }

        $json = wp_json_encode( $prefill, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        if ( ! is_string( $json ) || '' === $json ) {
            return '';
        }

        // replaceState changes the browser URL without reloading the WordPress page,
        // so the email is not sent back through a new WordPress HTTP request. The
        // temporary parameter is removed after the embed has had time to initialize.
        return '<script>(function(){try{var v=' . $json . ';var u=new URL(window.location.href);var original=u.toString();Object.keys(v).forEach(function(k){u.searchParams.set(k,v[k]);});window.history.replaceState(window.history.state,document.title,u.toString());window.setTimeout(function(){try{window.history.replaceState(window.history.state,document.title,original);}catch(e){}},10000);}catch(e){}})();</script>';
    }

    private function render_status_table(): string {
        $rows = PPH_DB::for_user( get_current_user_id(), 100 );
        if ( ! $rows ) {
            return '<div class="pph-card"><p>No linked submissions yet.</p></div>';
        }

        ob_start();
        ?>
        <div class="pph-table-wrap">
            <table class="pph-table">
                <thead><tr><th>Form</th><th>Status</th><th>Submitted</th><th>Updated</th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['form_name'] ?: 'Patient Form' ); ?></td>
                        <td><?php echo $this->status_badge( (string) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        <td><?php echo esc_html( $this->display_date( (string) $row['submitted_at'] ) ); ?></td>
                        <td><?php echo esc_html( $this->display_date( (string) $row['updated_at'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function status_badge( string $status ): string {
        $labels = array_merge( array( 'not_submitted' => 'Not Submitted' ), PPH_DB::allowed_statuses() );
        $label  = $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
        return '<span class="pph-status pph-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
    }

    private function display_date( string $utc ): string {
        if ( ! $utc ) {
            return '—';
        }
        $timestamp = strtotime( $utc . ' UTC' );
        return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '—';
    }

    private function verification_required(): string {
        return '<div class="pph-card"><h3>Email verification required</h3><p>Please verify your email address before accessing patient forms or submission statuses.</p><a class="pph-button" href="' . esc_url( PPH_Plugin::registration_url() ) . '">Verify Account</a> <a class="pph-button pph-button-secondary" href="' . esc_url( wp_logout_url( PPH_Plugin::login_url() ) ) . '">Log Out</a></div>';
    }

    private function patient_account_required(): string {
        return '<div class="pph-card"><h3>Patient account required</h3><p>This portal is available only to Patient or Provider accounts.</p><a class="pph-button" href="' . esc_url( PPH_Plugin::login_url() ) . '">Patient Login</a></div>';
    }

    private function login_required(): string {
        return '<div class="pph-card"><h3>Sign in required</h3><p>Please sign in to access the patient portal.</p><a class="pph-button" href="' . esc_url( PPH_Plugin::login_url() ) . '">Patient Login</a></div>';
    }
}
