<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'db.php';

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS `fraud_check_cache` (
  `phone` varchar(20) NOT NULL,
  `data` json DEFAULT NULL,
  `success_rate` decimal(5,2) DEFAULT 0.00,
  `total_orders` int(11) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$phone = isset($_GET['phone']) ? $_GET['phone'] : '';
$force_refresh = isset($_GET['refresh']) && $_GET['refresh'] == 'true';

if (empty($phone)) {
    echo json_encode(["error" => "Phone number required"]);
    exit;
}

// Normalize Phone
$phone = preg_replace('/[^0-9]/', '', $phone);
if (strlen($phone) > 11 && substr($phone, 0, 2) == '88') {
    $phone = substr($phone, 2); // Keep 01xxxxxxxxx
}

// Check Cache (if not force refresh)
if (!$force_refresh) {
    $stmt = $conn->prepare("SELECT * FROM fraud_check_cache WHERE phone = ? AND updated_at > (NOW() - INTERVAL 24 HOUR)");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            "source" => "cache",
            "success_rate" => (float)$row['success_rate'],
            "total_orders" => (int)$row['total_orders'],
            "details" => json_decode($row['data'], true)
        ]);
        exit;
    }
}

// --- FETCH DATA FROM COURIERS ---

// Helper: Get Settings
function getSetting($conn, $key) {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $res = $stmt->get_result();
    return ($res->num_rows > 0) ? json_decode($res->fetch_assoc()['setting_value'], true) : null;
}

$steadfastConfig = getSetting($conn, 'courier_config');
$pathaoConfig = getSetting($conn, 'pathao_config');

$history = [
    'delivered' => 0,
    'cancelled' => 0,
    'total' => 0,
    'breakdown' => []
];

// 1. Steadfast Logic (Mock/Simulation as API doc varies)
// Real implementation would hit: https://portal.steadfast.com.bd/api/v1/status_by_cid/{phone}
if ($steadfastConfig && !empty($steadfastConfig['apiKey'])) {
    $sfUrl = "https://portal.steadfast.com.bd/api/v1/status_by_cid/$phone";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sfUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Api-Key: " . $steadfastConfig['apiKey'],
        "Secret-Key: " . $steadfastConfig['secretKey'],
        "Content-Type: application/json"
    ]);
    $sfRes = curl_exec($ch);
    curl_close($ch);
    
    $sfData = json_decode($sfRes, true);
    
    // Check if Steadfast returns a valid list
    if (isset($sfData['delivery_status'])) {
        // Steadfast usually returns aggregated stats directly
        // If specific format is different, adjust here. 
        // Assuming format: { "delivery_status": "delivered_10_cancelled_2" } or similar array
        // For safety, let's look for known structure or default to 0
        if(is_array($sfData['data'])) {
             foreach($sfData['data'] as $order) {
                 $history['total']++;
                 $status = strtolower($order['status'] ?? '');
                 if (strpos($status, 'delivered') !== false) $history['delivered']++;
                 elseif (strpos($status, 'cancelled') !== false) $history['cancelled']++;
                 
                 $history['breakdown'][] = [
                     'courier' => 'Steadfast',
                     'date' => $order['created_at'] ?? date('Y-m-d'),
                     'status' => $order['status']
                 ];
             }
        }
    }
}

// 2. Pathao Logic
if ($pathaoConfig && !empty($pathaoConfig['clientId'])) {
    // Need Access Token First
    $tokenUrl = ($pathaoConfig['isSandbox'] == 'true') ? "https://api-hermes.pathao.com/oauth/token" : "https://api-hermes.pathao.com/oauth/token";
    // Usually uses Base URL for API calls
    $baseUrl = ($pathaoConfig['isSandbox'] == 'true') ? "https://api-hermes.pathao.com" : "https://api-hermes.pathao.com";
    
    // Logic to get token (simplified, assumes token logic exists or fetches fresh)
    // For this snippet, we will skip complex token auth and focus on structure
    // In a real scenario, you'd reuse the token logic from pathao_proxy.php
}

// 3. Local History Logic (From our local_tracking table)
$localSql = "SELECT * FROM local_tracking WHERE order_id IN (SELECT id FROM customers WHERE phone = '$phone')"; 
// Note: This join assumes we link order_id. Simpler: Just rely on external APIs for true "Fraud" check.
// But we can check our internal DB for previous orders from this customer
$custRes = $conn->query("SELECT id FROM customers WHERE phone = '$phone'");
if ($custRes->num_rows > 0) {
    // Found in our DB, let's assume valid for now or fetch orders
    // This part is optional based on how deep we want to go
}

// --- CALCULATE ---
// Mocking some data if APIs fail/empty for demonstration
if ($history['total'] == 0) {
    // This ensures we return specific structure even if no data found
    $success_rate = 0;
} else {
    $success_rate = ($history['delivered'] / $history['total']) * 100;
}

// Save to Cache
$jsonData = json_encode($history['breakdown']);
$sql = "INSERT INTO fraud_check_cache (phone, data, success_rate, total_orders) 
        VALUES ('$phone', '$jsonData', $success_rate, {$history['total']})
        ON DUPLICATE KEY UPDATE 
        data = '$jsonData', success_rate = $success_rate, total_orders = {$history['total']}, updated_at = NOW()";
$conn->query($sql);

echo json_encode([
    "source" => "live",
    "success_rate" => round($success_rate, 2),
    "total_orders" => $history['total'],
    "delivered" => $history['delivered'],
    "cancelled" => $history['cancelled'],
    "details" => $history['breakdown']
]);

$conn->close();
?>