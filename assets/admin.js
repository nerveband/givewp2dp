/* GiveWP → DonorPerfect Sync Admin JS */
(function($) {
    'use strict';

    // ─── API Key toggle ───
    $('#gwdp-toggle-key').on('click', function() {
        var $input = $('input[name="gwdp_api_key"]');
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $(this).text('Hide');
        } else {
            $input.attr('type', 'password');
            $(this).text('Show');
        }
    });

    // ─── Test API connection ───
    $('#gwdp-test-api').on('click', function() {
        var $btn = $(this);
        var $result = $('#gwdp-api-test-result');
        $btn.prop('disabled', true);
        $result.text('Testing...').css('color', '#666');

        $.post(gwdp.ajax_url, {
            action: 'gwdp_match_report',
            nonce: gwdp.nonce
        }).done(function(response) {
            if (response.success) {
                $result.text('Connected! Found ' + response.data.total + ' GiveWP donors.').css('color', '#46b450');
            } else {
                $result.text('Error: ' + (response.data || 'Unknown error')).css('color', '#dc3232');
            }
        }).fail(function() {
            $result.text('Connection failed.').css('color', '#dc3232');
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    // ─── Gateway mapping ───
    $('#gwdp-add-gateway').on('click', function() {
        var row = '<tr>' +
            '<td><input type="text" name="gwdp_gw_keys[]" value="" class="regular-text" placeholder="gateway_id"></td>' +
            '<td><input type="text" name="gwdp_gw_values[]" value="" class="small-text" placeholder="CC"></td>' +
            '<td><button type="button" class="button gwdp-remove-row">Remove</button></td>' +
            '</tr>';
        $('#gwdp-gateway-table tbody').append(row);
    });

    $(document).on('click', '.gwdp-remove-row', function() {
        $(this).closest('tr').remove();
    });

    // ─── Backfill Preview ───
    $('#gwdp-backfill-preview').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Running preview...');
        $('#gwdp-backfill-results').html('<p>Loading preview...</p>');

        $.post(gwdp.ajax_url, {
            action: 'gwdp_backfill_preview',
            nonce: gwdp.nonce,
            batch_size: 50,
            offset: 0
        }).done(function(response) {
            if (response.success) {
                renderBackfillResults(response.data, true);
                $('#gwdp-backfill-run').prop('disabled', false);
            } else {
                $('#gwdp-backfill-results').html('<p style="color:#dc3232">Error: ' + (response.data || 'Unknown') + '</p>');
            }
        }).fail(function() {
            $('#gwdp-backfill-results').html('<p style="color:#dc3232">Request failed.</p>');
        }).always(function() {
            $btn.prop('disabled', false).text('Run Preview (50 donations)');
        });
    });

    // ─── Backfill Run ───
    var backfillRunning = false;
    var backfillOffset = 0;
    var backfillTotal = 0;
    var backfillProcessed = 0;

    $('#gwdp-backfill-run').on('click', function() {
        if (!confirm('This will send donations to DonorPerfect. Continue?')) return;

        backfillRunning = true;
        backfillOffset = 0;
        backfillProcessed = 0;
        $(this).prop('disabled', true);
        $('#gwdp-backfill-stop').show();
        $('#gwdp-backfill-progress').show();
        $('#gwdp-backfill-results').html('');

        runBackfillBatch();
    });

    $('#gwdp-backfill-stop').on('click', function() {
        backfillRunning = false;
        $(this).hide();
        $('#gwdp-backfill-run').prop('disabled', false);
        $('.gwdp-progress-text').text('Stopped. Processed ' + backfillProcessed + ' donations.');
    });

    function runBackfillBatch() {
        if (!backfillRunning) return;

        $.post(gwdp.ajax_url, {
            action: 'gwdp_backfill_run',
            nonce: gwdp.nonce,
            batch_size: 10,
            offset: backfillOffset
        }).done(function(response) {
            if (!response.success) {
                backfillRunning = false;
                $('.gwdp-progress-text').text('Error: ' + (response.data || 'Unknown'));
                $('#gwdp-backfill-stop').hide();
                $('#gwdp-backfill-run').prop('disabled', false);
                return;
            }

            var data = response.data;
            backfillTotal = data.total_unsynced + backfillProcessed;
            backfillProcessed += data.processed;
            backfillOffset += data.batch_size;

            var pct = backfillTotal > 0 ? Math.round((backfillProcessed / backfillTotal) * 100) : 100;
            $('.gwdp-progress-fill').css('width', pct + '%');
            $('.gwdp-progress-text').text('Processed ' + backfillProcessed + ' of ~' + backfillTotal + ' (' + pct + '%)');

            renderBackfillResults(data, false);

            if (data.has_more && backfillRunning) {
                setTimeout(runBackfillBatch, 500);
            } else {
                backfillRunning = false;
                $('#gwdp-backfill-stop').hide();
                $('#gwdp-backfill-run').prop('disabled', false);
                if (!data.has_more) {
                    $('.gwdp-progress-text').text('Complete! Processed ' + backfillProcessed + ' donations.');
                }
            }
        }).fail(function() {
            backfillRunning = false;
            $('.gwdp-progress-text').text('Request failed. Processed ' + backfillProcessed + ' so far.');
            $('#gwdp-backfill-stop').hide();
            $('#gwdp-backfill-run').prop('disabled', false);
        });
    }

    function renderBackfillResults(data, isPreview) {
        var html = '<p><strong>' + (isPreview ? 'Preview' : 'Batch') + ':</strong> ';
        html += data.processed + ' processed, ' + data.total_unsynced + ' remaining</p>';

        if (data.items && data.items.length) {
            html += '<table class="widefat striped"><thead><tr>';
            html += '<th>Give #</th><th>Type</th><th>Amount</th>';
            if (isPreview) {
                html += '<th>Name</th><th>Email</th><th>Donor</th><th>Pledge</th><th>Status</th>';
            } else {
                html += '<th>Donor</th><th>DP Donor</th><th>DP Gift</th><th>DP Pledge</th><th>Status</th><th>Error</th>';
            }
            html += '</tr></thead><tbody>';

            data.items.forEach(function(item) {
                var statusClass = 'gwdp-badge-' + (item.status || 'preview');
                html += '<tr>';
                html += '<td>' + (item.donation_id || item.give_donation_id || '—') + '</td>';
                html += '<td>' + (item.type || item.donation_type || '—') + '</td>';
                html += '<td>$' + parseFloat(item.amount || item.donation_amount || 0).toFixed(2) + '</td>';

                if (isPreview) {
                    html += '<td>' + (item.name || '—') + '</td>';
                    html += '<td>' + (item.email || '—') + '</td>';
                    html += '<td>' + (item.donor_action || '—') + (item.dp_donor_id ? ' (#' + item.dp_donor_id + ')' : '') + '</td>';
                    html += '<td>' + (item.pledge_action || 'none') + '</td>';
                    html += '<td><span class="gwdp-badge ' + statusClass + '">' + (item.status || '') + '</span></td>';
                } else {
                    html += '<td>' + (item.donor_action || '—') + '</td>';
                    html += '<td>' + (item.dp_donor_id ? '#' + item.dp_donor_id : '—') + '</td>';
                    html += '<td>' + (item.dp_gift_id ? '#' + item.dp_gift_id : '—') + '</td>';
                    html += '<td>' + (item.dp_pledge_id ? '#' + item.dp_pledge_id : '—') + '</td>';
                    html += '<td><span class="gwdp-badge ' + statusClass + '">' + (item.status || '') + '</span></td>';
                    html += '<td style="color:#dc3232">' + (item.error || '') + '</td>';
                }
                html += '</tr>';
            });
            html += '</tbody></table>';
        }

        var $results = $('#gwdp-backfill-results');
        if (isPreview) {
            $results.html(html);
        } else {
            $results.append(html);
        }
    }

    // ─── Sync Single ───
    $('#gwdp-sync-single').on('click', function() {
        var id = parseInt($('#gwdp-single-id').val());
        if (!id || id <= 0) {
            $('#gwdp-single-result').text('Enter a valid donation ID').css('color', '#dc3232');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#gwdp-single-result').text('Syncing...').css('color', '#666');

        $.post(gwdp.ajax_url, {
            action: 'gwdp_sync_single',
            nonce: gwdp.nonce,
            donation_id: id
        }).done(function(response) {
            if (response.success) {
                var d = response.data;
                if (d.status === 'success') {
                    $('#gwdp-single-result').text('Synced! DP Donor #' + d.dp_donor_id + ', Gift #' + d.dp_gift_id + (d.dp_pledge_id ? ', Pledge #' + d.dp_pledge_id : '')).css('color', '#46b450');
                } else {
                    $('#gwdp-single-result').text(d.status + ': ' + (d.error || '')).css('color', '#dc3232');
                }
            } else {
                $('#gwdp-single-result').text('Error: ' + (response.data || 'Unknown')).css('color', '#dc3232');
            }
        }).fail(function() {
            $('#gwdp-single-result').text('Request failed.').css('color', '#dc3232');
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    // ─── Match Report ───
    $('#gwdp-match-report').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#gwdp-match-loading').show();
        $('#gwdp-match-results').hide();

        $.post(gwdp.ajax_url, {
            action: 'gwdp_match_report',
            nonce: gwdp.nonce
        }).done(function(response) {
            if (response.success) {
                var data = response.data;
                $('#gwdp-match-total').text(data.total);
                $('#gwdp-match-found').text(data.matched);
                $('#gwdp-match-new').text(data.new);

                var $tbody = $('#gwdp-match-table tbody');
                $tbody.empty();

                data.donors.forEach(function(d) {
                    var actionClass = d.dp_donor_id ? 'color:#46b450' : 'color:#0073aa';
                    $tbody.append(
                        '<tr>' +
                        '<td>' + d.give_donor_id + '</td>' +
                        '<td>' + d.name + '</td>' +
                        '<td>' + d.email + '</td>' +
                        '<td>' + (d.dp_donor_id ? '#' + d.dp_donor_id : '—') + '</td>' +
                        '<td style="' + actionClass + '">' + d.action + '</td>' +
                        '</tr>'
                    );
                });

                $('#gwdp-match-results').show();
            } else {
                alert('Error: ' + (response.data || 'Unknown'));
            }
        }).fail(function() {
            alert('Request failed.');
        }).always(function() {
            $btn.prop('disabled', false);
            $('#gwdp-match-loading').hide();
        });
    });

})(jQuery);
