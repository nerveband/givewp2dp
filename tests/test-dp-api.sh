#!/usr/bin/env bash
# =============================================================================
# DonorPerfect API Integration Tests
# Tests all stored procedures and SQL queries against the live DP API.
# Creates test data, verifies, then cleans up.
#
# Usage:  ./tests/test-dp-api.sh
# Requires: DONORPERFECT_API_KEY env var or ../donorperfect/.env file
# =============================================================================

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$(dirname "$PROJECT_DIR")/donorperfect/.env"

# Load API key
if [[ -z "${DONORPERFECT_API_KEY:-}" ]] && [[ -f "$ENV_FILE" ]]; then
    DONORPERFECT_API_KEY=$(grep '^DONORPERFECT_API_KEY=' "$ENV_FILE" | head -1 | cut -d= -f2-)
fi

if [[ -z "${DONORPERFECT_API_KEY:-}" ]]; then
    echo "FAIL: DONORPERFECT_API_KEY not set and .env not found at $ENV_FILE"
    exit 1
fi

BASE='https://www.donorperfect.net/prod/xmlrequest.asp'
TODAY=$(date +%m/%d/%Y)
PASS=0
FAIL=0
TEST_DONOR_ID=""
TEST_GIFT_ID=""
TEST_PLEDGE_ID=""

# ─── Helpers ───

dp_query() {
    curl -sf "${BASE}?apikey=${DONORPERFECT_API_KEY}" --data-urlencode "action=$1" -G 2>/dev/null
}

dp_proc() {
    curl -sf "${BASE}?apikey=${DONORPERFECT_API_KEY}&action=$1&params=$2" 2>/dev/null
}

extract_value() {
    # Extract first field value from XML result
    echo "$1" | sed -n "s/.*value='\([^']*\)'.*/\1/p" | head -1
}

extract_field() {
    # Extract value of a specific field name from XML
    local xml="$1" field="$2"
    echo "$xml" | sed -n "s/.*name='${field}'[^>]*value='\([^']*\)'.*/\1/p" | head -1
}

assert_contains() {
    local test_name="$1" output="$2" expected="$3"
    if echo "$output" | grep -q "$expected"; then
        echo "  PASS: $test_name"
        ((PASS++))
    else
        echo "  FAIL: $test_name (expected '$expected' in output)"
        echo "        Got: $(echo "$output" | head -c 200)"
        ((FAIL++))
    fi
}

assert_not_contains() {
    local test_name="$1" output="$2" unexpected="$3"
    if echo "$output" | grep -q "$unexpected"; then
        echo "  FAIL: $test_name (unexpected '$unexpected' found)"
        ((FAIL++))
    else
        echo "  PASS: $test_name"
        ((PASS++))
    fi
}

assert_numeric() {
    local test_name="$1" value="$2"
    if [[ "$value" =~ ^[0-9]+$ ]] && [[ "$value" -gt 0 ]]; then
        echo "  PASS: $test_name (got $value)"
        ((PASS++))
    else
        echo "  FAIL: $test_name (expected numeric > 0, got '$value')"
        ((FAIL++))
    fi
}

# ─── Tests ───

echo "================================================"
echo "DonorPerfect API Integration Tests"
echo "================================================"
echo ""

# --- Test Group 1: Dynamic SQL Queries ---
echo "--- Dynamic SQL Queries ---"

echo "Test: SELECT from DP table"
RESULT=$(dp_query "SELECT TOP 1 donor_id, first_name, last_name FROM dp WHERE donor_id > 0")
assert_contains "DP SELECT returns record" "$RESULT" "<record>"
assert_contains "DP SELECT has donor_id field" "$RESULT" "name='donor_id'"

echo "Test: SELECT from DPCODES table"
RESULT=$(dp_query "SELECT TOP 1 field_name, code, description FROM DPCODES WHERE field_name='GL_CODE'")
assert_contains "DPCODES SELECT returns record" "$RESULT" "<record>"
assert_contains "DPCODES SELECT has code field" "$RESULT" "name='code'"

echo "Test: SELECT from DPGIFT table"
RESULT=$(dp_query "SELECT TOP 1 gift_id, donor_id, amount FROM DPGIFT WHERE gift_id > 0")
assert_contains "DPGIFT SELECT returns record" "$RESULT" "<record>"

echo "Test: SELECT with JOIN (DP + DPGIFT)"
RESULT=$(dp_query "SELECT TOP 1 d.donor_id, d.first_name, g.gift_id, g.amount FROM dp d, dpgift g WHERE d.donor_id = g.donor_id AND g.amount > 0")
assert_contains "JOIN query returns record" "$RESULT" "<record>"

