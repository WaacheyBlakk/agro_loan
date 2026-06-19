<?php
// --- API Configuration ---
define('MOMO_USE_MOCK', true); // Set to FALSE to use real MTN MoMo API calls
define('MOMO_ENV', 'sandbox'); // 'sandbox' or 'production'
define('MOMO_CURRENCY', 'GHS');
define('MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'); // Base API URL

// MTN MoMo API Credentials (Required if MOMO_USE_MOCK is set to false)
define('MOMO_COLLECTION_SUB_KEY', 'your_collection_primary_key');
define('MOMO_DISBURSE_SUB_KEY', 'your_disbursements_primary_key');
define('MOMO_API_USER', 'your-api-user-uuid');
define('MOMO_API_KEY', 'your-api-key');

/**
 * Initiates a MoMo collection (Request to Pay)
 *
 * @param array $params Contains: 'amount', 'currency', 'phone', 'external_id', 'description', 'network'
 * @return array ['success' => bool, 'reference' => string, 'message' => string]
 */
function initiateMoMoCollection(array $params) {
    if (MOMO_USE_MOCK) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $ref = 'MOCK-COLL-' . generate_uuidv4();
        
        // Track the mock transaction status in session storage to simulate live polling
        if (!isset($_SESSION['momo_mock_payments'])) {
            $_SESSION['momo_mock_payments'] = [];
        }
        $_SESSION['momo_mock_payments'][$ref] = [
            'status' => 'PENDING',
            'created_at' => time(),
            'amount' => $params['amount']
        ];

        return [
            'success' => true,
            'reference' => $ref,
            'message' => 'Mock payment initiated successfully.'
        ];
    }

    // Real API implementation
    $token = getMoMoAccessToken('collection');
    if (!$token) {
        return [
            'success' => false,
            'message' => 'Unable to authenticate with MoMo API.'
        ];
    }

    $referenceId = generate_uuidv4();
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-Reference-Id' => $referenceId,
        'X-Target-Environment' => MOMO_ENV,
        'Ocp-Apim-Subscription-Key' => MOMO_COLLECTION_SUB_KEY,
        'Content-Type' => 'application/json'
    ];

    $cleanPhone = preg_replace('/\D/', '', $params['phone']);

    $body = [
        'amount' => number_format((float)$params['amount'], 2, '.', ''),
        'currency' => MOMO_CURRENCY,
        'externalId' => $params['external_id'],
        'payer' => [
            'partyIdType' => 'MSISDN',
            'partyId' => $cleanPhone
        ],
        'payerMessage' => substr($params['description'], 0, 80),
        'payeeNote' => 'AgroMarket Escrow Deposit'
    ];

    $res = momo_curl_request('POST', '/collection/v1_0/requesttopay', $headers, $body);

    if ($res['code'] === 202) {
        return [
            'success' => true,
            'reference' => $referenceId,
            'message' => 'Payment request submitted successfully.'
        ];
    }

    $errorMsg = is_array($res['body']) ? ($res['body']['message'] ?? json_encode($res['body'])) : 'HTTP ' . $res['code'];
    return [
        'success' => false,
        'message' => 'MoMo transaction failed: ' . $errorMsg
    ];
}

/**
 * Checks the status of a specific collection request
 *
 * @param string $reference The UUID reference created during initiation
 * @return array ['status' => string] ('pending', 'successful', 'failed', 'cancelled')
 */
function checkMoMoPaymentStatus(string $reference) {
    if (MOMO_USE_MOCK) {
        // Sessionless Fallback Check: Query order creation time from the DB directly.
        // This makes the mock payment robust even if sessions drop during polling.
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT created_at FROM orders WHERE momo_reference = ?");
            $stmt->execute([$reference]);
            $createdAt = $stmt->fetchColumn();
            
            if ($createdAt) {
                $elapsed = time() - strtotime($createdAt);
                if ($elapsed >= 8) {
                    return ['status' => 'successful'];
                }
                return ['status' => 'pending'];
            }
        } catch (Exception $e) {
            // Keep going if database query fails
        }

        // Backup Session-based Check (standard mock fallback)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['momo_mock_payments'][$reference])) {
            $payment = $_SESSION['momo_mock_payments'][$reference];
            $elapsed = time() - $payment['created_at'];
            if ($elapsed >= 8) {
                $_SESSION['momo_mock_payments'][$reference]['status'] = 'SUCCESSFUL';
                return ['status' => 'successful'];
            }
            return ['status' => 'pending'];
        }
        
        return ['status' => 'successful'];
    }

    // Real API implementation (unchanged)
    $token = getMoMoAccessToken('collection');
    if (!$token) {
        return ['status' => 'failed'];
    }

    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-Target-Environment' => MOMO_ENV,
        'Ocp-Apim-Subscription-Key' => MOMO_COLLECTION_SUB_KEY
    ];

    $res = momo_curl_request('GET', "/collection/v1_0/requesttopay/{$reference}", $headers);

    if ($res['code'] === 200 && isset($res['body']['status'])) {
        return [
            'status' => strtolower($res['body']['status'])
        ];
    }

    return ['status' => 'pending'];
}

