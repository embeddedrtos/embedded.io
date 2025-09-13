<?php
// get-discussions.php
header('Content-Type: application/json; charset=utf-8');

// --- CONFIG ---
$GITHUB_OWNER = "embeddedrtos";
$GITHUB_REPO  = "embedded.io";

// lấy token từ env
$TOKEN_GITHUB = getenv('GITHUB_EMBEDDEDIO_TOKEN');
if (!$TOKEN_GITHUB) {
    http_response_code(500);
    echo json_encode(["error" => "Server configuration error: GITHUB_EMBEDDEDIO_TOKEN not set."]);
    exit;
}

// --- CACHE ---
$CACHE_TTL  = 300; // 5 phút
$cache_file = sys_get_temp_dir() . "/embeddedio_discussions_cache.json";

// dùng cache nếu hợp lệ
if (file_exists($cache_file) && (time() - filemtime($cache_file) < $CACHE_TTL)) {
    echo file_get_contents($cache_file);
    exit;
}

// --- GraphQL query ---
$query = <<<GRAPHQL
{
  repository(owner: "$GITHUB_OWNER", name: "$GITHUB_REPO") {
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
        comments(first: 2) {
          totalCount
          nodes {
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

// --- cURL request ---
$ch = curl_init("https://api.github.com/graphql");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "User-Agent: embedded.io",
        "Authorization: Bearer $TOKEN_GITHUB"
    ],
    CURLOPT_POSTFIELDS => json_encode(["query" => $query]),
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

// --- Error handling ---
if ($response === false || $curlErr) {
    http_response_code(502);
    echo json_encode(["error" => "cURL error: " . $curlErr]);
    exit;
}

$decoded = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(["error" => "Invalid JSON from GitHub", "raw" => $response]);
    exit;
}

if (isset($decoded["errors"])) {
    http_response_code(502);
    echo json_encode(["error" => "GitHub API returned errors", "details" => $decoded["errors"]]);
    exit;
}

// --- Cache kết quả ---
@file_put_contents($cache_file, $response);

// cho phép trình duyệt cache
header("Cache-Control: public, max-age=$CACHE_TTL");

// --- Xuất JSON ra frontend ---
echo $response;