echo ""

# --- Test Group 2: dp_donorsearch ---
echo "--- dp_donorsearch ---"

echo "Test: Search all donors (null params)"
RESULT=$(dp_proc "dp_donorsearch" "@donor_id=null,@last_name=null,@first_name=null,@opt_line=null,@address=null,@city=null,@state=null,@zip=null,@country=null,@filter_id=null,@user_id=null")
assert_contains "dp_donorsearch returns results" "$RESULT" "<record>"
assert_not_contains "dp_donorsearch no error" "$RESULT" "user not authorized"

echo "Test: Search by last name"
RESULT=$(dp_proc "dp_donorsearch" "@donor_id=null,@last_name=%27ZTEST%27,@first_name=null,@opt_line=null,@address=null,@city=null,@state=null,@zip=null,@country=null,@filter_id=null,@user_id=null")
assert_not_contains "dp_donorsearch by name no error" "$RESULT" "user not authorized"

echo ""

# --- Test Group 3: dp_savedonor ---
echo "--- dp_savedonor (28 params per PDF p25) ---"

echo "Test: Create test donor"
RESULT=$(dp_proc "dp_savedonor" "@donor_id=0,@first_name=%27ZTEST_API%27,@last_name=%27ZTEST_API%27,@middle_name=null,@suffix=null,@title=null,@salutation=null,@prof_title=null,@opt_line=null,@address=null,@address2=null,@city=null,@state=null,@zip=null,@country=%27US%27,@address_type=null,@home_phone=null,@business_phone=null,@fax_phone=null,@mobile_phone=null,@email=%27ztest_api@test.example%27,@org_rec=%27N%27,@donor_type=%27IN%27,@nomail=%27N%27,@nomail_reason=null,@narrative=null,@donor_rcpt_type=%27I%27,@user_id=%27GiveWP_Test%27")
assert_not_contains "dp_savedonor no error" "$RESULT" "user not authorized"
assert_contains "dp_savedonor returns record" "$RESULT" "<record>"
TEST_DONOR_ID=$(extract_value "$RESULT")
assert_numeric "dp_savedonor returns donor_id" "$TEST_DONOR_ID"

echo "Test: Verify donor via SELECT"
RESULT=$(dp_query "SELECT donor_id, first_name, email FROM dp WHERE donor_id=${TEST_DONOR_ID}")
assert_contains "Created donor has correct name" "$RESULT" "ZTEST_API"
assert_contains "Created donor has correct email" "$RESULT" "ztest_api@test.example"

echo "Test: Find donor by email via SELECT"
RESULT=$(dp_query "SELECT TOP 1 donor_id FROM dp WHERE email='ztest_api@test.example'")
FOUND_ID=$(extract_field "$RESULT" "donor_id")
if [[ "$FOUND_ID" == "$TEST_DONOR_ID" ]]; then
    echo "  PASS: Email lookup returns correct donor_id ($FOUND_ID)"
    ((PASS++))
else
    echo "  FAIL: Email lookup returned $FOUND_ID, expected $TEST_DONOR_ID"
    ((FAIL++))
fi

echo ""

# --- Test Group 4: dp_savegift (32 params per PDF p30) ---
echo "--- dp_savegift (32 params per PDF p30) ---"

echo "Test: Create test gift"
RESULT=$(dp_proc "dp_savegift" "@gift_id=0,@donor_id=${TEST_DONOR_ID},@record_type=%27G%27,@gift_date=%27${TODAY}%27,@amount=0.01,@gl_code=%27UN%27,@solicit_code=null,@sub_solicit_code=%27ONETIME%27,@campaign=null,@gift_type=%27CC%27,@split_gift=%27N%27,@pledge_payment=%27N%27,@reference=%27GWDP-TEST%27,@transaction_id=null,@memory_honor=null,@gfname=null,@glname=null,@fmv=0,@batch_no=0,@gift_narrative=%27API%20Test%20Gift%27,@ty_letter_no=null,@glink=null,@plink=null,@nocalc=%27N%27,@receipt=%27N%27,@old_amount=null,@user_id=%27GiveWP_Test%27,@gift_aid_date=null,@gift_aid_amt=null,@gift_aid_eligible_g=null,@currency=%27USD%27,@first_gift=%27N%27")
assert_not_contains "dp_savegift no error" "$RESULT" "user not authorized"
assert_contains "dp_savegift returns record" "$RESULT" "<record>"
TEST_GIFT_ID=$(extract_value "$RESULT")
assert_numeric "dp_savegift returns gift_id" "$TEST_GIFT_ID"

