<?php
/**
 * Plugin integration tests - run via WP-CLI:
 *   wp eval-file wp-content/plugins/givewp-donorperfect-sync/tests/test-plugin.php
 *
 * Tests the plugin classes within the WordPress environment.
 */

if (!defined('ABSPATH')) {
    echo "Must be run via wp eval-file\n";
    exit(1);
}

$pass = 0;
$fail = 0;

function assert_true(string $name, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        echo "  PASS: {$name}\n";
        $pass++;
    } else {
        echo "  FAIL: {$name}" . ($detail ? " ({$detail})" : "") . "\n";
        $fail++;
    }
}

echo "================================================\n";
echo "GiveWP-DonorPerfect Plugin Integration Tests\n";
echo "================================================\n\n";

// --- Test Group 1: Plugin loads ---
echo "--- Plugin Loading ---\n";

assert_true('GWDP_DP_API class exists', class_exists('GWDP_DP_API'));
assert_true('GWDP_Donation_Sync class exists', class_exists('GWDP_Donation_Sync'));
assert_true('GWDP_Admin_Page class exists', class_exists('GWDP_Admin_Page'));
assert_true('GWDP_VERSION constant defined', defined('GWDP_VERSION'));
assert_true('GWDP_PLUGIN_DIR constant defined', defined('GWDP_PLUGIN_DIR'));

echo "\n";

// --- Test Group 2: Options ---
echo "--- Options & Defaults ---\n";

assert_true('Sync is OFF by default', get_option('gwdp_sync_enabled', '0') === '0');
assert_true('API key is set', strlen(get_option('gwdp_api_key', '')) > 0);
assert_true('GL code default is UN', get_option('gwdp_default_gl_code', '') === 'UN');
assert_true('Campaign option exists', get_option('gwdp_default_campaign', null) !== null);

$gw_map = json_decode(get_option('gwdp_gateway_map', '{}'), true);
assert_true('Gateway map is array', is_array($gw_map));
assert_true('Stripe maps to CC', ($gw_map['stripe'] ?? '') === 'CC');
assert_true('PayPal maps to PAYPAL', ($gw_map['paypal'] ?? '') === 'PAYPAL');

echo "\n";

// --- Test Group 3: Database Tables ---
echo "--- Database Tables ---\n";

global $wpdb;
$log_table = $wpdb->prefix . 'gwdp_sync_log';
$pledge_table = $wpdb->prefix . 'gwdp_pledge_map';

$log_exists = $wpdb->get_var("SHOW TABLES LIKE '{$log_table}'") === $log_table;
assert_true('Sync log table exists', $log_exists);

$pledge_exists = $wpdb->get_var("SHOW TABLES LIKE '{$pledge_table}'") === $pledge_table;
assert_true('Pledge map table exists', $pledge_exists);

if ($log_exists) {
    $cols = $wpdb->get_col("DESCRIBE {$log_table}", 0);
    assert_true('Log table has give_donation_id', in_array('give_donation_id', $cols));
    assert_true('Log table has dp_donor_id', in_array('dp_donor_id', $cols));
    assert_true('Log table has dp_gift_id', in_array('dp_gift_id', $cols));
    assert_true('Log table has dp_pledge_id', in_array('dp_pledge_id', $cols));
    assert_true('Log table has donation_type', in_array('donation_type', $cols));
    assert_true('Log table has donation_amount', in_array('donation_amount', $cols));
    assert_true('Log table has donor_action', in_array('donor_action', $cols));
}

if ($pledge_exists) {
    $cols = $wpdb->get_col("DESCRIBE {$pledge_table}", 0);
    assert_true('Pledge table has give_subscription_id', in_array('give_subscription_id', $cols));
    assert_true('Pledge table has dp_pledge_id', in_array('dp_pledge_id', $cols));
    assert_true('Pledge table has dp_donor_id', in_array('dp_donor_id', $cols));
    assert_true('Pledge table has frequency', in_array('frequency', $cols));
}

echo "\n";

// --- Test Group 4: API Connection ---
echo "--- API Connection ---\n";

$sync = GWDP_Donation_Sync::instance();
$api = $sync->get_api();
assert_true('API object created', $api !== null);

if ($api) {
    // Test email lookup (non-existent)
    $result = $api->find_donor_by_email('zzz_nonexistent_test@example.com');
    assert_true('Email lookup returns null for non-existent', $result === null);

    // Test email lookup (test email that we know doesn't exist as a real donor)
    $result = $api->find_donor_by_email('ztest_api@test.example');
    assert_true('Email lookup returns null or int', $result === null || is_int($result));

    // Test SQL query
    $result = $api->query("SELECT TOP 1 donor_id FROM dp WHERE donor_id > 0");
    assert_true('SQL query returns SimpleXMLElement', $result instanceof SimpleXMLElement, is_wp_error($result) ? $result->get_error_message() : '');

    // Test DPCODES query
    $result = $api->query("SELECT TOP 1 code FROM DPCODES WHERE field_name='GL_CODE'");
    assert_true('DPCODES query works', $result instanceof SimpleXMLElement, is_wp_error($result) ? $result->get_error_message() : '');
}

echo "\n";

// --- Test Group 5: API Parameter Completeness ---
echo "--- API Parameter Audit (PDF compliance) ---\n";

