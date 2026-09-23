<?php
/**
 * Plugin Name: The Chocolate Rabbit Orders App
 * Description: Secure owner-app access to WooCommerce orders, refunds, and new-order push notifications.
 * Version: 1.0.2
 * Author: Computer Garage
 * Requires Plugins: woocommerce
 */
declare(strict_types=1);

if (!defined('ABSPATH')) exit;

final class TCR_Orders_App {
    private const NS = 'tcr-orders/v1';
    private const TOKEN = 'tcr_orders_app_token_hash';
    private const PAIR = 'tcr_orders_pair_code';
    private const PAIR_EXPIRY = 'tcr_orders_pair_expiry';
    private const DEVICE = 'tcr_orders_fcm_token';
    private const FIREBASE = 'tcr_orders_firebase';

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_tcr_orders_save', [self::class, 'save_settings']);
        add_action('admin_post_tcr_orders_pair', [self::class, 'new_pairing_code']);
        add_action('admin_post_tcr_orders_revoke', [self::class, 'revoke']);
        add_action('admin_post_tcr_orders_test_push', [self::class, 'test_push']);
        add_action('woocommerce_new_order', [self::class, 'queue_notification'], 20, 1);
        add_action('tcr_orders_send_new_order_push', [self::class, 'send_new_order_notification'], 10, 1);
    }

    public static function routes(): void {
        register_rest_route(self::NS, '/pair', ['methods' => 'POST', 'callback' => [self::class, 'pair'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/config', ['methods' => 'GET', 'callback' => [self::class, 'config'], 'permission_callback' => [self::class, 'authorized']]);
        register_rest_route(self::NS, '/device/push', ['methods' => 'POST', 'callback' => [self::class, 'register_push'], 'permission_callback' => [self::class, 'authorized']]);
        register_rest_route(self::NS, '/orders', ['methods' => 'GET', 'callback' => [self::class, 'orders'], 'permission_callback' => [self::class, 'authorized']]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [self::class, 'order'], 'permission_callback' => [self::class, 'authorized']]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/status', ['methods' => 'POST', 'callback' => [self::class, 'status'], 'permission_callback' => [self::class, 'authorized']]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/capture', ['methods' => 'POST', 'callback' => [self::class, 'capture'], 'permission_callback' => [self::class, 'authorized']]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/notes', ['methods' => 'POST', 'callback' => [self::class, 'note'], 'permission_callback' => [self::class, 'authorized']]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/refunds', ['methods' => 'POST', 'callback' => [self::class, 'refund'], 'permission_callback' => [self::class, 'authorized']]);
    }

    public static function authorized(WP_REST_Request $request): bool {
        $header = trim((string)$request->get_header('authorization'));
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,100})$/D', $header, $match)) return false;
        $expected = (string)get_option(self::TOKEN, '');
        return $expected !== '' && hash_equals($expected, self::token_hash($match[1]));
    }

    public static function pair(WP_REST_Request $request) {
        $ip = sanitize_text_field((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $limit_key = 'tcr_pair_' . substr(hash('sha256', $ip), 0, 32);
        $attempts = (int)get_transient($limit_key);
        if ($attempts >= 8) return new WP_Error('too_many_attempts', 'Too many pairing attempts. Wait 15 minutes and try again.', ['status' => 429]);
        set_transient($limit_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
        $body = self::body($request);
        $code = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
        $saved = (string)get_option(self::PAIR, '');
        $expiry = (int)get_option(self::PAIR_EXPIRY, 0);
        if (strlen($code) !== 8 || $expiry < time() || $saved === '' || !hash_equals($saved, self::pair_hash($code))) {
            return new WP_Error('invalid_pairing_code', 'That pairing code is invalid or expired.', ['status' => 401]);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        update_option(self::TOKEN, self::token_hash($token), false);
        delete_option(self::PAIR); delete_option(self::PAIR_EXPIRY); delete_option(self::DEVICE);
        delete_transient($limit_key);
        return rest_ensure_response(['token' => $token, 'storeName' => get_bloginfo('name')]);
    }

    public static function config(): WP_REST_Response {
        $firebase = self::firebase();
        $public = $firebase['public'] ?? [];
        $configured = self::firebase_ready($firebase);
        return rest_ensure_response(['firebaseConfigured' => $configured, 'firebase' => $configured ? $public : (object)[]]);
    }

    public static function register_push(WP_REST_Request $request) {
        $token = trim((string)(self::body($request)['token'] ?? ''));
        if (strlen($token) < 20 || strlen($token) > 4096 || preg_match('/[\x00-\x20]/', $token)) return new WP_Error('invalid_token', 'Invalid notification token.', ['status' => 400]);
        update_option(self::DEVICE, $token, false);
        return rest_ensure_response(['registered' => true]);
    }

    public static function orders(WP_REST_Request $request) {
        if (!function_exists('wc_get_orders')) return self::woocommerce_missing();
        $page = max(1, min(1000, (int)$request->get_param('page')));
        $status = sanitize_key((string)$request->get_param('status'));
        $allowed = array_keys(wc_get_order_statuses());
        $args = ['limit' => 20, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order'];
        if ($status !== '' && $status !== 'any') {
            $prefixed = 'wc-' . $status;
            if (!in_array($prefixed, $allowed, true)) return new WP_Error('invalid_status', 'Invalid order status.', ['status' => 400]);
            $args['status'] = $status;
        }
        $search = trim(sanitize_text_field((string)$request->get_param('search')));
        if ($search !== '') $args['s'] = mb_substr($search, 0, 80);
        $result = wc_get_orders($args);
        $rows = [];
        foreach ($result->orders as $order) $rows[] = self::order_summary($order);
        return rest_ensure_response(['orders' => $rows, 'total' => (int)$result->total, 'pages' => (int)$result->max_num_pages, 'page' => $page]);
    }

    public static function order(WP_REST_Request $request) {
        $order = self::get_order((int)$request['id']); if (is_wp_error($order)) return $order;
        return rest_ensure_response(['order' => self::order_detail($order)]);
    }

    public static function status(WP_REST_Request $request) {
        $order = self::get_order((int)$request['id']); if (is_wp_error($order)) return $order;
        $status = sanitize_key((string)(self::body($request)['status'] ?? ''));
        if (!array_key_exists('wc-' . $status, wc_get_order_statuses())) return new WP_Error('invalid_status', 'Invalid order status.', ['status' => 400]);
        if (in_array($status, ['processing', 'completed'], true) && self::square_capture_state($order)['requiresCapture']) {
            return new WP_Error('capture_required', 'Capture the Square authorization before marking this order paid.', ['status' => 409]);
        }
        $order->update_status($status, 'Status changed from The Chocolate Rabbit Online Orders app.', true);
        return rest_ensure_response(['order' => self::order_detail($order)]);
    }

    public static function capture(WP_REST_Request $request) {
        $order = self::get_order((int)$request['id']); if (is_wp_error($order)) return $order;
        $state = self::square_capture_state($order);
        if (!$state['available']) return new WP_Error('capture_unavailable', $state['message'] ?: 'This order has no capturable Square authorization.', ['status' => 409]);

        $lock = 'tcr_orders_capture_lock_' . $order->get_id();
        if (!add_option($lock, time(), '', false)) {
            if ((int)get_option($lock, 0) > time() - 120) return new WP_Error('capture_busy', 'A capture is already in progress. Refresh the order before retrying.', ['status' => 409]);
            delete_option($lock);
            if (!add_option($lock, time(), '', false)) return new WP_Error('capture_busy', 'A capture is already in progress.', ['status' => 409]);
        }
        try {
            $order = self::get_order((int)$request['id']);
            if (is_wp_error($order)) return $order;
            $state = self::square_capture_state($order);
            if (!$state['available']) return new WP_Error('capture_unavailable', $state['message'] ?: 'The Square authorization is no longer capturable.', ['status' => 409]);
            $gateway = self::square_gateway();
            $result = $gateway->get_capture_handler()->perform_capture($order, (float)$order->get_total());
            if (empty($result['success'])) return new WP_Error('capture_failed', wp_strip_all_tags((string)($result['message'] ?? 'Square declined the capture.')), ['status' => 409]);
            $order = wc_get_order($order->get_id());
            if (!$order || !$gateway->get_capture_handler()->is_order_captured($order)) {
                return new WP_Error('capture_unconfirmed', 'Square returned success but the order was not confirmed. Check WooCommerce and Square before retrying.', ['status' => 502]);
            }
            return rest_ensure_response(['captured' => true, 'order' => self::order_detail($order)]);
        } catch (Throwable $error) {
            return new WP_Error('capture_uncertain', 'The capture result could not be confirmed. Check WooCommerce and Square before retrying.', ['status' => 502]);
        } finally {
            delete_option($lock);
        }
    }

    private static function square_gateway() {
        if (!function_exists('WC') || !WC()->payment_gateways()) return null;
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = $gateways['square_credit_card'] ?? null;
        return is_object($gateway) && method_exists($gateway, 'get_capture_handler') ? $gateway : null;
    }

    private static function square_capture_state(WC_Order $order): array {
        $state = ['available' => false, 'requiresCapture' => false, 'amount' => (float)$order->get_total(), 'message' => ''];
        if ($order->get_payment_method() !== 'square_credit_card' || $order->get_status() !== 'on-hold' || $order->get_transaction_id() === '') return $state;
        $state['requiresCapture'] = true;
        $gateway = self::square_gateway();
        if (!$gateway) { $state['message'] = 'The WooCommerce Square gateway is unavailable.'; return $state; }
        $handler = $gateway->get_capture_handler();
        if (!$handler || !method_exists($handler, 'order_can_be_captured') || !method_exists($handler, 'get_order_authorization_amount') || !method_exists($handler, 'is_order_captured')) {
            $state['message'] = 'This Square version does not expose the capture controls needed by the app.';
            return $state;
        }
        if ($handler->is_order_captured($order)) { $state['message'] = 'This payment has already been partly captured. Review it in WooCommerce.'; return $state; }
        if (abs((float)$handler->get_order_authorization_amount($order) - (float)$order->get_total()) > 0.005) {
            $state['message'] = 'The order total differs from its authorization. Review and capture it in WooCommerce.';
            return $state;
        }
        if (!$handler->order_can_be_captured($order)) { $state['message'] = 'The Square authorization has expired or cannot be captured. Review it in WooCommerce.'; return $state; }
        $state['available'] = true;
        return $state;
    }

    public static function note(WP_REST_Request $request) {
        $order = self::get_order((int)$request['id']); if (is_wp_error($order)) return $order;
        $note = trim(sanitize_textarea_field((string)(self::body($request)['note'] ?? '')));
        if ($note === '' || mb_strlen($note) > 1000) return new WP_Error('invalid_note', 'Enter a note up to 1,000 characters.', ['status' => 400]);
        $note_id = $order->add_order_note($note, false, false);
        return rest_ensure_response(['created' => true, 'noteId' => (int)$note_id]);
    }

    public static function refund(WP_REST_Request $request) {
        $order = self::get_order((int)$request['id']); if (is_wp_error($order)) return $order;
        $body = self::body($request);
        $amount = wc_format_decimal((string)($body['amount'] ?? ''), wc_get_price_decimals());
        $reason = mb_substr(trim(sanitize_text_field((string)($body['reason'] ?? ''))), 0, 500);
        $refund_payment = !empty($body['refundPayment']);
        $remaining = max(0.0, (float)$order->get_total() - (float)$order->get_total_refunded());
        if (!is_numeric($amount) || (float)$amount <= 0 || (float)$amount > $remaining + 0.0001) return new WP_Error('invalid_refund', 'The refund amount must be greater than zero and no more than the refundable balance.', ['status' => 400]);
        $refund = wc_create_refund(['amount' => (float)$amount, 'reason' => $reason, 'order_id' => $order->get_id(), 'refund_payment' => $refund_payment, 'restock_items' => false]);
        if (is_wp_error($refund)) return new WP_Error('refund_failed', $refund->get_error_message(), ['status' => 400]);
        $order = wc_get_order($order->get_id());
        return rest_ensure_response(['created' => true, 'refundId' => $refund->get_id(), 'order' => self::order_detail($order)]);
    }

    private static function order_summary(WC_Order $order): array {
        $names = [];
        foreach ($order->get_items() as $item) $names[] = $item->get_name() . ' × ' . $item->get_quantity();
        $customer = trim($order->get_formatted_billing_full_name());
        return ['id' => $order->get_id(), 'number' => $order->get_order_number(), 'customer' => $customer ?: 'Guest', 'total' => (float)$order->get_total(),
            'currencySymbol' => html_entity_decode(get_woocommerce_currency_symbol($order->get_currency()), ENT_QUOTES, 'UTF-8'), 'status' => $order->get_status(),
            'statusLabel' => wc_get_order_status_name($order->get_status()), 'date' => $order->get_date_created() ? wc_format_datetime($order->get_date_created(), 'M j, Y g:i a') : '',
            'paymentMethod' => $order->get_payment_method_title(), 'shippingMethod' => $order->get_shipping_method(), 'itemSummary' => implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) . ' more' : '')];
    }

    private static function order_detail(WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item) {
            $meta = [];
            foreach ($item->get_formatted_meta_data('') as $entry) {
                $label = wp_strip_all_tags((string)$entry->display_key); $value = wp_strip_all_tags((string)$entry->display_value);
                if ($label !== '' && $value !== '') $meta[] = ['label' => $label, 'value' => $value];
            }
            $items[] = ['name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'total' => (float)$order->get_line_total($item, true, false), 'meta' => $meta];
        }
        $totals = [];
        foreach ($order->get_order_item_totals() as $key => $row) {
            if ($key === 'order_total') continue;
            $raw = wp_strip_all_tags((string)$row['value']);
            preg_match('/-?[0-9][0-9,]*(?:\.[0-9]+)?/', $raw, $match);
            $totals[] = ['label' => wp_strip_all_tags((string)$row['label']), 'value' => isset($match[0]) ? (float)str_replace(',', '', $match[0]) : 0];
        }
        $statuses = []; foreach (wc_get_order_statuses() as $key => $label) $statuses[substr($key, 3)] = $label;
        $billing = array_filter([$order->get_billing_address_1(), $order->get_billing_address_2(), trim($order->get_billing_city() . ' ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode()), $order->get_billing_country()]);
        return array_merge(self::order_summary($order), ['paid' => $order->is_paid(), 'capture' => self::square_capture_state($order), 'transactionId' => $order->get_transaction_id(), 'refunded' => (float)$order->get_total_refunded(),
            'refundable' => max(0.0, (float)$order->get_total() - (float)$order->get_total_refunded()), 'items' => $items, 'totals' => $totals, 'statuses' => $statuses,
            'customer' => ['name' => trim($order->get_formatted_billing_full_name()) ?: 'Guest', 'email' => $order->get_billing_email(), 'phone' => $order->get_billing_phone(), 'billing' => implode("\n", $billing)]]);
    }

    private static function get_order(int $id) {
        if (!function_exists('wc_get_order')) return self::woocommerce_missing();
        $order = wc_get_order($id);
        if (!$order || !is_a($order, 'WC_Order')) return new WP_Error('order_not_found', 'Order not found.', ['status' => 404]);
        return $order;
    }
    private static function body(WP_REST_Request $request): array { $value = $request->get_json_params(); return is_array($value) ? $value : []; }
    private static function woocommerce_missing(): WP_Error { return new WP_Error('woocommerce_unavailable', 'WooCommerce is unavailable.', ['status' => 503]); }
    private static function token_hash(string $token): string { return hash_hmac('sha256', $token, wp_salt('auth')); }
    private static function pair_hash(string $code): string { return hash_hmac('sha256', $code, wp_salt('nonce')); }

    public static function queue_notification(int $order_id): void {
        if (!wp_next_scheduled('tcr_orders_send_new_order_push', [$order_id])) wp_schedule_single_event(time() + 8, 'tcr_orders_send_new_order_push', [$order_id]);
    }

    public static function send_new_order_notification(int $order_id): void {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        $device = (string)get_option(self::DEVICE, ''); $firebase = self::firebase();
        if (!$order || $device === '' || !self::firebase_ready($firebase)) return;
        $title = 'New order #' . $order->get_order_number();
        $name = trim($order->get_formatted_billing_full_name()) ?: 'Guest';
        $body = $name . ' · ' . html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8');
        $result = self::fcm_send($device, ['data' => ['orderId' => (string)$order_id, 'title' => $title, 'body' => $body], 'android' => ['priority' => 'high'] ], $firebase);
        if (($result['invalid'] ?? false) === true) delete_option(self::DEVICE);
    }

    private static function firebase(): array { $value = get_option(self::FIREBASE, []); return is_array($value) ? $value : []; }
    private static function firebase_ready(array $firebase): bool {
        $p = $firebase['public'] ?? []; $s = $firebase['service'] ?? [];
        return isset($p['apiKey'], $p['applicationId'], $p['projectId'], $p['senderId'], $s['client_email'], $s['private_key'], $s['project_id'])
            && $p['projectId'] === $s['project_id'];
    }

    private static function fcm_send(string $token, array $payload, array $firebase): array {
        $access = get_transient('tcr_orders_fcm_access');
        if (!is_string($access) || $access === '') {
            $service = $firebase['service']; $now = time();
            $header = self::base64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = self::base64url(wp_json_encode(['iss' => $service['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
            $message = $header . '.' . $claims; $signature = '';
            if (!openssl_sign($message, $signature, $service['private_key'], OPENSSL_ALGO_SHA256)) return ['ok' => false];
            $jwt = $message . '.' . self::base64url($signature);
            $response = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 12, 'redirection' => 0, 'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return ['ok' => false];
            $json = json_decode(wp_remote_retrieve_body($response), true); $access = (string)($json['access_token'] ?? '');
            if ($access === '') return ['ok' => false];
            set_transient('tcr_orders_fcm_access', $access, max(60, (int)($json['expires_in'] ?? 3600) - 120));
        }
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($firebase['public']['projectId']) . '/messages:send';
        $response = wp_remote_post($url, ['timeout' => 12, 'redirection' => 0, 'headers' => ['Authorization' => 'Bearer ' . $access, 'Content-Type' => 'application/json'], 'body' => wp_json_encode(['message' => ['token' => $token] + $payload])]);
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response); $body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
        $invalid = false; foreach (($body['error']['details'] ?? []) as $detail) if (($detail['errorCode'] ?? '') === 'UNREGISTERED' || ($detail['errorCode'] ?? '') === 'SENDER_ID_MISMATCH') $invalid = true;
        if ($code === 401) delete_transient('tcr_orders_fcm_access');
        return ['ok' => $code >= 200 && $code < 300, 'invalid' => $invalid];
    }
    private static function base64url(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }

    public static function menu(): void { add_submenu_page('woocommerce', 'Orders App', 'Orders App', 'manage_woocommerce', 'tcr-orders-app', [self::class, 'page']); }
    public static function page(): void {
        if (!current_user_can('manage_woocommerce')) return;
        $firebase = self::firebase(); $public = $firebase['public'] ?? []; $expiry = (int)get_option(self::PAIR_EXPIRY, 0); $paired = (string)get_option(self::TOKEN, '') !== '';
        $code = get_transient('tcr_orders_pair_display');
        ?>
        <div class="wrap"><h1>The Chocolate Rabbit Online Orders</h1>
        <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
        <?php if (isset($_GET['test_push'])): ?><div class="notice <?php echo $_GET['test_push'] === 'sent' ? 'notice-success' : 'notice-error'; ?>"><p><?php echo $_GET['test_push'] === 'sent' ? 'Test notification sent to the paired phone.' : 'Test notification could not be sent. Check Firebase settings and the paired phone.'; ?></p></div><?php endif; ?>
        <div class="card" style="max-width:760px;padding:20px"><h2>Owner phone</h2><p>Status: <strong><?php echo $paired ? 'Paired' : 'Not paired'; ?></strong></p>
        <?php if (is_string($code) && $expiry > time()): ?><p>Enter this one-time code in the app. It expires in 10 minutes.</p><div style="font-size:34px;font-weight:800;letter-spacing:.18em;background:#fff4c8;padding:18px;display:inline-block"><?php echo esc_html($code); ?></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px"><?php wp_nonce_field('tcr_orders_pair'); ?><input type="hidden" name="action" value="tcr_orders_pair"><button class="button button-primary">Generate new pairing code</button></form>
        <?php if ($paired): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px" onsubmit="return confirm('Revoke the paired phone?')"><?php wp_nonce_field('tcr_orders_revoke'); ?><input type="hidden" name="action" value="tcr_orders_revoke"><button class="button">Revoke paired phone</button></form><?php endif; ?></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="card" style="max-width:760px;padding:20px;margin-top:18px"><?php wp_nonce_field('tcr_orders_save'); ?><input type="hidden" name="action" value="tcr_orders_save"><h2>Push notifications (Firebase)</h2><p>Create an Android app in Firebase for package <code>ca.thechocolaterabbit.onlineorders</code>. Paste the four public Android values and the service-account JSON below. The private service account stays only in WordPress.</p>
        <?php foreach (['apiKey'=>'API key','applicationId'=>'Application ID','projectId'=>'Project ID','senderId'=>'Sender ID'] as $key=>$label): ?><p><label><strong><?php echo esc_html($label); ?></strong><br><input class="regular-text" name="public[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string)($public[$key] ?? '')); ?>"></label></p><?php endforeach; ?>
        <p><label><strong>Firebase service-account JSON</strong><br><textarea name="service_json" rows="10" class="large-text code" placeholder="Paste the full JSON file here. Leave blank to keep the saved credential."></textarea></label></p><p><button class="button button-primary">Save notification settings</button></p></form>
        <div class="card" style="max-width:760px;padding:20px;margin-top:18px"><h2>Notification check</h2>
        <p>Firebase: <strong><?php echo self::firebase_ready($firebase) ? 'Configured' : 'Needs setup'; ?></strong><br>Owner phone: <strong><?php echo get_option(self::DEVICE, '') ? 'Registered for push' : 'Not registered for push'; ?></strong></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tcr_orders_test_push'); ?><input type="hidden" name="action" value="tcr_orders_test_push"><button class="button button-primary" <?php disabled(!self::firebase_ready($firebase) || !get_option(self::DEVICE, '')); ?>>Send test notification</button></form></div></div>
        <?php
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden', 403); check_admin_referer('tcr_orders_save');
        $public_in = is_array($_POST['public'] ?? null) ? wp_unslash($_POST['public']) : [];
        $public = ['apiKey' => sanitize_text_field((string)($public_in['apiKey'] ?? '')), 'applicationId' => sanitize_text_field((string)($public_in['applicationId'] ?? '')), 'projectId' => sanitize_key((string)($public_in['projectId'] ?? '')), 'senderId' => preg_replace('/\D/', '', (string)($public_in['senderId'] ?? ''))];
        $current = self::firebase(); $service = $current['service'] ?? [];
        $raw = trim((string)wp_unslash($_POST['service_json'] ?? ''));
        if ($raw !== '') { $decoded = json_decode($raw, true); if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key']) || empty($decoded['project_id'])) wp_die('The Firebase service-account JSON is invalid.'); $service = ['client_email' => sanitize_email($decoded['client_email']), 'private_key' => (string)$decoded['private_key'], 'project_id' => sanitize_key($decoded['project_id'])]; }
        update_option(self::FIREBASE, ['public' => $public, 'service' => $service], false); delete_transient('tcr_orders_fcm_access');
        wp_safe_redirect(admin_url('admin.php?page=tcr-orders-app&updated=1')); exit;
    }
    public static function test_push(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden', 403);
        check_admin_referer('tcr_orders_test_push');
        $firebase = self::firebase(); $device = (string)get_option(self::DEVICE, '');
        $result = $device !== '' && self::firebase_ready($firebase)
            ? self::fcm_send($device, ['data' => ['title' => 'Chocolate Rabbit test', 'body' => 'New-order notifications are ready.', 'orderId' => ''], 'android' => ['priority' => 'high']], $firebase)
            : ['ok' => false];
        if (!empty($result['invalid'])) delete_option(self::DEVICE);
        wp_safe_redirect(admin_url('admin.php?page=tcr-orders-app&test_push=' . (!empty($result['ok']) ? 'sent' : 'failed'))); exit;
    }
    public static function new_pairing_code(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden', 403); check_admin_referer('tcr_orders_pair');
        $code = (string)random_int(10000000, 99999999); update_option(self::PAIR, self::pair_hash($code), false); update_option(self::PAIR_EXPIRY, time() + 10 * MINUTE_IN_SECONDS, false); set_transient('tcr_orders_pair_display', $code, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=tcr-orders-app')); exit;
    }
    public static function revoke(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden', 403); check_admin_referer('tcr_orders_revoke');
        delete_option(self::TOKEN); delete_option(self::DEVICE); delete_option(self::PAIR); delete_option(self::PAIR_EXPIRY); delete_transient('tcr_orders_pair_display');
        wp_safe_redirect(admin_url('admin.php?page=tcr-orders-app')); exit;
    }
}

TCR_Orders_App::boot();
