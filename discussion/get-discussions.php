<?php
declare(strict_types=1);
/**
 * get-discussions.php (enhanced)
 * - Supports ?type=graphql|rest|search and ?after=<cursor> for GraphQL pagination
 * - File-based cache (atomic) and optional Redis cache if REDIS_URL provided
 * - Rate limiting: file-based fallback; Redis-backed token bucket if Redis available
 * - Webhook endpoint to invalidate caches (use ?webhook=1)
 * - Optional client API key / HMAC requirement for search endpoint
 * - Improved error handling and logging
 *
 * Comments and configuration in English.
 */

// ensure errors are not displayed to clients in production
@ini_set('display_errors', '0');
error_reporting(E_ALL);

// Content type
header('Content-Type: application/json; charset=utf-8');

// ---------------------------
// Configuration (EDIT as needed)
// ---------------------------
$GITHUB_OWNER = 'embeddedrtos';
$GITHUB_REPO  = 'embedded.io';

$TOKEN_GITHUB = getenv('GITHUB_EMBEDDEDIO_TOKEN'); // required
$ALLOWED_ORIGINS = getenv('ALLOWED_ORIGINS') ?: ''; // comma separated list

// Redis (optional) - set REDIS_URL like "tcp://127.0.0.1:6379" or "unix:///path/to/socket"
$REDIS_URL = getenv('REDIS_URL') ?: '';

// Webhook secret (optional) - used to validate incoming GitHub webhook signature
$GITHUB_WEBHOOK_SECRET = getenv('GITHUB_WEBHOOK_SECRET') ?: '';

// Optional client auth for search endpoint
$CLIENT_API_KEY = getenv('CLIENT_API_KEY') ?: '';     // simple API key
$CLIENT_SECRET  = getenv('CLIENT_SECRET') ?: '';      // HMAC secret (for X-SIGNATURE)

// Rate limiting defaults
$RATE_LIMIT_MAX_REQUESTS = 15;   // default max requests per window for file-based
$RATE_LIMIT_WINDOW_SEC    = 60;  // file-based window seconds

// Redis token bucket defaults (if using Redis)
$REDIS_RATE_MAX_TOKENS = 30;   // tokens (permits) per bucket
$REDIS_RATE_REFILL_SEC = 1;    // refill interval per token (1 token per second -> 30/sec sustained)

// Cache TTL
$CACHE_TTL = 300; // seconds

// Temporary directory for file cache/rate files
$TMP_DIR = sys_get_temp_dir();
if (!is_writable($TMP_DIR)) {
    $fallback = __DIR__ . '/tmp';
    if (!is_dir($fallback)) @mkdir($fallback, 0755, true);
    $TMP_DIR = $fallback;
}

// ---------------------------
// Utility functions
// ---------------------------