/**
 * Disburses MoMo Payment (Transfers money to farmer from system wallet)
 *
 * @param array $params Contains: 'amount', 'currency', 'phone', 'external_id', 'description'
 * @return array ['success' => bool, 'reference' => string, 'message' => string]
 */
function disburseMoMoPayment(array $params) {
    if (MOMO_USE_MOCK) {
        return [
            'success' => true,
            'reference' => 'MOCK-DISB-' . generate_uuidv4(),
            'message' => 'Mock funds transferred successfully.'
        ];
    }

    // Real API implementation
    $token = getMoMoAccessToken('disbursement');
    if (!$token) {
        return [
            'success' => false,
            'reference' => null,
            'message' => 'Authentication failed for disbursements.'
        ];
    }

    $referenceId = generate_uuidv4();
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-Reference-Id' => $referenceId,
        'X-Target-Environment' => MOMO_ENV,
        'Ocp-Apim-Subscription-Key' => MOMO_DISBURSE_SUB_KEY,
        'Content-Type' => 'application/json'
    ];

    $cleanPhone = preg_replace('/\D/', '', $params['phone']);

    $body = [
        'amount' => number_format((float)$params['amount'], 2, '.', ''),
        'currency' => MOMO_CURRENCY,
        'externalId' => $params['external_id'],
        'payee' => [
            'partyIdType' => 'MSISDN',
            'partyId' => $cleanPhone
        ],
        'payerMessage' => substr($params['description'], 0, 80),
        'payeeNote' => 'AgroMarket Escrow Disbursal'
    ];

    $res = momo_curl_request('POST', '/disbursement/v1_0/transfer', $headers, $body);

    if ($res['code'] === 202) {
        return [
            'success' => true,
            'reference' => $referenceId,
            'message' => 'Disbursal initiated successfully.'
        ];
    }

    $errorMsg = is_array($res['body']) ? ($res['body']['message'] ?? json_encode($res['body'])) : 'HTTP ' . $res['code'];
    return [
        'success' => false,
        'reference' => null,
        'message' => 'Disbursal transaction failed: ' . $errorMsg
    ];
}

// --- Internal Helper Functions ---

/**
 * Fetches an active OAuth2 Bearer Access Token for MoMo Products
 */
function getMoMoAccessToken(string $product = 'collection') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $sessionKey = "momo_token_{$product}";
    // Use session cached token if still valid to reduce API request volume
    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey]['expires_at'] > time()) {
        return $_SESSION[$sessionKey]['token'];
    }

    $subscriptionKey = ($product === 'collection') ? MOMO_COLLECTION_SUB_KEY : MOMO_DISBURSE_SUB_KEY;
    $basicCredentials = base64_encode(MOMO_API_USER . ':' . MOMO_API_KEY);

    $headers = [
        'Authorization' => 'Basic ' . $basicCredentials,
        'Ocp-Apim-Subscription-Key' => $subscriptionKey,
        'Content-Type' => 'application/json'
    ];

    $res = momo_curl_request('POST', "/{$product}/token/", $headers);

    if ($res['code'] === 200 && isset($res['body']['access_token'])) {
        $expiresIn = (int)($res['body']['expires_in'] ?? 3600);
        $_SESSION[$sessionKey] = [
            'token' => $res['body']['access_token'],
            'expires_at' => time() + $expiresIn - 60 // Pad 60 seconds buffer
        ];
        return $res['body']['access_token'];
    }

    return null;
}

/**
 * Standard cURL executor wrapper
 */
function momo_curl_request(string $method, string $endpoint, array $headers, $body = null) {
    $ch = curl_init();
    
    $url = rtrim(MOMO_BASE_URL, '/') . '/' . ltrim($endpoint, '/');
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $formattedHeaders = [];
    foreach ($headers as $key => $val) {
        $formattedHeaders[] = "$key: $val";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    }

    // Set connection timeouts
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    // Disable SSL validation only on sandbox/local tests
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, MOMO_ENV === 'production');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => json_decode($response, true) ?: $response
    ];
}

/**
 * Generates a standard UUID v4 required for MTN standard MoMo API tracking
 */
function generate_uuidv4() {
    try {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    } catch (Exception $e) {
        // Fallback generator if random_bytes isn't functional
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}