echo "Test: Verify gift via DPGIFT SELECT"
RESULT=$(dp_query "SELECT gift_id, donor_id, amount, sub_solicit_code, campaign, reference FROM DPGIFT WHERE gift_id=${TEST_GIFT_ID}")
assert_contains "Gift has correct donor" "$RESULT" "value='${TEST_DONOR_ID}'"
assert_contains "Gift has ONETIME sub_solicit" "$RESULT" "value='ONETIME'"
assert_contains "Gift has GWDP-TEST reference" "$RESULT" "GWDP-TEST"

echo ""

# --- Test Group 5: dp_savepledge (27 params per PDF p33) ---
echo "--- dp_savepledge (27 params per PDF p33) ---"

echo "Test: Create test pledge (open-ended, total=0)"
RESULT=$(dp_proc "dp_savepledge" "@gift_id=0,@donor_id=${TEST_DONOR_ID},@gift_date=%27${TODAY}%27,@start_date=%27${TODAY}%27,@total=0,@bill=0.01,@frequency=%27M%27,@reminder=%27N%27,@gl_code=%27UN%27,@solicit_code=null,@initial_payment=%27Y%27,@sub_solicit_code=%27RECURRING%27,@writeoff_amount=0,@writeoff_date=null,@user_id=%27GiveWP_Test%27,@campaign=null,@membership_type=null,@membership_level=null,@membership_enr_date=null,@membership_exp_date=null,@membership_link_ID=null,@address_id=null,@gift_narrative=%27API%20Test%20Pledge%27,@ty_letter_no=null,@vault_id=null,@receipt_delivery_g=null,@contact_id=null")
assert_not_contains "dp_savepledge no error" "$RESULT" "user not authorized"
assert_contains "dp_savepledge returns record" "$RESULT" "<record>"
TEST_PLEDGE_ID=$(extract_value "$RESULT")
assert_numeric "dp_savepledge returns pledge_id" "$TEST_PLEDGE_ID"

echo "Test: Verify pledge via DPGIFT SELECT"
RESULT=$(dp_query "SELECT gift_id, donor_id, record_type, sub_solicit_code, total FROM DPGIFT WHERE gift_id=${TEST_PLEDGE_ID}")
assert_contains "Pledge has record_type P" "$RESULT" "value='P'"
assert_contains "Pledge has RECURRING sub_solicit" "$RESULT" "value='RECURRING'"
assert_contains "Pledge has total=0 (open-ended)" "$RESULT" "name='total' id='total' value='0'"

echo "Test: Create pledge-linked gift"
RESULT=$(dp_proc "dp_savegift" "@gift_id=0,@donor_id=${TEST_DONOR_ID},@record_type=%27G%27,@gift_date=%27${TODAY}%27,@amount=0.01,@gl_code=%27UN%27,@solicit_code=null,@sub_solicit_code=%27RECURRING%27,@campaign=null,@gift_type=%27CC%27,@split_gift=%27N%27,@pledge_payment=%27Y%27,@reference=%27GWDP-TEST-PLEDGE%27,@transaction_id=null,@memory_honor=null,@gfname=null,@glname=null,@fmv=0,@batch_no=0,@gift_narrative=%27Pledge%20Payment%20Test%27,@ty_letter_no=null,@glink=null,@plink=${TEST_PLEDGE_ID},@nocalc=%27N%27,@receipt=%27N%27,@old_amount=null,@user_id=%27GiveWP_Test%27,@gift_aid_date=null,@gift_aid_amt=null,@gift_aid_eligible_g=null,@currency=%27USD%27,@first_gift=%27N%27")
assert_not_contains "Pledge-linked gift no error" "$RESULT" "user not authorized"
LINKED_GIFT_ID=$(extract_value "$RESULT")
assert_numeric "Pledge-linked gift returns gift_id" "$LINKED_GIFT_ID"

echo "Test: Verify pledge-linked gift has plink"
RESULT=$(dp_query "SELECT gift_id, plink, pledge_payment FROM DPGIFT WHERE gift_id=${LINKED_GIFT_ID}")
assert_contains "Linked gift has plink to pledge" "$RESULT" "value='${TEST_PLEDGE_ID}'"

echo ""

# --- Test Group 6: dp_savecode (34 params per PDF p41) ---
echo "--- dp_savecode (34 params per PDF p41) ---"