function fail(string $msg, int $httpCode = 400): void {
    http_response_code($httpCode);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Log safely */
function log_info(string $msg): void {
    // include request id/time for traceability
    $id = substr(hash('sha256', microtime(true) . random_bytes(4)), 0, 8);
    error_log("[$id] $msg");
}

/** Atomic safe file write */
function safe_cache_write(string $path, string $data): bool {
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

/** Build a cache path */
function cache_path_for(string $tmpDir, string $type, ?string $q = null, ?string $after = null): string {
    $safeType = preg_replace('/[^a-z0-9_-]/i', '_', $type);
    $key = $safeType;
    if ($q !== null && $q !== '') {
        $key .= '_' . substr(hash('sha256', $q), 0, 12);
    }
    if ($after !== null && $after !== '') {
        $key .= '_after_' . substr(hash('sha256', $after), 0, 8);
    }
    return rtrim($tmpDir, DIRECTORY_SEPARATOR) . "/embeddedio_discussions_cache_{$key}.json";
}

/** Parse Redis URL into host/port/path */
function parse_redis_url(string $url): array {
    // simple parse, supports tcp://host:port or unix:///path
    $parts = parse_url($url);
    if ($parts === false) return [];
    return $parts;
}

// ---------------------------
// Redis initialization (optional)
// ---------------------------
$redis = null;
$useRedis = false;
if (!empty($REDIS_URL) && extension_loaded('redis')) {
    $parts = parse_redis_url($REDIS_URL);
    try {
        $redis = new Redis();
        if (isset($parts['scheme']) && $parts['scheme'] === 'unix' && isset($parts['path'])) {
            $redis->connect($parts['path']);
        } else {
            $host = $parts['host'] ?? '127.0.0.1';
            $port = $parts['port'] ?? 6379;
            $redis->connect($host, (int)$port);
            if (isset($parts['pass'])) $redis->auth($parts['pass']);
        }
        // optional: test ping
        $pong = $redis->ping();
        if ($pong === '+PONG' || $pong === 'PONG') {
            $useRedis = true;
            log_info("Connected to Redis at $REDIS_URL");
        }
    } catch (Throwable $e) {
        error_log("Redis connect failed: " . $e->getMessage());
        $useRedis = false;
        $redis = null;
    }
}

// ---------------------------
// Rate limiting
// - If Redis available: token-bucket-like via Lua or simple atomic commands
// - Else: file-based sliding window (coarse but OK for low traffic)
// ---------------------------

function redis_rate_allow(Redis $redis, string $key, int $maxTokens, int $refillSec): bool {
    // Simple token-bucket implemented in Lua for atomicity
    $lua = <<<'LUA'
local key = KEYS[1]
local max_tokens = tonumber(ARGV[1])
local refill_sec = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local data = redis.call("HMGET", key, "tokens", "last")
local tokens = tonumber(data[1])
local last = tonumber(data[2])

if tokens == nil then
  tokens = max_tokens
  last = now
end

-- add refill tokens proportionally
local delta = math.max(0, now - last)
local refill = math.floor(delta / refill_sec)
if refill > 0 then
  tokens = math.min(max_tokens, tokens + refill)
  last = last + refill * refill_sec
end

local allowed = 0
if tokens > 0 then
  tokens = tokens - 1
  allowed = 1
end

redis.call("HMSET", key, "tokens", tokens, "last", last)
redis.call("EXPIRE", key, math.ceil(refill_sec * 2 + 1))
return allowed
LUA;

    $now = microtime(true);
    try {
        $res = $redis->eval($lua, [$key, $maxTokens, $refillSec, $now], 1);
        return (int)$res === 1;
    } catch (Throwable $e) {
        error_log("Redis rate eval error: " . $e->getMessage());
        // fallback: deny-safe
        return false;
    }
}

function file_rate_allow(string $tmpDir, int $maxRequests, int $windowSec): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_' . preg_replace('/[^a-z0-9_.-]/i', '_', $ip);
    $path = rtrim($tmpDir, DIRECTORY_SEPARATOR) . "/embeddedio_rate_{$key}.json";

    $now = time();
    $data = ['window_start' => $now, 'count' => 0];

    if (file_exists($path)) {
        $raw = @file_get_contents($path);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
            $data = $decoded;
            if ($now - (int)$data['window_start'] >= $windowSec) {
                $data = ['window_start' => $now, 'count' => 0];
            }
        }
    }

    if ($data['count'] + 1 > $maxRequests) {
        return false;
    }
    $data['count']++;
    @file_put_contents($path, json_encode($data), LOCK_EX);
    return true;
}

// perform rate limiting now (before expensive ops)
if ($useRedis && $redis instanceof Redis) {
    // key partition by IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateKey = "rate_ip_" . preg_replace('/[^a-z0-9_.-]/i', '_', $ip);
    $allowed = redis_rate_allow($redis, $rateKey, $REDIS_RATE_MAX_TOKENS, $REDIS_RATE_REFILL_SEC);
    if (!$allowed) {
        fail('Too many requests (try later)', 429);
    }
} else {
    if (!file_rate_allow($TMP_DIR, $RATE_LIMIT_MAX_REQUESTS, $RATE_LIMIT_WINDOW_SEC)) {
        fail('Too many requests (try later)', 429);
    }
}

