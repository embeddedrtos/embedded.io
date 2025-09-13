<?php
declare(strict_types=1);
// get-discussions.php (secure version)
// - Supports ?type=graphql|rest|search
// - Uses environment token only
// - Input validation + basic rate limiting + safe caching
// - Returns JSON with appropriate HTTP status codes
// - Comments in English

header('Content-Type: application/json; charset=utf-8');

// ---------------------------
// Configuration
// ---------------------------
$GITHUB_OWNER = 'embeddedrtos';
$GITHUB_REPO  = 'embedded.io';

// Token MUST be provided as environment variable
$TOKEN_GITHUB = getenv('GITHUB_EMBEDDEDIO_TOKEN');

// Optional: comma separated allowed origins for CORS (e.g. "https://example.com,https://app.example.com")
$ALLOWED_ORIGINS = getenv('ALLOWED_ORIGINS') ?: '';

// Basic rate limiting (per IP) configuration
$RATE_LIMIT_MAX_REQUESTS = 15;   // max requests
$RATE_LIMIT_WINDOW_SEC    = 60;   // per X seconds

// Cache TTL (seconds)
$CACHE_TTL = 300; // 5 minutes

// Paths
$TMP_DIR = sys_get_temp_dir();
if (!is_writable($TMP_DIR)) {
    // fallback - attempt to use a subdir under script dir
    $fallback = __DIR__ . '/tmp';
    if (!is_dir($fallback)) @mkdir($fallback, 0755, true);
    $TMP_DIR = $fallback;
}

// ---------------------------
// Helper functions
// ---------------------------

/**
 * Send a JSON error response and exit.
 */
function fail(string $msg, int $httpCode = 400): void {
    http_response_code($httpCode);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Safe wrapper to call GitHub (REST or GraphQL).
 * Returns raw response string or false on network error.
 */
function call_github_api(string $url, string $method, ?string $body, string $token): string|false {
    $ch = curl_init($url);

    $headers = [
        'User-Agent: embedded.io',
        "Authorization: Bearer $token",
        'Accept: application/vnd.github+json'
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            // Content-Type is added automatically by GitHub for GraphQL; add explicitly if needed
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    }

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $err) {
        // network error
        error_log("GitHub cURL error: $err");
        return false;
    }

    // Return raw response (caller should json_decode and inspect)
    return $resp;
}

/**
 * Atomic safe cache write
 */
function safe_cache_write(string $path, string $data): bool {
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

/**
 * Build a cache file path (per type and optional query)
 */
function cache_path_for(string $tmpDir, string $type, ?string $q = null): string {
    $safeType = preg_replace('/[^a-z0-9_-]/i', '_', $type);
    if ($q !== null && $q !== '') {
        $hash = substr(hash('sha256', $q), 0, 12);
        return rtrim($tmpDir, DIRECTORY_SEPARATOR) . "/embeddedio_discussions_cache_{$safeType}_{$hash}.json";
    }
    return rtrim($tmpDir, DIRECTORY_SEPARATOR) . "/embeddedio_discussions_cache_{$safeType}.json";
}

/**
 * Basic per-IP rate limiter using a small file counter in tmp dir.
 * Returns true if allowed, false if rate-limited.
 */
function rate_limit_check(string $tmpDir, int $maxRequests, int $windowSec): bool {
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
            // If current window expired, reset
            if ($now - (int)$data['window_start'] >= $windowSec) {
                $data = ['window_start' => $now, 'count' => 0];
            }
        }
    }

    if ($data['count'] + 1 > $maxRequests) {
        return false;
    }

    $data['count'] = $data['count'] + 1;
    @file_put_contents($path, json_encode($data), LOCK_EX);
    return true;
}

/**
 * Return allowed CORS header if origin matches allowed list env.
 */
function maybe_set_cors(string $allowedOrigins): void {
    if (empty($allowedOrigins)) {
        // no CORS header set (default same-origin)
        return;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$origin) return;

    // build array of allowed origins and trim
    $arr = array_map('trim', explode(',', $allowedOrigins));
    if (in_array($origin, $arr, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
}

// ---------------------------
// Begin request handling
// ---------------------------

// Set CORS if configured (safe.)
maybe_set_cors($ALLOWED_ORIGINS);

// Token must exist
if (!$TOKEN_GITHUB) {
    fail('Server configuration error: GITHUB_EMBEDDEDIO_TOKEN not set.', 500);
}

// Validate 'type' parameter
$allowedTypes = ['graphql', 'rest', 'search'];
$type = strtolower(trim((string)($_GET['type'] ?? 'graphql')));
if (!in_array($type, $allowedTypes, true)) {
    fail('Invalid type parameter. Allowed: graphql, rest, search', 400);
}

// Basic rate limiter per IP
if (!rate_limit_check($TMP_DIR, $RATE_LIMIT_MAX_REQUESTS, $RATE_LIMIT_WINDOW_SEC)) {
    fail('Too many requests from your IP. Try again later.', 429);
}

// For search type, validate query param
$q = (string)($_GET['q'] ?? '');
if ($type === 'search') {
    $q = trim($q);
    if ($q === '') {
        fail('Missing search query parameter ?q=your+terms', 400);
    }
    // Allow only a safe subset of characters: letters, numbers, spaces, - _ . +
    if (strlen($q) > 200) {
        fail('Search query too long (max 200 chars)', 400);
    }
    if (!preg_match('/^[A-Za-z0-9\s\-\_\.\+]+$/', $q)) {
        fail('Search query contains invalid characters', 400);
    }
}

// Cache path depends on type and query (for search include q)
$cacheFile = cache_path_for($TMP_DIR, $type, $type === 'search' ? $q : null);

// Serve cache if fresh
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $CACHE_TTL)) {
    // safe: echo cached content directly (should be JSON)
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false) {
        header("Cache-Control: public, max-age=$CACHE_TTL");
        echo $cached;
        exit;
    }
}

