<?php
// get-discussions.php
header('Content-Type: application/json; charset=utf-8');

// --- CONFIG ---
$GITHUB_OWNER = "embeddedrtos";
$GITHUB_REPO  = "embedded.io";

// read token from environment OR from a PHP constant (fallback)
$TOKEN_GITHUB = getenv('GITHUB_EMBEDDEDIO_TOKEN');
if (!$TOKEN_GITHUB && defined('GITHUB_EMBEDDEDIO_TOKEN')) {
    $TOKEN_GITHUB = GITHUB_EMBEDDEDIO_TOKEN;
}

// cache settings (seconds)
$CACHE_TTL = 300; // 5 minutes
$cache_file = sys_get_temp_dir() . '/embeddedio_discussions_cache.json';

// serve cached response if fresh
if (file_exists($cache_file) && (time() - filemtime($cache_file) < $CACHE_TTL)) {
    // send cached content (already JSON)
    echo file_get_contents($cache_file);
    exit;
}

// require token (you set it on server). If you prefer public-no-token, remove this check.
if (empty($TOKEN_GITHUB)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error: GITHUB_EMBEDDEDIO_TOKEN not set.']);
    exit;
}

// GraphQL query
$query = <<<'GRAPHQL'
{
  repository(owner: "%OWNER%", name: "%REPO%") {
    discussions(first: 5, orderBy: {field: CREATED_AT, direction: DESC}) {
      nodes {
        title
        url
        createdAt
        author {
          login
          avatarUrl
        }
      }
    }
  }
}
GRAPHQL;

$query = str_replace(['%OWNER%','%REPO%'], [$GITHUB_OWNER, $GITHUB_REPO], $query);

// cURL to GitHub GraphQL
$ch = curl_init('https://api.github.com/graphql');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'User-Agent: embedded.io',
        'Authorization: Bearer ' . $TOKEN_GITHUB
    ],
    CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
]);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL error: ' . $curlErr]);
    exit;
}

// decode to check for GraphQL errors
$decoded = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid JSON from GitHub', 'raw' => $response]);
    exit;
}

if (isset($decoded['errors'])) {
    http_response_code(502);
    echo json_encode(['error' => 'GitHub API returned errors', 'details' => $decoded['errors']]);
    exit;
}

// store raw JSON response to cache file (silently ignore write errors)
@file_put_contents($cache_file, $response);

// set browser cache header (optional)
header('Cache-Control: public, max-age=' . $CACHE_TTL);

// output GitHub response (raw JSON)
echo $response;
