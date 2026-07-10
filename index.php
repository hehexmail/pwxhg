<?php
// ─── Config file path (same directory as this script) ───────────────────────
define('CONFIG_FILE', __DIR__ . '/pw_config.json');

// ─── CORS headers ────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only']); exit;
}

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// ─── UUID helper ─────────────────────────────────────────────────────────────
function makeUUID(): string {
    return sprintf('%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF),
        random_int(0, 0x0FFF), random_int(0x8000, 0xBFFF),
        random_int(0, 0xFFFFFFFFFFFF));
}

// ─── Config load / save ──────────────────────────────────────────────────────

/**
 * Load config from pw_config.json.
 * Expected structure:
 * {
 *   "access_token":  "...",
 *   "refresh_token": "...",
 *   "client_secret": "...",
 *   "expires_in":    1781662175866   ← millisecond timestamp from PW API
 * }
 */
function loadConfig(): array {
    if (!file_exists(CONFIG_FILE)) return [];
    $raw = file_get_contents(CONFIG_FILE);
    return json_decode($raw, true) ?? [];
}

function saveConfig(array $cfg): void {
    file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ─── Token refresh ───────────────────────────────────────────────────────────

/**
 * Call PW refresh-token endpoint, update config file, return new access_token.
 * Returns null on failure.
 */
function refreshAccessToken(array &$cfg): ?string {
    $refreshToken  = $cfg['refresh_token']  ?? '';
    $clientSecret  = $cfg['client_secret']  ?? '';
    if (!$refreshToken || !$clientSecret) return null;

    $body = json_encode([
        'refresh_token' => $refreshToken,
        'client_secret' => $clientSecret,
        'client_id'     => 'system-admin',
    ]);

    $headers = [
        'authority: api.penpencil.co',
        'accept: */*',
        'accept-language: en-US,en;q=0.9',
        'client-id: 5eb393ee95fab7468a79d189',
        'client-type: WEB',
        'content-type: application/json',
        'origin: https://www.pw.live',
        'randomid: ' . makeUUID(),
        'referer: https://www.pw.live/',
        'sec-ch-ua: "Chromium";v="137", "Not/A)Brand";v="24"',
        'sec-ch-ua-mobile: ?1',
        'sec-ch-ua-platform: "Android"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: cross-site',
        'user-agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
    ];

    $ch = curl_init('https://api.penpencil.co/v3/oauth/refresh-token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $data = json_decode($response, true);
    if (empty($data['success']) || empty($data['data']['access_token'])) return null;

    // Persist new tokens
    $cfg['access_token']  = $data['data']['access_token'];
    $cfg['refresh_token'] = $data['data']['refresh_token'] ?? $cfg['refresh_token'];
    $cfg['expires_in']    = $data['data']['expires_in']    ?? 0;
    saveConfig($cfg);

    return $cfg['access_token'];
}

// ─── Get a valid access token (auto-refresh if expired / missing) ─────────────

/**
 * expires_in from PW API is a millisecond epoch timestamp.
 * We treat the token as expired 60 seconds before the actual expiry
 * to avoid using a token that expires mid-request.
 */
function getValidToken(): string {
    $cfg = loadConfig();

    $accessToken = $cfg['access_token'] ?? '';
    $expiresIn   = (int)($cfg['expires_in'] ?? 0);

    // Check if token is missing or about to expire (within 60 s)
    $nowMs         = (int)(microtime(true) * 1000);
    $bufferMs      = 60 * 1000; // 60 seconds in ms
    $isExpired     = ($expiresIn === 0) || ($nowMs >= ($expiresIn - $bufferMs));

    if (!$accessToken || $isExpired) {
        $newToken = refreshAccessToken($cfg);
        if ($newToken) return $newToken;
        // If refresh failed, fall back to whatever we have (may be empty)
        return $accessToken;
    }

    return $accessToken;
}

// ─── PW request helpers ───────────────────────────────────────────────────────

function pwHeaders(string $host = 'api.penpencil.co'): array {
    $token = getValidToken();
    return [
        "Host: $host",
        "Accept: */*",
        "Accept-Language: en-IN,en-GB;q=0.9,en-US;q=0.8,en;q=0.7",
        "Authorization: Bearer $token",
        "client-id: 5eb393ee95fab7468a79d189",
        "client-type: WEB",
        "client-version: 200",
        "Content-Type: application/json",
        "Origin: https://www.pw.live",
        "Referer: https://www.pw.live/",
        "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36",
        "randomid: " . makeUUID(),
        'sec-ch-ua: "Chromium";v="137", "Not/A)Brand";v="24"',
        "sec-ch-ua-mobile: ?0",
        'sec-ch-ua-platform: "Linux"',
        "sec-fetch-dest: empty",
        "sec-fetch-mode: cors",
        "sec-fetch-site: cross-site",
        "x-sdk-version: 0.0.20",
    ];
}

function pwReq(string $url, string $method = 'GET', ?array $body = null, string $host = 'api.penpencil.co'): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => pwHeaders($host),
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $body ? json_encode($body) : '{}';
    }
    curl_setopt_array($ch, $opts);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['ok' => false, 'code' => 0, 'data' => ['message' => $curlError]];
    return ['ok' => true, 'code' => $httpCode, 'data' => json_decode($response, true) ?? []];
}

