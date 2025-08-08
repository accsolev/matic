<?php
// check-ahrefs-data.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Check if user is VIP (replace with your actual VIP check logic)
$isVIP = true; // $_SESSION['user_type'] === 'VIP' || $_SESSION['user_type'] === 'VIPMAX';

if (!$isVIP) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. VIP access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get the request data
$input = json_decode(file_get_contents('php://input'), true);
$domain = $input['domain'] ?? '';
$type = $input['type'] ?? 'basic'; // basic, traffic, backlinks

if (empty($domain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Domain is required']);
    exit;
}

// Validate and sanitize domain
$domain = filter_var(trim($domain), FILTER_SANITIZE_URL);
if (!filter_var($domain, FILTER_VALIDATE_URL) && !filter_var("https://" . $domain, FILTER_VALIDATE_URL)) {
    // If not a valid URL, try adding https://
    if (!preg_match('/^https?:\/\//', $domain)) {
        $domain = 'https://' . $domain;
    }
}

try {
    switch ($type) {
        case 'basic':
            $result = fetchAhrefsBasicData($domain);
            break;
        case 'traffic':
            $result = fetchAhrefsTrafficData($domain);
            break;
        case 'backlinks':
            $result = fetchAhrefsBacklinksData($domain);
            break;
        default:
            throw new Exception('Invalid data type requested');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function fetchAhrefsBasicData($domain) {
    $url = "https://www.trustpositif.web.id/api/check-ahrefs?domains=" . urlencode($domain);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP Error: " . $httpCode);
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response");
    }
    
    return $data;
}

function fetchAhrefsTrafficData($domain) {
    $url = "https://www.trustpositif.web.id/api/check-ahrefs-traffic?domain=" . urlencode($domain);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP Error: " . $httpCode);
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response");
    }
    
    return $data;
}

function fetchAhrefsBacklinksData($domain) {
    $url = "https://www.trustpositif.web.id/api/check-ahrefs-backlinks?domain=" . urlencode($domain);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45, // Increased timeout for backlinks data
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP Error: " . $httpCode);
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response");
    }
    
    return $data;
}

// Alternative direct implementation without separate PHP file
function handleDirectRequest() {
    $domainsInput = trim($_POST['domain'] ?? '');
    $domains = array_filter(array_map('trim', explode("\n", $domainsInput)));
    
    if (empty($domains)) {
        return ['error' => 'No valid domains provided'];
    }
    
    $results = [];
    
    foreach ($domains as $domain) {
        try {
            // Fetch all data types for each domain
            $basicData = fetchAhrefsBasicData($domain);
            $trafficData = fetchAhrefsTrafficData($domain);
            $backlinksData = fetchAhrefsBacklinksData($domain);
            
            $results[$domain] = [
                'basic' => $basicData,
                'traffic' => $trafficData,
                'backlinks' => $backlinksData,
                'status' => 'success'
            ];
            
            // Add delay between requests to avoid rate limiting
            if (count($results) < count($domains)) {
                sleep(2);
            }
            
        } catch (Exception $e) {
            $results[$domain] = [
                'error' => $e->getMessage(),
                'status' => 'error'
            ];
        }
    }
    
    return $results;
}

// If this file is accessed directly via POST for batch processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['domain'])) {
    header('Content-Type: application/json');
    echo json_encode(handleDirectRequest());
    exit;
}
?>