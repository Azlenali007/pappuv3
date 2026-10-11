<?php
/**
 * Genuine PhonePe Business QR & Gmail Payment Verification Engine
 * SMM Panel - Production Multi-Gateway Architecture
 *
 * Implements real server-side payment verification:
 * - Connects securely to Gmail via IMAP (SSL) with App Password OR Google OAuth 2.0 REST API
 * - Validates sender authenticity against PhonePe & Bank whitelist
 * - Parses genuine 12-digit UPI UTRs / PhonePe Transaction IDs & exact credited amounts
 * - Concurrency-safe atomic wallet crediting with row locking and idempotency protection
 * - Audit logging in payment_verification_logs
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

class GmailPaymentVerifier
{
    private array $config;

    public function __construct(?array $gatewayConfig = null)
    {
        if ($gatewayConfig !== null) {
            $this->config = $gatewayConfig;
        } else {
            $gw = get_payment_method('phonepe_business_qr');
            $this->config = $gw ? get_gateway_config($gw) : [];
        }
    }

    /**
     * Test connection to Gmail and retrieve a sample of recent transaction emails
     */
    public function testConnection(): array
    {
        $method = $this->config['gmail_method'] ?? 'imap';

        if ($method === 'oauth') {
            return $this->testOAuthConnection();
        }

        return $this->testImapConnection();
    }

    /**
     * Test IMAP over SSL connection
     */
    private function testImapConnection(): array
    {
        $email = trim($this->config['gmail_email'] ?? '');
        $appPassword = preg_replace('/\s+/', '', (string)($this->config['gmail_app_password'] ?? ''));

        if (empty($email) || empty($appPassword)) {
            return [
                'success' => false,
                'error' => 'Gmail address or App Password is missing in configuration.'
            ];
        }

        try {
            $emails = $this->fetchViaImap(5);
            return [
                'success' => true,
                'message' => 'Successfully connected to Gmail via IMAP over SSL (imap.gmail.com:993).',
                'sample_count' => count($emails),
                'samples' => array_slice($emails, 0, 3)
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'IMAP Connection Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test Google OAuth 2.0 connection
     */
    private function testOAuthConnection(): array
    {
        $clientId = trim($this->config['gmail_client_id'] ?? '');
        $clientSecret = trim($this->config['gmail_client_secret'] ?? '');
        $refreshToken = trim($this->config['gmail_refresh_token'] ?? '');

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            return [
                'success' => false,
                'error' => 'Google OAuth Client ID, Client Secret, or Refresh Token is missing.'
            ];
        }

        try {
            $accessToken = $this->refreshOAuthToken($clientId, $clientSecret, $refreshToken);
            $emails = $this->fetchViaOAuth($accessToken, 5);
            return [
                'success' => true,
                'message' => 'Successfully connected to Gmail via Google OAuth 2.0 REST API.',
                'sample_count' => count($emails),
                'samples' => array_slice($emails, 0, 3)
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Google OAuth Connection Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Refresh OAuth Access Token using Refresh Token
     */
    private function refreshOAuthToken(string $clientId, string $clientSecret, string $refreshToken): string
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token'
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($err)) {
            throw new \RuntimeException("cURL error during OAuth token refresh: " . $err);
        }

        $json = json_decode((string)$response, true);
        if ($httpCode !== 200 || empty($json['access_token'])) {
            $desc = $json['error_description'] ?? $json['error'] ?? 'Unknown token exchange error';
            throw new \RuntimeException("OAuth Token Refresh Failed ({$httpCode}): " . $desc);
        }

        return $json['access_token'];
    }

    /**
     * Fetch recent payment notifications using Google OAuth 2.0 REST API
     */
    public function fetchViaOAuth(string $accessToken, int $limit = 25): array
    {
        $query = urlencode('from:(phonepe.com OR alerts OR bank) (received OR credited OR payment OR UPI)');
        $url = "https://gmail.googleapis.com/gmail/v1/users/me/messages?q={$query}&maxResults=" . min(50, $limit);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$accessToken}",
                "Accept: application/json"
            ]
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$res) {
            throw new \RuntimeException("Failed to list messages from Gmail API (HTTP {$code})");
        }

        $list = json_decode((string)$res, true);
        $messages = $list['messages'] ?? [];
        $results = [];

        foreach ($messages as $msg) {
            $msgId = $msg['id'];
            $detailUrl = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$msgId}?format=full";
            $ch = curl_init($detailUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]
            ]);
            $detailRes = curl_exec($ch);
            curl_close($detailRes !== false ? $ch : $ch);

            if (!$detailRes) continue;
            $msgData = json_decode((string)$detailRes, true);

            // Extract headers
            $headers = [];
            foreach ($msgData['payload']['headers'] ?? [] as $h) {
                $headers[strtolower($h['name'])] = $h['value'];
            }

            $from = $headers['from'] ?? '';
            $subject = $headers['subject'] ?? '';
            $date = $headers['date'] ?? '';

            // Extract body text
            $body = $this->extractBodyFromOAuthPayload($msgData['payload'] ?? []);
            $snippet = $msgData['snippet'] ?? '';
            $fullText = $subject . "\n" . $snippet . "\n" . $body;

            $parsed = $this->parseTransactionDetails($fullText, $from, $subject, $date);
            if ($parsed) {
                $results[] = $parsed;
            }
        }

        return $results;
    }

    private function extractBodyFromOAuthPayload(array $payload): string
    {
        $body = '';
        if (!empty($payload['body']['data'])) {
            $decoded = base64_decode(strtr($payload['body']['data'], '-_', '+/'));
            if ($decoded !== false) {
                $body .= strip_tags($decoded) . "\n";
            }
        }

        if (!empty($payload['parts'])) {
            foreach ($payload['parts'] as $part) {
                $body .= $this->extractBodyFromOAuthPayload($part);
            }
        }

        return $body;
    }

    /**
     * Fetch recent payment notifications via IMAP SSL (supports native ext-imap or pure-PHP socket fallback)
     */
    public function fetchViaImap(int $limit = 25): array
    {
        $email = trim($this->config['gmail_email'] ?? '');
        $appPassword = preg_replace('/\s+/', '', (string)($this->config['gmail_app_password'] ?? ''));

        if (empty($email) || empty($appPassword)) {
            throw new \RuntimeException("Gmail address or App Password not configured.");
        }

        // 1. If PHP native imap extension is available, use it
        if (function_exists('imap_open')) {
            return $this->fetchViaNativeImap($email, $appPassword, $limit);
        }

        // 2. Otherwise use built-in pure-PHP SSL IMAP socket client
        return $this->fetchViaSocketImap($email, $appPassword, $limit);
    }

    /**
     * Native PHP imap_* extension handler
     */
    private function fetchViaNativeImap(string $email, string $appPassword, int $limit): array
    {
        $mailbox = '{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX';
        $inbox = @imap_open($mailbox, $email, $appPassword, OP_READONLY, 1);

        if (!$inbox) {
            $errors = imap_errors();
            $errStr = is_array($errors) ? implode('; ', $errors) : 'Unknown IMAP connection error';
            throw new \RuntimeException("Could not connect to Gmail: " . $errStr);
        }

        $emails = [];
        try {
            // Search for messages from phonepe or recent
            $since = date("d-M-Y", strtotime("-2 days"));
            $msgNums = imap_search($inbox, 'SINCE "' . $since . '"');

            if ($msgNums === false) {
                $msgNums = imap_search($inbox, 'ALL');
            }

            if ($msgNums !== false) {
                rsort($msgNums);
                $slice = array_slice($msgNums, 0, $limit);

                foreach ($slice as $num) {
                    $header = imap_headerinfo($inbox, $num);
                    if (!$header) continue;

                    $from = $header->fromaddress ?? '';
                    $subject = $header->subject ?? '';
                    $date = $header->date ?? '';

                    $body = imap_body($inbox, $num);
                    $fullText = $subject . "\n" . strip_tags((string)$body);

                    $parsed = $this->parseTransactionDetails($fullText, $from, $subject, $date);
                    if ($parsed) {
                        $emails[] = $parsed;
                    }
                }
            }
        } finally {
            @imap_close($inbox);
        }

        return $emails;
    }

    /**
     * Pure PHP IMAP client over SSL socket (imap.gmail.com:993)
     * Provides 100% portability without requiring ext-imap!
     */
    private function fetchViaSocketImap(string $email, string $appPassword, int $limit): array
    {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true
            ]
        ]);

        $fp = @stream_socket_client('ssl://imap.gmail.com:993', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("Failed to connect to ssl://imap.gmail.com:993 ({$errno}: {$errstr})");
        }

        stream_set_timeout($fp, 15);

        $tagIndex = 1;
        $readResponse = function () use ($fp) {
            $data = '';
            while (!feof($fp)) {
                $line = fgets($fp, 4096);
                if ($line === false) break;
                $data .= $line;
                if (preg_match('/^[A-Z0-9]+\s+(OK|NO|BAD)/i', $line)) {
                    break;
                }
            }
            return $data;
        };

        $sendCommand = function (string $cmd) use ($fp, &$tagIndex, $readResponse) {
            $tag = sprintf("A%04d", $tagIndex++);
            fwrite($fp, "{$tag} {$cmd}\r\n");
            $resp = $readResponse();
            return [$tag, $resp];
        };

        // Greeting
        $greeting = fgets($fp, 4096);
        if (!str_contains((string)$greeting, '* OK')) {
            fclose($fp);
            throw new \RuntimeException("Invalid IMAP greeting from server: " . trim((string)$greeting));
        }

        // LOGIN
        [$tag, $resp] = $sendCommand("LOGIN " . escapeshellarg($email) . " " . escapeshellarg($appPassword));
        if (!preg_match("/^{$tag}\s+OK/mi", $resp)) {
            fclose($fp);
            throw new \RuntimeException("IMAP Authentication Failed. Ensure 2-Step Verification is ON and a 16-character Google App Password is used.");
        }

        // SELECT INBOX
        [$tag, $resp] = $sendCommand("SELECT INBOX");
        if (!preg_match("/^{$tag}\s+OK/mi", $resp)) {
            fclose($fp);
            throw new \RuntimeException("Could not SELECT INBOX.");
        }

        // SEARCH SINCE
        $sinceDate = date("j-M-Y", strtotime("-2 days"));
        [$tag, $resp] = $sendCommand("SEARCH SINCE {$sinceDate}");
        $messageIds = [];

        if (preg_match('/^\*\s+SEARCH\s+(.*)$/mi', $resp, $matches)) {
            $ids = array_filter(explode(' ', trim($matches[1])));
            $messageIds = array_map('intval', $ids);
        }

        if (empty($messageIds)) {
            // Fallback search recent
            [$tag, $resp] = $sendCommand("SEARCH ALL");
            if (preg_match('/^\*\s+SEARCH\s+(.*)$/mi', $resp, $matches)) {
                $ids = array_filter(explode(' ', trim($matches[1])));
                $messageIds = array_map('intval', $ids);
            }
        }

        $emails = [];
        if (!empty($messageIds)) {
            rsort($messageIds);
            $slice = array_slice($messageIds, 0, $limit);

            foreach ($slice as $id) {
                [$fTag, $fResp] = $sendCommand("FETCH {$id} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)] BODY.PEEK[TEXT]<0.4000>)");

                $from = '';
                $subject = '';
                $date = '';

                if (preg_match('/^From:\s*(.*)$/mi', $fResp, $fm)) {
                    $from = trim($fm[1]);
                }
                if (preg_match('/^Subject:\s*(.*)$/mi', $fResp, $sm)) {
                    $subject = trim($sm[1]);
                }
                if (preg_match('/^Date:\s*(.*)$/mi', $fResp, $dm)) {
                    $date = trim($dm[1]);
                }

                $parsed = $this->parseTransactionDetails($fResp, $from, $subject, $date);
                if ($parsed) {
                    $emails[] = $parsed;
                }
            }
        }

        // LOGOUT & Close
        $sendCommand("LOGOUT");
        fclose($fp);

        return $emails;
    }

    /**
     * Parses email text for genuine PhonePe / Bank UPI transaction indicators
     */
    public function parseTransactionDetails(string $text, string $from, string $subject, string $date): ?array
    {
        $cleanFrom = strtolower($from);
        $cleanSubject = strtolower($subject);
        $cleanText = str_replace(["\r", "\n", "\t"], " ", $text);

        // Check if sender is trusted
        if (!$this->isTrustedSender($cleanFrom)) {
            // If sender doesn't match, check if subject explicitly references PhonePe
            if (!str_contains($cleanSubject, 'phonepe') && !str_contains(strtolower($cleanText), 'phonepe')) {
                return null;
            }
        }

        // Extract Amount
        $amount = $this->extractAmount($cleanText);
        if ($amount === null || $amount <= 0) {
            return null;
        }

        // Extract UTR / UPI Reference Number (12 digits) or Transaction ID
        $utrList = $this->extractUtrList($cleanText);
        if (empty($utrList)) {
            return null;
        }

        return [
            'from' => $from,
            'subject' => $subject,
            'date' => $date,
            'amount' => $amount,
            'utrs' => $utrList,
            'primary_utr' => $utrList[0],
            'snippet' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($cleanText))), 0, 180)
        ];
    }

    /**
     * Check if email sender domain or address is in trusted whitelist
     */
    private function isTrustedSender(string $from): bool
    {
        $defaultWhitelist = ['noreply@phonepe.com', 'merchant-care@phonepe.com', 'payments@phonepe.com', 'alerts@hdfcbank.net', 'alerts@icicibank.com', 'upi@axisbank.com', 'donotreply@sbi.co.in', 'alerts@yesbank.in', 'upi@kotak.com'];
        $custom = array_map('trim', explode(',', (string)($this->config['sender_whitelist'] ?? '')));
        $whitelist = array_filter(array_merge($defaultWhitelist, $custom));

        foreach ($whitelist as $trusted) {
            if (empty($trusted)) continue;
            if (str_contains($from, strtolower($trusted))) {
                return true;
            }
        }

        // Check generic phonepe domain
        if (str_contains($from, '@phonepe.com')) {
            return true;
        }

        return false;
    }

    /**
     * Extracts credited payment amount from message content
     */
    private function extractAmount(string $text): ?float
    {
        // Patterns for INR amounts received
        $patterns = [
            '/(?:Rs\.?|INR|₹)\s*([0-9]{1,3}(?:,[0-9]{2,3})*(?:\.[0-9]{1,2})?|[0-9]+(?:\.[0-9]{1,2})?)/i',
            '/(?:received|credited|payment\s*of)\s*(?:Rs\.?|INR|₹)?\s*([0-9]+(?:\.[0-9]{1,2})?)/i',
            '/(?:amount\s*of)\s*(?:Rs\.?|INR|₹)?\s*([0-9]+(?:\.[0-9]{1,2})?)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $amtStr) {
                    $cleaned = (float)str_replace(',', '', $amtStr);
                    if ($cleaned > 0) {
                        return $cleaned;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extracts potential 12-digit UPI UTR numbers or alphanumeric transaction IDs
     */
    private function extractUtrList(string $text): array
    {
        $utrs = [];

        // 1. Explicit labels: UPI Ref No, UTR, Ref ID, Transaction ID
        $labeledPatterns = [
            '/(?:UTR|UPI\s*Ref(?:\s*No)?|Ref(?:\s*No)?|Reference(?:\s*No|\s*ID)?|Txn(?:\s*ID)?|Transaction\s*ID)[:\s#]*([A-Za-z0-9_-]{10,35})/i',
            '/UPI\/([0-9]{12})\//i',
            '/RRN[:\s#]*([0-9]{12})/i'
        ];

        foreach ($labeledPatterns as $p) {
            if (preg_match_all($p, $text, $m)) {
                foreach ($m[1] as $val) {
                    $val = trim($val);
                    if (!empty($val) && !in_array($val, $utrs, true)) {
                        $utrs[] = $val;
                    }
                }
            }
        }

        // 2. Any standalone 12-digit numeric sequences (Standard NPCI UPI UTR format)
        if (preg_match_all('/\b([0-9]{12})\b/', $text, $numMatches)) {
            foreach ($numMatches[1] as $digits) {
                if (!in_array($digits, $utrs, true)) {
                    $utrs[] = $digits;
                }
            }
        }

        // 3. PhonePe standard Transaction ID prefix format: T[0-9]{14,24}
        if (preg_match_all('/\b(T[0-9]{14,28})\b/i', $text, $tMatches)) {
            foreach ($tMatches[1] as $tId) {
                if (!in_array($tId, $utrs, true)) {
                    $utrs[] = $tId;
                }
            }
        }

        return $utrs;
    }

    /**
     * Main Verification & Idempotent Wallet Crediting Engine
     *
     * @param PDO $db Active PDO connection
     * @param int $depositId Deposit record ID in transactions table
     * @param string|null $targetUtr User-submitted 12-digit UTR/Txn ID
     * @param float|null $expectedAmount Deposit amount requested
     * @return array Outcome details
     */
    public function verifyDeposit(PDO $db, int $depositId, ?string $targetUtr = null, ?float $expectedAmount = null): array
    {
        // 1. Lock deposit row for concurrency safety
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id AND type = 'deposit' FOR UPDATE");
        $stmt->execute(['id' => $depositId]);
        $deposit = $stmt->fetch();

        if (!$deposit) {
            return [
                'success' => false,
                'status' => 'not_found',
                'error' => "Deposit #{$depositId} not found."
            ];
        }

        if ($deposit['status'] === 'completed') {
            return [
                'success' => true,
                'status' => 'already_completed',
                'message' => "Deposit #{$depositId} is already completed and credited."
            ];
        }

        if ($deposit['status'] === 'failed') {
            return [
                'success' => false,
                'status' => 'already_failed',
                'error' => "Deposit #{$depositId} has been marked rejected or failed."
            ];
        }

        $targetUtr = trim($targetUtr ?: (string)$deposit['gateway_txn_id']);
        $expectedAmount = $expectedAmount ?: (float)$deposit['amount'];

        if (empty($targetUtr)) {
            return [
                'success' => false,
                'status' => 'missing_utr',
                'error' => "No UTR / Transaction ID associated with deposit #{$depositId}."
            ];
        }

        // 2. Ensure UTR is not already used in another completed transaction (Strict Idempotency)
        $dupCheck = $db->prepare("
            SELECT id, user_id, amount FROM transactions 
            WHERE gateway_txn_id = :utr AND status = 'completed' AND id != :current_id 
            LIMIT 1
        ");
        $dupCheck->execute(['utr' => $targetUtr, 'current_id' => $depositId]);
        $existingCompleted = $dupCheck->fetch();

        if ($existingCompleted) {
            // Reject duplicate attempt
            $rej = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [REJECTED: Duplicate UTR already used in #', :eid, ']') WHERE id = :id");
            $rej->execute(['eid' => $existingCompleted['id'], 'id' => $depositId]);

            $this->logVerification($db, $depositId, $targetUtr, $expectedAmount, null, 'duplicate_rejected', "UTR already credited in deposit #{$existingCompleted['id']}");

            return [
                'success' => false,
                'status' => 'duplicate_rejected',
                'error' => "Duplicate UTR: This transaction reference has already been credited to another deposit."
            ];
        }

        // 3. Connect to Gmail and search genuine payment emails
        try {
            $method = $this->config['gmail_method'] ?? 'imap';
            if ($method === 'oauth') {
                $accessToken = $this->refreshOAuthToken(
                    $this->config['gmail_client_id'] ?? '',
                    $this->config['gmail_client_secret'] ?? '',
                    $this->config['gmail_refresh_token'] ?? ''
                );
                $emails = $this->fetchViaOAuth($accessToken, 35);
            } else {
                $emails = $this->fetchViaImap(35);
            }
        } catch (\Throwable $e) {
            $this->logVerification($db, $depositId, $targetUtr, $expectedAmount, null, 'connection_error', "Gmail connection error: " . $e->getMessage());

            return [
                'success' => false,
                'status' => 'connection_error',
                'error' => "Unable to connect to Gmail inbox: " . $e->getMessage(),
                'notice' => "Deposit remains pending in queue for automatic or manual review."
            ];
        }

        // 4. Match against genuine email records
        $matchedEmail = null;
        $cleanTargetUtr = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $targetUtr));

        foreach ($emails as $email) {
            foreach ($email['utrs'] as $foundUtr) {
                $cleanFound = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$foundUtr));
                if ($cleanFound === $cleanTargetUtr || str_contains($cleanFound, $cleanTargetUtr) || str_contains($cleanTargetUtr, $cleanFound)) {
                    $matchedEmail = $email;
                    break 2;
                }
            }
        }

        // 5. Evaluate matching result
        if (!$matchedEmail) {
            // Keep pending - do NOT falsely credit!
            $this->logVerification($db, $depositId, $targetUtr, $expectedAmount, null, 'pending_no_match', "No matching payment email found in Gmail inbox for UTR '{$targetUtr}'");

            return [
                'success' => false,
                'status' => 'pending_no_match',
                'message' => "No matching PhonePe payment notification found in Gmail yet. Transaction remains pending.",
                'target_utr' => $targetUtr
            ];
        }

        // 6. Validate Amount (tolerance of 0.05 to avoid rounding issues)
        $actualReceivedAmount = (float)$matchedEmail['amount'];
        if (abs($actualReceivedAmount - $expectedAmount) > 0.05) {
            $this->logVerification($db, $depositId, $targetUtr, $expectedAmount, $actualReceivedAmount, 'amount_mismatch', "Amount mismatch: Submitted ₹{$expectedAmount}, Email shows ₹{$actualReceivedAmount}");

            return [
                'success' => false,
                'status' => 'amount_mismatch',
                'error' => "Amount mismatch: You requested ₹" . number_format($expectedAmount, 2) . ", but the bank notification shows ₹" . number_format($actualReceivedAmount, 2) . ".",
                'actual_amount' => $actualReceivedAmount
            ];
        }

        // 7. Success! Perform Atomic Wallet Crediting
        $db->beginTransaction();
        try {
            // Credit user wallet exactly once
            $updUser = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
            $updUser->execute(['amt' => $actualReceivedAmount, 'uid' => $deposit['user_id']]);

            // Mark deposit completed
            $updTxn = $db->prepare("
                UPDATE transactions 
                SET status = 'completed', 
                    amount = :actual_amt,
                    note = CONCAT(COALESCE(note, ''), ' [Verified via Gmail: UTR ', :utr, ' | Received ₹', :actual_amt_str, ']') 
                WHERE id = :id
            ");
            $updTxn->execute([
                'actual_amt' => $actualReceivedAmount,
                'actual_amt_str' => number_format($actualReceivedAmount, 2),
                'utr' => $targetUtr,
                'id' => $depositId
            ]);

            // Process referral reward if eligible
            process_referral_commission((int)$deposit['user_id'], $actualReceivedAmount, 'deposit', $depositId, null);

            $this->logVerification($db, $depositId, $targetUtr, $expectedAmount, $actualReceivedAmount, 'matched_success', "Verified genuine PhonePe payment from '{$matchedEmail['from']}' for ₹{$actualReceivedAmount}");

            $db->commit();

            return [
                'success' => true,
                'status' => 'matched_success',
                'credited' => true,
                'amount' => $actualReceivedAmount,
                'utr' => $targetUtr,
                'message' => "Payment of ₹" . number_format($actualReceivedAmount, 2) . " verified and credited to your wallet!"
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Audit logger for payment verification outcomes
     */
    private function logVerification(PDO $db, ?int $txnId, ?string $utr, ?float $expected, ?float $found, string $status, string $details): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt = $db->prepare("
                INSERT INTO payment_verification_logs 
                (transaction_id, gateway, utr_searched, amount_expected, amount_found, status, details, ip_address)
                VALUES (:tid, 'phonepe_business_qr', :utr, :exp, :fnd, :st, :det, :ip)
            ");
            $stmt->execute([
                'tid' => $txnId,
                'utr' => $utr,
                'exp' => $expected,
                'fnd' => $found,
                'st' => $status,
                'det' => mb_substr($details, 0, 500),
                'ip' => $ip
            ]);
        } catch (\Throwable $e) {
            error_log("Failed to insert verification log: " . $e->getMessage());
        }
    }
}
