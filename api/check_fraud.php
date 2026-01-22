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

// Helper: Get Settings
function getSetting($conn, $key) {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $res = $stmt->get_result();
    return ($res->num_rows > 0) ? json_decode($res->fetch_assoc()['setting_value'], true) : null;
}

// Helper: Save Settings (Used for session storage)
function saveSetting($conn, $key, $value) {
    $val = json_encode($value);
    $stmt = $conn->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    $stmt->bind_param("ss", $key, $val);
    $stmt->execute();
}

// Helper: Parse Cookies from Header
function get_cookies_from_header($header) {
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = [];
    foreach ($matches[1] as $item) {
        $cookies[] = $item;
    }
    return implode("; ", $cookies);
}

// --- MAIN LOGIC ---

$phone = isset($_GET['phone']) ? $_GET['phone'] : '';
$force_refresh = isset($_GET['refresh']) && $_GET['refresh'] == 'true';

if (empty($phone)) {
    echo json_encode(["error" => "Phone number required"]);
    exit;
}

// Normalize Phone
$phone = preg_replace('/[^0-9]/', '', $phone);
if (strlen($phone) > 11 && substr($phone, 0, 2) == '88') {
    $phone = substr($phone, 2); 
}

// Check Cache
if (!$force_refresh) {
    $stmt = $conn->prepare("SELECT * FROM fraud_check_cache WHERE phone = ? AND updated_at > (NOW() - INTERVAL 12 HOUR)");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            "source" => "cache",
            "success_rate" => (float)$row['success_rate'],
            "total_orders" => (int)$row['total_orders'],
            "delivered" => 0, 
            "cancelled" => 0,
            "details" => json_decode($row['data'], true)
        ]);
        exit;
    }
}

$history = [
    'delivered' => 0,
    'cancelled' => 0,
    'total' => 0,
    'breakdown' => []
];

$steadfastConfig = getSetting($conn, 'courier_config');
$pathaoConfig = getSetting($conn, 'pathao_config');

// 1. STEADFAST GLOBAL CHECK (Login & Scrape)
if ($steadfastConfig && !empty($steadfastConfig['email']) && !empty($steadfastConfig['password'])) {
    
    // Check if we have valid session cookies
    $session = getSetting($conn, 'steadfast_session');
    $cookies = $session['cookies'] ?? '';
    $last_login = $session['last_login'] ?? 0;
    
    // If no cookies or session older than 24h, login
    if (empty($cookies) || (time() - $last_login > 86400)) {
        // Step A: Get CSRF Token
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://steadfast.com.bd/login");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        if ($resp) {
            preg_match('/<input type="hidden" name="_token" value="(.*?)"/', $resp, $matches);
            $token = $matches[1] ?? '';
            $init_cookies = get_cookies_from_header($resp);
            
            if ($token) {
                // Step B: Post Login
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://steadfast.com.bd/login");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    '_token' => $token,
                    'email' => $steadfastConfig['email'],
                    'password' => $steadfastConfig['password']
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: $init_cookies"]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, true);
                $loginResp = curl_exec($ch);
                curl_close($ch);
                
                $new_cookies = get_cookies_from_header($loginResp);
                if ($new_cookies) {
                    $cookies = $new_cookies; // Update cookies
                    saveSetting($conn, 'steadfast_session', ['cookies' => $cookies, 'last_login' => time()]);
                }
            }
        }
    }
    
    // Step C: Check Fraud
    if ($cookies) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://steadfast.com.bd/user/frauds/check/" . $phone);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: $cookies", "X-Requested-With: XMLHttpRequest"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $checkResp = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($checkResp, true);
        if ($data) {
            $del = isset($data['total_delivered']) ? (int)$data['total_delivered'] : 0;
            $can = isset($data['total_cancelled']) ? (int)$data['total_cancelled'] : 0;
            $tot = $del + $can; // Or total_parcel if available
            
            $history['delivered'] += $del;
            $history['cancelled'] += $can;
            $history['total'] += $tot;
            
            if ($tot > 0) {
                $history['breakdown'][] = [
                    'courier' => 'Steadfast (Global)',
                    'status' => "Delivered: $del | Cancelled: $can"
                ];
            }
        }
    }
}

// 2. PATHAO GLOBAL CHECK (Merchant API)
if ($pathaoConfig && !empty($pathaoConfig['username']) && !empty($pathaoConfig['password'])) {
    
    $p_session = getSetting($conn, 'pathao_session');
    $access_token = $p_session['access_token'] ?? '';
    $last_login = $p_session['last_login'] ?? 0;
    
    // Login if needed (Token expires in ~6-24h usually)
    if (empty($access_token) || (time() - $last_login > 20000)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://merchant.pathao.com/api/v1/login");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "username" => $pathaoConfig['username'],
            "password" => $pathaoConfig['password']
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $loginResp = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($loginResp, true);
        if (isset($data['access_token'])) {
            $access_token = $data['access_token'];
            saveSetting($conn, 'pathao_session', ['access_token' => $access_token, 'last_login' => time()]);
        }
    }
    
    if ($access_token) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://merchant.pathao.com/api/v1/user/success");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["phone" => $phone]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $access_token"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $checkResp = curl_exec($ch);
        curl_close($ch);
        
        $pData = json_decode($checkResp, true);
        
        if (isset($pData['data']['customer'])) {
            $cust = $pData['data']['customer'];
            $del = isset($cust['successful_delivery']) ? (int)$cust['successful_delivery'] : 0;
            $tot = isset($cust['total_delivery']) ? (int)$cust['total_delivery'] : 0;
            $can = $tot - $del;
            
            $history['delivered'] += $del;
            $history['cancelled'] += $can;
            $history['total'] += $tot;
            
            if ($tot > 0) {
                $history['breakdown'][] = [
                    'courier' => 'Pathao (Global)',
                    'status' => "Delivered: $del | Returned/Failed: $can"
                ];
            }
        }
    }
}

// Calculate Success Rate
$success_rate = 0;
if ($history['total'] > 0) {
    $success_rate = ($history['delivered'] / $history['total']) * 100;
}

// Save to Cache
$jsonData = $conn->real_escape_string(json_encode($history['breakdown']));
$sql = "INSERT INTO fraud_check_cache (phone, data, success_rate, total_orders) 
        VALUES ('$phone', '$jsonData', $success_rate, {$history['total']})
        ON DUPLICATE KEY UPDATE 
        data = '$jsonData', success_rate = $success_rate, total_orders = {$history['total']}, updated_at = NOW()";
$conn->query($sql);

echo json_encode([
    "source" => "live_global",
    "success_rate" => round($success_rate, 2),
    "total_orders" => $history['total'],
    "delivered" => $history['delivered'],
    "cancelled" => $history['cancelled'],
    "details" => $history['breakdown']
]);

$conn->close();
?>