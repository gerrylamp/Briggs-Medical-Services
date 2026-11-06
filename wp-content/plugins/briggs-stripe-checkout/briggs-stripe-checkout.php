<?php
/**
 * Plugin Name: Briggs Stripe Checkout
 * Description: Stripe Checkout integration with server-side payment validation for Briggs Medical Services phlebotomy class registrations.
 * Version: 0.2.0
 * Author: Briggs Medical Services
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

final class Briggs_Stripe_Checkout {
    const OPT = 'briggs_stripe_checkout_settings';
    const NONCE = 'briggs_stripe_checkout_nonce';
    const NS = 'briggs-stripe/v1';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'maybe_create_payments_table'], 1);
        add_shortcode('briggs_stripe_checkout', [$this, 'shortcode']);
        add_action('wp_ajax_briggs_create_checkout', [$this, 'create_checkout']);
        add_action('wp_ajax_nopriv_briggs_create_checkout', [$this, 'create_checkout']);
        add_action('wp_ajax_briggs_payment_status', [$this, 'payment_status']);
        add_action('wp_ajax_nopriv_briggs_payment_status', [$this, 'payment_status']);
        add_action('rest_api_init', [$this, 'webhook_route']);
        add_action('elementor_pro/forms/validation', [$this, 'validate_registration_payment'], 10, 2);
        add_action('elementor_pro/forms/new_record', [$this, 'consume_registration_payment'], 10, 2);
    }
    private function settings() { $s = get_option(self::OPT, []); return is_array($s) ? $s : []; }
    private function key() { $s = $this->settings(); return trim((string)($s['secret_key'] ?? '')); }
    private function whsec() { $s = $this->settings(); return trim((string)($s['webhook_secret'] ?? '')); }
    public function menu() { add_options_page('Briggs Stripe Checkout', 'Briggs Stripe Checkout', 'manage_options', 'briggs-stripe-checkout', [$this, 'settings_page']); }
    public function register_settings() {
        register_setting('briggs_stripe_group', self::OPT, ['type'=>'array', 'sanitize_callback'=>[$this,'sanitize_settings'], 'default'=>[]]);
    }
    public function sanitize_settings($in) {
        $old = $this->settings(); $in = is_array($in) ? $in : [];
        $key = trim((string)($in['secret_key'] ?? '')); $wh = trim((string)($in['webhook_secret'] ?? ''));
        return [
            'secret_key' => $key !== '' ? sanitize_text_field($key) : (string)($old['secret_key'] ?? ''),
            'webhook_secret' => $wh !== '' ? sanitize_text_field($wh) : (string)($old['webhook_secret'] ?? ''),
        ];
    }
    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->settings(); $url = rest_url(self::NS.'/webhook'); ?>
        <div class="wrap"><h1>Briggs Stripe Checkout</h1>
        <p><strong>Choose matching Stripe credentials.</strong> Use a test key and test webhook secret for testing, or a live key and live webhook secret for real payments. Never mix modes. Back up this plugin and test on staging before launch.</p>
        <p>Webhook endpoint: <code><?php echo esc_html($url); ?></code></p>
        <p>In the same Stripe mode as your API key, create a webhook endpoint for this URL and subscribe to <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, and <code>checkout.session.expired</code>. Paste that endpoint's signing secret below. Keep test and live webhook secrets separate.</p>
        <form method="post" action="options.php"><?php settings_fields('briggs_stripe_group'); ?>
        <table class="form-table"><tr><th><label for="briggs-sk">Restricted Stripe secret key</label></th><td>
        <input type="password" autocomplete="new-password" class="regular-text" id="briggs-sk" name="<?php echo esc_attr(self::OPT); ?>[secret_key]" value="" />
        <p class="description"><?php echo !empty($s['secret_key']) ? 'A key is already saved. Leave blank to keep it.' : 'Paste a restricted Stripe secret key: rk_test_ for test mode or rk_live_ for live mode.'; ?></p></td></tr>
        <tr><th><label for="briggs-wh">Webhook signing secret</label></th><td>
        <input type="password" autocomplete="new-password" class="regular-text" id="briggs-wh" name="<?php echo esc_attr(self::OPT); ?>[webhook_secret]" value="" />
        <p class="description"><?php echo !empty($s['webhook_secret']) ? 'A webhook secret is already saved. Leave blank to keep it.' : 'Paste the signing secret (whsec_) for the webhook endpoint in the same Stripe mode.'; ?></p></td></tr></table>
        <?php submit_button('Save Stripe Settings'); ?></form>
        <hr><h2>Elementor shortcode</h2><p>Add an Elementor <strong>Shortcode</strong> widget to the Payment step with:</p><p><code>[briggs_stripe_checkout]</code></p>
        <p>After installing, add Elementor Hidden fields with IDs <code>payment_status</code>, <code>amount_paid</code>, <code>payment_option_paid</code>, and <code>stripe_session_id</code>. Keep the existing payment setup until the replacement is tested.</p></div><?php
    }
    public function shortcode() {
        $ready = ($this->key() !== '' && $this->whsec() !== '');
        $sid = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';
        $ret = isset($_GET['briggs_payment_return']) && (string)$_GET['briggs_payment_return'] === '1' && $sid !== '';
        $cancel = isset($_GET['briggs_payment_cancel']) && (string)$_GET['briggs_payment_cancel'] === '1';
        ob_start(); ?>
        <div class="briggs-stripe-checkout" data-ready="<?php echo $ready ? '1':'0'; ?>">
        <?php if ($ret): ?><div class="briggs-payment-return" data-session-id="<?php echo esc_attr($sid); ?>"><p>Confirming your payment with Stripe…</p><p class="briggs-payment-message" aria-live="polite"></p></div>
        <?php elseif (!$ready): ?><p>Online payment is not configured yet. Please contact the site administrator.</p>
        <?php else: ?><?php if ($cancel): ?><p>Payment was cancelled. Return to the original registration tab to try again.</p><?php endif; ?>
        <div class="briggs-payment-buttons"><p><strong>Choose your payment option:</strong></p><button type="button" class="briggs-pay-button" data-choice="deposit">Pay $200 Deposit</button><button type="button" class="briggs-pay-button" data-choice="full">Pay $775 in Full</button><p class="briggs-payment-message" aria-live="polite"></p></div><?php endif; ?></div>
        <style>.briggs-stripe-checkout{margin:16px 0;padding:16px;border:1px solid #ddd;border-radius:8px}.briggs-payment-buttons{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.briggs-payment-buttons p:first-child,.briggs-payment-message{width:100%;margin:0 0 6px}.briggs-pay-button{padding:12px 18px;border:0;border-radius:5px;background:#4b237c;color:#fff;font-weight:600;cursor:pointer}.briggs-pay-button:disabled{opacity:.6;cursor:wait}.briggs-payment-message{font-size:14px}</style>
        <script>(function(){
        const root=document.currentScript.previousElementSibling.previousElementSibling;
        if(!root||!root.classList||!root.classList.contains('briggs-stripe-checkout'))return;
        const ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce=<?php echo wp_json_encode(wp_create_nonce(self::NONCE)); ?>;
        function form(){return document.querySelector('form.elementor-form');}
        function setField(id,value){const f=form();if(!f)return;let el=f.querySelector('#form-field-'+id)||f.querySelector('[name="form_fields['+id+']"]');if(el){el.value=value;el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));}}
        function message(t){const m=root.querySelector('.briggs-payment-message');if(m)m.textContent=t;}
        function paid(data){setField('payment_status','paid');setField('amount_paid',(data.amount/100).toFixed(2));setField('payment_option_paid',data.choice==='full'?'Full payment ($775)':'Deposit ($200)');setField('stripe_session_id',data.session_id);const f=form();if(f){f.querySelectorAll('.elementor-field-type-submit button,.elementor-field-type-submit input[type="submit"]').forEach(b=>{b.disabled=false;b.removeAttribute('aria-disabled');b.style.display='';b.dataset.briggsPaid='1';});}message('Payment verified. You can now click Reserve My Seat to submit your registration.');}
        function syncStep(){if(root.querySelector('.briggs-payment-return'))return;const active=document.querySelector('.e-form__indicators__indicator--state-active');const hasElementorSteps=!!active;const isPayment=!hasElementorSteps||(active.textContent.toLowerCase().includes('payment'));root.style.display=isPayment?'':'none';const f=form();if(f){f.querySelectorAll('.elementor-field-type-submit button,.elementor-field-type-submit input[type="submit"]').forEach(b=>{if(isPayment&&b.dataset.briggsPaid!=='1'){b.disabled=true;b.setAttribute('aria-disabled','true');}else if(!isPayment){b.disabled=false;b.removeAttribute('aria-disabled');}});}}
        function email(){const f=form(),e=f&&f.querySelector('input[type="email"]');return e?e.value.trim():'';}
        root.querySelectorAll('.briggs-pay-button').forEach(btn=>btn.addEventListener('click',async function(){const popup=window.open('about:blank','_blank');if(!popup){message('Allow pop-ups for this site, then try again.');return;}root.querySelectorAll('.briggs-pay-button').forEach(b=>b.disabled=true);message('Connecting securely to Stripe…');try{const body=new URLSearchParams({action:'briggs_create_checkout',nonce:nonce,choice:btn.dataset.choice,email:email(),return_url:location.href.split('?')[0].split('#')[0]});const r=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});const j=await r.json();if(!r.ok||!j.success||!j.data.url)throw new Error(j.data&&j.data.message?j.data.message:'Could not start checkout.');popup.location=j.data.url;message('Stripe Checkout opened in a new tab. Keep this registration tab open.');}catch(e){if(popup&&!popup.closed)popup.close();message(e.message||'Could not start checkout.');root.querySelectorAll('.briggs-pay-button').forEach(b=>b.disabled=false);}}));
        window.addEventListener('message',e=>{if(e.origin!==location.origin||!e.data||e.data.type!=='briggs-payment-verified')return;paid(e.data);root.querySelectorAll('.briggs-pay-button').forEach(b=>b.disabled=false);syncStep();});
        const returnBox=root.querySelector('.briggs-payment-return');if(returnBox){const sid=returnBox.dataset.sessionId;let n=0;const timer=setInterval(async()=>{n++;try{const body=new URLSearchParams({action:'briggs_payment_status',nonce:nonce,session_id:sid});const r=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});const j=await r.json();if(j.success&&j.data&&j.data.status==='paid'){clearInterval(timer);if(window.opener&&!window.opener.closed)window.opener.postMessage({type:'briggs-payment-verified',session_id:j.data.session_id,amount:j.data.amount,choice:j.data.choice},location.origin);message('Payment verified. Returning to your registration…');setTimeout(()=>window.close(),800);}else if(n>=40){clearInterval(timer);message('Payment is not confirmed yet. Keep this tab open and contact the site administrator if this continues.');}}catch(e){if(n>=40)clearInterval(timer);}},1500);}else{syncStep();const f=form();if(f){new MutationObserver(syncStep).observe(f,{subtree:true,childList:true,attributes:true,attributeFilter:['class']});f.addEventListener('click',()=>{setTimeout(syncStep,100);setTimeout(syncStep,500);});}}
        })();</script><?php
        return ob_get_clean();
    }
    public function payments_table() {
        global $wpdb;
        return $wpdb->prefix . 'briggs_stripe_payments';
    }
    public function maybe_create_payments_table() {
        $installed = get_option('briggs_stripe_payments_db_version');
        if ($installed === '1.0') return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->payments_table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(50) NOT NULL DEFAULT 'training_education',
            session_id varchar(255) NOT NULL,
            payment_intent_id varchar(255) NOT NULL DEFAULT '',
            customer_id varchar(255) NOT NULL DEFAULT '',
            customer_name varchar(191) NOT NULL DEFAULT '',
            customer_email varchar(191) NOT NULL DEFAULT '',
            description varchar(255) NOT NULL DEFAULT '',
            choice varchar(50) NOT NULL DEFAULT '',
            amount_cents bigint(20) unsigned NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'usd',
            status varchar(20) NOT NULL DEFAULT 'pending',
            livemode tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            paid_at datetime NULL DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_id (session_id),
            KEY source_status (source,status),
            KEY customer_email (customer_email),
            KEY paid_at (paid_at)
        ) {$charset};";
        dbDelta($sql);
        update_option('briggs_stripe_payments_db_version', '1.0', false);
    }
    private function save_payment_record($data) {
        global $wpdb;
        $this->maybe_create_payments_table();
        $table = $this->payments_table();
        $session_id = sanitize_text_field((string)($data['session_id'] ?? ''));
        if (!$session_id) return false;
        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE session_id = %s", $session_id));
        $row = array(
            'source' => 'training_education',
            'session_id' => $session_id,
            'payment_intent_id' => sanitize_text_field((string)($data['payment_intent_id'] ?? '')),
            'customer_id' => sanitize_text_field((string)($data['customer_id'] ?? '')),
            'customer_name' => sanitize_text_field((string)($data['customer_name'] ?? '')),
            'customer_email' => sanitize_email((string)($data['customer_email'] ?? '')),
            'description' => sanitize_text_field((string)($data['description'] ?? 'Training/Education class registration')),
            'choice' => sanitize_key((string)($data['choice'] ?? '')),
            'amount_cents' => absint($data['amount_cents'] ?? 0),
            'currency' => sanitize_key((string)($data['currency'] ?? 'usd')),
            'status' => in_array(($data['status'] ?? ''), array('pending','paid','expired'), true) ? $data['status'] : 'pending',
            'livemode' => !empty($data['livemode']) ? 1 : 0,
            'created_at' => !empty($data['created_at']) ? gmdate('Y-m-d H:i:s', is_numeric($data['created_at']) ? (int)$data['created_at'] : strtotime($data['created_at'])) : gmdate('Y-m-d H:i:s'),
            'paid_at' => !empty($data['paid_at']) ? (is_numeric($data['paid_at']) ? gmdate('Y-m-d H:i:s', (int)$data['paid_at']) : sanitize_text_field($data['paid_at'])) : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        if ($existing_id) {
            return false !== $wpdb->update($table, $row, array('id' => (int)$existing_id));
        }
        return false !== $wpdb->insert($table, $row);
    }

    private function valid_key($key) { return (strpos($key, 'rk_test_') === 0 || strpos($key, 'sk_test_') === 0 || strpos($key, 'rk_live_') === 0 || strpos($key, 'sk_live_') === 0); }
    private function is_live_key() { $key = $this->key(); return strpos($key, 'rk_live_') === 0 || strpos($key, 'sk_live_') === 0; }
    private function tkey($sid) { return 'briggs_stripe_'.hash('sha256',(string)$sid); }
    public function create_checkout() {
        check_ajax_referer(self::NONCE,'nonce');
        if (!is_ssl() && !in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true)) wp_send_json_error(['message'=>'Secure HTTPS is required for checkout.'],400);
        $key=$this->key(); if (!$this->valid_key($key)) wp_send_json_error(['message'=>'A valid Stripe restricted secret key is not configured. Use matching test or live credentials.'],400);
        $choice=isset($_POST['choice'])?sanitize_key(wp_unslash($_POST['choice'])):'';
        $prices=['deposit'=>['amount'=>20000,'name'=>'Phlebotomy Class - $200 Deposit'],'full'=>['amount'=>77500,'name'=>'Phlebotomy Class - Full Payment ($775)']];
        if(!isset($prices[$choice]))wp_send_json_error(['message'=>'Please select a valid payment option.'],400);
        $email=isset($_POST['email'])?sanitize_email(wp_unslash($_POST['email'])):'';
        $return=isset($_POST['return_url'])?esc_url_raw(wp_unslash($_POST['return_url'])):home_url('/');
        if(strtolower((string)wp_parse_url($return,PHP_URL_HOST))!==strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST)))$return=home_url('/');
        $ref=wp_generate_uuid4();
        $body=['mode'=>'payment','success_url'=>add_query_arg(['briggs_payment_return'=>'1','session_id'=>'{CHECKOUT_SESSION_ID}'],$return),'cancel_url'=>add_query_arg('briggs_payment_cancel','1',$return),'client_reference_id'=>$ref,'metadata[purpose]'=>'phlebotomy_class_registration','metadata[choice]'=>$choice,'metadata[amount_cents]'=>(string)$prices[$choice]['amount'],'metadata[reference]'=>$ref,'line_items[0][quantity]'=>'1','line_items[0][price_data][currency]'=>'usd','line_items[0][price_data][unit_amount]'=>(string)$prices[$choice]['amount'],'line_items[0][price_data][product_data][name]'=>$prices[$choice]['name']];
        if($email&&is_email($email))$body['customer_email']=$email;
        $res=wp_remote_post('https://api.stripe.com/v1/checkout/sessions',['timeout'=>25,'headers'=>['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/x-www-form-urlencoded'],'body'=>$body]);
        if(is_wp_error($res))wp_send_json_error(['message'=>'Could not connect to Stripe. Please try again.'],502);
        $code=wp_remote_retrieve_response_code($res);$data=json_decode(wp_remote_retrieve_body($res),true);
        if($code<200||$code>=300||empty($data['id'])||empty($data['url'])){$msg=$data['error']['message']??'Stripe could not create checkout.';wp_send_json_error(['message'=>sanitize_text_field($msg)],502);}
        set_transient($this->tkey($data['id']),['status'=>'pending','session_id'=>sanitize_text_field($data['id']),'choice'=>$choice,'amount'=>(int)$prices[$choice]['amount'],'reference'=>$ref,'created'=>time()],DAY_IN_SECONDS);
        $this->save_payment_record(array('session_id'=>$data['id'],'customer_email'=>$email,'description'=>$prices[$choice]['name'],'choice'=>$choice,'amount_cents'=>$prices[$choice]['amount'],'currency'=>'usd','status'=>'pending','livemode'=>$this->is_live_key(),'created_at'=>$data['created'] ?? time()));
        wp_send_json_success(['url'=>esc_url_raw($data['url'])]);
    }
    public function payment_status() {
        check_ajax_referer(self::NONCE,'nonce');$sid=isset($_POST['session_id'])?sanitize_text_field(wp_unslash($_POST['session_id'])):'';
        if(!$sid || (strpos($sid,'cs_test_')!==0 && strpos($sid,'cs_live_')!==0))wp_send_json_error(['message'=>'Invalid checkout session.'],400);
        if (($this->is_live_key() && strpos($sid, 'cs_live_') !== 0) || (!$this->is_live_key() && strpos($sid, 'cs_test_') !== 0)) wp_send_json_error(['message'=>'Checkout session does not match the configured Stripe mode.'],400);
        $r=get_transient($this->tkey($sid));if(!is_array($r))wp_send_json_success(['status'=>'pending']);
        wp_send_json_success(['status'=>$r['status']??'pending','session_id'=>$sid,'choice'=>$r['choice']??'','amount'=>(int)($r['amount']??0)]);
    }
    private function record_fields($record) {
        $raw = $record->get('fields');
        return is_array($raw) ? $raw : [];
    }
    private function field_value($fields, $id) {
        if (!isset($fields[$id])) return '';
        $field = $fields[$id];
        if (is_array($field) && isset($field['value'])) return is_scalar($field['value']) ? trim((string)$field['value']) : '';
        return '';
    }
    public function validate_registration_payment($record, $ajax_handler) {
        $fields = $this->record_fields($record);
        // Only enforce payment on forms that contain the custom integration's payment fields.
        if (!array_key_exists('payment_status', $fields) && !array_key_exists('stripe_session_id', $fields)) return;
        $status = $this->field_value($fields, 'payment_status');
        $sid = sanitize_text_field($this->field_value($fields, 'stripe_session_id'));
        $submitted_amount = $this->field_value($fields, 'amount_paid');
        $submitted_choice = $this->field_value($fields, 'payment_option_paid');
        if ($status !== 'paid' || !$sid) {
            $ajax_handler->add_error('payment_status', 'Please complete and confirm your Stripe payment before reserving your seat.');
            return;
        }
        $is_live = $this->is_live_key();
        if (($is_live && strpos($sid, 'cs_live_') !== 0) || (!$is_live && strpos($sid, 'cs_test_') !== 0)) {
            $ajax_handler->add_error('payment_status', 'The payment session does not match the configured Stripe mode. Please contact the site administrator.');
            return;
        }
        $payment = get_transient($this->tkey($sid));
        if (!is_array($payment) || ($payment['status'] ?? '') !== 'paid' || !empty($payment['consumed'])) {
            $ajax_handler->add_error('payment_status', 'Stripe has not confirmed this payment, or this payment was already used. Please complete checkout again.');
            return;
        }
        $choice = $payment['choice'] ?? '';
        $amount = (int)($payment['amount'] ?? 0);
        $expected_amount = $choice === 'full' ? 77500 : ($choice === 'deposit' ? 20000 : 0);
        $expected_label = $choice === 'full' ? 'Full payment ($775)' : 'Deposit ($200)';
        if (!$expected_amount || $amount !== $expected_amount || number_format(((float)$submitted_amount), 2, '.', '') !== number_format($amount / 100, 2, '.', '') || $submitted_choice !== $expected_label) {
            $ajax_handler->add_error('payment_status', 'The submitted payment details do not match the confirmed Stripe payment. Please contact the site administrator.');
        }
    }
    public function consume_registration_payment($record, $handler) {
        $fields = $this->record_fields($record);
        if (!array_key_exists('payment_status', $fields) && !array_key_exists('stripe_session_id', $fields)) return;
        $sid = sanitize_text_field($this->field_value($fields, 'stripe_session_id'));
        if (!$sid) return;
        $payment = get_transient($this->tkey($sid));
        if (is_array($payment) && ($payment['status'] ?? '') === 'paid') {
            $payment['status'] = 'consumed';
            $payment['consumed'] = true;
            $payment['consumed_at'] = time();
            set_transient($this->tkey($sid), $payment, DAY_IN_SECONDS);
        }
    }
    public function webhook_route() { register_rest_route(self::NS,'/webhook',['methods'=>'POST','callback'=>[$this,'webhook'],'permission_callback'=>'__return_true']); }
    private function valid_signature($payload,$header,$secret) {
        if(!$header||!$secret)return false;$parts=[];foreach(explode(',',$header) as $p){$x=explode('=',trim($p),2);if(count($x)===2)$parts[$x[0]][]=$x[1];}
        if(empty($parts['t'][0])||empty($parts['v1']))return false;$ts=(int)$parts['t'][0];if(abs(time()-$ts)>300)return false;$expected=hash_hmac('sha256',$ts.'.'.$payload,$secret);foreach($parts['v1'] as $sig)if(hash_equals($expected,$sig))return true;return false;
    }
    public function webhook(WP_REST_Request $req) {
        $payload=$req->get_body();if(!$this->valid_signature($payload,$req->get_header('stripe-signature'),$this->whsec()))return new WP_REST_Response(['error'=>'Invalid webhook signature'],400);
        $event=json_decode($payload,true);if(!is_array($event)||empty($event['type'])||empty($event['data']['object']))return new WP_REST_Response(['error'=>'Invalid event payload'],400);
        $s = $event['data']['object'];
        $sid = sanitize_text_field($s['id'] ?? '');
        $m = is_array($s['metadata'] ?? null) ? $s['metadata'] : array();
        if ($sid && ($m['purpose'] ?? '') === 'phlebotomy_class_registration') {
            $session_live = !empty($s['livemode']);
            if ($session_live === $this->is_live_key()) {
                if ('checkout.session.expired' === $event['type']) {
                    $this->save_payment_record(array(
                        'session_id' => $sid,
                        'customer_email' => $s['customer_details']['email'] ?? ($s['customer_email'] ?? ''),
                        'customer_name' => $s['customer_details']['name'] ?? '',
                        'description' => 'Phlebotomy Class - ' . (('full' === ($m['choice'] ?? '')) ? 'Full Payment ($775)' : 'Deposit ($200)'),
                        'choice' => $m['choice'] ?? '',
                        'amount_cents' => $s['amount_total'] ?? ($m['amount_cents'] ?? 0),
                        'currency' => $s['currency'] ?? 'usd',
                        'status' => 'expired',
                        'livemode' => $session_live,
                        'created_at' => $s['created'] ?? time(),
                    ));
                } elseif (in_array($event['type'], array('checkout.session.completed','checkout.session.async_payment_succeeded'), true) && ('paid' === ($s['payment_status'] ?? '')) && in_array(($m['choice'] ?? ''), array('deposit','full'), true)) {
                    $r = get_transient($this->tkey($sid));
                    $expected_amount = ('full' === $m['choice']) ? 77500 : 20000;
                    if (is_array($r) && ($r['choice'] ?? '') === $m['choice'] && (int)($r['amount'] ?? 0) === $expected_amount && (int)($m['amount_cents'] ?? 0) === $expected_amount && !empty($m['reference']) && hash_equals((string)($r['reference'] ?? ''), (string)$m['reference']) && strtolower((string)($s['currency'] ?? '')) === 'usd' && (int)($s['amount_total'] ?? 0) === $expected_amount) {
                        $r['status'] = 'paid';
                        $r['paid_at'] = time();
                        $r['livemode'] = $session_live;
                        set_transient($this->tkey($sid), $r, DAY_IN_SECONDS);
                        $this->save_payment_record(array(
                            'session_id' => $sid,
                            'payment_intent_id' => is_string($s['payment_intent'] ?? null) ? $s['payment_intent'] : ($s['payment_intent']['id'] ?? ''),
                            'customer_id' => is_string($s['customer'] ?? null) ? $s['customer'] : ($s['customer']['id'] ?? ''),
                            'customer_email' => $s['customer_details']['email'] ?? ($s['customer_email'] ?? ''),
                            'customer_name' => $s['customer_details']['name'] ?? '',
                            'description' => 'Phlebotomy Class - ' . (('full' === $m['choice']) ? 'Full Payment ($775)' : 'Deposit ($200)'),
                            'choice' => $m['choice'],
                            'amount_cents' => (int)$s['amount_total'],
                            'currency' => $s['currency'] ?? 'usd',
                            'status' => 'paid',
                            'livemode' => $session_live,
                            'created_at' => $s['created'] ?? time(),
                            'paid_at' => time(),
                        ));
                    }
                }
            }
        }
        return new WP_REST_Response(['received'=>true],200);
    }
}
new Briggs_Stripe_Checkout();
