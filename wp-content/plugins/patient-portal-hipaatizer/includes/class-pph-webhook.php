<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PPH_Webhook {
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            'patient-portal/v1',
            '/hipaatizer',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'receive' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function receive( WP_REST_Request $request ) {
        $raw = $request->get_body();
        if ( '' === trim( $raw ) ) {
            return new WP_Error( 'pph_empty_body', 'Empty request body.', array( 'status' => 400 ) );
        }

        $auth = $this->authenticate( $request, $raw );
        if ( is_wp_error( $auth ) ) {
            $this->record_health( false, 'Authentication failed' );
            return $auth;
        }

        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'pph_invalid_json', 'Webhook body must be valid JSON.', array( 'status' => 400 ) );
        }

        // Intentionally whitelist only routing/status metadata. Unknown payload fields are never persisted.
        $form_id       = $this->first_value( $payload, array( 'form_id', 'formId', 'form' ) );
        $submission_id = $this->first_value( $payload, array( 'submission_id', 'submissionId', 'id' ) );
        $event          = $this->first_value( $payload, array( 'event', 'event_name', 'eventName', 'type' ) );
        $incoming       = $this->first_value( $payload, array( 'status', 'portal_status', 'portalStatus' ) );

        $form_id       = sanitize_text_field( (string) $form_id );
        $submission_id = sanitize_text_field( (string) $submission_id );
        $event          = sanitize_key( (string) $event );
        $incoming       = sanitize_key( (string) $incoming );

        if ( '' === $form_id || '' === $submission_id ) {
            return new WP_Error(
                'pph_missing_ids',
                'The webhook must include form_id and submission_id.',
                array( 'status' => 422 )
            );
        }

        $user = $this->resolve_user( $payload, $form_id );
        if ( ! $user ) {
            return new WP_Error(
                'pph_user_not_found',
                'No WordPress patient account matched this webhook. Include wp_user_id, patient_email, or patient_username.',
                array( 'status' => 422 )
            );
        }

        $status       = $this->normalize_status( $incoming, $event );
        $reset_manual = filter_var( $this->first_value( $payload, array( 'reset_manual_override', 'resetManualOverride' ) ), FILTER_VALIDATE_BOOLEAN );
        $submitted_at = $this->normalize_datetime( $this->first_value( $payload, array( 'submitted_at', 'submittedAt', 'created_at', 'createdAt' ) ) );
        $form_name    = $this->form_name( $form_id );

        $id = PPH_DB::upsert(
            array(
                'user_id'                => (int) $user->ID,
                'form_id'                => $form_id,
                'form_name'              => $form_name,
                'external_submission_id' => $submission_id,
                'status'                 => $status,
                'event_name'             => $event,
                'submitted_at'           => $submitted_at,
                'force_status'           => (bool) $reset_manual,
            )
        );

        if ( false === $id ) {
            $this->record_health( false, 'Database update failed' );
            return new WP_Error( 'pph_db_error', 'Could not save submission status.', array( 'status' => 500 ) );
        }

        $this->record_health( true, 'Webhook processed' );

        return new WP_REST_Response(
            array(
                'ok'       => true,
                'record_id'=> $id,
                'status'   => $status,
            ),
            200
        );
    }

    private function authenticate( WP_REST_Request $request, string $raw ) {
        $settings = PPH_Plugin::settings();
        $mode     = in_array( $settings['webhook_auth_mode'], array( 'hmac', 'bearer', 'both' ), true ) ? $settings['webhook_auth_mode'] : 'hmac';

        $secret = defined( 'PPH_WEBHOOK_SECRET' ) ? (string) PPH_WEBHOOK_SECRET : (string) $settings['webhook_secret'];
        $bearer = defined( 'PPH_WEBHOOK_BEARER_TOKEN' ) ? (string) PPH_WEBHOOK_BEARER_TOKEN : (string) $settings['webhook_bearer_token'];

        if ( in_array( $mode, array( 'hmac', 'both' ), true ) ) {
            if ( '' === $secret ) {
                return new WP_Error( 'pph_webhook_not_configured', 'Webhook HMAC secret is not configured.', array( 'status' => 503 ) );
            }
            $signature = (string) $request->get_header( 'HIPAA-Signature' );
            if ( '' === $signature || ! $this->verify_hmac( $raw, $signature, $secret, (string) $settings['webhook_hmac_algo'] ) ) {
                return new WP_Error( 'pph_bad_signature', 'Invalid webhook signature.', array( 'status' => 401 ) );
            }
        }

        if ( in_array( $mode, array( 'bearer', 'both' ), true ) ) {
            if ( '' === $bearer ) {
                return new WP_Error( 'pph_bearer_not_configured', 'Webhook bearer token is not configured.', array( 'status' => 503 ) );
            }
            $authorization = trim( (string) $request->get_header( 'authorization' ) );
            $received      = preg_replace( '/^Bearer\s+/i', '', $authorization );
            if ( ! is_string( $received ) || ! hash_equals( $bearer, trim( $received ) ) ) {
                return new WP_Error( 'pph_bad_bearer', 'Invalid webhook authorization token.', array( 'status' => 401 ) );
            }
        }

        return true;
    }

    private function verify_hmac( string $raw, string $signature, string $secret, string $algorithm ): bool {
        $allowed   = array( 'sha256', 'sha512', 'sha1' );
        $algorithm = in_array( $algorithm, $allowed, true ) ? $algorithm : 'sha256';
        $signature = trim( $signature );

        $hex    = hash_hmac( $algorithm, $raw, $secret, false );
        $base64 = base64_encode( hash_hmac( $algorithm, $raw, $secret, true ) );

        $candidates = array(
            $hex,
            $algorithm . '=' . $hex,
            'v1=' . $hex,
            $base64,
            $algorithm . '=' . $base64,
            'v1=' . $base64,
        );

        foreach ( $candidates as $candidate ) {
            if ( hash_equals( $candidate, $signature ) ) {
                return true;
            }
        }
        return false;
    }

    private function resolve_user( array $payload, string $form_id = '' ): ?WP_User {
        $wp_user_id = absint( $this->first_value( $payload, array( 'wp_user_id', 'wpUserId', 'user_id', 'userId' ) ) );
        if ( $wp_user_id ) {
            $user = get_user_by( 'id', $wp_user_id );
            if ( $user instanceof WP_User && PPH_Plugin::is_patient( $user ) ) {
                return $user;
            }
        }

        $email_keys = array( 'patient_email', 'patientEmail', 'email' );
        $configured_email_key = $this->configured_email_field_name( $form_id );
        if ( $configured_email_key && ! in_array( $configured_email_key, $email_keys, true ) ) {
            $email_keys[] = $configured_email_key;
        }

        $email = sanitize_email( (string) $this->first_value( $payload, $email_keys ) );
        if ( $email ) {
            $user = get_user_by( 'email', $email );
            if ( $user instanceof WP_User && PPH_Plugin::is_patient( $user ) ) {
                return $user;
            }
        }

        $username = sanitize_user( (string) $this->first_value( $payload, array( 'patient_username', 'patientUsername', 'username' ) ), true );
        if ( $username ) {
            $user = get_user_by( 'login', $username );
            if ( $user instanceof WP_User && PPH_Plugin::is_patient( $user ) ) {
                return $user;
            }
        }

        return null;
    }

    private function configured_email_field_name( string $form_id ): string {
        if ( '' === $form_id ) {
            return '';
        }

        foreach ( PPH_Plugin::forms() as $form ) {
            if ( ! isset( $form['hipaatizer_form_id'] ) || (string) $form['hipaatizer_form_id'] !== $form_id ) {
                continue;
            }
            $name = isset( $form['email_field_unique_name'] ) ? trim( (string) $form['email_field_unique_name'] ) : 'email';
            $name = preg_replace( '/[^A-Za-z0-9_.-]/', '', $name );
            return is_string( $name ) ? $name : '';
        }

        return '';
    }

    private function normalize_status( string $incoming, string $event ): string {
        $aliases = array(
            'partial'         => 'incomplete',
            'in_progress'     => 'incomplete',
            'inprogress'      => 'incomplete',
            'saved'           => 'incomplete',
            'new'             => 'submitted',
            'created'         => 'submitted',
            'submitted'       => 'submitted',
            'under_review'    => 'under_review',
            'review'          => 'under_review',
            'action_needed'   => 'action_needed',
            'returned'        => 'action_needed',
            'needs_action'    => 'action_needed',
            'complete'        => 'completed',
            'completed'       => 'completed',
            'void'            => 'voided',
            'voided'          => 'voided',
            'deleted'         => 'voided',
        );

        if ( $incoming && isset( $aliases[ $incoming ] ) ) {
            return $aliases[ $incoming ];
        }
        if ( $incoming && isset( PPH_DB::allowed_statuses()[ $incoming ] ) ) {
            return $incoming;
        }

        foreach ( $aliases as $needle => $status ) {
            if ( $event && false !== strpos( $event, $needle ) ) {
                return $status;
            }
        }

        return 'submitted';
    }

    private function normalize_datetime( $value ): ?string {
        if ( empty( $value ) ) {
            return current_time( 'mysql', true );
        }
        if ( is_numeric( $value ) ) {
            $timestamp = (int) $value;
        } else {
            $timestamp = strtotime( (string) $value );
        }
        return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql', true );
    }

    private function first_value( array $payload, array $keys ) {
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $payload ) && '' !== $payload[ $key ] && null !== $payload[ $key ] ) {
                return $payload[ $key ];
            }
        }
        return '';
    }

    private function form_name( string $form_id ): string {
        foreach ( PPH_Plugin::forms() as $form ) {
            if ( isset( $form['hipaatizer_form_id'] ) && (string) $form['hipaatizer_form_id'] === $form_id ) {
                return sanitize_text_field( (string) $form['title'] );
            }
        }
        return 'Patient Form';
    }

    private function record_health( bool $success, string $message ): void {
        update_option(
            'pph_webhook_health',
            array(
                'success' => $success ? 1 : 0,
                'message' => sanitize_text_field( $message ),
                'time'    => current_time( 'mysql', true ),
            ),
            false
        );
    }
}
