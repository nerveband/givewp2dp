<?php
/**
 * Core sync logic: hooks into GiveWP, matches donors, creates gifts/pledges in DP.
 *
 * Recurring donation flow (DP-native):
 *   1. First recurring payment (type=subscription) → create pledge via dp_savepledge + gift linked via @plink
 *   2. Renewal payments (type=renewal) → create gift linked to existing pledge via @plink
 *   3. One-time donations (type=single) → create regular gift, no pledge
 */

if (!defined('ABSPATH')) exit;

class GWDP_Donation_Sync {

    private static ?self $instance = null;
    private ?GWDP_DP_API $api = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('givewp_donation_updated', [$this, 'on_donation_updated'], 10, 1);

        add_action('wp_ajax_gwdp_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_gwdp_test_codes', [$this, 'ajax_test_codes']);
        add_action('wp_ajax_gwdp_backfill_preview', [$this, 'ajax_backfill_preview']);
        add_action('wp_ajax_gwdp_backfill_run', [$this, 'ajax_backfill_run']);
        add_action('wp_ajax_gwdp_sync_single', [$this, 'ajax_sync_single']);
        add_action('wp_ajax_gwdp_match_report', [$this, 'ajax_match_report']);
    }

    // ─── Activation ───

    public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Sync log table
        $log_table = $wpdb->prefix . 'gwdp_sync_log';
        $sql1 = "CREATE TABLE {$log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            give_donation_id bigint(20) unsigned NOT NULL,
            give_donor_id bigint(20) unsigned DEFAULT NULL,
            give_subscription_id bigint(20) unsigned DEFAULT NULL,
            dp_donor_id bigint(20) unsigned DEFAULT NULL,
            dp_gift_id bigint(20) unsigned DEFAULT NULL,
            dp_pledge_id bigint(20) unsigned DEFAULT NULL,
            donor_action varchar(20) DEFAULT NULL,
            donation_type varchar(20) DEFAULT NULL,
            donation_amount decimal(10,2) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_message text DEFAULT NULL,
            synced_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY give_donation_id (give_donation_id),
            KEY status (status),
            KEY dp_donor_id (dp_donor_id),
            KEY give_subscription_id (give_subscription_id)
        ) {$charset};";

        // Pledge mapping table (GiveWP subscription → DP pledge)
        $pledge_table = $wpdb->prefix . 'gwdp_pledge_map';
        $sql2 = "CREATE TABLE {$pledge_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            give_subscription_id bigint(20) unsigned NOT NULL,
            give_donor_id bigint(20) unsigned NOT NULL,
            dp_donor_id bigint(20) unsigned NOT NULL,
            dp_pledge_id bigint(20) unsigned NOT NULL,
            amount decimal(10,2) DEFAULT NULL,
            frequency varchar(10) DEFAULT 'month',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY give_subscription_id (give_subscription_id),
            KEY dp_pledge_id (dp_pledge_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);

        add_option('gwdp_sync_enabled', '0');
        add_option('gwdp_api_key', '');
        add_option('gwdp_default_gl_code', 'UN');
        add_option('gwdp_default_campaign', '');
        add_option('gwdp_default_solicit_code', '');
        add_option('gwdp_gateway_map', wp_json_encode([
            'stripe'          => 'CC',
            'stripe_checkout' => 'CC',
            'paypal'          => 'PAYPAL',
            'manual'          => 'CK',
            'offline'         => 'CK',
        ]));

        update_option('gwdp_db_version', GWDP_VERSION);
    }

    // ─── API ───

    public function get_api(): ?GWDP_DP_API {
        if ($this->api === null) {
            $key = get_option('gwdp_api_key', '');
            if (empty($key)) return null;
            $this->api = new GWDP_DP_API($key);
        }
        return $this->api;
    }

    // ─── Hook ───

    public function on_donation_updated($donation): void {
        if (get_option('gwdp_sync_enabled', '0') !== '1') return;

        $status = is_object($donation->status) ? $donation->status->getValue() : (string) $donation->status;
        if (!in_array($status, ['publish', 'give_subscription'], true)) return;

        $donation_id = (int) $donation->id;
        if ($donation_id <= 0 || $this->is_synced($donation_id)) return;

        $this->sync_donation($donation);
    }

    // ─── Core Sync ───

    public function sync_donation($donation, bool $dry_run = false): array {
        $api = $this->get_api();
        if (!$api) return ['status' => 'error', 'error' => 'API key not configured'];

        $donation_id  = (int) $donation->id;
        $email        = (string) $donation->email;
        $first_name   = (string) $donation->firstName;
        $last_name    = (string) $donation->lastName;
        $amount       = $this->get_amount($donation);
        $created_at   = $this->get_date($donation);
        $gateway_id   = (string) ($donation->gatewayId ?? 'unknown');
        $form_title   = (string) ($donation->formTitle ?? 'Donation');
        $don_type     = $this->get_donation_type($donation);
        $sub_id       = $this->get_subscription_id($donation);

        if (empty($email)) {
            return $this->log_sync($donation_id, [
                'status' => 'skipped', 'error' => 'No email address',
                'donation_type' => $don_type, 'donation_amount' => $amount,
            ]);
        }

        // Dry run: just show what would happen
        if ($dry_run) {
            $dp_donor_id = $api->find_donor_by_email($email);
            $pledge_info = $sub_id ? $this->find_pledge_by_subscription($sub_id) : null;
            return [
                'donation_id'  => $donation_id,
                'email'        => $email,
                'name'         => "{$first_name} {$last_name}",
                'amount'       => $amount,
                'date'         => $created_at,
                'type'         => $don_type,
                'form'         => $form_title,
                'donor_action' => $dp_donor_id ? 'match' : 'create',
                'dp_donor_id'  => $dp_donor_id,
                'pledge_action'=> ($don_type === 'subscription') ? 'create_pledge' :
                                  (($don_type === 'renewal' && $pledge_info) ? 'link_to_pledge #' . $pledge_info['dp_pledge_id'] :
                                  (($don_type === 'renewal') ? 'gift_only (no pledge found)' : 'none')),
                'status'       => 'preview',
            ];
        }

        // Step 1: Find or create donor
        $dp_donor_id = $api->find_donor_by_email($email);
        $donor_action = 'matched';

        if ($dp_donor_id === null) {
            $dp_donor_id = $api->create_donor([
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'country'    => 'US',
            ]);
            if (is_wp_error($dp_donor_id)) {
                return $this->log_sync($donation_id, [
                    'status' => 'error',
                    'error'  => 'Failed to create donor: ' . $dp_donor_id->get_error_message(),
                    'donation_type' => $don_type, 'donation_amount' => $amount,
                ]);
            }
            $donor_action = 'created';
        }

        // Step 2: Handle recurring vs one-time
        $gateway_map = json_decode(get_option('gwdp_gateway_map', '{}'), true) ?: [];
        $gift_type   = $gateway_map[$gateway_id] ?? 'CC';
        $gl_code     = get_option('gwdp_default_gl_code', 'UN');
        $campaign    = get_option('gwdp_default_campaign', '') ?: null;
        $solicit     = get_option('gwdp_default_solicit_code', '') ?: null;

        $dp_pledge_id = null;
        $sub_solicit  = 'ONETIME';

        if ($don_type === 'subscription') {
            // First recurring payment: create a pledge, then a gift linked to it
            $sub_solicit = 'RECURRING';

            $freq_map = ['day' => 'M', 'week' => 'M', 'month' => 'M', 'quarter' => 'Q', 'year' => 'A'];
            $give_freq = $this->get_subscription_period($donation);
            $dp_freq = $freq_map[$give_freq] ?? 'M';

            $pledge_narrative = "GiveWP Recurring - {$form_title} (\${$amount}/{$give_freq})";

            $dp_pledge_id = $api->create_pledge([
                'donor_id'         => $dp_donor_id,
                'gift_date'        => $created_at,
                'start_date'       => $created_at,
                'total'            => 0,  // 0 = open-ended, no outstanding balance
                'bill'             => $amount,
                'frequency'        => $dp_freq,
                'gl_code'          => $gl_code,
                'solicit_code'     => $solicit,
                'sub_solicit_code' => $sub_solicit,
                'campaign'         => $campaign,
                'initial_payment'  => 'Y',
                'gift_narrative'   => $pledge_narrative,
            ]);

            if (is_wp_error($dp_pledge_id)) {
                return $this->log_sync($donation_id, [
                    'dp_donor_id' => $dp_donor_id, 'donor_action' => $donor_action,
                    'status' => 'error',
                    'error'  => 'Failed to create pledge: ' . $dp_pledge_id->get_error_message(),
                    'donation_type' => $don_type, 'donation_amount' => $amount,
                ]);
            }

            // Save pledge mapping for future renewals
            if ($sub_id) {
                $this->save_pledge_mapping($sub_id, (int) ($donation->donorId ?? 0), $dp_donor_id, $dp_pledge_id, $amount, $give_freq);
            }

        } elseif ($don_type === 'renewal') {
            $sub_solicit = 'RECURRING';
            // Find existing pledge for this subscription
            if ($sub_id) {
                $pledge_info = $this->find_pledge_by_subscription($sub_id);
                if ($pledge_info) {
                    $dp_pledge_id = (int) $pledge_info['dp_pledge_id'];
                }
            }
        }

        // Step 3: Create the gift
        $type_label = match ($don_type) {
            'single'       => 'One-Time',
            'subscription' => 'Recurring - Initial',
            'renewal'      => 'Recurring - Renewal',
            default        => $don_type,
        };
        $narrative = "GiveWP #{$donation_id} - {$form_title} ({$type_label} \${$amount})";

        $gift_params = [
            'donor_id'         => $dp_donor_id,
            'gift_date'        => $created_at,
            'amount'           => $amount,
            'gl_code'          => $gl_code,
            'solicit_code'     => $solicit,
            'sub_solicit_code' => $sub_solicit,
            'campaign'         => $campaign,
            'gift_type'        => $gift_type,
            'reference'        => "GIVEWP-{$donation_id}",
            'gift_narrative'   => $narrative,
        ];

        // Link gift to pledge if recurring
        if ($dp_pledge_id) {
            $gift_params['pledge_payment'] = 'Y';
            $gift_params['plink'] = $dp_pledge_id;
        }

        $dp_gift_id = $api->create_gift($gift_params);

        if (is_wp_error($dp_gift_id)) {
            return $this->log_sync($donation_id, [
                'dp_donor_id' => $dp_donor_id, 'dp_pledge_id' => $dp_pledge_id,
                'donor_action' => $donor_action, 'status' => 'error',
                'error' => 'Failed to create gift: ' . $dp_gift_id->get_error_message(),
                'donation_type' => $don_type, 'donation_amount' => $amount,
            ]);
        }

        return $this->log_sync($donation_id, [
            'give_donor_id'       => (int) ($donation->donorId ?? 0),
            'give_subscription_id'=> $sub_id,
            'dp_donor_id'         => $dp_donor_id,
            'dp_gift_id'          => $dp_gift_id,
            'dp_pledge_id'        => $dp_pledge_id,
            'donor_action'        => $donor_action,
            'status'              => 'success',
            'donation_type'       => $don_type,
            'donation_amount'     => $amount,
        ]);
    }

    // ─── Pledge Mapping ───

    private function save_pledge_mapping(int $sub_id, int $give_donor_id, int $dp_donor_id, int $dp_pledge_id, float $amount, string $frequency): void {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'gwdp_pledge_map', [
            'give_subscription_id' => $sub_id,
            'give_donor_id'        => $give_donor_id,
            'dp_donor_id'          => $dp_donor_id,
            'dp_pledge_id'         => $dp_pledge_id,
            'amount'               => $amount,
            'frequency'            => $frequency,
        ]);
    }

    private function find_pledge_by_subscription(int $sub_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gwdp_pledge_map WHERE give_subscription_id = %d",
            $sub_id
        ), ARRAY_A);
        return $row ?: null;
    }

    // ─── Helpers ───

    public function is_synced(int $donation_id): bool {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}gwdp_sync_log WHERE give_donation_id = %d AND status = 'success'",
            $donation_id
        )) > 0;
    }

    private function log_sync(int $donation_id, array $data): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gwdp_sync_log';

        $wpdb->delete($table, ['give_donation_id' => $donation_id, 'status' => 'error']);

        $wpdb->insert($table, [
            'give_donation_id'    => $donation_id,
            'give_donor_id'       => $data['give_donor_id'] ?? null,
            'give_subscription_id'=> $data['give_subscription_id'] ?? null,
            'dp_donor_id'         => $data['dp_donor_id'] ?? null,
            'dp_gift_id'          => $data['dp_gift_id'] ?? null,
            'dp_pledge_id'        => $data['dp_pledge_id'] ?? null,
            'donor_action'        => $data['donor_action'] ?? null,
            'donation_type'       => $data['donation_type'] ?? null,
            'donation_amount'     => $data['donation_amount'] ?? null,
            'status'              => $data['status'],
            'error_message'       => $data['error'] ?? null,
            'synced_at'           => current_time('mysql'),
        ]);

        return $data;
    }

    private function get_amount($donation): float {
        if (isset($donation->amount)) {
            if (is_object($donation->amount) && method_exists($donation->amount, 'formatToDecimal')) {
                return (float) $donation->amount->formatToDecimal();
            }
            return (float) $donation->amount;
        }
        return 0.0;
    }

    private function get_date($donation): string {
        if (isset($donation->createdAt)) {
            if ($donation->createdAt instanceof \DateTime || $donation->createdAt instanceof \DateTimeImmutable) {
                return $donation->createdAt->format('m/d/Y');
            }
            return date('m/d/Y', strtotime((string) $donation->createdAt));
        }
        return date('m/d/Y');
    }

    private function get_donation_type($donation): string {
        if (isset($donation->type)) {
            if (is_object($donation->type) && method_exists($donation->type, 'getValue')) {
                return $donation->type->getValue();
            }
            return (string) $donation->type;
        }
        return 'single';
    }

    private function get_subscription_id($donation): ?int {
        // GiveWP stores subscription ID in donation meta
        if (isset($donation->subscriptionId)) {
            return (int) $donation->subscriptionId ?: null;
        }
        // Fallback: check post meta
        $meta = get_post_meta((int) $donation->id, 'subscription_id', true);
        if ($meta) return (int) $meta;
        $meta = get_post_meta((int) $donation->id, '_give_subscription_id', true);
        return $meta ? (int) $meta : null;
    }

    private function get_subscription_period($donation): string {
        // Try to get billing period from subscription
        $sub_id = $this->get_subscription_id($donation);
        if ($sub_id && class_exists('Give\Subscriptions\Models\Subscription')) {
            $sub = \Give\Subscriptions\Models\Subscription::find($sub_id);
            if ($sub && isset($sub->period)) {
                $period = is_object($sub->period) && method_exists($sub->period, 'getValue')
                    ? $sub->period->getValue() : (string) $sub->period;
                return $period ?: 'month';
            }
        }
        // Fallback
        return 'month';
    }

    // ─── Stats & Logging ───

    public function get_log(int $limit = 100, int $offset = 0, string $status_filter = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gwdp_sync_log';
        $where = $status_filter ? $wpdb->prepare(' WHERE status = %s', $status_filter) : '';
        return $wpdb->get_results(
            "SELECT * FROM {$table}{$where} ORDER BY synced_at DESC LIMIT {$limit} OFFSET {$offset}",
            ARRAY_A
        ) ?: [];
    }

    public function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gwdp_sync_log';

        $stats = $wpdb->get_results("SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", ARRAY_A);
        $result = ['success' => 0, 'error' => 0, 'skipped' => 0, 'total' => 0];
        foreach ($stats as $row) {
            $result[$row['status']] = (int) $row['count'];
            $result['total'] += (int) $row['count'];
        }

        $result['donors_created'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE donor_action='created' AND status='success'");
        $result['donors_matched'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE donor_action='matched' AND status='success'");
        $result['pledges_created'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE dp_pledge_id IS NOT NULL AND donation_type='subscription' AND status='success'");
        $result['recurring_gifts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE donation_type IN ('subscription','renewal') AND status='success'");
        $result['onetime_gifts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE donation_type='single' AND status='success'");
        $result['last_sync'] = $wpdb->get_var("SELECT synced_at FROM {$table} WHERE status='success' ORDER BY synced_at DESC LIMIT 1");

        return $result;
    }

    public function get_give_donation_count(): int {
        if (!class_exists('Give\Donations\Models\Donation')) return 0;
        return \Give\Donations\Models\Donation::query()->count();
    }

    // ─── AJAX Handlers ───

    public function ajax_test_connection(): void {
        check_ajax_referer('gwdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $api = $this->get_api();
        if (!$api) wp_send_json_error('API key not configured');

        // Test 1: Basic SELECT query
        $result = $api->query("SELECT TOP 1 donor_id FROM dp WHERE donor_id > 0");
        if (is_wp_error($result)) {
            wp_send_json_error('API connection failed: ' . $result->get_error_message());
        }

        $donor_count = $api->query("SELECT COUNT(*) AS total FROM dp WHERE donor_id > 0");
        $total = 0;
        if (!is_wp_error($donor_count) && isset($donor_count->record->field)) {
            $total = (int) $donor_count->record->field['value'];
        }

        wp_send_json_success([
            'status'  => 'connected',
            'message' => "API connected successfully. {$total} donors in DonorPerfect.",
            'donors'  => $total,
        ]);
    }

    public function ajax_test_codes(): void {
        check_ajax_referer('gwdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $api = $this->get_api();
        if (!$api) wp_send_json_error('API key not configured');

        $checks = [];

        // Check GL code
        $gl = get_option('gwdp_default_gl_code', 'UN');
        if ($gl) {
            $result = $api->query("SELECT code FROM DPCODES WHERE field_name='GL_CODE' AND code='{$gl}'");
            $checks['gl_code'] = [
                'code'  => $gl,
                'valid' => !is_wp_error($result) && isset($result->record),
            ];
        }

        // Check campaign
        $campaign = get_option('gwdp_default_campaign', '');
        if ($campaign) {
            $result = $api->query("SELECT code FROM DPCODES WHERE field_name='CAMPAIGN' AND code='{$campaign}'");
            $checks['campaign'] = [
                'code'  => $campaign,
                'valid' => !is_wp_error($result) && isset($result->record),
            ];
        }

        // Check ONETIME sub_solicit
        $result = $api->query("SELECT code FROM DPCODES WHERE field_name='SUB_SOLICIT_CODE' AND code='ONETIME'");
        $checks['onetime'] = [
            'code'  => 'ONETIME',
            'valid' => !is_wp_error($result) && isset($result->record),
        ];

        // Check RECURRING sub_solicit
        $result = $api->query("SELECT code FROM DPCODES WHERE field_name='SUB_SOLICIT_CODE' AND code='RECURRING'");
        $checks['recurring'] = [
            'code'  => 'RECURRING',
            'valid' => !is_wp_error($result) && isset($result->record),
        ];

        wp_send_json_success($checks);
    }

    public function ajax_backfill_preview(): void {
        check_ajax_referer('gwdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        wp_send_json_success($this->run_backfill(true, (int) ($_POST['batch_size'] ?? 50), (int) ($_POST['offset'] ?? 0)));
    }

    public function ajax_backfill_run(): void {
        check_ajax_referer('gwdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        wp_send_json_success($this->run_backfill(false, (int) ($_POST['batch_size'] ?? 10), (int) ($_POST['offset'] ?? 0)));
    }

    public function ajax_sync_single(): void {
        check_ajax_referer('gwdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $donation_id = (int) ($_POST['donation_id'] ?? 0);
        if ($donation_id <= 0) wp_send_json_error('Invalid donation ID');
        if (!class_exists('Give\Donations\Models\Donation')) wp_send_json_error('GiveWP not active');

        $donation = \Give\Donations\Models\Donation::find($donation_id);
        if (!$donation) wp_send_json_error('Donation not found');

        wp_send_json_success($this->sync_donation($donation));
    }

    public function ajax_match_report(): void {
        check_ajax_referer('gwdp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $api = $this->get_api();
        if (!$api) wp_send_json_error('API key not configured');
        if (!class_exists('Give\Donors\Models\Donor')) wp_send_json_error('GiveWP not active');

        $donors = \Give\Donors\Models\Donor::query()->getAll();
        $report = [];
        foreach ($donors as $donor) {
            $email = (string) $donor->email;
            if (empty($email)) continue;
            $dp_id = $api->find_donor_by_email($email);
            $report[] = [
                'give_donor_id' => $donor->id,
                'name'          => "{$donor->firstName} {$donor->lastName}",
                'email'         => $email,
                'dp_donor_id'   => $dp_id,
                'action'        => $dp_id ? 'Will match to DP #' . $dp_id : 'Will create new donor',
            ];
        }

        wp_send_json_success([
            'total'   => count($report),
            'matched' => count(array_filter($report, fn($r) => $r['dp_donor_id'] !== null)),
            'new'     => count(array_filter($report, fn($r) => $r['dp_donor_id'] === null)),
            'donors'  => $report,
        ]);
    }

    // ─── Backfill ───

    private function run_backfill(bool $dry_run, int $batch_size = 50, int $offset = 0): array {
        if (!class_exists('Give\Donations\Models\Donation')) {
            return ['error' => 'GiveWP not active', 'items' => [], 'has_more' => false];
        }

        global $wpdb;
        $sync_table  = $wpdb->prefix . 'gwdp_sync_log';
        $posts_table = $wpdb->prefix . 'posts';

        $donation_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$posts_table} p
             WHERE p.post_type = 'give_payment'
             AND p.post_status IN ('publish', 'give_subscription')
             AND p.ID NOT IN (SELECT give_donation_id FROM {$sync_table} WHERE status = 'success')
             ORDER BY p.ID ASC LIMIT %d OFFSET %d",
            $batch_size, $offset
        ));

        $total_unsynced = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts_table} p
             WHERE p.post_type = 'give_payment'
             AND p.post_status IN ('publish', 'give_subscription')
             AND p.ID NOT IN (SELECT give_donation_id FROM {$sync_table} WHERE status = 'success')"
        );

        $results = [];
        foreach ($donation_ids as $id) {
            $donation = \Give\Donations\Models\Donation::find((int) $id);
            if (!$donation) continue;
            $results[] = $this->sync_donation($donation, $dry_run);
            if (!$dry_run) usleep(200000); // 200ms delay
        }

        return [
            'items'          => $results,
            'batch_size'     => $batch_size,
            'offset'         => $offset,
            'processed'      => count($results),
            'total_unsynced' => $total_unsynced,
            'has_more'       => ($offset + $batch_size) < $total_unsynced,
            'dry_run'        => $dry_run,
        ];
    }
}