// ---------------------------
// CORS handling (ALLOWED_ORIGINS env)
// ---------------------------
function maybe_set_cors(string $allowedOrigins): void {
    if (empty($allowedOrigins)) {
        return;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$origin) return;
    $arr = array_map('trim', explode(',', $allowedOrigins));
    if (in_array($origin, $arr, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE');
    }
}
maybe_set_cors($ALLOWED_ORIGINS);

// ---------------------------
// Handle preflight OPTIONS quickly
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------
// Webhook: invalidate caches
// Usage: POST /get-discussions.php?webhook=1
// Requires GITHUB_WEBHOOK_SECRET env. Validates X-Hub-Signature-256 header.
// ---------------------------
if (isset($_GET['webhook']) && $_GET['webhook'] == '1') {
    if (empty($GITHUB_WEBHOOK_SECRET)) {
        fail('Webhook secret not configured on server', 403);
    }
    $payload = file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!$sig_header) {
        fail('Missing signature header', 401);
    }
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $GITHUB_WEBHOOK_SECRET);
    if (!hash_equals($expected, $sig_header)) {
        error_log('Webhook signature mismatch');
        fail('Invalid signature', 401);
    }
    // parse event to choose which caches to clear (simple approach: clear all)
    // Optionally analyze payload to clear specific caches (e.g. discussion id).
    // Clear file caches:
    foreach (glob(rtrim($TMP_DIR, DIRECTORY_SEPARATOR) . "/embeddedio_discussions_cache_*.json") as $f) {
        @unlink($f);
    }
    // If Redis used, delete keys matching pattern
    if ($useRedis && $redis instanceof Redis) {
        try {
            $keys = $redis->keys('embeddedio_discussions_cache_*');
            foreach ($keys as $k) $redis->del($k);
        } catch (Throwable $e) {
            error_log("Redis cache clear error: " . $e->getMessage());
        }
    }
    // respond 204 (no content)
    http_response_code(204);
    exit;
}

// ---------------------------
// Validate GitHub token
// ---------------------------
if (empty($TOKEN_GITHUB)) {
    fail('Server configuration error: GITHUB_EMBEDDEDIO_TOKEN not set.', 500);
}

// ---------------------------
// Validate 'type' parameter
// ---------------------------
$allowedTypes = ['graphql', 'rest', 'search'];
$type = strtolower(trim((string)($_GET['type'] ?? 'graphql')));
if (!in_array($type, $allowedTypes, true)) {
    fail('Invalid type parameter. Allowed: graphql, rest, search', 400);
}

// Optional: for GraphQL pagination
$after = isset($_GET['after']) ? trim((string)$_GET['after']) : '';

// For search security: optionally require client API key or HMAC (if configured)
if ($type === 'search') {
    // If CLIENT_API_KEY is set on server, require X-API-KEY header
    if (!empty($CLIENT_API_KEY)) {
        $clientKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!hash_equals($CLIENT_API_KEY, (string)$clientKey)) {
            fail('Missing or invalid API key for search', 401);
        }
    }
    // If CLIENT_SECRET is set, support HMAC signature (X-SIGNATURE) using request path+q+timestamp
    if (!empty($CLIENT_SECRET)) {
        $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $tst = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
        if (empty($sig) || empty($tst)) {
            fail('Missing HMAC signature or timestamp', 401);
        }
        // enforce freshness: 5 minutes
        if (abs(time() - (int)$tst) > 300) {
            fail('Timestamp out of allowed range', 401);
        }
        // compute expected signature on server side: HMAC_SHA256(path + '|' + q + '|' + timestamp, CLIENT_SECRET)
        $q = (string)($_GET['q'] ?? '');
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $payload = $path . '|' . $q . '|' . $tst;
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $CLIENT_SECRET);
        if (!hash_equals($expected, (string)$sig)) {
            fail('Invalid HMAC signature', 401);
        }
    }
}

