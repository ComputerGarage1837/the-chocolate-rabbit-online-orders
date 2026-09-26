<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
function add_action(...$args): void {}
function rest_ensure_response($value) { return $value; }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_key(string $value): string { return $value; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function get_woocommerce_currency_symbol(string $currency): string { return '$'; }
function wc_get_order_statuses(): array { return ['wc-on-hold' => 'On hold', 'wc-processing' => 'Processing']; }
function wc_get_order_status_name(string $status): string { return ucfirst($status); }
function sanitize_text_field(string $value): string { return $value; }
function wp_hash_password(string $value): string { return password_hash($value, PASSWORD_DEFAULT); }
function wp_check_password(string $value, string $hash): bool { return password_verify($value, $hash); }
function wp_salt(string $scheme): string { return 'test-' . $scheme; }
function get_bloginfo(string $field): string { return 'The Chocolate Rabbit'; }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $expiry): void { $GLOBALS['transients'][$key] = $value; }
function delete_transient(string $key): void { unset($GLOBALS['transients'][$key]); }

class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
class WP_REST_Request implements ArrayAccess {
    public function __construct(private int $id, private array $body = [], private string $authorization = '') {}
    public function get_header(string $name): string { return $name === 'authorization' ? $this->authorization : ''; }
    public function offsetExists(mixed $offset): bool { return $offset === 'id'; }
    public function offsetGet(mixed $offset): mixed { return $this->id; }
    public function offsetSet(mixed $offset, mixed $value): void {}
    public function offsetUnset(mixed $offset): void {}
    public function get_json_params(): array { return $this->body; }
}
class WC_Order {
    public string $status = 'on-hold';
    public string $method = 'square_credit_card';
    public string $transaction = 'square-payment-1';
    public float $total = 42.50;
    public float $subtotal = 42.50;
    public float $discount = 0;
    public float $shipping = 0;
    public array $shippingMethods = [];
    public array $fees = [];
    public array $taxes = [];
    public function get_id(): int { return 17; }
    public function get_order_number(): string { return '17'; }
    public function get_payment_method(): string { return $this->method; }
    public function get_payment_method_title(): string { return 'Square Credit Card'; }
    public function get_transaction_id(): string { return $this->transaction; }
    public function get_status(): string { return $this->status; }
    public function get_total(): float { return $this->total; }
    public function get_total_refunded(): float { return 0.0; }
    public function get_currency(): string { return 'CAD'; }
    public function get_formatted_billing_full_name(): string { return 'Test Customer'; }
    public function get_date_created() { return null; }
    public function get_shipping_method(): string { return ''; }
    public function get_items(): array { return []; }
    public function get_order_item_totals(): array { return []; }
    public function get_subtotal(): float { return $this->subtotal; }
    public function get_total_discount(): float { return $this->discount; }
    public function get_shipping_methods(): array { return $this->shippingMethods; }
    public function get_shipping_total(): float { return $this->shipping; }
    public function get_fees(): array { return $this->fees; }
    public function get_taxes(): array { return $this->taxes; }
    public function get_billing_address_1(): string { return ''; }
    public function get_billing_address_2(): string { return ''; }
    public function get_billing_city(): string { return ''; }
    public function get_billing_state(): string { return ''; }
    public function get_billing_postcode(): string { return ''; }
    public function get_billing_country(): string { return ''; }
    public function get_billing_email(): string { return ''; }
    public function get_billing_phone(): string { return ''; }
    public function is_paid(): bool { return $this->status === 'processing'; }
    public function update_status(string $status, string $note, bool $manual): void { $this->status = $status; }
}
class FakeCaptureHandler {
    public bool $captured = false;
    public bool $eligible = true;
    public bool $fail = false;
    public float $authorized = 42.50;
    public int $calls = 0;
    public function is_order_captured(WC_Order $order): bool { return $this->captured; }
    public function get_order_authorization_amount(WC_Order $order): float { return $this->authorized; }
    public function order_can_be_captured(WC_Order $order): bool { return $this->eligible && !$this->captured; }
    public function perform_capture(WC_Order $order, float $amount): array {
        ++$this->calls;
        if ($this->fail) return ['success' => false, 'message' => 'Gateway declined'];
        if ($amount !== $this->authorized) return ['success' => false, 'message' => 'Wrong amount'];
        $this->captured = true;
        $order->status = 'processing';
        return ['success' => true];
    }
}
class FakeGateway {
    public function __construct(public FakeCaptureHandler $handler) {}
    public function get_capture_handler(): FakeCaptureHandler { return $this->handler; }
}
class FakeGateways {
    public function __construct(public FakeGateway $gateway) {}
    public function payment_gateways(): array { return ['square_credit_card' => $this->gateway]; }
}
class FakeWooCommerce {
    public function __construct(public FakeGateways $gateways) {}
    public function payment_gateways(): FakeGateways { return $this->gateways; }
}
function WC(): FakeWooCommerce { return $GLOBALS['woo']; }
function wc_get_order(int $id): ?WC_Order { return $id === 17 ? $GLOBALS['order'] : null; }
function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = false): bool {
    if (array_key_exists($key, $GLOBALS['options'])) return false;
    $GLOBALS['options'][$key] = $value;
    return true;
}
function get_option(string $key, mixed $default = null): mixed { return $GLOBALS['options'][$key] ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = false): void { $GLOBALS['options'][$key] = $value; }
function delete_option(string $key): void { unset($GLOBALS['options'][$key]); }