// ---------------------------
// Mode handlers
// ---------------------------

$responseRaw = null;

if ($type === 'rest') {
    // Use GitHub REST API for discussions (basic)
    // Validate per_page param optionally (we fix to 10 for simplicity)
    $perPage = 10;
    $restUrl = "https://api.github.com/repos/" . rawurlencode($GITHUB_OWNER) . "/" . rawurlencode($GITHUB_REPO) . "/discussions?per_page=$perPage";

    $responseRaw = call_github_api($restUrl, 'GET', null, $TOKEN_GITHUB);
    if ($responseRaw === false) {
        fail('Network error when contacting GitHub REST API', 502);
    }

    // try to decode to check GitHub error status
    $decoded = json_decode($responseRaw, true);
    if ($decoded === null) {
        // return a generic message
        fail('Invalid response from upstream API', 502);
    }

    // If GitHub returned message and documentation_url, do not leak raw debug details
    if (isset($decoded['message']) && !isset($decoded[0])) {
        // propagate limited error info
        $msg = $decoded['message'];
        http_response_code(502);
        $out = ['error' => "GitHub REST API error: $msg"];
        // Cache the error briefly to avoid repeated failing calls
        safe_cache_write($cacheFile, json_encode($out));
        echo json_encode($out);
        exit;
    }

    // otherwise response is likely OK (array of discussions)
    $final = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    safe_cache_write($cacheFile, $final);
    header("Cache-Control: public, max-age=$CACHE_TTL");
    echo $final;
    exit;
}

if ($type === 'search') {
    // Use GitHub Search API for issues/discussions
    // Build safe URL-encoded query; include repo and is:discussion
    $encoded = rawurlencode("repo:{$GITHUB_OWNER}/{$GITHUB_REPO} is:discussion " . $q);
    $searchUrl = "https://api.github.com/search/issues?q={$encoded}&per_page=30";

    $responseRaw = call_github_api($searchUrl, 'GET', null, $TOKEN_GITHUB);
    if ($responseRaw === false) {
        fail('Network error when contacting GitHub Search API', 502);
    }

    $decoded = json_decode($responseRaw, true);
    if ($decoded === null) {
        fail('Invalid response from upstream API', 502);
    }

    if (isset($decoded['message'])) {
        $msg = $decoded['message'];
        http_response_code(502);
        $out = ['error' => "GitHub Search API error: $msg"];
        safe_cache_write($cacheFile, json_encode($out));
        echo json_encode($out);
        exit;
    }

    $final = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    safe_cache_write($cacheFile, $final);
    header("Cache-Control: public, max-age=$CACHE_TTL");
    echo $final;
    exit;
}

// Default (graphql)
if ($type === 'graphql') {
    // GraphQL query: fetch rich data for UI (labels, categories, reactions, comments, replies)
    // Keep size reasonable: first:10 discussions, comments first:20, replies first:5 -- adjust as needed
    $gql = <<<'GRAPHQL'
{
  repository(owner: "%s", name: "%s") {
    discussions(first: 10, orderBy: {field: CREATED_AT, direction: DESC}) {
      totalCount
      nodes {
        id
        number
        title
        body
        bodyHTML
        url
        createdAt
        updatedAt
        state
        locked
        answerChosen
        author {
          login
          avatarUrl
          url
        }
        category {
          id
          name
          description
          slug
        }
        labels(first: 10) {
          nodes { id name color description }
        }
        reactions(first: 20) {
          totalCount
          nodes { content user { login avatarUrl url } }
        }
        comments(first: 20, orderBy: {field: UPDATED_AT, direction: ASC}) {
          totalCount
          nodes {
            id
            body
            bodyHTML
            createdAt
            updatedAt
            author { login avatarUrl url }
            replies(first: 5) {
              totalCount
              nodes { id bodyHTML createdAt author { login avatarUrl url } }
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

    $responseRaw = call_github_api('https://api.github.com/graphql', 'POST', $payload, $TOKEN_GITHUB);
    if ($responseRaw === false) {
        fail('Network error when contacting GitHub GraphQL API', 502);
    }

    $decoded = json_decode($responseRaw, true);
    if ($decoded === null) {
        fail('Invalid JSON from GitHub GraphQL API', 502);
    }

    // If GitHub returned GraphQL errors, log them and show a generic error to client
    if (isset($decoded['errors']) && is_array($decoded['errors'])) {
        error_log('GitHub GraphQL errors: ' . json_encode($decoded['errors']));
        http_response_code(502);
        $out = ['error' => 'Upstream GraphQL API returned errors'];
        safe_cache_write($cacheFile, json_encode($out));
        echo json_encode($out);
        exit;
    }

    $final = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    safe_cache_write($cacheFile, $final);
    header("Cache-Control: public, max-age=$CACHE_TTL");
    echo $final;
    exit;
}

// Fallback (shouldn't reach)
fail('Unhandled request type', 400);
