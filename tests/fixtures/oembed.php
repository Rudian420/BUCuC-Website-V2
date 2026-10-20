<?php
// Fake YouTube oEmbed for local tests.
parse_str(parse_url($_GET['url'] ?? '', PHP_URL_QUERY) ?: '', $q);
$id = $q['v'] ?? '';
header('Content-Type: application/json');
if ($id === 'noEmbed0001') { http_response_code(401); echo 'Unauthorized'; exit; }
if ($id === 'deleted0001') { http_response_code(404); echo 'Not Found'; exit; }
$tall = str_starts_with($id, 'tall');
echo json_encode(['title' => "Test video $id <b>&\"quote\"", 'author_name' => 'BUCuC', 'type' => 'video',
  'width' => $tall ? 113 : 200, 'height' => $tall ? 200 : 113,
  'thumbnail_url' => "https://i.ytimg.com/vi/$id/hqdefault.jpg", 'thumbnail_width' => 480, 'thumbnail_height' => 360]);
