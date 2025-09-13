<?php
// get-discussions.php
header('Content-Type: application/json; charset=utf-8');

// --- CONFIG ---
$GITHUB_OWNER = "embeddedrtos";
$GITHUB_REPO  = "embedded.io";

// Read token from environment or fallback constant
$TOKEN_GITHUB = getenv('GITHUB_EMBEDDEDIO_TOKEN');
if (!$TOKEN_GITHUB && defined('GITHUB_EMBEDDEDIO_TOKEN')) {
    $TOKEN_GITHUB = GITHUB_EMBEDDEDIO_TOKEN;
}

// Cache settings (seconds)
$CACHE_TTL   = 300; // 5 minutes
$cache_file  = sys_get_temp_dir() . '/embeddedio_discussions_cache.json';

// Serve cached response if still valid
if (file_exists($cache_file) && (time() - filemtime($cache_file) < $CACHE_TTL)) {
    echo file_get_contents($cache_file);
    exit;
}

// Require token (if private / rate-limit sensitive)
// Nếu muốn public, có thể bỏ check này
if (empty($TOKEN_GITHUB)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error: GITHUB_EMBEDDEDIO_TOKEN not set.']);
    exit;
}

// --- GraphQL query: lấy 5 discussions + comments ---
$query = <<<'GRAPHQL'
{
  repository(owner: "%OWNER%", name: "%REPO%") {
    discussions(first: 5, orderBy: {field: CREATED_AT, direction: DESC}) {
      nodes {
        id
        title
        url
        createdAt
        author {
          login
          avatarUrl
          url
        }
        comments {
          totalCount
          nodes(first: 2) {
            bodyText
            createdAt
            author {
              login
              avatarUrl
              url
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

$query = str_replace(['%OWNER%', '%REPO%'], [$GITHUB_OWNER, $GITHUB_REPO], $query);

// --- cURL call to GitHub GraphQL ---
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
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- Error handling ---
if ($response === false || $curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL error: ' . $curlErr]);
    exit;
}

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

// --- Cache raw JSON response (ignore write errors) ---
@file_put_contents($cache_file, $response);

// Optional: set browser cache
header('Cache-Control: public, max-age=' . $CACHE_TTL);

// --- Output GitHub response ---
echo $response;
