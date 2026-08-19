<?php
class Config {
  protected static array $env = [];

  public static function load(string $path): void {
    if (!file_exists($path)) {
      // allow running with only environment variables exported
      return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      if (str_starts_with(trim($line), '#')) continue;
      $parts = explode('=', $line, 2);
      if (count($parts) !== 2) continue;
      $key = trim($parts[0]);
      $value = trim($parts[1]);
      $value = trim($value, "\"' ");
      self::$env[$key] = $value;
      @putenv($key . '=' . $value);
    }
  }

  public static function get(string $key, $default = null) {
    $env = getenv($key);
    if ($env !== false && $env !== null) return $env;
    return self::$env[$key] ?? $default;
  }

  public static function getOutgoingProxy(): ?string {
    try {
      $pdo = DB::conn();
      $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE namespace = 'network' AND `key` = 'outgoing_proxy' LIMIT 1");
      $stmt->execute();
      $res = $stmt->fetchColumn();
      if ($res) {
        $val = json_decode($res, true);
        if (is_string($val) && trim($val) !== '') {
          return trim($val);
        }
      }
    } catch (\Throwable $e) {
      // Ignore DB errors
    }
    return null;
  }

  public static function applyCurlProxy(&$ch): void {
    $proxy = self::getOutgoingProxy();
    if ($proxy) {
      curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
  }

  /**
   * Save or update key-value pairs in the .env file
   */
  public static function saveEnv(array $pairs, ?string $path = null): bool {
    $path = $path ?: (dirname(__DIR__) . '/.env');
    $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $updatedKeys = [];

    foreach ($lines as $i => $line) {
      $trimmed = trim($line);
      if (str_starts_with($trimmed, '#') || empty($trimmed)) continue;
      $parts = explode('=', $line, 2);
      if (count($parts) === 2) {
        $key = trim($parts[0]);
        if (array_key_exists($key, $pairs)) {
          $val = (string)$pairs[$key];
          $escapedVal = (strpos($val, ' ') !== false || strpos($val, '#') !== false) ? '"' . addcslashes($val, '"\\') . '"' : $val;
          $lines[$i] = $key . '=' . $escapedVal;
          $updatedKeys[$key] = true;
          self::$env[$key] = $val;
          @putenv($key . '=' . $val);
        }
      }
    }

    foreach ($pairs as $k => $v) {
      if (!isset($updatedKeys[$k])) {
        $val = (string)$v;
        $escapedVal = (strpos($val, ' ') !== false || strpos($val, '#') !== false) ? '"' . addcslashes($val, '"\\') . '"' : $val;
        $lines[] = $k . '=' . $escapedVal;
        self::$env[$k] = $val;
        @putenv($k . '=' . $val);
      }
    }

    return (bool)@file_put_contents($path, implode("\n", $lines) . "\n");
  }
}