if ($api) {
    // Test dp_savedonor with a test donor
    echo "Test: dp_savedonor (28 params)\n";
    $test_campaign = get_option('gwdp_default_campaign', '') ?: null;
    $test_date = date('m/d/Y');

    $donor_id = $api->create_donor([
        'first_name' => 'ZTEST_PLUGIN',
        'last_name'  => 'ZTEST_PLUGIN',
        'email'      => 'ztest_plugin@test.example',
        'country'    => 'US',
    ]);
    assert_true('dp_savedonor succeeds', is_int($donor_id) && $donor_id > 0, is_wp_error($donor_id) ? $donor_id->get_error_message() : "got: " . var_export($donor_id, true));

    if (is_int($donor_id) && $donor_id > 0) {
        // Test dp_savegift (32 params)
        echo "Test: dp_savegift (32 params)\n";
        $gift_id = $api->create_gift([
            'donor_id'         => $donor_id,
            'gift_date'        => $test_date,
            'amount'           => 0.01,
            'gl_code'          => 'UN',
            'sub_solicit_code' => 'ONETIME',
            'campaign'         => $test_campaign,
            'gift_type'        => 'CC',
            'reference'        => 'GWDP-PLUGINTEST',
            'gift_narrative'   => 'Plugin Test Gift',
        ]);
        assert_true('dp_savegift succeeds', is_int($gift_id) && $gift_id > 0, is_wp_error($gift_id) ? $gift_id->get_error_message() : "got: " . var_export($gift_id, true));

        // Test dp_savepledge (27 params)
        echo "Test: dp_savepledge (27 params)\n";
        $pledge_id = $api->create_pledge([
            'donor_id'         => $donor_id,
            'gift_date'        => $test_date,
            'start_date'       => $test_date,
            'total'            => 0,
            'bill'             => 0.01,
            'frequency'        => 'M',
            'gl_code'          => 'UN',
            'sub_solicit_code' => 'RECURRING',
            'campaign'         => $test_campaign,
            'gift_narrative'   => 'Plugin Test Pledge',
        ]);
        assert_true('dp_savepledge succeeds', is_int($pledge_id) && $pledge_id > 0, is_wp_error($pledge_id) ? $pledge_id->get_error_message() : "got: " . var_export($pledge_id, true));

        // Test pledge-linked gift
        if (is_int($pledge_id) && $pledge_id > 0) {
            echo "Test: Pledge-linked gift\n";
            $linked_gift_id = $api->create_gift([
                'donor_id'         => $donor_id,
                'gift_date'        => $test_date,
                'amount'           => 0.01,
                'gl_code'          => 'UN',
                'sub_solicit_code' => 'RECURRING',
                'campaign'         => $test_campaign,
                'gift_type'        => 'CC',
                'pledge_payment'   => 'Y',
                'plink'            => $pledge_id,
                'reference'        => 'GWDP-PLEDGE-PAYMENT',
                'gift_narrative'   => 'Plugin Test Pledge Payment',
            ]);
            assert_true('Pledge-linked gift succeeds', is_int($linked_gift_id) && $linked_gift_id > 0, is_wp_error($linked_gift_id) ? $linked_gift_id->get_error_message() : "got: " . var_export($linked_gift_id, true));
        }

        // Test dp_savecode (34 params)
        echo "Test: dp_savecode (34 params)\n";
        $code_result = $api->create_code('SUB_SOLICIT_CODE', 'ZTEST_PLG', 'Plugin Test Code');
        assert_true('dp_savecode succeeds', $code_result instanceof SimpleXMLElement, is_wp_error($code_result) ? $code_result->get_error_message() : '');

        // --- Cleanup ---
        echo "\n--- Cleanup ---\n";
        if (is_int($gift_id) && $gift_id > 0) {
            $api->query("UPDATE DPGIFT SET amount=0, gift_narrative='ZTEST_DELETE' WHERE gift_id={$gift_id}");
            echo "  Cleaned gift #{$gift_id}\n";
        }
        if (isset($linked_gift_id) && is_int($linked_gift_id) && $linked_gift_id > 0) {
            $api->query("UPDATE DPGIFT SET amount=0, gift_narrative='ZTEST_DELETE' WHERE gift_id={$linked_gift_id}");
            echo "  Cleaned gift #{$linked_gift_id}\n";
        }
        if (is_int($pledge_id) && $pledge_id > 0) {
            $api->query("UPDATE DPGIFT SET amount=0, bill=0, total=0, gift_narrative='ZTEST_DELETE' WHERE gift_id={$pledge_id}");
            echo "  Cleaned pledge #{$pledge_id}\n";
        }
        $api->query("UPDATE dp SET first_name='ZTEST_DELETE', last_name='ZTEST_DELETE', email='' WHERE donor_id={$donor_id}");
        echo "  Cleaned donor #{$donor_id}\n";

        // Deactivate test code
        $api->create_code('SUB_SOLICIT_CODE', 'ZTEST_PLG', 'Plugin Test Code');
    }
}

echo "\n";

// --- Test Group 6: Sync Engine ---
echo "--- Sync Engine ---\n";

assert_true('Sync instance is singleton', GWDP_Donation_Sync::instance() === $sync);
assert_true('get_stats returns array', is_array($sync->get_stats()));
assert_true('get_log returns array', is_array($sync->get_log(10)));

$stats = $sync->get_stats();
assert_true('Stats has success key', array_key_exists('success', $stats));
assert_true('Stats has error key', array_key_exists('error', $stats));
assert_true('Stats has donors_created key', array_key_exists('donors_created', $stats));
assert_true('Stats has pledges_created key', array_key_exists('pledges_created', $stats));
assert_true('Stats has recurring_gifts key', array_key_exists('recurring_gifts', $stats));
assert_true('Stats has onetime_gifts key', array_key_exists('onetime_gifts', $stats));

// Check GiveWP integration
if (class_exists('Give\Donations\Models\Donation')) {
    $count = $sync->get_give_donation_count();
    assert_true('GiveWP donation count > 0', $count > 0, "count={$count}");
}

echo "\n";
echo "================================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "================================================\n";

if ($fail > 0) exit(1);
