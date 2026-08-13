<?php
/**
 * CSRF Protection Helper Class
 */
class Csrf {
    /**
     * Get or generate the CSRF token stored in session
     */
    public static function getToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify if the provided CSRF token matches the session token
     */
    public static function verifyToken(?string $token): bool {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Validate CSRF token from current request (POST parameters or X-CSRF-TOKEN HTTP header)
     */
    public static function validateRequest(): bool {
        $token = $_POST['csrf_token'] ?? $_POST['_token'] ?? null;
        
        if ($token === null) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null;
        }

        if ($token === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $val) {
                    if (in_array(strtolower((string)$key), ['x-csrf-token', 'x-xsrf-token', 'csrf-token'], true)) {
                        $token = $val;
                        break;
                    }
                }
            }
        }

        if ($token === null) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $jsonData = json_decode($rawInput, true);
                if (is_array($jsonData)) {
                    $token = $jsonData['csrf_token'] ?? $jsonData['_token'] ?? null;
                }
            }
        }
        
        return self::verifyToken($token);
    }

    /**
     * Generate HTML input field for form inclusion
     */
    public static function field(): string {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