require dirname(__DIR__) . '/wordpress-plugin/the-chocolate-rabbit-orders-app/the-chocolate-rabbit-orders-app.php';

function setup(): FakeCaptureHandler {
    $GLOBALS['options'] = [];
    $GLOBALS['order'] = new WC_Order();
    $handler = new FakeCaptureHandler();
    $GLOBALS['woo'] = new FakeWooCommerce(new FakeGateways(new FakeGateway($handler)));
    return $handler;
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$handler = setup();
$blocked = TCR_Orders_App::status(new WP_REST_Request(17, ['status' => 'processing']));
check($blocked instanceof WP_Error && $blocked->code === 'capture_required', 'Status bypassed capture');
check($GLOBALS['order']->status === 'on-hold', 'Status changed before capture');
$captured = TCR_Orders_App::capture(new WP_REST_Request(17));
check(is_array($captured) && $captured['captured'] === true, 'Capture did not succeed');
check($handler->calls === 1 && $GLOBALS['order']->status === 'processing', 'Square capture not invoked exactly once');
$duplicate = TCR_Orders_App::capture(new WP_REST_Request(17));
check($duplicate instanceof WP_Error && $handler->calls === 1, 'Duplicate capture reached Square');

$handler = setup();
$GLOBALS['order']->total = 43.00;
$changed = TCR_Orders_App::capture(new WP_REST_Request(17));
check($changed instanceof WP_Error && $handler->calls === 0, 'Edited amount reached Square');

$handler = setup();
$handler->eligible = false;
$expired = TCR_Orders_App::capture(new WP_REST_Request(17));
check($expired instanceof WP_Error && $handler->calls === 0, 'Expired authorization reached Square');

$handler = setup();
$handler->fail = true;
$failed = TCR_Orders_App::capture(new WP_REST_Request(17));
check($failed instanceof WP_Error && $failed->code === 'capture_failed', 'Gateway failure was not reported');
check(!isset($GLOBALS['options']['tcr_orders_capture_lock_17']), 'Capture lock was not released after failure');

$handler = setup();
$GLOBALS['options']['tcr_orders_capture_lock_17'] = time();
$busy = TCR_Orders_App::capture(new WP_REST_Request(17));
check($busy instanceof WP_Error && $busy->code === 'capture_busy' && $handler->calls === 0, 'Concurrent capture reached Square');

$order = new WC_Order();
$order->subtotal = 100;
$order->discount = 10;
$order->shipping = 5;
$order->shippingMethods = [new stdClass()];
$order->fees = [new class { public function get_name(): string { return 'Service fee'; } public function get_total(): float { return 2; } }];
$order->taxes = [new class { public function get_label(): string { return 'HST'; } public function get_tax_total(): float { return 12; } public function get_shipping_tax_total(): float { return 0.65; } }];
$order->total = 109.65;
$detail = (new ReflectionMethod(TCR_Orders_App::class, 'order_detail'))->invoke(null, $order);
$rows = array_column($detail['totals'], 'value', 'label');
check($rows === ['Subtotal' => 100.0, 'Discount' => -10.0, 'Shipping' => 5.0, 'Service fee' => 2.0, 'HST' => 12.65], 'Numeric totals or HST are wrong');
check($detail['total'] === 109.65, 'Order grand total changed');

setup();
$GLOBALS['transients'] = [];
$GLOBALS['options']['tcr_orders_app_pin_hash'] = wp_hash_password('2468');
$legacy_token = str_repeat('a', 64);
$GLOBALS['options']['tcr_orders_app_token_hash'] = hash_hmac('sha256', $legacy_token, wp_salt('auth'));
$first = TCR_Orders_App::pair(new WP_REST_Request(0, ['code' => '2468']));
$second = TCR_Orders_App::pair(new WP_REST_Request(0, ['code' => '2468']));
check(is_array($first) && is_array($second) && $first['token'] !== $second['token'], 'Devices did not receive independent tokens');
check(count($GLOBALS['options']['tcr_orders_app_token_hashes']) === 2, 'Pairing replaced the first device');
check(TCR_Orders_App::authorized(new WP_REST_Request(0, [], 'Bearer ' . $first['token'])), 'First device lost access');
check(TCR_Orders_App::authorized(new WP_REST_Request(0, [], 'Bearer ' . $second['token'])), 'Second device cannot access');
check(TCR_Orders_App::authorized(new WP_REST_Request(0, [], 'Bearer ' . $legacy_token)), 'Existing paired device lost access');
$bad = TCR_Orders_App::pair(new WP_REST_Request(0, ['code' => '0000']));
check($bad instanceof WP_Error && $bad->code === 'invalid_pin', 'Incorrect PIN was accepted');
for ($attempt = 0; $attempt < 4; $attempt++) TCR_Orders_App::pair(new WP_REST_Request(0, ['code' => '0000']));
$limited = TCR_Orders_App::pair(new WP_REST_Request(0, ['code' => '0000']));
check($limited instanceof WP_Error && $limited->code === 'too_many_attempts', 'PIN guessing was not rate limited');
$GLOBALS['options']['tcr_orders_fcm_token'] = 'old-phone';
$GLOBALS['options']['tcr_orders_fcm_tokens'] = ['new-phone-1', 'new-phone-2', 'old-phone'];
$push_tokens = (new ReflectionMethod(TCR_Orders_App::class, 'push_tokens'))->invoke(null);
check($push_tokens === ['new-phone-1', 'new-phone-2', 'old-phone'], 'Push registrations were not retained for every device');

echo "Square capture, numeric totals, and multi-device PIN checks passed\n";