function errorResp(?string $msg = null): void {
    echo json_encode(['success' => false, 'message' => $msg ?? 'Something went wrong.']); exit;
}

// ─── Routes ───────────────────────────────────────────────────────────────────

switch ($action) {

    // ── Save / update credentials ──────────────────────────────────────────────
    // POST ?action=set_credentials
    // Body: { "access_token": "...", "refresh_token": "...", "client_secret": "...", "expires_in": 1781662175866 }
    case 'set_credentials': {
        $required = ['refresh_token', 'client_secret'];
        foreach ($required as $key) {
            if (empty($input[$key])) errorResp("$key is required");
        }
        $cfg = loadConfig(); // preserve any existing keys
        $cfg['access_token']  = $input['access_token']  ?? ($cfg['access_token'] ?? '');
        $cfg['refresh_token'] = $input['refresh_token'];
        $cfg['client_secret'] = $input['client_secret'];
        $cfg['expires_in']    = (int)($input['expires_in'] ?? 0);
        saveConfig($cfg);
        echo json_encode(['success' => true, 'message' => 'Credentials saved.']); break;
    }

    // ── Force refresh token now ────────────────────────────────────────────────
    case 'force_refresh': {
        $cfg = loadConfig();
        $token = refreshAccessToken($cfg);
        if (!$token) errorResp('Token refresh failed. Check refresh_token and client_secret in config.');
        echo json_encode(['success' => true, 'message' => 'Token refreshed.', 'expires_in' => $cfg['expires_in']]); break;
    }

    // ── Original routes (unchanged logic) ─────────────────────────────────────

    case 'get_contents': {
        $batch_id       = $input['batch_id']       ?? null;
        $subject_id     = $input['subject_id']     ?? null;
        $content_type   = $input['content_type']   ?? 'NOTES';
        $tag_id         = $input['tag_id']         ?? '';
        $skip           = (int)($input['skip']     ?? 0);
        $limit          = (int)($input['limit']    ?? 20);
        $content_filter = $input['content_filter'] ?? 'ALL';
        if (!$batch_id || !$subject_id) errorResp('batch_id and subject_id required');
        $url = "https://api.penpencil.co/batch-service/v3/batch-subject-schedules/{$batch_id}/subject/{$subject_id}/contents?skip={$skip}&limit={$limit}&contentType={$content_type}&contentFilter={$content_filter}";
        if ($tag_id) $url .= "&tagId=" . urlencode($tag_id);
        $resp = pwReq($url);
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'get_slides': {
        $batch_id    = $input['batch_id']    ?? null;
        $subject_id  = $input['subject_id']  ?? null;
        $schedule_id = $input['schedule_id'] ?? null;
        if (!$batch_id || !$subject_id || !$schedule_id) errorResp('batch_id, subject_id, schedule_id required');
        $resp = pwReq("https://api.penpencil.co/v1/batches/{$batch_id}/subject/{$subject_id}/schedule/{$schedule_id}/slides");
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'get_schedule_details': {
        $batch_id    = $input['batch_id']    ?? null;
        $subject_id  = $input['subject_id']  ?? null;
        $schedule_id = $input['schedule_id'] ?? null;
        if (!$batch_id || !$subject_id || !$schedule_id) errorResp('batch_id, subject_id, schedule_id required');
        $resp = pwReq("https://api.penpencil.co/v1/batches/{$batch_id}/subject/{$subject_id}/schedule/{$schedule_id}/schedule-details");
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'today_schedule': {
        $batch_id = $input['batch_id'] ?? null;
        if (!$batch_id) errorResp('batch_id required');
        $resp = pwReq("https://api.penpencil.co/v1/batches/{$batch_id}/todays-schedule?batchId={$batch_id}&isNewStudyMaterialFlow=true");
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']['data'] ?? null]); break;
    }

    case 'get_dpp': {
        $batch_id   = $input['batch_id']   ?? null;
        $subject_id = $input['subject_id'] ?? null;
        $chapter_id = $input['chapter_id'] ?? null;
        $page       = (int)($input['page'] ?? 1);
        $is_subj    = !empty($input['is_subjective']) ? 'true' : 'false';
        if (!$batch_id || !$subject_id || !$chapter_id) errorResp('batch_id, subject_id and chapter_id required');
        $resp = pwReq("https://api.penpencil.co/v3/test-service/tests/dpp?batchId={$batch_id}&batchSubjectId={$subject_id}&chapterId={$chapter_id}&isSubjective={$is_subj}&page={$page}");
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'get_test_instructions': {
        $test_id = $input['test_id'] ?? null;
        if (!$test_id) errorResp('test_id required');
        $resp = pwReq("https://api.penpencil.co/v3/test-service/tests/{$test_id}/instructions");
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'get_test_filters': {
        $batch_id = $input['batch_id'] ?? null;
        if (!$batch_id) errorResp('batch_id required');
        $resp = pwReq("https://api.penpencil.co/v3/test-service/tests/filters?batchId={$batch_id}");
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'get_tests': {
        $batch_id            = $input['batch_id']            ?? null;
        $category_section_id = $input['category_section_id'] ?? '';
        $is_subj             = !empty($input['is_subjective']) ? 'true' : 'false';
        if (!$batch_id) errorResp('batch_id required');
        $url = "https://api.penpencil.co/v3/test-service/tests?batchId={$batch_id}&isSubjective={$is_subj}";
        if ($category_section_id) $url .= "&categorySectionId=" . urlencode($category_section_id);
        $resp = pwReq($url);
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'get_topics': {
        $batch_id     = $input['batch_id']     ?? null;
        $subject_slug = $input['subject_slug'] ?? null;
        $page         = (int)($input['page']   ?? 1);
        if (!$batch_id || !$subject_slug) errorResp('batch_id and subject_slug required');
        $resp = pwReq("https://api.penpencil.co/v1/batches/{$batch_id}/subject/{$subject_slug}/topics?page={$page}");
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'get_community_channels': {
        $batch_id = $input['batch_id'] ?? null;
        if (!$batch_id) errorResp('batch_id required');
        $resp = pwReq("https://pw-api-gate.penpencil.co/v3/community/channels/batch/{$batch_id}", 'GET', null, 'pw-api-gate.penpencil.co');
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    case 'get_community_posts': {
        $channel_id = $input['channel_id'] ?? null;
        $page       = (int)($input['page'] ?? 1);
        if (!$channel_id) errorResp('channel_id required');
        $resp = pwReq("https://pw-api-gate.penpencil.co/v3/community/posts/v2?channelId={$channel_id}&page={$page}&timestamp=" . time(), 'GET', null, 'pw-api-gate.penpencil.co');
        if (!$resp['ok'] || $resp['code'] !== 200 || !($resp['data']['success'] ?? false)) errorResp($resp['data']['message'] ?? null);
        echo json_encode(['success' => true, 'data' => $resp['data']]); break;
    }

    default:
        echo json_encode([
            'success'   => false,
            'message'   => 'Unknown action',
            'available' => [
                'set_credentials','force_refresh',
                'get_contents','get_slides','get_schedule_details','today_schedule',
                'get_dpp','get_test_instructions','get_test_filters','get_tests',
                'get_topics','get_community_channels','get_community_posts',
            ],
        ]);
}
