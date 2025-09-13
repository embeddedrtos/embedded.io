<?php
header('Content-Type: application/json; charset=utf-8');

// --- CONFIG ---
$GITHUB_OWNER = "embeddedrtos";
$GITHUB_REPO  = "embedded.io";
$TOKEN_GITHUB = getenv('GITHUB_EMBEDDEDIO_TOKEN');

if (!$TOKEN_GITHUB) {
    http_response_code(500);
    echo json_encode(["error" => "Server configuration error: GITHUB_EMBEDDEDIO_TOKEN not set."]);
    exit;
}

// --- INPUT PARAMS ---
$type = $_GET['type'] ?? "graphql"; // default = graphql
$queryParam = $_GET['q'] ?? "";

// --- CACHE ---
$CACHE_TTL  = 300; // 5 phút
$cache_file = sys_get_temp_dir() . "/embeddedio_discussions_cache_" . $type . ".json";

if (file_exists($cache_file) && (time() - filemtime($cache_file) < $CACHE_TTL)) {
    echo file_get_contents($cache_file);
    exit;
}

// --- FUNCTION CALLER ---
function callGithub($url, $method = "GET", $body = null, $token = null) {
    $ch = curl_init($url);
    $headers = [
        "User-Agent: embedded.io",
        "Authorization: Bearer $token",
        "Accept: application/vnd.github+json"
    ];
    if ($method === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) {
            $headers[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        http_response_code(502);
        return json_encode(["error" => "cURL error: $err"]);
    }
    return $response;
}

// --- SWITCH BETWEEN MODES ---
if ($type === "rest") {
    // REST API: danh sách discussions cơ bản
    $url = "https://api.github.com/repos/$GITHUB_OWNER/$GITHUB_REPO/discussions?per_page=10";
    $response = callGithub($url, "GET", null, $TOKEN_GITHUB);

} elseif ($type === "search") {
    // SEARCH API: tìm kiếm discussions
    if (!$queryParam) {
        echo json_encode(["error" => "Missing search query ?q=keyword"]);
        exit;
    }
    $url = "https://api.github.com/search/issues?q=repo:$GITHUB_OWNER/$GITHUB_REPO+is:discussion+$queryParam";
    $response = callGithub($url, "GET", null, $TOKEN_GITHUB);

} else {
    // GRAPHQL API: lấy đầy đủ fields
    $gql = <<<GRAPHQL
    {
      repository(owner: "$GITHUB_OWNER", name: "$GITHUB_REPO") {
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
            answer { id body author { login url avatarUrl } }
            author { login avatarUrl url }
            category { id name description slug }
            labels(first: 10) { nodes { name color description } }
            reactions(first: 20) { totalCount nodes { content user { login avatarUrl url } } }
            comments(first: 20) {
              totalCount
              nodes {
                id
                body
                bodyHTML
                createdAt
                updatedAt
                author { login avatarUrl url }
                replies(first: 10) {
                  totalCount
                  nodes { bodyHTML createdAt author { login avatarUrl url } }
                }
                reactions(first: 10) { totalCount nodes { content user { login } } }
              }
            }
          }
        }
      }
    }
    GRAPHQL;

    $response = callGithub("https://api.github.com/graphql", "POST", json_encode(["query" => $gql]), $TOKEN_GITHUB);
}

// --- Cache & Output ---
if ($response) {
    @file_put_contents($cache_file, $response);
    header("Cache-Control: public, max-age=$CACHE_TTL");
    echo $response;
} else {
    http_response_code(502);
    echo json_encode(["error" => "Empty response from GitHub API"]);
}