// ---------------------------
// Search param validation
// ---------------------------
$q = (string)($_GET['q'] ?? '');
if ($type === 'search') {
    $q = trim($q);
    if ($q === '') fail('Missing search query parameter ?q=your+terms', 400);
    if (strlen($q) > 200) fail('Search query too long (max 200 chars)', 400);
    if (!preg_match('/^[A-Za-z0-9\s\-\_\.\+]+$/', $q)) fail('Search query contains invalid characters', 400);
}

// ---------------------------
// Cache location for this request (file or redis key)
// ---------------------------
$cacheFile = cache_path_for($TMP_DIR, $type, $type === 'search' ? $q : null, $after);

// Try serve from Redis cache first (if enabled)
if ($useRedis && $redis instanceof Redis) {
    $redisKey = "embeddedio_discussions_cache_" . preg_replace('/[^a-z0-9_]/i', '_', $type);
    if ($type === 'search') $redisKey .= '_' . substr(hash('sha256', $q), 0, 12);
    if ($after !== '') $redisKey .= '_after_' . substr(hash('sha256', $after), 0, 8);

    try {
        $cached = $redis->get($redisKey);
        if ($cached !== false && $cached !== null) {
            header("Cache-Control: public, max-age=$CACHE_TTL");
            echo $cached;
            exit;
        }
    } catch (Throwable $e) {
        // log and continue to file-cache fallback
        error_log("Redis get error: " . $e->getMessage());
    }
} else {
    // File cache path check
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $CACHE_TTL)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            header("Cache-Control: public, max-age=$CACHE_TTL");
            echo $cached;
            exit;
        }
    }
}

// ---------------------------
// GitHub API caller helper
// (uses curl); enhanced error handling for status codes
// ---------------------------
function call_github_api(string $url, string $method, ?string $body, string $token): array {
    $ch = curl_init($url);
    $headers = [
        'User-Agent: embedded.io',
        "Authorization: Bearer $token",
        'Accept: application/vnd.github+json'
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $err) {
        error_log("GitHub cURL error: $err");
        return ['ok' => false, 'status' => 502, 'error' => 'Network error contacting GitHub'];
    }

    $decoded = json_decode($resp, true);
    if ($decoded === null) {
        return ['ok' => false, 'status' => 502, 'error' => 'Invalid JSON from GitHub'];
    }

    // GitHub returns HTTP 200 + "message" or GraphQL "errors" sometimes
    if ($status >= 400) {
        $msg = $decoded['message'] ?? 'GitHub returned error';
        return ['ok' => false, 'status' => $status, 'error' => "GitHub API error: $msg"];
    }

    return ['ok' => true, 'status' => $status, 'data' => $decoded, 'raw' => $resp];
}

// ---------------------------
// Handlers per mode
// ---------------------------

if ($type === 'rest') {
    $perPage = 10;
    $restUrl = "https://api.github.com/repos/" . rawurlencode($GITHUB_OWNER) . "/" . rawurlencode($GITHUB_REPO) . "/discussions?per_page=$perPage";
    $res = call_github_api($restUrl, 'GET', null, $TOKEN_GITHUB);
    if (!$res['ok']) {
        // friendly map for common status codes
        if ($res['status'] === 401) fail('Upstream: Unauthorized (check token)', 502);
        if ($res['status'] === 403) fail('Upstream: Forbidden or rate-limited', 502);
        if ($res['status'] === 429) fail('Upstream: Too many requests, try later', 502);
        fail($res['error'], $res['status']);
    }
    $final = json_encode($res['data'], JSON_UNESCAPED_UNICODE);
    // cache to Redis or file
    if ($useRedis && $redis instanceof Redis) {
        try {
            $redis->setex($redisKey ?? 'embeddedio_discussions_cache_rest', $CACHE_TTL, $final);
        } catch (Throwable $e) { error_log("Redis set error: " . $e->getMessage()); }
    } else {
        safe_cache_write($cacheFile, $final);
    }
    header("Cache-Control: public, max-age=$CACHE_TTL");
    echo $final;
    exit;
}

