
<?php 
// Allow access to variables from render_dashboard
$is_connected = isset($is_connected) ? $is_connected : false;
$customers = isset($customers) ? $customers : [];
// $features is passed from the main class
$features = isset($features) ? $features : [];

// Helper to check feature status
function is_feature_active($key, $features) {
    return isset($features[$key]) && $features[$key] == true;
}
?>
<div class="wrap font-sans">
    <h1 class="wp-heading-inline text-2xl font-bold text-gray-800 mb-6">BdCommerce SMS Manager</h1>
    
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <!-- Status Bar -->
        <div class="bg-gray-50 px-6 py-3 border-b border-gray-200 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <?php if($is_connected): ?>
                    <span class="w-3 h-3 rounded-full bg-green-500"></span>
                    <span class="text-sm font-bold text-gray-700">Connected to Dashboard Relay</span>
                <?php else: ?>
                    <span class="w-3 h-3 rounded-full bg-red-500 animate-pulse"></span>
                    <span class="text-sm font-bold text-red-600">Disconnected. Check Dashboard URL.</span>
                <?php endif; ?>
            </div>
            <div class="text-xs text-gray-500">
                Ver: 1.4.0
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="flex border-b border-gray-200 bg-white">
            <button onclick="switchTab('customers')" id="tab-customers" class="tab-btn px-6 py-4 text-sm font-bold text-orange-600 border-b-2 border-orange-600 bg-white focus:outline-none">Customers</button>
            <button onclick="switchTab('features')" id="tab-features" class="tab-btn px-6 py-4 text-sm font-bold text-gray-500 hover:text-gray-700 focus:outline-none">Features & Modules</button>
            <button onclick="switchTab('send-sms')" id="tab-send-sms" class="tab-btn px-6 py-4 text-sm font-bold text-gray-500 hover:text-gray-700 focus:outline-none">Send SMS</button>
            <button onclick="switchTab('settings')" id="tab-settings" class="tab-btn px-6 py-4 text-sm font-bold text-gray-500 hover:text-gray-700 focus:outline-none">Settings</button>
        </div>

        <!-- Tab Content: Customers -->
        <div id="content-customers" class="tab-content p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Customer Database (<?php echo count($customers); ?>)</h2>
                <button id="sync-btn" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-blue-700 transition flex items-center gap-2">
                    <span class="dashicons dashicons-update"></span> Sync from WooCommerce
                </button>
            </div>
            
            <div class="overflow-x-auto max-h-[500px] overflow-y-auto border border-gray-200 rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <input type="checkbox" id="select-all" class="rounded border-gray-300 text-orange-600 focus:ring-orange-500">
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Spent</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Orders</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if ( empty( $customers ) ) : ?>
                            <tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">No customers found. Click Sync.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $customers as $customer ) : ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <input type="checkbox" name="customer_phone[]" value="<?php echo esc_attr( $customer->phone ); ?>" class="customer-cb rounded border-gray-300 text-orange-600 focus:ring-orange-500">
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo esc_html( $customer->name ); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo esc_html( $customer->phone ); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo wc_price( $customer->total_spent ); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            <?php echo esc_html( $customer->order_count ); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab Content: Features (NEW) -->
        <div id="content-features" class="tab-content p-6 hidden">
            <div class="mb-6">
                <h2 class="text-xl font-bold text-gray-800">Active Modules</h2>
                <p class="text-sm text-gray-500">Manage these features from your <a href="#" class="text-orange-600 hover:underline">Central Dashboard</a>. Changes take effect immediately.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Live Capture -->
                <div class="border rounded-lg p-5 <?php echo is_feature_active('live_capture', $features) ? 'bg-white border-green-200' : 'bg-gray-50 border-gray-200 grayscale opacity-80'; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex items-center gap-3">
                            <span class="dashicons dashicons-visibility text-2xl text-orange-600"></span>
                            <h3 class="font-bold text-gray-800 text-lg">Live Lead Capture</h3>
                        </div>
                        <?php if(is_feature_active('live_capture', $features)): ?>
                            <span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded-full border border-green-200">ACTIVE</span>
                        <?php else: ?>
                            <span class="bg-gray-200 text-gray-600 text-xs font-bold px-2 py-1 rounded-full border border-gray-300">INACTIVE</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-600 mb-3">Automatically captures customer data (Phone, Name) as they type in the checkout form. View incomplete orders in Dashboard.</p>
                    <div class="text-xs text-gray-400">
                        Status: <?php echo is_feature_active('live_capture', $features) ? '<span class="text-green-600">Script Injected</span>' : 'Script Disabled'; ?>
                    </div>
                </div>

                <!-- Fraud Guard -->
                <div class="border rounded-lg p-5 <?php echo is_feature_active('fraud_guard', $features) ? 'bg-white border-green-200' : 'bg-gray-50 border-gray-200 grayscale opacity-80'; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex items-center gap-3">
                            <span class="dashicons dashicons-shield text-2xl text-blue-600"></span>
                            <h3 class="font-bold text-gray-800 text-lg">Fraud Guard AI</h3>
                        </div>
                        <?php if(is_feature_active('fraud_guard', $features)): ?>
                            <span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded-full border border-green-200">ACTIVE</span>
                        <?php else: ?>
                            <span class="bg-gray-200 text-gray-600 text-xs font-bold px-2 py-1 rounded-full border border-gray-300">INACTIVE</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-600 mb-3">Prevents fake orders by analyzing phone number validity, order history, and IP address. Blocks known bad actors.</p>
                </div>

                <!-- SMS Automation -->
                <div class="border rounded-lg p-5 <?php echo is_feature_active('sms_automation', $features) ? 'bg-white border-green-200' : 'bg-gray-50 border-gray-200 grayscale opacity-80'; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex items-center gap-3">
                            <span class="dashicons dashicons-email-alt text-2xl text-purple-600"></span>
                            <h3 class="font-bold text-gray-800 text-lg">SMS Automation</h3>
                        </div>
                        <?php if(is_feature_active('sms_automation', $features)): ?>
                            <span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded-full border border-green-200">ACTIVE</span>
                        <?php else: ?>
                            <span class="bg-gray-200 text-gray-600 text-xs font-bold px-2 py-1 rounded-full border border-gray-300">INACTIVE</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-600 mb-3">Sends automated SMS for order status changes (Pending, Processing, Completed) based on templates set in Dashboard.</p>
                </div>

                <!-- Pixel / CAPI -->
                <div class="border rounded-lg p-5 <?php echo is_feature_active('pixel_capi', $features) ? 'bg-white border-green-200' : 'bg-gray-50 border-gray-200 grayscale opacity-80'; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex items-center gap-3">
                            <span class="dashicons dashicons-chart-line text-2xl text-teal-600"></span>
                            <h3 class="font-bold text-gray-800 text-lg">Facebook Pixel & CAPI</h3>
                        </div>
                        <?php if(is_feature_active('pixel_capi', $features)): ?>
                            <span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded-full border border-green-200">ACTIVE</span>
                        <?php else: ?>
                            <span class="bg-gray-200 text-gray-600 text-xs font-bold px-2 py-1 rounded-full border border-gray-300">INACTIVE</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-600 mb-3">Server-side tracking for Facebook Events with advanced matching. Improves ad performance and tracking accuracy.</p>
                </div>
            </div>
        </div>

        <!-- Tab Content: Send SMS -->
        <div id="content-send-sms" class="tab-content p-6 hidden">
            <?php if(!$is_connected): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <p class="font-bold">API Not Connected</p>
                    <p>Please go to the <strong>Settings</strong> tab and enter your React Dashboard URL.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <h3 class="text-lg font-bold mb-4">Compose Message</h3>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Message</label>
                            <textarea id="sms-message" rows="6" class="shadow-sm focus:ring-orange-500 focus:border-orange-500 mt-1 block w-full sm:text-sm border border-gray-300 rounded-md p-3" placeholder="Type your message here..."></textarea>
                            <p class="text-xs text-gray-500 mt-1">Unicode allows 70 chars, Regular allows 160 chars per SMS.</p>
                        </div>
                        <button id="send-btn" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                            Send Bulk SMS via Dashboard
                        </button>
                    </div>
                    <div class="bg-gray-50 p-6 rounded-lg border border-gray-200">
                        <h3 class="text-sm font-bold text-gray-700 uppercase mb-4">Selected Recipients</h3>
                        <div id="selected-count-display" class="text-3xl font-bold text-orange-600 mb-2">0</div>
                        <p class="text-sm text-gray-500 mb-4">Customers selected from the 'Customers' tab.</p>
                        
                        <div class="bg-white p-4 rounded border border-gray-200 h-40 overflow-y-auto text-xs font-mono text-gray-600" id="recipient-list">
                            No recipients selected.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab Content: Settings -->
        <div id="content-settings" class="tab-content p-6 hidden">
            <form method="post" action="options.php" class="max-w-lg">
                <?php settings_fields( 'bdc_sms_group' ); ?>
                <?php do_settings_sections( 'bdc_sms_group' ); ?>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700">React Dashboard URL</label>
                        <p class="text-xs text-gray-500 mb-2">Enter the full URL (e.g. <code>https://crm.coverboutiquebd.com/api</code>).</p>
                        <input type="url" name="bdc_dashboard_url" value="<?php echo esc_attr( get_option( 'bdc_dashboard_url' ) ); ?>" class="mt-1 focus:ring-orange-500 focus:border-orange-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md p-3" placeholder="https://example.com/api">
                    </div>
                    
                    <?php 
                    $dashboard_url = get_option( 'bdc_dashboard_url' );
                    if(!empty($dashboard_url)): 
                        $clean_url = preg_replace('/\/[a-zA-Z0-9_-]+\.php$/', '', $dashboard_url);
                        $base_url = rtrim( $clean_url, '/' );
                        $debug_url = ( substr( $base_url, -3 ) === 'api' ) ? $base_url : $base_url . '/api';
                        
                        // Test connection to settings.php directly to verify network
                        $response = wp_remote_get($debug_url . '/settings.php', array('timeout'=>5, 'sslverify'=>false));
                        $code = is_wp_error($response) ? 'Error' : wp_remote_retrieve_response_code($response);
                    ?>
                        <div class="p-3 bg-gray-100 rounded text-xs text-gray-600 break-all">
                            <strong>Connection Debug:</strong><br>
                            Base API: <code><?php echo esc_html($debug_url); ?></code><br>
                            Target Status: <code><?php echo esc_html($code); ?></code><br>
                            <?php if($code == 200): ?>
                                <span class="text-green-600 font-bold">Relay Server Active. Credentials are handled by Dashboard.</span>
                            <?php else: ?>
                                <span class="text-red-500 font-bold">Unreachable. Check URL or Server Config.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php submit_button( 'Save Settings' ); ?>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        document.getElementById('content-' + tabId).classList.remove('hidden');
        document.querySelectorAll('.tab-btn').forEach(el => {
            el.classList.remove('text-orange-600', 'border-b-2', 'border-orange-600', 'bg-white');
            el.classList.add('text-gray-500');
        });
        const btn = document.getElementById('tab-' + tabId);
        btn.classList.remove('text-gray-500');
        btn.classList.add('text-orange-600', 'border-b-2', 'border-orange-600', 'bg-white');
    }

    jQuery(document).ready(function($) {
        $('#select-all').on('change', function() {
            $('.customer-cb').prop('checked', $(this).prop('checked'));
            updateRecipients();
        });

        $('.customer-cb').on('change', function() {
            updateRecipients();
        });

        function updateRecipients() {
            let count = 0;
            let listHtml = '';
            $('.customer-cb:checked').each(function() {
                count++;
                listHtml += '<div>' + $(this).val() + '</div>';
            });
            $('#selected-count-display').text(count);
            $('#recipient-list').html(listHtml || 'No recipients selected.');
        }

        $('#sync-btn').on('click', function() {
            const btn = $(this);
            btn.text('Syncing...').prop('disabled', true);
            $.post(ajaxurl, {
                action: 'bdc_sync_customers',
                nonce: '<?php echo wp_create_nonce( "bdc_sms_nonce" ); ?>'
            }, function(res) {
                if(res.success) {
                    alert(res.data);
                    location.reload();
                } else {
                    alert('Error: ' + res.data);
                    btn.text('Sync Failed').prop('disabled', false);
                }
            });
        });

        $('#send-btn').on('click', function() {
            const btn = $(this);
            const msg = $('#sms-message').val();
            let numbers = [];
            $('.customer-cb:checked').each(function() {
                numbers.push($(this).val());
            });

            if(numbers.length === 0) {
                alert('Please select customers first.');
                return;
            }
            if(!msg) {
                alert('Please type a message.');
                return;
            }

            if(!confirm('Send SMS via Dashboard Relay?')) return;

            btn.text('Sending...').prop('disabled', true);

            $.post(ajaxurl, {
                action: 'bdc_send_sms',
                numbers: numbers,
                message: msg,
                nonce: '<?php echo wp_create_nonce( "bdc_sms_nonce" ); ?>'
            }, function(res) {
                if(res.success) {
                    alert(res.data);
                    $('#sms-message').val('');
                } else {
                    alert('Error: ' + res.data);
                }
                btn.text('Send Bulk SMS via Dashboard').prop('disabled', false);
            });
        });
    });
</script>
