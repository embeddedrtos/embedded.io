<?php
header("Content-Type: application/json");

// GitHub repository information
$GITHUB_OWNER = "embeddedrtos";
$GITHUB_REPO  = "embedded.io";

// Load GitHub token from environment variable (set in server configuration)
$TOKEN_GITHUB = getenv("GITHUB_EMBEDDEDIO_TOKEN");

// GraphQL query to fetch recent discussions
$query = <<<GRAPHQL
{
  repository(owner: "$GITHUB_OWNER", name: "$GITHUB_REPO") {
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

// Initialize cURL request
$ch = curl_init("https://api.github.com/graphql");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "User-Agent: WordPress",
    "Authorization: Bearer $TOKEN_GITHUB"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["query" => $query]));

// Execute request and return response
$response = curl_exec($ch);
curl_close($ch);

echo $response;
