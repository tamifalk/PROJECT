<?php
// allocation/TaxAuthorityClient.php 

class TaxAuthorityClient {
    private AllocationConfig $config;
    private string $tokenCacheFile;

    public function __construct(AllocationConfig $config) {
        $this->config = $config;
        $this->tokenCacheFile = __DIR__ . '/.token_cache.json'; // קובץ מטמון זמני לטוקן 
        
        // יצירת תיקיית לוגים אם היא לא קיימת
        $logPath = dirname($this->config->get('logging.file_path'));
        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
        }
    }

    /**
     * ניהול ושליפת ה-OAuth2 Token עם מנגנון Cache 
     */
    public function getAccessToken(): string {
        if (file_exists($this->tokenCacheFile)) {
            $cache = json_decode(file_get_contents($this->tokenCacheFile), true);
            // בדיקה אם הטוקן קיים ולא פג תוקף (עם מרווח ביטחון של 60 שניות)
            if (isset($cache['access_token']) && isset($cache['expires_at']) && $cache['expires_at'] > time() + 60) {
                return $cache['access_token'];
            }
        }

        return $this->fetchNewToken();
    }

    /**
     * פנייה לשרת ה-OAuth לקבלת טוקן חדש 
     */
    private function fetchNewToken(): string {
        $url = $this->config->get('oauth.token_url');
        $clientId = $this->config->get('oauth.client_id');
        $clientSecret = $this->config->get('oauth.client_secret');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $this->config->get('oauth.scope')
        ]));

        $response = $this->executeWithRetry($ch);
        $data = json_decode($response, true);

        if (!isset($data['access_token'])) {
            $this->log("OAuth Error: Failed to retrieve access token. Response: " . $response, 'ERROR');
            throw new Exception("Failed to obtain OAuth2 access token.");
        }

        // שמירה במטמון כולל חישוב זמן תפוגה
        $expiresIn = $data['expires_in'] ?? 3600;
        $cacheData = [
            'access_token' => $data['access_token'],
            'expires_at' => time() + $expiresIn
        ];
        file_put_contents($this->tokenCacheFile, json_encode($cacheData));

        return $data['access_token'];
    }

    /**
     * ביצוע קריאת ה-API לאישור החשבונית (Approval Endpoint) 
     */
    public function sendApprovalRequest(array $payload): array {
        $env = $this->config->get('environment', 'sandbox'); 
        $url = $env === 'production' 
            ? $this->config->get('api.approval_url_production') 
            : $this->config->get('api.approval_url_sandbox'); 

        $token = $this->getAccessToken(); 

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $this->log("Sending Request to Tax Authority. Invoice ID: " . $payload['invoice_id'], 'INFO'); 
        
        $response = $this->executeWithRetry($ch);
        $statusCode = curl_getinfo($ch, curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        $this->log("Received Response. Status Code: {$statusCode}. Body: {$response}", 'INFO'); 

        return [
            'status' => $statusCode,
            'body' => json_decode($response, true) ?: $response
        ];
    }

    /**
     * מנגנון Retry אוטומטי של עד 3 ניסיונות במקרה של שגיאות רשת 
     */
    private function executeWithRetry($ch) {
        $maxRetries = 3; [cite: 53]
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            // במקרה של ניסיון חוזר, נשכפל את ה-Handle של ה-cURL כדי לא לאבד הגדרות
            $chCopy = curl_copy_handle($ch);
            $response = curl_exec($chCopy);
            $error = curl_error($chCopy);
            $httpCode = curl_getinfo($chCopy, CURLINFO_HTTP_CODE);
            curl_close($chCopy);

            if ($response !== false && $httpCode >= 200 && $httpCode < 500) {
                return $response;
            }

            $this->log("Network attempt {$attempt} failed. Error: {$error}. HTTP Code: {$httpCode}", 'WARNING'); 
            if ($attempt < $maxRetries) {
                usleep(500000); // המתנה של חצי שנייה בין ניסיונות
            }
        }

        throw new Exception("Network connection failed after {$maxRetries} attempts."); 
    }

    /**
     * כתיבת לוגים למערכת במבנה מותאם [cite: 54, 63]
     */
    public function log(string $message, string $level = 'INFO'): void {
        $logFile = $this->config->get('logging.file_path'); 
        $processId = $this->config->get('logging.api_log_process_id');
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [Process: {$processId}] [{$level}] {$message}" . PHP_EOL; 
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}