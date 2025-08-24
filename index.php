<?php
// Bot configuration
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'Place_Your_Token_Here');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Set proper permissions for files
function ensureFilePermissions() {
    $files = [USERS_FILE, ERROR_LOG];
    foreach ($files as $file) {
        if (!file_exists($file)) {
            file_put_contents($file, '');
        }
        chmod($file, 0664);
    }
}

// Initialize bot (set webhook for production)
function initializeBot() {
    try {
        // For web service, we'll use webhook instead of polling
        $webhookUrl = getenv('RENDER_EXTERNAL_URL') ?: (getenv('WEBHOOK_URL') ?: 'https://your-app-name.onrender.com');
        $result = file_get_contents(API_URL . 'setWebhook?url=' . urlencode($webhookUrl));
        logError("Webhook set: " . $result);
        return true;
    } catch (Exception $e) {
        logError("Initialization failed: " . $e->getMessage());
        return false;
    }
}

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
}

// Data management
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
        }
        return json_decode(file_get_contents(USERS_FILE), true) ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Message sending with inline keyboard
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $url = API_URL . 'sendMessage?' . http_build_query($params);
        file_get_contents($url);
        return true;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Process webhook updates
function processWebhookUpdate() {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    
    if (!$update) {
        logError("Invalid update received");
        return;
    }
    
    $users = loadUsers();
    
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $ref = explode(' ', $text)[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50; // Referral bonus
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }
            
            $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
        // Handle other text commands
        elseif ($text === '/balance') {
            $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        elseif ($text === '/earn') {
            $time_diff = time() - $users[$chat_id]['last_earn'];
            if ($time_diff < 60) {
                $remaining = 60 - $time_diff;
                $msg = "⏳ Please wait $remaining seconds before earning again!";
            } else {
                $earn = 10;
                $users[$chat_id]['balance'] += $earn;
                $users[$chat_id]['last_earn'] = time();
                $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
            }
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        elseif ($text === '/leaderboard') {
            $sorted = array_column($users, 'balance');
            arsort($sorted);
            $top = array_slice($sorted, 0, 5, true);
            $msg = "🏆 Top Earners\n";
            $i = 1;
            foreach ($top as $id => $bal) {
                $msg .= "$i. User $id: $bal points\n";
                $i++;
            }
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        elseif ($text === '/referrals') {
            $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: t.me/" . BOT_TOKEN . "?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        elseif ($text === '/withdraw') {
            $min = 100;
            if ($users[$chat_id]['balance'] < $min) {
                $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
            } else {
                $amount = $users[$chat_id]['balance'];
                $users[$chat_id]['balance'] = 0;
                $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
            }
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        elseif ($text === '/help') {
            $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below to navigate!";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $chat_id = $update['callback_query']['message']['chat']['id'];
        $data = $update['callback_query']['data'];
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                $sorted = array_column($users, 'balance');
                arsort($sorted);
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: t.me/" . BOT_TOKEN . "?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below to navigate!";
                break;
                
            default:
                $msg = "Unknown command. Please use the buttons below.";
                break;
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
        
        // Answer the callback query to remove loading state
        file_get_contents(API_URL . 'answerCallbackQuery?callback_query_id=' . $update['callback_query']['id']);
    }
    
    saveUsers($users);
}

// Handle webhook request
ensureFilePermissions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    processWebhookUpdate();
    http_response_code(200);
    echo "OK";
} else {
    // For health checks and initial setup
    if (isset($_GET['setup'])) {
        if (initializeBot()) {
            echo "Webhook setup completed successfully!";
        } else {
            echo "Webhook setup failed. Check error.log for details.";
        }
    } elseif (isset($_GET['delete_webhook'])) {
        // Option to delete webhook
        $result = file_get_contents(API_URL . 'setWebhook?url=');
        echo "Webhook deleted: " . $result;
    } else {
        echo "Telegram Bot is running!<br>";
        echo "Use ?setup to configure webhook<br>";
        echo "Use ?delete_webhook to remove webhook<br>";
        echo "Current time: " . date('Y-m-d H:i:s');
    }
}
?>