if ($type === 'search') {
    $encoded = rawurlencode("repo:{$GITHUB_OWNER}/{$GITHUB_REPO} is:discussion " . $q);
    $searchUrl = "https://api.github.com/search/issues?q={$encoded}&per_page=30";
    $res = call_github_api($searchUrl, 'GET', null, $TOKEN_GITHUB);
    if (!$res['ok']) {
        if ($res['status'] === 401) fail('Upstream: Unauthorized (check token)', 502);
        if ($res['status'] === 403) fail('Upstream: Forbidden or rate-limited', 502);
        fail($res['error'], $res['status']);
    }
    $final = json_encode($res['data'], JSON_UNESCAPED_UNICODE);
    if ($useRedis && $redis instanceof Redis) {
        try { $redis->setex($redisKey, $CACHE_TTL, $final); } catch (Throwable $e) { error_log("Redis set error: " . $e->getMessage()); }
    } else {
        safe_cache_write($cacheFile, $final);
    }
    header("Cache-Control: public, max-age=$CACHE_TTL");
    echo $final;
    exit;
}

// GraphQL handler with optional pagination (after)
if ($type === 'graphql') {
    // prepare GraphQL with optional 'after' cursor
    $afterPart = ($after !== '') ? ', after: "' . addslashes($after) . '"' : '';
    $gql = <<<GRAPHQL
{
  repository(owner: "%s", name: "%s") {
    discussions(first: 10{$afterPart}, orderBy: {field: UPDATED_AT, direction: DESC}) {
      totalCount
      pageInfo { hasNextPage endCursor }
      nodes {
        id
        databaseId
        number
        title
        body
        bodyHTML
        url
        createdAt
        updatedAt
        locked
        author { login avatarUrl url }
        category { id name description slug }
        labels(first: 10) { 
          nodes { id name color description } 
        }
        reactions(first: 20) { 
          totalCount 
          nodes { content user { login avatarUrl url } } 
        }
        comments(first: 20) {
          totalCount
          nodes {
            id
            databaseId
            body
            bodyHTML
            createdAt
            updatedAt
            author { login avatarUrl url }
            replies(first: 5) {
              totalCount
              nodes { 
                id 
                databaseId
                bodyHTML 
                createdAt 
                author { login avatarUrl url } 
              }
            }
            reactions(first: 10) { 
              totalCount 
              nodes { content user { login } } 
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

    $payload = json_encode(['query' => sprintf($gql, $GITHUB_OWNER, $GITHUB_REPO)], JSON_UNESCAPED_UNICODE);
    $res = call_github_api('https://api.github.com/graphql', 'POST', $payload, $TOKEN_GITHUB);
    if (!$res['ok']) {
        if ($res['status'] === 401) fail('Upstream: Unauthorized (check token)', 502);
        if ($res['status'] === 403) fail('Upstream: Forbidden or rate-limited', 502);
        fail($res['error'], $res['status']);
    }
    // handle GraphQL errors array specially
    if (isset($res['data']['errors'])) {
        error_log('GraphQL errors: ' . json_encode($res['data']['errors']));
        fail('Upstream GraphQL returned errors', 502);
    }
    $final = json_encode($res['data'], JSON_UNESCAPED_UNICODE);
    // cache
    if ($useRedis && $redis instanceof Redis) {
        try { $redis->setex($redisKey, $CACHE_TTL, $final); } catch (Throwable $e) { error_log("Redis set error: " . $e->getMessage()); }
    } else {
        safe_cache_write($cacheFile, $final);
    }
    header("Cache-Control: public, max-age=$CACHE_TTL");
    echo $final;
    exit;
}

// fallback (should not reach)
fail('Unhandled request type', 400);
