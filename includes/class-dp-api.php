<?php
/**
 * DonorPerfect XML API Client
 * Uses named parameter format for stored procedure calls.
 */

if (!defined('ABSPATH')) exit;

class GWDP_DP_API {

    private string $api_key;
    private string $base_url = 'https://www.donorperfect.net/prod/xmlrequest.asp';

    public function __construct(string $api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Execute a stored procedure with named parameters.
     * Returns parsed XML as SimpleXMLElement or WP_Error.
     */
    public function call_procedure(string $action, array $params): SimpleXMLElement|WP_Error {
        $param_parts = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                $param_parts[] = "@{$key}=null";
            } elseif (is_numeric($value) && !is_string($value)) {
                $param_parts[] = "@{$key}={$value}";
            } else {
                $escaped = str_replace("'", "''", (string) $value);
                $param_parts[] = "@{$key}='{$escaped}'";
            }
        }

        $url = add_query_arg([
            'apikey' => $this->api_key,
            'action' => $action,
            'params' => implode(',', $param_parts),
        ], $this->base_url);

        return $this->execute($url);
    }

    /**
     * Execute a direct SQL query (SELECT/UPDATE only).
     */
    public function query(string $sql): SimpleXMLElement|WP_Error {
        $url = add_query_arg([
            'apikey' => $this->api_key,
            'action' => $sql,
        ], $this->base_url);

        return $this->execute($url);
    }

    /**
     * Search for a donor by email address.
     * Returns donor_id if found, null if not found.
     */
    public function find_donor_by_email(string $email): ?int {
        $escaped = str_replace("'", "''", $email);
        $result = $this->query("SELECT TOP 1 donor_id FROM dp WHERE email='{$escaped}'");

        if (is_wp_error($result)) {
            return null;
        }

        $record = $result->record ?? null;
        if (!$record) {
            return null;
        }

        foreach ($record->field as $field) {
            if ((string) $field['name'] === 'donor_id') {
                return (int) $field['value'];
            }
        }

        return null;
    }

    /**
     * Create a new donor in DonorPerfect.
     * Returns new donor_id or WP_Error.
     */
    public function create_donor(array $donor_data): int|WP_Error {
        $params = array_merge([
            'donor_id'       => 0,
            'first_name'     => null,
            'last_name'      => null,
            'middle_name'    => null,
            'suffix'         => null,
            'title'          => null,
            'salutation'     => null,
            'prof_title'     => null,
            'opt_line'       => null,
            'address'        => null,
            'address2'       => null,
            'city'           => null,
            'state'          => null,
            'zip'            => null,
            'country'        => null,
            'address_type'   => null,
            'home_phone'     => null,
            'business_phone' => null,
            'fax_phone'      => null,
            'mobile_phone'   => null,
            'email'          => null,
            'org_rec'        => 'N',
            'donor_type'     => 'IN',
            'nomail'         => 'N',
            'nomail_reason'  => null,
            'narrative'      => null,
            'donor_rcpt_type'=> 'I',
            'user_id'        => 'GiveWP_Sync',
        ], $donor_data);

        $result = $this->call_procedure('dp_savedonor', $params);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->extract_id($result);
    }

    /**
     * Create a new gift in DonorPerfect.
     * Returns new gift_id or WP_Error.
     */
    public function create_gift(array $gift_data): int|WP_Error {
        $params = array_merge([
            'gift_id'          => 0,
            'donor_id'         => 0,
            'record_type'      => 'G',
            'gift_date'        => null,
            'amount'           => 0,
            'gl_code'          => 'UN',
            'solicit_code'     => null,
            'sub_solicit_code' => null,
            'campaign'         => null,
            'gift_type'        => 'CC',
            'split_gift'       => 'N',
            'pledge_payment'   => 'N',
            'reference'        => null,
            'transaction_id'   => null,
            'memory_honor'     => null,
            'gfname'           => null,
            'glname'           => null,
            'fmv'              => 0,
            'batch_no'         => 0,
            'gift_narrative'   => null,
            'ty_letter_no'     => null,
            'glink'            => null,
            'plink'            => null,
            'nocalc'           => 'N',
            'receipt'          => 'N',
            'old_amount'       => null,
            'user_id'          => 'GiveWP_Sync',
            'gift_aid_date'    => null,
            'gift_aid_amt'     => null,
            'gift_aid_eligible_g' => null,
            'currency'         => 'USD',
            'first_gift'       => 'N',
        ], $gift_data);

        $result = $this->call_procedure('dp_savegift', $params);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->extract_id($result);
    }

    /**
     * Create a pledge (for recurring donations) in DonorPerfect.
     * @total=0 means open-ended/ad infinitum (no outstanding balance).
     * Returns new pledge gift_id or WP_Error.
     */
    public function create_pledge(array $pledge_data): int|WP_Error {
        $params = array_merge([
            'gift_id'            => 0,
            'donor_id'           => 0,
            'gift_date'          => null,
            'start_date'         => null,
            'total'              => 0,        // 0 = ad infinitum (open-ended)
            'bill'               => 0,        // Monthly billing amount
            'frequency'          => 'M',      // M=monthly, Q=quarterly, S=semi-annually, A=annually
            'reminder'           => 'N',
            'gl_code'            => 'UN',
            'solicit_code'       => null,
            'initial_payment'    => 'Y',
            'sub_solicit_code'   => null,
            'writeoff_amount'    => 0,
            'writeoff_date'      => null,
            'user_id'            => 'GiveWP_Sync',
            'campaign'           => null,
            'membership_type'    => null,
            'membership_level'   => null,
            'membership_enr_date'=> null,
            'membership_exp_date'=> null,
            'membership_link_ID' => null,
            'address_id'         => null,
            'gift_narrative'     => null,
            'ty_letter_no'       => null,
            'vault_id'           => null,
            'receipt_delivery_g' => null,
            'contact_id'         => null,
        ], $pledge_data);

        $result = $this->call_procedure('dp_savepledge', $params);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->extract_id($result);
    }

    /**
     * Create a code value in DPCODES.
     */
    public function create_code(string $field_name, string $code, string $description): SimpleXMLElement|WP_Error {
        return $this->call_procedure('dp_savecode', [
            'field_name'       => $field_name,
            'code'             => $code,
            'description'      => $description,
            'original_code'    => null,
            'code_date'        => null,
            'mcat_hi'          => null,
            'mcat_lo'          => null,
            'mcat_gl'          => null,
            'reciprocal'       => null,
            'mailed'           => null,
            'printing'         => null,
            'other'            => null,
            'goal'             => null,
            'acct_num'         => null,
            'campaign'         => null,
            'solicit_code'     => null,
            'overwrite'        => null,
            'inactive'         => 'N',
            'client_id'        => null,
            'available_for_sol'=> null,
            'user_id'          => 'GiveWP_Sync',
            'cashact'          => null,
            'membership_type'  => null,
            'leeway_days'      => null,
            'comments'         => null,
            'begin_date'       => null,
            'end_date'         => null,
            'ty_prioritize'    => null,
            'ty_filter_id'     => null,
            'ty_gift_option'   => null,
            'ty_amount_option' => null,
            'ty_from_amount'   => null,
            'ty_to_amount'     => null,
            'ty_alternate'     => null,
            'ty_priority'      => null,
        ]);
    }

    /**
     * Execute HTTP request and parse XML response.
     */
    private function execute(string $url): SimpleXMLElement|WP_Error {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return new WP_Error('dp_api_http_error', "HTTP {$code}: {$body}");
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if ($xml === false) {
            return new WP_Error('dp_api_xml_error', "Failed to parse XML: {$body}");
        }

        // Check for API error response
        if (isset($xml->field) && (string) ($xml->field['value'] ?? '') === 'false') {
            $reason = (string) ($xml->field['reason'] ?? 'Unknown error');
            return new WP_Error('dp_api_error', $reason);
        }

        if (isset($xml->error)) {
            return new WP_Error('dp_api_error', (string) $xml->error);
        }

        return $xml;
    }

    /**
     * Extract the returned ID from a save procedure response.
     */
    private function extract_id(SimpleXMLElement $xml): int|WP_Error {
        if (isset($xml->record->field)) {
            return (int) $xml->record->field['value'];
        }

        return new WP_Error('dp_api_no_id', 'No ID returned from API');
    }
}
