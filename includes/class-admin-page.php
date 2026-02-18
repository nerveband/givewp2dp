<?php
/**
 * Admin settings page for GiveWP → DonorPerfect Sync.
 * Provides: settings, sync log, backfill tool, match report.
 */

if (!defined('ABSPATH')) exit;

class GWDP_Admin_Page {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void {
        add_menu_page(
            'Give2DP',
            'Give2DP',
            'manage_options',
            'gwdp-sync',
            [$this, 'render_page'],
            'dashicons-update',
            80
        );
    }

    public function register_settings(): void {
        register_setting('gwdp_settings', 'gwdp_sync_enabled');
        register_setting('gwdp_settings', 'gwdp_api_key');
        register_setting('gwdp_settings', 'gwdp_default_gl_code');
        register_setting('gwdp_settings', 'gwdp_default_campaign');
        register_setting('gwdp_settings', 'gwdp_default_solicit_code');
        register_setting('gwdp_settings', 'gwdp_gateway_map');
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_gwdp-sync') return;
        wp_enqueue_style('gwdp-admin', GWDP_PLUGIN_URL . 'assets/admin.css', [], GWDP_VERSION);
        wp_enqueue_script('gwdp-admin', GWDP_PLUGIN_URL . 'assets/admin.js', ['jquery'], GWDP_VERSION, true);
        wp_localize_script('gwdp-admin', 'gwdp', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('gwdp_admin_nonce'),
        ]);
    }

    public function render_page(): void {
        $sync = GWDP_Donation_Sync::instance();
        $stats = $sync->get_stats();
        $tab = sanitize_text_field($_GET['tab'] ?? 'dashboard');
        ?>
        <div class="wrap gwdp-wrap">
            <h1>GiveWP → DonorPerfect Sync</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=gwdp-sync&tab=dashboard" class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">Dashboard</a>
                <a href="?page=gwdp-sync&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=gwdp-sync&tab=log" class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">Sync Log</a>
                <a href="?page=gwdp-sync&tab=backfill" class="nav-tab <?php echo $tab === 'backfill' ? 'nav-tab-active' : ''; ?>">Backfill</a>
                <a href="?page=gwdp-sync&tab=match" class="nav-tab <?php echo $tab === 'match' ? 'nav-tab-active' : ''; ?>">Match Report</a>
                <a href="?page=gwdp-sync&tab=docs" class="nav-tab <?php echo $tab === 'docs' ? 'nav-tab-active' : ''; ?>">Documentation</a>
            </nav>

            <div class="gwdp-tab-content">
                <?php
                match ($tab) {
                    'settings'  => $this->render_settings(),
                    'log'       => $this->render_log($sync),
                    'backfill'  => $this->render_backfill($sync),
                    'match'     => $this->render_match(),
                    'docs'      => $this->render_docs(),
                    default     => $this->render_dashboard($stats),
                };
                ?>
            </div>
        </div>
        <?php
    }

    // ─── Dashboard ───

    private function render_dashboard(array $stats): void {
        $enabled = get_option('gwdp_sync_enabled', '0') === '1';
        ?>
        <div class="gwdp-status-banner <?php echo $enabled ? 'gwdp-status-on' : 'gwdp-status-off'; ?>">
            <strong>Real-time sync is <?php echo $enabled ? 'ON' : 'OFF'; ?></strong>
            <?php if (!$enabled): ?>
                <span>— New donations are NOT being synced. <a href="?page=gwdp-sync&tab=settings">Enable in Settings</a></span>
            <?php endif; ?>
        </div>

        <div class="gwdp-stats-grid">
            <div class="gwdp-stat-card">
                <div class="gwdp-stat-number"><?php echo esc_html($stats['success']); ?></div>
                <div class="gwdp-stat-label">Synced</div>
            </div>
            <div class="gwdp-stat-card gwdp-stat-error">
                <div class="gwdp-stat-number"><?php echo esc_html($stats['error']); ?></div>
                <div class="gwdp-stat-label">Errors</div>
            </div>
            <div class="gwdp-stat-card">
                <div class="gwdp-stat-number"><?php echo esc_html($stats['skipped']); ?></div>
                <div class="gwdp-stat-label">Skipped</div>
            </div>
            <div class="gwdp-stat-card">
                <div class="gwdp-stat-number"><?php echo esc_html($stats['donors_created']); ?></div>
                <div class="gwdp-stat-label">Donors Created</div>
            </div>
            <div class="gwdp-stat-card">
                <div class="gwdp-stat-number"><?php echo esc_html($stats['donors_matched']); ?></div>
                <div class="gwdp-stat-label">Donors Matched</div>
            </div>
            <div class="gwdp-stat-card">
                <div class="gwdp-stat-number"><?php echo esc_html($stats['pledges_created']); ?></div>
                <div class="gwdp-stat-label">Pledges Created</div>
            </div>
            <div class="gwdp-stat-card">
                <div class="gwdp-stat-number"><?php echo esc_html($stats['recurring_gifts']); ?></div>
                <div class="gwdp-stat-label">Recurring Gifts</div>
            </div>
            <div class="gwdp-stat-card">
                <div class="gwdp-stat-number"><?php echo esc_html($stats['onetime_gifts']); ?></div>
                <div class="gwdp-stat-label">One-Time Gifts</div>
            </div>
        </div>

        <?php if ($stats['last_sync']): ?>
            <p class="gwdp-last-sync">Last successful sync: <strong><?php echo esc_html($stats['last_sync']); ?></strong></p>
        <?php endif; ?>

        <h3>Recent Activity</h3>
        <?php $this->render_log_table(GWDP_Donation_Sync::instance()->get_log(10)); ?>
        <?php
    }

    // ─── Settings ───

    private function render_settings(): void {
        if (isset($_POST['gwdp_settings_nonce']) && wp_verify_nonce($_POST['gwdp_settings_nonce'], 'gwdp_save_settings')) {
            update_option('gwdp_sync_enabled', isset($_POST['gwdp_sync_enabled']) ? '1' : '0');
            update_option('gwdp_api_key', sanitize_text_field($_POST['gwdp_api_key'] ?? ''));
            update_option('gwdp_default_gl_code', sanitize_text_field($_POST['gwdp_default_gl_code'] ?? 'UN'));
            update_option('gwdp_default_campaign', sanitize_text_field($_POST['gwdp_default_campaign'] ?? ''));
            update_option('gwdp_default_solicit_code', sanitize_text_field($_POST['gwdp_default_solicit_code'] ?? ''));

            $gw_map = [];
            if (!empty($_POST['gwdp_gw_keys']) && !empty($_POST['gwdp_gw_values'])) {
                $keys = array_map('sanitize_text_field', $_POST['gwdp_gw_keys']);
                $vals = array_map('sanitize_text_field', $_POST['gwdp_gw_values']);
                foreach ($keys as $i => $key) {
                    if ($key !== '' && isset($vals[$i])) {
                        $gw_map[$key] = $vals[$i];
                    }
                }
            }
            update_option('gwdp_gateway_map', wp_json_encode($gw_map));

            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $enabled  = get_option('gwdp_sync_enabled', '0');
        $api_key  = get_option('gwdp_api_key', '');
        $gl_code  = get_option('gwdp_default_gl_code', 'UN');
        $campaign = get_option('gwdp_default_campaign', '');
        $solicit  = get_option('gwdp_default_solicit_code', '');
        $gw_map   = json_decode(get_option('gwdp_gateway_map', '{}'), true) ?: [];
        ?>
        <form method="post">
            <?php wp_nonce_field('gwdp_save_settings', 'gwdp_settings_nonce'); ?>

            <h2>Sync Control</h2>
            <table class="form-table">
                <tr>
                    <th>Real-Time Sync</th>
                    <td>
                        <label>
                            <input type="checkbox" name="gwdp_sync_enabled" value="1" <?php checked($enabled, '1'); ?>>
                            Enable automatic sync of new donations to DonorPerfect
                        </label>
                        <p class="description">When OFF, new donations will NOT be sent to DonorPerfect. Use the Backfill tab to sync historical data.</p>
                    </td>
                </tr>
            </table>

            <h2>DonorPerfect API</h2>
            <table class="form-table">
                <tr>
                    <th>API Key</th>
                    <td>
                        <input type="password" name="gwdp_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" autocomplete="off">
                        <button type="button" class="button" id="gwdp-toggle-key">Show</button>
                    </td>
                </tr>
                <tr>
                    <th>Connection Tests</th>
                    <td>
                        <button type="button" class="button" id="gwdp-test-api">Test API Connection</button>
                        <button type="button" class="button" id="gwdp-test-codes">Validate Codes</button>
                        <div id="gwdp-api-test-result" class="gwdp-test-results"></div>
                    </td>
                </tr>
            </table>

            <h2>Default Field Mapping</h2>
            <table class="form-table">
                <tr>
                    <th>GL Code</th>
                    <td>
                        <input type="text" name="gwdp_default_gl_code" value="<?php echo esc_attr($gl_code); ?>" class="small-text" placeholder="UN">
                        <p class="description">DonorPerfect GL code for donations (e.g. UN for Unrestricted). Must exist in DPCODES.</p>
                    </td>
                </tr>
                <tr>
                    <th>Campaign</th>
                    <td>
                        <input type="text" name="gwdp_default_campaign" value="<?php echo esc_attr($campaign); ?>" class="regular-text" placeholder="Leave blank for none">
                        <p class="description">DonorPerfect campaign code. Must exist in DPCODES. Leave blank for none.</p>
                    </td>
                </tr>
                <tr>
                    <th>Solicit Code</th>
                    <td>
                        <input type="text" name="gwdp_default_solicit_code" value="<?php echo esc_attr($solicit); ?>" class="regular-text" placeholder="Leave blank for none">
                        <p class="description">Sub-solicit code is set automatically: ONETIME or RECURRING.</p>
                    </td>
                </tr>
            </table>

            <h2>Gateway → Gift Type Mapping</h2>
            <p class="description">Map GiveWP payment gateways to DonorPerfect gift type codes (CC, PAYPAL, CK, ACH, etc.)</p>
            <table class="widefat gwdp-gateway-map" id="gwdp-gateway-table">
                <thead>
                    <tr><th>GiveWP Gateway ID</th><th>DP Gift Type</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($gw_map as $key => $val): ?>
                    <tr>
                        <td><input type="text" name="gwdp_gw_keys[]" value="<?php echo esc_attr($key); ?>" class="regular-text"></td>
                        <td><input type="text" name="gwdp_gw_values[]" value="<?php echo esc_attr($val); ?>" class="small-text"></td>
                        <td><button type="button" class="button gwdp-remove-row">Remove</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button" id="gwdp-add-gateway">+ Add Gateway</button>

            <?php submit_button('Save Settings'); ?>
        </form>
        <?php
    }

    // ─── Sync Log ───

    private function render_log(GWDP_Donation_Sync $sync): void {
        $filter = sanitize_text_field($_GET['status'] ?? '');
        $page_num = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 50;
        $offset = ($page_num - 1) * $per_page;
        $rows = $sync->get_log($per_page, $offset, $filter);
        ?>
        <h2>Sync Log</h2>
        <div class="gwdp-log-filters">
            <a href="?page=gwdp-sync&tab=log" class="button <?php echo $filter === '' ? 'button-primary' : ''; ?>">All</a>
            <a href="?page=gwdp-sync&tab=log&status=success" class="button <?php echo $filter === 'success' ? 'button-primary' : ''; ?>">Success</a>
            <a href="?page=gwdp-sync&tab=log&status=error" class="button <?php echo $filter === 'error' ? 'button-primary' : ''; ?>">Errors</a>
            <a href="?page=gwdp-sync&tab=log&status=skipped" class="button <?php echo $filter === 'skipped' ? 'button-primary' : ''; ?>">Skipped</a>
        </div>
        <?php $this->render_log_table($rows); ?>

        <div class="gwdp-pagination">
            <?php if ($page_num > 1): ?>
                <a href="?page=gwdp-sync&tab=log&status=<?php echo esc_attr($filter); ?>&paged=<?php echo $page_num - 1; ?>" class="button">&laquo; Previous</a>
            <?php endif; ?>
            <?php if (count($rows) === $per_page): ?>
                <a href="?page=gwdp-sync&tab=log&status=<?php echo esc_attr($filter); ?>&paged=<?php echo $page_num + 1; ?>" class="button">Next &raquo;</a>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─── Backfill ───

    private function render_backfill(GWDP_Donation_Sync $sync): void {
        $total_give = $sync->get_give_donation_count();
        $stats = $sync->get_stats();
        ?>
        <h2>Historical Backfill</h2>

        <div class="gwdp-backfill-info">
            <p><strong>GiveWP donations:</strong> <?php echo esc_html($total_give); ?> total</p>
            <p><strong>Already synced:</strong> <?php echo esc_html($stats['success']); ?></p>
            <p><strong>Remaining:</strong> ~<?php echo max(0, $total_give - $stats['success']); ?></p>
        </div>

        <div class="gwdp-backfill-controls">
            <h3>Step 1: Preview (Dry Run)</h3>
            <p>See what would happen without making any changes.</p>
            <button type="button" class="button button-secondary" id="gwdp-backfill-preview">Run Preview (50 donations)</button>

            <h3>Step 2: Sync</h3>
            <p>Send donations to DonorPerfect in batches of 10 (with 200ms delay between each).</p>
            <button type="button" class="button button-primary" id="gwdp-backfill-run" disabled>Start Backfill</button>
            <button type="button" class="button" id="gwdp-backfill-stop" style="display:none;">Stop</button>
        </div>

        <div id="gwdp-backfill-progress" style="display:none;">
            <div class="gwdp-progress-bar">
                <div class="gwdp-progress-fill" style="width:0%"></div>
            </div>
            <p class="gwdp-progress-text">Processing...</p>
        </div>

        <h3>Results</h3>
        <div id="gwdp-backfill-results">
            <p class="description">Run a preview or backfill to see results here.</p>
        </div>

        <h3>Sync Single Donation</h3>
        <div class="gwdp-single-sync">
            <input type="number" id="gwdp-single-id" placeholder="GiveWP Donation ID" min="1">
            <button type="button" class="button" id="gwdp-sync-single">Sync This Donation</button>
            <span id="gwdp-single-result"></span>
        </div>
        <?php
    }

    // ─── Match Report ───

    private function render_match(): void {
        ?>
        <h2>Donor Match Report</h2>
        <p>Preview how GiveWP donors will match to DonorPerfect records (by email address).</p>
        <p class="description">This checks each GiveWP donor's email against DonorPerfect. No data is modified.</p>

        <button type="button" class="button button-primary" id="gwdp-match-report">Generate Match Report</button>

        <div id="gwdp-match-loading" style="display:none;">
            <span class="spinner is-active" style="float:none;"></span> Checking donors... This may take a moment.
        </div>

        <div id="gwdp-match-results" style="display:none;">
            <div class="gwdp-match-summary">
                <span class="gwdp-match-total">Total: <strong id="gwdp-match-total">0</strong></span>
                <span class="gwdp-match-found">Matched: <strong id="gwdp-match-found">0</strong></span>
                <span class="gwdp-match-new">New: <strong id="gwdp-match-new">0</strong></span>
            </div>
            <table class="widefat striped" id="gwdp-match-table">
                <thead>
                    <tr>
                        <th>GiveWP ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>DP Donor ID</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <?php
    }

    // ─── Documentation ───

    private function render_docs(): void {
        ?>
        <div class="gwdp-docs">

        <h2>How Give2DP Works</h2>

        <div class="gwdp-docs-section">
            <h3>Overview</h3>
            <p>This plugin connects <strong>GiveWP</strong> (your WordPress donation form) to <strong>DonorPerfect</strong> (your donor management system). When someone makes a donation through GiveWP, this plugin automatically creates the corresponding donor record and gift in DonorPerfect.</p>
        </div>

        <div class="gwdp-docs-section">
            <h3>One-Time Donations</h3>
            <ol>
                <li>A donor completes a one-time donation via your GiveWP form</li>
                <li>The plugin searches DonorPerfect for an existing donor with the same email address</li>
                <li>If found, it uses the existing DP donor record. If not, it creates a new one.</li>
                <li>A gift is created in DonorPerfect with <code>sub_solicit_code = ONETIME</code></li>
                <li>The sync is logged with the DP donor ID and gift ID</li>
            </ol>
        </div>

        <div class="gwdp-docs-section">
            <h3>Recurring Donations</h3>
            <p>Recurring donations use DonorPerfect's native <strong>pledge</strong> system:</p>
            <ol>
                <li><strong>First payment:</strong> Creates a DP pledge (open-ended, <code>total=0</code>) + a gift linked to that pledge</li>
                <li><strong>Renewal payments:</strong> Creates a new gift linked to the existing pledge via <code>plink</code></li>
            </ol>
            <p>The plugin maps GiveWP subscription IDs to DP pledge IDs so renewals are correctly linked.</p>

            <table class="widefat gwdp-docs-table">
                <thead><tr><th>GiveWP Event</th><th>DP Action</th><th>Sub-Solicit</th></tr></thead>
                <tbody>
                    <tr><td>One-time donation</td><td>Create gift</td><td>ONETIME</td></tr>
                    <tr><td>First recurring payment</td><td>Create pledge + linked gift</td><td>RECURRING</td></tr>
                    <tr><td>Renewal payment</td><td>Create gift linked to existing pledge</td><td>RECURRING</td></tr>
                </tbody>
            </table>
        </div>

        <div class="gwdp-docs-section">
            <h3>Donor Matching</h3>
            <p>Donors are matched by <strong>email address</strong>. When a donation comes in, the plugin runs:</p>
            <code>SELECT TOP 1 donor_id FROM dp WHERE email='donor@example.com'</code>
            <p>If a match is found, the existing DP donor is used. If no match, a new donor is created via <code>dp_savedonor</code>.</p>
        </div>

        <div class="gwdp-docs-section">
            <h3>Settings Explained</h3>
            <table class="widefat gwdp-docs-table">
                <thead><tr><th>Setting</th><th>What It Does</th></tr></thead>
                <tbody>
                    <tr><td><strong>Real-Time Sync</strong></td><td>When ON, every new donation is automatically synced to DP. When OFF, nothing is sent — use Backfill to sync manually.</td></tr>
                    <tr><td><strong>API Key</strong></td><td>Your DonorPerfect XML API key. Get it from DP Admin > My Settings > API Keys.</td></tr>
                    <tr><td><strong>GL Code</strong></td><td>Default General Ledger code assigned to gifts (e.g. <code>UN</code> for Unrestricted). Must exist in DP's DPCODES table.</td></tr>
                    <tr><td><strong>Campaign</strong></td><td>Default campaign code assigned to gifts. Must exist in DPCODES. Leave blank for none.</td></tr>
                    <tr><td><strong>Solicit Code</strong></td><td>Optional solicit code. Sub-solicit is set automatically (ONETIME or RECURRING).</td></tr>
                    <tr><td><strong>Gateway Mapping</strong></td><td>Maps GiveWP payment gateways to DP gift type codes. E.g. <code>stripe</code> → <code>CC</code>, <code>paypal</code> → <code>PAYPAL</code>, <code>manual</code> → <code>CK</code>.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="gwdp-docs-section">
            <h3>Using the Tabs</h3>
            <table class="widefat gwdp-docs-table">
                <thead><tr><th>Tab</th><th>Purpose</th></tr></thead>
                <tbody>
                    <tr><td><strong>Dashboard</strong></td><td>Overview of sync stats (success/error counts, donors created/matched, recent activity).</td></tr>
                    <tr><td><strong>Settings</strong></td><td>Configure API key, field mappings, gateway mappings. Test your connection and validate codes.</td></tr>
                    <tr><td><strong>Sync Log</strong></td><td>View every sync attempt with status, DP IDs, and error messages. Filter by status.</td></tr>
                    <tr><td><strong>Backfill</strong></td><td>Sync historical donations. Run a Preview first (dry run, no data sent), then Start Backfill. You can also sync a single donation by ID.</td></tr>
                    <tr><td><strong>Match Report</strong></td><td>Preview how GiveWP donors will map to DP records. Read-only — no data is modified.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="gwdp-docs-section">
            <h3>Recommended First Steps</h3>
            <ol>
                <li>Enter your API key in <strong>Settings</strong> and click <strong>Test API Connection</strong></li>
                <li>Click <strong>Validate Codes</strong> to confirm your GL code, campaign, and sub-solicit codes exist in DP</li>
                <li>Go to <strong>Match Report</strong> to preview how donors will be matched</li>
                <li>Go to <strong>Backfill</strong> and run a <strong>Preview</strong> of 50 donations to see what would happen</li>
                <li>When satisfied, run the actual <strong>Backfill</strong> to sync historical donations</li>
                <li>Enable <strong>Real-Time Sync</strong> in Settings to start syncing new donations automatically</li>
            </ol>
        </div>

        <div class="gwdp-docs-section">
            <h3>Prerequisites in DonorPerfect</h3>
            <p>Before syncing, ensure these codes exist in your DonorPerfect system:</p>
            <ul>
                <li><strong>ONETIME</strong> — sub_solicit_code for one-time donations</li>
                <li><strong>RECURRING</strong> — sub_solicit_code for recurring donations</li>
                <li>Your <strong>GL code</strong> (e.g. <code>UN</code>) in the GL_CODE field of DPCODES</li>
                <li>Your <strong>campaign code</strong> (if using one) in the CAMPAIGN field of DPCODES</li>
            </ul>
            <p>Use the <strong>Validate Codes</strong> button in Settings to check these automatically.</p>
        </div>

        <div class="gwdp-docs-section">
            <h3>Database Tables</h3>
            <p>The plugin creates two tables on activation:</p>
            <ul>
                <li><code><?php echo esc_html($GLOBALS['wpdb']->prefix); ?>gwdp_sync_log</code> — logs every sync attempt with Give donation ID, DP donor/gift/pledge IDs, status, and errors</li>
                <li><code><?php echo esc_html($GLOBALS['wpdb']->prefix); ?>gwdp_pledge_map</code> — maps GiveWP subscription IDs to DP pledge IDs for linking renewal payments</li>
            </ul>
        </div>

        <div class="gwdp-docs-section">
            <h3>About</h3>
            <p>
                <strong>GiveWP to DonorPerfect Sync</strong> v<?php echo esc_html(GWDP_VERSION); ?><br>
                By <a href="https://ashrafali.net" target="_blank">Ashraf Ali</a><br>
                <a href="https://github.com/nerveband/givewp-donorperfect-sync" target="_blank">GitHub Repository</a> &middot; MIT License
            </p>
        </div>

        </div>
        <?php
    }

    // ─── Shared Log Table ───

    private function render_log_table(array $rows): void {
        if (empty($rows)) {
            echo '<p class="description">No sync records found.</p>';
            return;
        }
        ?>
        <table class="widefat striped gwdp-log-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Give #</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Donor Action</th>
                    <th>DP Donor</th>
                    <th>DP Gift</th>
                    <th>DP Pledge</th>
                    <th>Status</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr class="gwdp-row-<?php echo esc_attr($row['status']); ?>">
                    <td><?php echo esc_html($row['synced_at']); ?></td>
                    <td><?php echo esc_html($row['give_donation_id']); ?></td>
                    <td><?php echo esc_html($row['donation_type'] ?? '—'); ?></td>
                    <td><?php echo $row['donation_amount'] ? '$' . esc_html(number_format((float)$row['donation_amount'], 2)) : '—'; ?></td>
                    <td><?php echo esc_html($row['donor_action'] ?? '—'); ?></td>
                    <td><?php echo $row['dp_donor_id'] ? '#' . esc_html($row['dp_donor_id']) : '—'; ?></td>
                    <td><?php echo $row['dp_gift_id'] ? '#' . esc_html($row['dp_gift_id']) : '—'; ?></td>
                    <td><?php echo $row['dp_pledge_id'] ? '#' . esc_html($row['dp_pledge_id']) : '—'; ?></td>
                    <td><span class="gwdp-badge gwdp-badge-<?php echo esc_attr($row['status']); ?>"><?php echo esc_html($row['status']); ?></span></td>
                    <td class="gwdp-error-cell"><?php echo esc_html($row['error_message'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
