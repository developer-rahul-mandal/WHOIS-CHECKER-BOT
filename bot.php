<?php
// Load environment variables
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
// Telegram Bot Config
$BOT_TOKEN =  $_ENV['BOT_TOKEN'];// Replace with your bot token
$API_URL = "https://api.telegram.org/bot$BOT_TOKEN/";

// Telegram Update
$update = json_decode(file_get_contents("php://input"), true);
$message = $update['message']['text'] ?? '';
$chat_id = $update['message']['chat']['id'] ?? '';

if (!$chat_id) exit;

// Handle /start and /help
if (in_array($message, ['/start', '/help'])) {
    $msg = "👋 Welcome to the WHOIS Bot!\n\n";
    $msg .= "🔍 Send any domain name (e.g., google.com) to get WHOIS details.\n\n";
    $msg .= "Example: `google.com`\n\n";
    $msg .= "📎 You will receive key info + full JSON data.\n\n";
    $msg .= "Developed by @DevZoop";
    sendMessage($chat_id, $msg, true);
    exit;
}

// Use provided domain or default to google.com
$domain = strtolower(trim($message));
if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
    $domain = "google.com"; // fallback if not valid
}

// Fetch WHOIS from RDAP
$rdap_url = "https://rdap.org/domain/" . urlencode($domain);
$response = @file_get_contents($rdap_url);

if (!$response) {
    sendMessage($chat_id, "❌ Could not fetch WHOIS info for $domain");
    exit;
}

$data = json_decode($response, true);

// Extract WHOIS Data
$domainName = $data['ldhName'] ?? 'N/A';
$status = implode(', ', $data['status'] ?? []);
$created = findEvent($data['events'], 'registration');
$updated = findEvent($data['events'], 'last changed');
$expires = findEvent($data['events'], 'expiration');

// Registrar
$registrarEntity = findEntityByRole($data['entities'], 'registrar');
$registrarName = getVCardValue($registrarEntity, 'fn');
$registrarAddress = getVCardValue($registrarEntity, 'adr');

// Registrant
$registrantEntity = findEntityByRole($data['entities'], 'registrant');
$registrantOrg = getVCardValue($registrantEntity, 'org');
$registrantAddress = getVCardValue($registrantEntity, 'adr');

// Nameservers & IPs
$nameservers = $data['nameservers'] ?? [];
$nsLines = [];
$ipv4List = [];
$ipv6List = [];

foreach ($nameservers as $ns) {
    $nsName = $ns['ldhName'] ?? 'Unknown';
    $nsLines[] = "• <code>$nsName</code>";
    foreach ($ns['ipAddresses']['v4'] ?? [] as $ip) $ipv4List[] = $ip;
    foreach ($ns['ipAddresses']['v6'] ?? [] as $ip) $ipv6List[] = $ip;
}

// Final Message
$reply = "🔎 <b>WHOIS for $domainName</b>\n\n";
$reply .= "🧾 Status: <b>$status</b>\n";
$reply .= "📅 Created: <b>$created</b>\n";
$reply .= "♻️ Updated: <b>$updated</b>\n";
$reply .= "⏳ Expires: <b>$expires</b>\n\n";
$reply .= "🏢 Registrar: <b>$registrarName</b>\n📍 <code>$registrarAddress</code>\n\n";
$reply .= "👤 Registrant: <b>$registrantOrg</b>\n📍 <code>$registrantAddress</code>\n\n";

if (!empty($ipv4List)) {
    $reply .= "🌐 IP Addresses\n🔹 IPv4:\n<code>" . implode("\n", $ipv4List) . "</code>\n\n";
}
if (!empty($ipv6List)) {
    $reply .= "🔹 IPv6:\n<code>" . implode("\n", $ipv6List) . "</code>\n\n";
}

if (!empty($nsLines)) {
    $reply .= "📡 Name Servers:\n" . implode("\n", $nsLines) . "\n\n";
}

$reply .= "🔧 Developed by @DevZoop";

sendMessage($chat_id, $reply, true);

// Send full JSON file
$tmpFile = tempnam(sys_get_temp_dir(), 'whois_');
file_put_contents($tmpFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
rename($tmpFile, $tmpFile .= '.json');
sendDocument($chat_id, $tmpFile, "📄 Full WHOIS JSON for $domainName");
unlink($tmpFile);


// ──────── Helper Functions ────────

function sendMessage($chat_id, $text, $isHtml = false) {
    global $API_URL;
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $isHtml ? 'HTML' : null,
        'disable_web_page_preview' => true
    ];
    file_get_contents($API_URL . "sendMessage?" . http_build_query($params));
}

function sendDocument($chat_id, $filePath, $caption = '') {
    global $API_URL;
    $post = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'document' => new CURLFile($filePath)
    ];
    $ch = curl_init($API_URL . "sendDocument");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function findEvent($events, $type) {
    foreach ($events as $event) {
        if (strtolower($event['eventAction']) === strtolower($type)) {
            return $event['eventDate'] ?? 'N/A';
        }
    }
    return 'N/A';
}

function findEntityByRole($entities, $role) {
    foreach ($entities as $entity) {
        if (in_array($role, $entity['roles'] ?? [])) {
            return $entity;
        }
    }
    return null;
}

function getVCardValue($entity, $key) {
    if (!isset($entity['vcardArray'][1])) return 'N/A';
    foreach ($entity['vcardArray'][1] as $item) {
        if ($item[0] === $key) {
            return ($key === 'adr') ? implode(", ", array_filter($item[3])) : $item[3];
        }
    }
    return 'N/A';
}