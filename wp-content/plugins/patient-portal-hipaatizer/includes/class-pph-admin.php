<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PPH_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_pph_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_pph_save_form', array( $this, 'save_form' ) );
        add_action( 'admin_post_pph_delete_form', array( $this, 'delete_form' ) );
        add_action( 'admin_post_pph_update_submission_status', array( $this, 'update_submission_status' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( PPH_FILE ), array( $this, 'plugin_links' ) );
        add_filter( 'custom_menu_order', array( $this, 'custom_menu_order' ) );
        add_filter( 'menu_order', array( $this, 'menu_order' ) );
        add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
    }

    public function menu(): void {
        add_menu_page(
            'Patient Portal',
            'Patient Portal',
            'manage_patient_portal',
            'pph-dashboard',
            array( $this, 'dashboard_page' ),
            'dashicons-id-alt',
            3
        );
        add_submenu_page( 'pph-dashboard', 'Patient Portal Overview', 'Overview', 'manage_patient_portal', 'pph-dashboard', array( $this, 'dashboard_page' ) );
        add_submenu_page( 'pph-dashboard', 'Forms', 'Forms', 'manage_patient_portal', 'pph-forms', array( $this, 'forms_page' ) );
        add_submenu_page( 'pph-dashboard', 'Submissions', 'Submissions', 'manage_patient_portal', 'pph-submissions', array( $this, 'submissions_page' ) );

        if ( current_user_can( 'manage_options' ) ) {
            add_submenu_page( 'pph-dashboard', 'Settings', 'Settings', 'manage_options', 'pph-settings', array( $this, 'settings_page' ) );
        }

        $settings = PPH_Plugin::settings();
        if ( PPH_Plugin::is_client_dashboard_user() && ! empty( $settings['client_hide_wp_dashboard'] ) ) {
            remove_menu_page( 'index.php' );
        }
    }

    public function enqueue_assets( $hook ): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 0 !== strpos( $page, 'pph-' ) ) {
            return;
        }
        wp_enqueue_style( 'pph-admin', PPH_URL . 'assets/admin.css', array(), PPH_VERSION );
    }

    public function admin_body_class( string $classes ): string {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 0 === strpos( $page, 'pph-' ) ) {
            $classes .= ' pph-admin-page';
        }
        return $classes;
    }

    public function custom_menu_order( $custom ) {
        return PPH_Plugin::is_client_dashboard_user() ? true : $custom;
    }

    public function menu_order( array $menu_order ): array {
        if ( ! PPH_Plugin::is_client_dashboard_user() ) {
            return $menu_order;
        }

        $preferred = array( 'pph-dashboard', 'upload.php' );
        $result    = array();

        foreach ( $preferred as $slug ) {
            if ( in_array( $slug, $menu_order, true ) ) {
                $result[] = $slug;
            }
        }

        foreach ( $menu_order as $slug ) {
            if ( ! in_array( $slug, $result, true ) ) {
                $result[] = $slug;
            }
        }
        return $result;
    }

    public function plugin_links( array $links ): array {
        $url = current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=pph-settings' ) : admin_url( 'admin.php?page=pph-dashboard' );
        $label = current_user_can( 'manage_options' ) ? 'Settings' : 'Overview';
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' );
        return $links;
    }

    public function dashboard_page(): void {
        $settings = PPH_Plugin::settings();
        $days     = isset( $settings['client_dashboard_period'] ) ? absint( $settings['client_dashboard_period'] ) : 14;
        $days     = in_array( $days, array( 7, 14, 30, 60 ), true ) ? $days : 14;
        $data     = PPH_DB::dashboard_data( $days );
        $statuses = PPH_DB::allowed_statuses();
        $user     = wp_get_current_user();
        $name     = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
        $name     = $name ?: $user->display_name;
        $accent   = sanitize_hex_color( (string) $settings['accent_color'] ) ?: '#2563eb';
        $job_url  = $this->find_job_menu_url();
        $forms    = PPH_Plugin::forms();
        $active_forms = count( array_filter( $forms, static function ( $form ) { return ! empty( $form['active'] ); } ) );
        ?>
        <div class="wrap pph-dashboard" style="--pph-accent:<?php echo esc_attr( $accent ); ?>;">
            <div class="pph-hero">
                <div>
                    <div class="pph-eyebrow">Patient Portal</div>
                    <h1>Welcome back, <?php echo esc_html( $name ); ?></h1>
                    <p>Here is a quick view of patient form activity and items that may need attention.</p>
                </div>
                <div class="pph-hero-actions">
                    <a class="pph-btn pph-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=pph-submissions' ) ); ?>"><span class="dashicons dashicons-list-view"></span> View submissions</a>
                    <?php if ( current_user_can( 'manage_patient_portal' ) ) : ?>
                        <a class="pph-btn pph-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=pph-forms' ) ); ?>"><span class="dashicons dashicons-feedback"></span> Manage forms</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pph-stat-grid">
                <?php $this->stat_card( 'Total Submissions', (int) $data['total'], 'dashicons-forms', 'All linked form submissions', 'blue' ); ?>
                <?php $this->stat_card( 'Submitted Today', (int) $data['today'], 'dashicons-calendar-alt', 'New submissions today', 'indigo' ); ?>
                <?php $this->stat_card( 'Processing', (int) $data['counts']['under_review'], 'dashicons-search', 'Currently being processed', 'orange' ); ?>
                <?php $this->stat_card( 'Action Needed', (int) $data['counts']['action_needed'], 'dashicons-warning', 'Requires follow-up', 'red' ); ?>
                <?php $this->stat_card( 'Completed', (int) $data['counts']['completed'], 'dashicons-yes-alt', 'Finished submissions', 'green' ); ?>
            </div>

            <div class="pph-dashboard-grid pph-dashboard-grid-main">
                <section class="pph-panel pph-panel-wide">
                    <div class="pph-panel-head">
                        <div>
                            <h2>Submission Activity</h2>
                            <p>New linked submissions over the last <?php echo (int) $days; ?> days.</p>
                        </div>
                        <span class="pph-pill"><?php echo (int) $data['patients']; ?> patient accounts</span>
                    </div>
                    <?php $this->render_trend_chart( (array) $data['trend'] ); ?>
                </section>

                <section class="pph-panel">
                    <div class="pph-panel-head">
                        <div>
                            <h2>Status Breakdown</h2>
                            <p>Current submission statuses.</p>
                        </div>
                    </div>
                    <?php $this->render_status_donut( (array) $data['counts'], $statuses ); ?>
                </section>
            </div>

            <div class="pph-dashboard-grid">
                <section class="pph-panel">
                    <div class="pph-panel-head">
                        <div>
                            <h2>Forms by Activity</h2>
                            <p>Most-used forms in the portal.</p>
                        </div>
                        <span class="pph-pill"><?php echo (int) $active_forms; ?> active forms</span>
                    </div>
                    <?php $this->render_form_bars( (array) $data['forms'] ); ?>
                </section>

                <section class="pph-panel">
                    <div class="pph-panel-head">
                        <div>
                            <h2>Quick Actions</h2>
                            <p>Common administrative tasks.</p>
                        </div>
                    </div>
                    <div class="pph-quick-actions">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=pph-submissions' ) ); ?>"><span class="dashicons dashicons-list-view"></span><strong>Patient Submissions</strong><small>Review and update statuses</small></a>
                        <a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><span class="dashicons dashicons-admin-media"></span><strong>Media Library</strong><small>View uploaded files</small></a>
                        <?php if ( $job_url ) : ?>
                            <a href="<?php echo esc_url( $job_url ); ?>"><span class="dashicons dashicons-portfolio"></span><strong>Job List</strong><small>Open jobs management</small></a>
                        <?php endif; ?>
                        <?php if ( current_user_can( 'manage_options' ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pph-settings' ) ); ?>"><span class="dashicons dashicons-admin-generic"></span><strong>Portal Settings</strong><small>Configure portal and client access</small></a>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <section class="pph-panel pph-recent-panel">
                <div class="pph-panel-head">
                    <div>
                        <h2>Recent Submissions</h2>
                        <p>The latest webhook-linked patient form activity.</p>
                    </div>
                    <a class="pph-text-link" href="<?php echo esc_url( admin_url( 'admin.php?page=pph-submissions' ) ); ?>">View all <span aria-hidden="true">→</span></a>
                </div>
                <?php $this->render_recent_submissions( (array) $data['recent'], $statuses ); ?>
            </section>
        </div>
        <?php
    }

    private function stat_card( string $label, int $value, string $icon, string $note, string $tone ): void {
        ?>
        <div class="pph-stat-card pph-tone-<?php echo esc_attr( $tone ); ?>">
            <div class="pph-stat-icon"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span></div>
            <div class="pph-stat-content">
                <span class="pph-stat-label"><?php echo esc_html( $label ); ?></span>
                <strong><?php echo number_format_i18n( $value ); ?></strong>
                <small><?php echo esc_html( $note ); ?></small>
            </div>
        </div>
        <?php
    }

    private function render_trend_chart( array $trend ): void {
        if ( empty( $trend ) ) {
            echo '<div class="pph-empty">No submission activity yet.</div>';
            return;
        }

        $max = 1;
        foreach ( $trend as $point ) {
            $max = max( $max, (int) $point['count'] );
        }
        ?>
        <div class="pph-bar-chart" aria-label="Submission activity chart">
            <div class="pph-chart-gridline pph-gridline-25"></div>
            <div class="pph-chart-gridline pph-gridline-50"></div>
            <div class="pph-chart-gridline pph-gridline-75"></div>
            <?php foreach ( $trend as $index => $point ) :
                $count  = (int) $point['count'];
                $height = $count > 0 ? max( 7, round( ( $count / $max ) * 100, 2 ) ) : 2;
                $show_label = count( $trend ) <= 14 || 0 === $index % 3 || $index === count( $trend ) - 1;
                ?>
                <div class="pph-bar-column" title="<?php echo esc_attr( (string) $point['label'] . ': ' . $count . ' submissions' ); ?>">
                    <span class="pph-bar-value"><?php echo $count ? (int) $count : ''; ?></span>
                    <span class="pph-bar" style="height:<?php echo esc_attr( (string) $height ); ?>%;"></span>
                    <span class="pph-bar-label"><?php echo $show_label ? esc_html( (string) $point['label'] ) : '&nbsp;'; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_status_donut( array $counts, array $statuses ): void {
        $colors = array(
            'incomplete'    => '#94a3b8',
            'submitted'     => '#3b82f6',
            'under_review'  => '#f59e0b',
            'action_needed' => '#ef4444',
            'completed'     => '#22c55e',
            'voided'        => '#64748b',
        );
        $total = max( 0, array_sum( array_map( 'intval', $counts ) ) );
        $start = 0;
        $segments = array();
        foreach ( $statuses as $key => $label ) {
            $count = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
            if ( $count <= 0 || $total <= 0 ) {
                continue;
            }
            $degrees = ( $count / $total ) * 360;
            $end = $start + $degrees;
            $segments[] = sprintf( '%s %.2fdeg %.2fdeg', $colors[ $key ], $start, $end );
            $start = $end;
        }
        $gradient = $segments ? 'conic-gradient(' . implode( ',', $segments ) . ')' : 'conic-gradient(#e2e8f0 0 360deg)';
        ?>
        <div class="pph-status-layout">
            <div class="pph-donut" style="background:<?php echo esc_attr( $gradient ); ?>;">
                <div class="pph-donut-center"><strong><?php echo number_format_i18n( $total ); ?></strong><span>Total</span></div>
            </div>
            <div class="pph-status-legend">
                <?php foreach ( $statuses as $key => $label ) :
                    $count = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
                    ?>
                    <div><span class="pph-legend-dot" style="background:<?php echo esc_attr( $colors[ $key ] ); ?>;"></span><span><?php echo esc_html( $label ); ?></span><strong><?php echo number_format_i18n( $count ); ?></strong></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_form_bars( array $forms ): void {
        if ( empty( $forms ) ) {
            echo '<div class="pph-empty">No form activity yet.</div>';
            return;
        }
        $max = 1;
        foreach ( $forms as $row ) {
            $max = max( $max, (int) $row['total'] );
        }
        echo '<div class="pph-form-bars">';
        foreach ( $forms as $row ) {
            $count = (int) $row['total'];
            $pct   = max( 4, round( ( $count / $max ) * 100, 2 ) );
            echo '<div class="pph-form-row"><div class="pph-form-row-head"><span>' . esc_html( (string) $row['form_label'] ) . '</span><strong>' . number_format_i18n( $count ) . '</strong></div><div class="pph-progress"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div></div>';
        }
        echo '</div>';
    }

    private function render_recent_submissions( array $rows, array $statuses ): void {
        if ( empty( $rows ) ) {
            echo '<div class="pph-empty">No webhook-linked submissions yet.</div>';
            return;
        }
        ?>
        <div class="pph-table-wrap">
            <table class="pph-table">
                <thead><tr><th>Patient</th><th>Form</th><th>Status</th><th>Updated</th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $patient = get_user_by( 'id', (int) $row['user_id'] );
                    $display = $patient ? $patient->display_name : 'Deleted user';
                    $status  = isset( $statuses[ $row['status'] ] ) ? $statuses[ $row['status'] ] : ucwords( str_replace( '_', ' ', (string) $row['status'] ) );
                    ?>
                    <tr>
                        <td><div class="pph-patient-cell"><span class="pph-avatar"><?php echo esc_html( strtoupper( substr( $display, 0, 1 ) ) ); ?></span><strong><?php echo esc_html( $display ); ?></strong></div></td>
                        <td><?php echo esc_html( $row['form_name'] ?: $row['form_id'] ); ?></td>
                        <td><?php echo $this->status_badge( (string) $row['status'], (string) $status ); ?></td>
                        <td><?php echo esc_html( get_date_from_gmt( $row['updated_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function status_badge( string $status, string $label ): string {
        return '<span class="pph-status-badge pph-status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
    }

    private function find_job_menu_url(): string {
        global $menu;
        if ( ! is_array( $menu ) ) {
            return '';
        }
        foreach ( $menu as $item ) {
            if ( empty( $item[0] ) || empty( $item[2] ) ) {
                continue;
            }
            $title = strtolower( wp_strip_all_tags( (string) $item[0] ) );
            $slug  = (string) $item[2];
            if ( false === strpos( $title, 'job' ) && false === strpos( strtolower( $slug ), 'job' ) ) {
                continue;
            }
            if ( false !== strpos( $slug, '.php' ) ) {
                return admin_url( ltrim( $slug, '/' ) );
            }
            return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
        }
        return '';
    }

    public function forms_page(): void {
        $forms    = PPH_Plugin::forms();
        $edit_key = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
        $editing  = $edit_key && isset( $forms[ $edit_key ] ) ? $forms[ $edit_key ] : array();
        ?>
        <div class="wrap pph-standard-page">
            <div class="pph-page-title"><div><span class="pph-eyebrow">Patient Portal</span><h1>Patient Forms</h1><p>Configure the HIPAAtizer forms available to your patients.</p></div></div>
            <?php $this->notice(); ?>

            <div class="pph-panel pph-form-config-panel">
                <h2><?php echo $editing ? 'Edit Form' : 'Add Form'; ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px;">
                    <input type="hidden" name="action" value="pph_save_form">
                    <input type="hidden" name="existing_key" value="<?php echo esc_attr( $edit_key ); ?>">
                    <?php wp_nonce_field( 'pph_save_form', 'pph_nonce' ); ?>
                    <table class="form-table">
                        <tr><th><label for="pph_title">Form title</label></th><td><input class="regular-text" id="pph_title" name="title" required value="<?php echo esc_attr( (string) ( $editing['title'] ?? '' ) ); ?>"></td></tr>
                        <tr><th><label for="pph_key">Form key</label></th><td><input class="regular-text" id="pph_key" name="form_key" value="<?php echo esc_attr( $edit_key ?: (string) ( $editing['key'] ?? '' ) ); ?>"><p class="description">Short identifier used by <code>[patient_form key="..."]</code>. Leave blank to generate from the title.</p></td></tr>
                        <tr><th><label for="pph_hid">HIPAAtizer Form ID</label></th><td><input class="regular-text" id="pph_hid" name="hipaatizer_form_id" required value="<?php echo esc_attr( (string) ( $editing['hipaatizer_form_id'] ?? '' ) ); ?>"><p class="description">This must match the <code>form_id</code> sent by the HIPAAtizer webhook.</p></td></tr>
                        <tr><th><label for="pph_embed">HIPAAtizer shortcode or form URL</label></th><td><textarea class="large-text code" rows="4" id="pph_embed" name="embed" required><?php echo esc_textarea( (string) ( $editing['embed'] ?? '' ) ); ?></textarea><p class="description">Use the shortcode generated by the HIPAAtizer WordPress plugin or a published HTTPS form URL.</p></td></tr>
                        <tr>
                            <th><label for="pph_email_unique_name">HIPAAtizer email field Unique Name</label></th>
                            <td>
                                <input class="regular-text code" id="pph_email_unique_name" name="email_field_unique_name" value="<?php echo esc_attr( (string) ( $editing['email_field_unique_name'] ?? 'email' ) ); ?>" placeholder="email">
                                <p class="description">Enter the exact <strong>Unique Name</strong> of the patient email field, for example <code>email</code> or <code>patient_email</code>.</p>
                                <label><input type="checkbox" name="auto_prefill_email" value="1" <?php checked( ! isset( $editing['auto_prefill_email'] ) || ! empty( $editing['auto_prefill_email'] ) ); ?>> Automatically prefill the logged-in patient's email</label>
                            </td>
                        </tr>
                        <tr><th><label for="pph_instructions">Patient instructions</label></th><td><textarea class="large-text" rows="3" id="pph_instructions" name="instructions"><?php echo esc_textarea( (string) ( $editing['instructions'] ?? '' ) ); ?></textarea></td></tr>
                        <tr><th>Availability</th><td><label><input type="checkbox" name="active" value="1" <?php checked( ! isset( $editing['active'] ) || ! empty( $editing['active'] ) ); ?>> Show this form to patients</label></td></tr>
                    </table>
                    <?php submit_button( $editing ? 'Update Form' : 'Add Form' ); ?>
                </form>
            </div>

            <div class="pph-panel" style="margin-top:20px;">
                <div class="pph-panel-head"><div><h2>Configured Forms</h2><p>Forms currently connected to the Patient Portal.</p></div></div>
                <div class="pph-table-wrap">
                    <table class="pph-table">
                        <thead><tr><th>Form</th><th>Form Key</th><th>HIPAAtizer ID</th><th>Active</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if ( ! $forms ) : ?><tr><td colspan="5">No forms configured yet.</td></tr><?php endif; ?>
                        <?php foreach ( $forms as $key => $form ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( (string) $form['title'] ); ?></strong></td>
                                <td><code><?php echo esc_html( $key ); ?></code></td>
                                <td><code><?php echo esc_html( (string) $form['hipaatizer_form_id'] ); ?></code></td>
                                <td><?php echo ! empty( $form['active'] ) ? '<span class="pph-status-badge pph-status-completed">Active</span>' : '<span class="pph-status-badge pph-status-incomplete">Hidden</span>'; ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=pph-forms&edit=' . rawurlencode( $key ) ) ); ?>">Edit</a> |
                                    <a style="color:#b32d2e" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pph_delete_form&key=' . rawurlencode( $key ) ), 'pph_delete_form_' . $key ) ); ?>" onclick="return confirm('Delete this portal form configuration? Existing status records will remain.');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function submissions_page(): void {
        $rows     = PPH_DB::all();
        $statuses = PPH_DB::staff_statuses();
        ?>
        <div class="wrap pph-standard-page">
            <div class="pph-page-title"><div><span class="pph-eyebrow">Patient Portal</span><h1>Patient Submissions</h1><p>Review form activity and update patient-facing statuses.</p></div></div>
            <?php $this->notice(); ?>
            <div class="pph-panel">
                <div class="pph-panel-head"><div><h2>Submission History</h2><p>Clinical form content remains in HIPAAtizer; WordPress stores only portal status metadata.</p></div></div>
                <div class="pph-table-wrap">
                    <table class="pph-table">
                        <thead><tr><th>Patient Account</th><th>Form</th><th>HIPAAtizer Submission ID</th><th>Status</th><th>Last Updated</th></tr></thead>
                        <tbody>
                        <?php if ( ! $rows ) : ?><tr><td colspan="5">No webhook-linked submissions yet.</td></tr><?php endif; ?>
                        <?php foreach ( $rows as $row ) :
                            $patient = get_user_by( 'id', (int) $row['user_id'] );
                            ?>
                            <tr>
                                <td><?php echo $patient ? esc_html( $patient->display_name . ' (' . $patient->user_email . ')' ) : 'Deleted WordPress user'; ?></td>
                                <td><?php echo esc_html( $row['form_name'] ?: $row['form_id'] ); ?></td>
                                <td><code><?php echo esc_html( $row['external_submission_id'] ); ?></code></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pph-status-form">
                                        <input type="hidden" name="action" value="pph_update_submission_status">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <?php wp_nonce_field( 'pph_update_status_' . (int) $row['id'], 'pph_nonce' ); ?>
                                        <select name="status">
                                            <?php foreach ( $statuses as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['status'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
                                        </select>
                                        <button class="button button-small" type="submit">Save</button>
                                    </form>
                                    <?php if ( ! empty( $row['manual_override'] ) ) : ?><small>Manual override</small><?php endif; ?>
                                </td>
                                <td><?php echo esc_html( get_date_from_gmt( $row['updated_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access these settings.', 'patient-portal-hipaatizer' ) );
        }

        $s      = PPH_Plugin::settings();
        $pages  = get_pages( array( 'post_status' => array( 'publish', 'draft', 'private' ) ) );
        $secret = defined( 'PPH_WEBHOOK_SECRET' ) ? 'Configured in wp-config.php' : ( $s['webhook_secret'] ? str_repeat( '•', 12 ) : '' );
        $bearer = defined( 'PPH_WEBHOOK_BEARER_TOKEN' ) ? 'Configured in wp-config.php' : ( $s['webhook_bearer_token'] ? str_repeat( '•', 12 ) : '' );
        $staff_users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
        ?>
        <div class="wrap pph-standard-page">
            <div class="pph-page-title"><div><span class="pph-eyebrow">Patient Portal</span><h1>Settings</h1><p>Configure patient pages, client dashboard access and HIPAAtizer connectivity.</p></div></div>
            <?php $this->notice(); ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="pph_save_settings">
                <?php wp_nonce_field( 'pph_save_settings', 'pph_nonce' ); ?>

                <div class="pph-panel pph-settings-section">
                    <h2>Client Admin Dashboard</h2>
                    <p>Select the staff account(s) that should land on the modern Patient Portal overview after logging into WordPress.</p>
                    <table class="form-table">
                        <tr>
                            <th>Client dashboard users</th>
                            <td>
                                <div class="pph-user-picker">
                                    <?php foreach ( $staff_users as $staff_user ) :
                                        if ( ! $staff_user instanceof WP_User || PPH_Plugin::is_patient( $staff_user ) ) {
                                            continue;
                                        }
                                        $roles = array_map( static function ( $role ) { return ucwords( str_replace( '_', ' ', $role ) ); }, (array) $staff_user->roles );
                                        ?>
                                        <label><input type="checkbox" name="client_dashboard_user_ids[]" value="<?php echo (int) $staff_user->ID; ?>" <?php checked( in_array( (int) $staff_user->ID, (array) $s['client_dashboard_user_ids'], true ) ); ?>> <strong><?php echo esc_html( $staff_user->display_name ); ?></strong> <span><?php echo esc_html( implode( ', ', $roles ) ); ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description">For your current setup, select <strong>Briggs Admin</strong>. This selection is based on the WordPress user ID, not the display name.</p>
                            </td>
                        </tr>
                        <tr><th>Login behavior</th><td><label><input type="checkbox" name="client_redirect_enabled" value="1" <?php checked( ! empty( $s['client_redirect_enabled'] ) ); ?>> Send selected users directly to Patient Portal Overview after login</label><br><label><input type="checkbox" name="client_hide_wp_dashboard" value="1" <?php checked( ! empty( $s['client_hide_wp_dashboard'] ) ); ?>> Hide the standard WordPress Dashboard menu for selected users</label></td></tr>
                        <tr><th>Chart period</th><td><select name="client_dashboard_period"><option value="7" <?php selected( (int) $s['client_dashboard_period'], 7 ); ?>>Last 7 days</option><option value="14" <?php selected( (int) $s['client_dashboard_period'], 14 ); ?>>Last 14 days</option><option value="30" <?php selected( (int) $s['client_dashboard_period'], 30 ); ?>>Last 30 days</option><option value="60" <?php selected( (int) $s['client_dashboard_period'], 60 ); ?>>Last 60 days</option></select></td></tr>
                    </table>
                    <p class="description"><strong>Optional safer role:</strong> this version also adds a <em>Patient Portal Manager</em> WordPress role with Media Library + Patient Portal capabilities. Job List capability varies by the Jobs plugin, so do not switch the client from Administrator to this role until the Jobs menu is confirmed to work.</p>
                </div>

                <div class="pph-panel pph-settings-section">
                    <h2>Portal</h2>
                    <table class="form-table">
                        <tr><th>Patient portal page</th><td><?php $this->page_select( 'portal_page_id', (int) $s['portal_page_id'], $pages ); ?></td></tr>
                        <tr><th>Login page</th><td><?php $this->page_select( 'login_page_id', (int) $s['login_page_id'], $pages ); ?></td></tr>
                        <tr><th>Registration page</th><td><?php $this->page_select( 'registration_page_id', (int) $s['registration_page_id'], $pages ); ?><p class="description">Use a page containing <code>[patient_register]</code>.</p></td></tr>
                        <tr><th>Patient registration</th><td><label><input type="checkbox" name="allow_registration" value="1" <?php checked( ! empty( $s['allow_registration'] ) ); ?>> Allow new patients to create their own portal accounts</label><p class="description">New self-registered patients must verify their email before they can sign in.</p></td></tr>
                        <tr><th>Accent color</th><td><input type="color" name="accent_color" value="<?php echo esc_attr( sanitize_hex_color( (string) $s['accent_color'] ) ?: '#2563eb' ); ?>"></td></tr>
                        <tr><th>Patient restrictions</th><td><label><input type="checkbox" name="hide_admin_bar" value="1" <?php checked( ! empty( $s['hide_admin_bar'] ) ); ?>> Hide WordPress admin bar for Patient users</label><br><label><input type="checkbox" name="block_patient_admin" value="1" <?php checked( ! empty( $s['block_patient_admin'] ) ); ?>> Redirect Patient users away from wp-admin</label></td></tr>
                    </table>
                </div>

                <div class="pph-panel pph-settings-section">
                    <h2>HIPAAtizer Webhook</h2>
                    <p><strong>Endpoint:</strong> <code><?php echo esc_html( rest_url( 'patient-portal/v1/hipaatizer' ) ); ?></code></p>
                    <table class="form-table">
                        <tr><th>Authentication mode</th><td><select name="webhook_auth_mode"><option value="hmac" <?php selected( $s['webhook_auth_mode'], 'hmac' ); ?>>HIPAA-Signature HMAC</option><option value="bearer" <?php selected( $s['webhook_auth_mode'], 'bearer' ); ?>>Authorization Bearer token</option><option value="both" <?php selected( $s['webhook_auth_mode'], 'both' ); ?>>Require both</option></select></td></tr>
                        <tr><th>HMAC algorithm</th><td><select name="webhook_hmac_algo"><option value="sha256" <?php selected( $s['webhook_hmac_algo'], 'sha256' ); ?>>SHA-256</option><option value="sha512" <?php selected( $s['webhook_hmac_algo'], 'sha512' ); ?>>SHA-512</option><option value="sha1" <?php selected( $s['webhook_hmac_algo'], 'sha1' ); ?>>SHA-1</option></select></td></tr>
                        <tr><th>Webhook secret</th><td><input type="password" class="regular-text" name="webhook_secret" value="" placeholder="<?php echo esc_attr( $secret ?: 'Enter webhook signing secret' ); ?>" autocomplete="new-password"><p class="description">Leave blank to keep the existing value.</p></td></tr>
                        <tr><th>Bearer token</th><td><input type="password" class="regular-text" name="webhook_bearer_token" value="" placeholder="<?php echo esc_attr( $bearer ?: 'Optional bearer token' ); ?>" autocomplete="new-password"><p class="description">Leave blank to keep the existing value.</p></td></tr>
                    </table>
                </div>

                <div class="pph-panel pph-settings-section">
                    <h2>Data</h2>
                    <table class="form-table"><tr><th>Uninstall behavior</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( ! empty( $s['delete_on_uninstall'] ) ); ?>> Delete this plugin's status table and settings when the plugin is deleted</label></td></tr></table>
                </div>

                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <div class="pph-panel pph-settings-section">
                <h2>Recommended Minimal HIPAAtizer Payload</h2>
                <p>Map only the fields the portal needs. Avoid sending clinical answers or other PHI that is not needed for portal routing.</p>
                <pre class="pph-code-block">{
  "event": "submitted",
  "form_id": "[map HIPAAtizer Form ID]",
  "submission_id": "[map HIPAAtizer Submission ID]",
  "patient_email": "[map the patient's email field]",
  "status": "submitted",
  "submitted_at": "[optional submission timestamp]"
}</pre>
            </div>
        </div>
        <?php
    }

    public function save_settings(): void {
        $this->guard( 'pph_save_settings', 'manage_options' );
        $old = PPH_Plugin::settings();

        $client_ids = isset( $_POST['client_dashboard_user_ids'] ) && is_array( $_POST['client_dashboard_user_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['client_dashboard_user_ids'] ) ) ) ) )
            : array();
        $period = isset( $_POST['client_dashboard_period'] ) ? absint( $_POST['client_dashboard_period'] ) : 14;
        if ( ! in_array( $period, array( 7, 14, 30, 60 ), true ) ) {
            $period = 14;
        }

        $settings = array(
            'portal_page_id'              => isset( $_POST['portal_page_id'] ) ? absint( $_POST['portal_page_id'] ) : 0,
            'login_page_id'               => isset( $_POST['login_page_id'] ) ? absint( $_POST['login_page_id'] ) : 0,
            'registration_page_id'        => isset( $_POST['registration_page_id'] ) ? absint( $_POST['registration_page_id'] ) : 0,
            'allow_registration'          => ! empty( $_POST['allow_registration'] ) ? 1 : 0,
            'accent_color'                => sanitize_hex_color( isset( $_POST['accent_color'] ) ? wp_unslash( $_POST['accent_color'] ) : '' ) ?: '#2563eb',
            'hide_admin_bar'              => ! empty( $_POST['hide_admin_bar'] ) ? 1 : 0,
            'block_patient_admin'         => ! empty( $_POST['block_patient_admin'] ) ? 1 : 0,
            'webhook_auth_mode'           => isset( $_POST['webhook_auth_mode'] ) && in_array( $_POST['webhook_auth_mode'], array( 'hmac', 'bearer', 'both' ), true ) ? sanitize_key( $_POST['webhook_auth_mode'] ) : 'hmac',
            'webhook_hmac_algo'           => isset( $_POST['webhook_hmac_algo'] ) && in_array( $_POST['webhook_hmac_algo'], array( 'sha256', 'sha512', 'sha1' ), true ) ? sanitize_key( $_POST['webhook_hmac_algo'] ) : 'sha256',
            'webhook_secret'              => ! empty( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : (string) $old['webhook_secret'],
            'webhook_bearer_token'        => ! empty( $_POST['webhook_bearer_token'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_bearer_token'] ) ) : (string) $old['webhook_bearer_token'],
            'delete_on_uninstall'         => ! empty( $_POST['delete_on_uninstall'] ) ? 1 : 0,
            'client_dashboard_user_ids'   => $client_ids,
            'client_redirect_enabled'     => ! empty( $_POST['client_redirect_enabled'] ) ? 1 : 0,
            'client_hide_wp_dashboard'    => ! empty( $_POST['client_hide_wp_dashboard'] ) ? 1 : 0,
            'client_dashboard_period'     => $period,
        );

        update_option( 'pph_settings', $settings, false );
        update_option( 'pph_portal_page_id', $settings['portal_page_id'], false );
        update_option( 'pph_login_page_id', $settings['login_page_id'], false );
        update_option( 'pph_registration_page_id', $settings['registration_page_id'], false );
        $this->redirect( 'pph-settings', 'saved' );
    }

    public function save_form(): void {
        $this->guard( 'pph_save_form', 'manage_patient_portal' );
        $forms        = PPH_Plugin::forms();
        $existing_key = isset( $_POST['existing_key'] ) ? sanitize_key( wp_unslash( $_POST['existing_key'] ) ) : '';
        $title        = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $requested    = isset( $_POST['form_key'] ) ? sanitize_key( wp_unslash( $_POST['form_key'] ) ) : '';
        $key          = $requested ?: sanitize_title( $title );

        if ( '' === $title || '' === $key ) {
            $this->redirect( 'pph-forms', 'error' );
        }

        if ( $existing_key && $existing_key !== $key ) {
            unset( $forms[ $existing_key ] );
        }

        $forms[ $key ] = array(
            'key'                     => $key,
            'title'                   => $title,
            'hipaatizer_form_id'      => isset( $_POST['hipaatizer_form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['hipaatizer_form_id'] ) ) : '',
            'embed'                   => isset( $_POST['embed'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['embed'] ) ) ) : '',
            'email_field_unique_name' => $this->sanitize_hipaatizer_field_name( isset( $_POST['email_field_unique_name'] ) ? wp_unslash( $_POST['email_field_unique_name'] ) : 'email' ),
            'auto_prefill_email'      => ! empty( $_POST['auto_prefill_email'] ) ? 1 : 0,
            'instructions'            => isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '',
            'active'                  => ! empty( $_POST['active'] ) ? 1 : 0,
        );

        update_option( 'pph_forms', $forms, false );
        $this->redirect( 'pph-forms', 'saved' );
    }

    private function sanitize_hipaatizer_field_name( $value ): string {
        $value = trim( (string) $value );
        $value = preg_replace( '/[^A-Za-z0-9_.-]/', '', $value );
        return is_string( $value ) && '' !== $value ? $value : 'email';
    }

    public function delete_form(): void {
        if ( ! current_user_can( 'manage_patient_portal' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'patient-portal-hipaatizer' ) );
        }
        $key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
        check_admin_referer( 'pph_delete_form_' . $key );
        $forms = PPH_Plugin::forms();
        unset( $forms[ $key ] );
        update_option( 'pph_forms', $forms, false );
        $this->redirect( 'pph-forms', 'deleted' );
    }

    public function update_submission_status(): void {
        if ( ! current_user_can( 'manage_patient_portal' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'patient-portal-hipaatizer' ) );
        }
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        check_admin_referer( 'pph_update_status_' . $id, 'pph_nonce' );
        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        if ( ! isset( PPH_DB::staff_statuses()[ $status ] ) ) {
            $this->redirect( 'pph-submissions', 'error' );
        }
        PPH_DB::update_status( $id, $status, true );
        $this->redirect( 'pph-submissions', 'saved' );
    }

    private function guard( string $action, string $capability = 'manage_patient_portal' ): void {
        if ( ! current_user_can( $capability ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'patient-portal-hipaatizer' ) );
        }
        if ( ! isset( $_POST['pph_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pph_nonce'] ) ), $action ) ) {
            wp_die( esc_html__( 'Security check failed.', 'patient-portal-hipaatizer' ) );
        }
    }

    private function redirect( string $page, string $notice ): void {
        wp_safe_redirect( add_query_arg( array( 'page' => $page, 'pph_notice' => $notice ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private function notice(): void {
        $notice = isset( $_GET['pph_notice'] ) ? sanitize_key( wp_unslash( $_GET['pph_notice'] ) ) : '';
        if ( ! $notice ) {
            return;
        }
        $messages = array( 'saved' => 'Saved successfully.', 'deleted' => 'Deleted successfully.', 'error' => 'Please check the required values and try again.' );
        if ( isset( $messages[ $notice ] ) ) {
            echo '<div class="notice ' . ( 'error' === $notice ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
        }
    }

    private function page_select( string $name, int $selected, array $pages ): void {
        echo '<select name="' . esc_attr( $name ) . '"><option value="0">— Select page —</option>';
        foreach ( $pages as $page ) {
            echo '<option value="' . (int) $page->ID . '" ' . selected( $selected, (int) $page->ID, false ) . '>' . esc_html( $page->post_title ) . '</option>';
        }
        echo '</select>';
    }
}
