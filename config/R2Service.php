<?php
class R2Service {
    private $accountId;
    private $accessKeyId;
    private $secretAccessKey;
    private $bucketName;
    private $region = 'auto'; // R2 uses auto

    public function __construct() {
        // Load .env file if exists
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim($value);
                    putenv(trim($key) . '=' . trim($value));
                }
            }
        }

        // Load from environment variables
        $this->accountId = getenv('R2_ACCOUNT_ID') ?: $_ENV['R2_ACCOUNT_ID'] ?? '';
        $this->accessKeyId = getenv('R2_ACCESS_KEY_ID') ?: $_ENV['R2_ACCESS_KEY_ID'] ?? '';
        $this->secretAccessKey = getenv('R2_SECRET_ACCESS_KEY') ?: $_ENV['R2_SECRET_ACCESS_KEY'] ?? '';
        $this->bucketName = getenv('R2_BUCKET_NAME') ?: $_ENV['R2_BUCKET_NAME'] ?? 'uploadviax';

        // Validate required credentials
        if (empty($this->accountId) || empty($this->accessKeyId) || empty($this->secretAccessKey)) {
            throw new Exception('R2 credentials not configured. Check .env file.');
        }
    }

    public function uploadFile($fileTempPath, $fileName, $contentType) {
        $host = "{$this->bucketName}.{$this->accountId}.r2.cloudflarestorage.com";
        $endpoint = "https://{$host}/{$fileName}";
        
        $content = file_get_contents($fileTempPath);
        if ($content === false) {
             throw new Exception("Error reading file content.");
        }

        $datetime = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        // Headers
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => hash('sha256', $content),
            'x-amz-date' => $datetime,
            'content-type' => $contentType,
        ];
        
        // Canonical Request
        $canonicalUri = '/' . $fileName;
        $canonicalQueryString = '';
        
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $payloadHash = hash('sha256', $content);
        $canonicalRequest = "PUT\n$canonicalUri\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

        // String to Sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "$date/{$this->region}/s3/aws4_request";
        $stringToSign = "$algorithm\n$datetime\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        // Calculate Signature
        $kSecret = 'AWS4' . $this->secretAccessKey;
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // Authorization Header
        $authorization = "$algorithm Credential={$this->accessKeyId}/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";
        
        // Make Request
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: $authorization",
            "x-amz-date: $datetime",
            "x-amz-content-sha256: $payloadHash",
            "Content-Type: $contentType"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
             // Return just the key (filename). Frontend will construct the full proxy URL.
             return $fileName;
        } else {
            throw new Exception("R2 Upload Failed: HTTP $httpCode - Response: $response - CurlError: $error");
        }
    }

    public function getFile($fileName) {
        $host = "{$this->bucketName}.{$this->accountId}.r2.cloudflarestorage.com";
        $endpoint = "https://{$host}/{$fileName}";
        
        $datetime = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        // Headers for GET
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => hash('sha256', ''), // Empty payload for GET
            'x-amz-date' => $datetime,
        ];
        
        // Canonical Request
        $canonicalUri = '/' . $fileName;
        $canonicalQueryString = '';
        
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $payloadHash = hash('sha256', '');
        $canonicalRequest = "GET\n$canonicalUri\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

        // String to Sign
        $stringToSign = "AWS4-HMAC-SHA256\n$datetime\n$date/{$this->region}/s3/aws4_request\n" . hash('sha256', $canonicalRequest);

        // Signature Calculation
        $kSecret = 'AWS4' . $this->secretAccessKey;
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/$date/{$this->region}/s3/aws4_request, SignedHeaders=$signedHeaders, Signature=$signature";
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Important: passthrough headers might be needed, but R2 returns proper types usually.
        // We will return the raw content.
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: $authorization",
            "x-amz-date: $datetime",
            "x-amz-content-sha256: $payloadHash"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($httpCode == 200) {
            return ['content' => $response, 'type' => $contentType];
        }
        return false;
    }

    public function deleteFile($fileName) {
        $host = "{$this->bucketName}.{$this->accountId}.r2.cloudflarestorage.com";
        $endpoint = "https://{$host}/{$fileName}";
        
        $datetime = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        // Headers for DELETE
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => hash('sha256', ''), // Empty payload for DELETE
            'x-amz-date' => $datetime,
        ];
        
        // Canonical Request
        $canonicalUri = '/' . $fileName;
        $canonicalQueryString = '';
        
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');
        
        $payloadHash = hash('sha256', '');
        $canonicalRequest = "DELETE\n$canonicalUri\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        
        // String to Sign
        $stringToSign = "AWS4-HMAC-SHA256\n$datetime\n$date/{$this->region}/s3/aws4_request\n" . hash('sha256', $canonicalRequest);
        
        // Signature Calculation
        $kSecret = 'AWS4' . $this->secretAccessKey;
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/$date/{$this->region}/s3/aws4_request, SignedHeaders=$signedHeaders, Signature=$signature";
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: $authorization",
            "x-amz-date: $datetime",
            "x-amz-content-sha256: $payloadHash"
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // 204 No Content is standard for DELETE success
        return ($httpCode >= 200 && $httpCode < 300);
    }
    public function listObjects($prefix = '') {
        $host = "{$this->bucketName}.{$this->accountId}.r2.cloudflarestorage.com";
        $endpoint = "https://{$host}/?list-type=2&prefix={$prefix}";
        
        $datetime = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        // Headers
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => hash('sha256', ''),
            'x-amz-date' => $datetime,
        ];

        // Canonical Request
        $canonicalUri = '/';
        
        // Query Params must be sorted and encoded
        $params = [
            'list-type' => '2',
            'prefix' => $prefix
        ];
        ksort($params);
        
        $canonicalQueryString = [];
        foreach ($params as $key => $value) {
            $canonicalQueryString[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $canonicalQueryString = implode('&', $canonicalQueryString);
        
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $payloadHash = hash('sha256', '');
        $canonicalRequest = "GET\n$canonicalUri\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

        // String to Sign
        $stringToSign = "AWS4-HMAC-SHA256\n$datetime\n$date/{$this->region}/s3/aws4_request\n" . hash('sha256', $canonicalRequest);

        // Signature
        $kSecret = 'AWS4' . $this->secretAccessKey;
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/$date/{$this->region}/s3/aws4_request, SignedHeaders=$signedHeaders, Signature=$signature";

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: $authorization",
            "x-amz-date: $datetime",
            "x-amz-content-sha256: $payloadHash"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            // Parse XML response
            $xml = simplexml_load_string($response);
            $files = [];
            if ($xml && isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $files[] = (string)$content->Key;
                }
            }
            return $files;
        } else {
             // throw new Exception("List Failed: " . $response);
             return []; // Fail silent or empty
        }
    }


    public function debugList($prefix = '') {
        $host = "{$this->bucketName}.{$this->accountId}.r2.cloudflarestorage.com";
        $endpoint = "https://{$host}/?list-type=2&prefix={$prefix}";
        
        $datetime = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        // Headers
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => hash('sha256', ''),
            'x-amz-date' => $datetime,
        ];

        // Canonical Request
        $canonicalUri = '/';
        $canonicalQueryString = "list-type=2&prefix={$prefix}";
        
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $payloadHash = hash('sha256', '');
        $canonicalRequest = "GET\n$canonicalUri\n$canonicalQueryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

        // String to Sign
        $stringToSign = "AWS4-HMAC-SHA256\n$datetime\n$date/{$this->region}/s3/aws4_request\n" . hash('sha256', $canonicalRequest);

        // Signature
        $kSecret = 'AWS4' . $this->secretAccessKey;
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/$date/{$this->region}/s3/aws4_request, SignedHeaders=$signedHeaders, Signature=$signature";

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: $authorization",
            "x-amz-date: $datetime",
            "x-amz-content-sha256: $payloadHash"
        ]);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        return "HTTP CODE: " . $info['http_code'] . "\nRESPONSE:\n" . $response;
    }
}