echo "Test: Create test code"
RESULT=$(dp_proc "dp_savecode" "@field_name=%27SUB_SOLICIT_CODE%27,@code=%27ZTEST_CODE%27,@description=%27Test%20Code%20Delete%20Me%27,@original_code=null,@code_date=null,@mcat_hi=null,@mcat_lo=null,@mcat_gl=null,@reciprocal=null,@mailed=null,@printing=null,@other=null,@goal=null,@acct_num=null,@campaign=null,@solicit_code=null,@overwrite=null,@inactive=%27N%27,@client_id=null,@available_for_sol=null,@user_id=%27GiveWP_Test%27,@cashact=null,@membership_type=null,@leeway_days=null,@comments=null,@begin_date=null,@end_date=null,@ty_prioritize=null,@ty_filter_id=null,@ty_gift_option=null,@ty_amount_option=null,@ty_from_amount=null,@ty_to_amount=null,@ty_alternate=null,@ty_priority=null")
assert_not_contains "dp_savecode no error" "$RESULT" "user not authorized"
assert_contains "dp_savecode returns result" "$RESULT" "<record>"

echo "Test: Verify code exists in DPCODES"
RESULT=$(dp_query "SELECT code, description FROM DPCODES WHERE field_name='SUB_SOLICIT_CODE' AND code='ZTEST_CODE'")
assert_contains "Code ZTEST_CODE found" "$RESULT" "ZTEST_CODE"

echo "Test: Deactivate test code"
RESULT=$(dp_proc "dp_savecode" "@field_name=%27SUB_SOLICIT_CODE%27,@code=%27ZTEST_CODE%27,@description=%27Test%20Code%20Delete%20Me%27,@original_code=%27ZTEST_CODE%27,@code_date=null,@mcat_hi=null,@mcat_lo=null,@mcat_gl=null,@reciprocal=null,@mailed=null,@printing=null,@other=null,@goal=null,@acct_num=null,@campaign=null,@solicit_code=null,@overwrite=%27Y%27,@inactive=%27Y%27,@client_id=null,@available_for_sol=null,@user_id=%27GiveWP_Test%27,@cashact=null,@membership_type=null,@leeway_days=null,@comments=null,@begin_date=null,@end_date=null,@ty_prioritize=null,@ty_filter_id=null,@ty_gift_option=null,@ty_amount_option=null,@ty_from_amount=null,@ty_to_amount=null,@ty_alternate=null,@ty_priority=null")
assert_not_contains "dp_savecode deactivate no error" "$RESULT" "user not authorized"

echo ""

# --- Test Group 7: Verify required sub_solicit codes ---
echo "--- Verify Required Codes ---"
echo "Note: ONETIME and RECURRING sub_solicit codes must exist in DonorPerfect."
echo "Create them via dp_savecode if they don't exist (see README)."

echo "Test: ONETIME code exists"
RESULT=$(dp_query "SELECT code, description FROM DPCODES WHERE field_name='SUB_SOLICIT_CODE' AND code='ONETIME'")
assert_contains "ONETIME code exists" "$RESULT" "ONETIME"

echo "Test: RECURRING code exists"
RESULT=$(dp_query "SELECT code, description FROM DPCODES WHERE field_name='SUB_SOLICIT_CODE' AND code='RECURRING'")
assert_contains "RECURRING code exists" "$RESULT" "RECURRING"

echo ""

# --- Cleanup ---
echo "--- Cleanup ---"

if [[ -n "$LINKED_GIFT_ID" ]]; then
    dp_query "UPDATE DPGIFT SET amount=0, gift_narrative='ZTEST_DELETE' WHERE gift_id=${LINKED_GIFT_ID}" > /dev/null 2>&1
    echo "  Cleaned gift #${LINKED_GIFT_ID}"
fi
if [[ -n "$TEST_GIFT_ID" ]]; then
    dp_query "UPDATE DPGIFT SET amount=0, gift_narrative='ZTEST_DELETE' WHERE gift_id=${TEST_GIFT_ID}" > /dev/null 2>&1
    echo "  Cleaned gift #${TEST_GIFT_ID}"
fi
if [[ -n "$TEST_PLEDGE_ID" ]]; then
    dp_query "UPDATE DPGIFT SET amount=0, bill=0, total=0, gift_narrative='ZTEST_DELETE' WHERE gift_id=${TEST_PLEDGE_ID}" > /dev/null 2>&1
    echo "  Cleaned pledge #${TEST_PLEDGE_ID}"
fi
if [[ -n "$TEST_DONOR_ID" ]]; then
    dp_query "UPDATE dp SET first_name='ZTEST_DELETE', last_name='ZTEST_DELETE', email='' WHERE donor_id=${TEST_DONOR_ID}" > /dev/null 2>&1
    echo "  Cleaned donor #${TEST_DONOR_ID}"
fi

echo ""
echo "================================================"
echo "Results: ${PASS} passed, ${FAIL} failed"
echo "================================================"

if [[ $FAIL -gt 0 ]]; then
    exit 1
fi
