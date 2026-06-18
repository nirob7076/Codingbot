<?php
require_once 'config.php';
require_once 'firebase.php';

$update = json_decode(file_get_contents('php://input'), true);

function bot($method, $datas = []) {
    global $config;
    $url = "https://api.telegram.org/bot" . $config['bot_token'] . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// চ্যানেল জয়েন চেক করার ফাংশন
function checkJoin($user_id) {
    global $config;
    $res = bot('getChatMember', [
        'chat_id' => $config['main_channel_username'],
        'user_id' => $user_id
    ]);
    if (isset($res['result']['status']) && in_array($res['result']['status'], ['member', 'administrator', 'creator'])) {
        return true;
    }
    return false;
}

// মেইন মেনু কীবোর্ড
$main_menu = json_encode([
    'keyboard' => [
        [['text' => 'Refer🟢'], ['text' => 'Balance🔴']],
        [['text' => 'Task ✅'], ['text' => 'Withdraw💸']],
        [['text' => 'Ads💰']]
    ],
    'resize_keyboard' => true
]);

// মেসেজ রিসিভ করা
if (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $text = $update['message']['text'];
    $name = $update['message']['from']['first_name'];
    $username = isset($update['message']['from']['username']) ? '@'.$update['message']['from']['username'] : 'N/A';

    // ইউজারের ডাটাবেজ চেক ও তৈরি করা
    $user_data = fb_get("users/$chat_id");
    if (!$user_data) {
        // র‍্যান্ডম রেফার কোড তৈরি
        $random_code = substr(md5(uniqid()), 0, 8);
        $user_data = [
            'name' => $name,
            'username' => $username,
            'balance' => 0,
            'refer_code' => $random_code,
            'joined' => date("Y-m-d")
        ];
        fb_put("users/$chat_id", $user_data);
        fb_put("refer_codes/$random_code", $chat_id); // রেফার কোড ম্যাপ করা
    }

    if ($text == '/start') {
        if (!checkJoin($chat_id)) {
            // জয়েন না থাকলে ইনলাইন বাটন দিবে
            $join_keyboard = json_encode([
                'inline_keyboard' => [
                    [['text' => '🔔 Join Channel', 'url' => $config['main_channel_url']]],
                    [['text' => '✅ Check Join', 'callback_data' => 'check_join']]
                ]
            ]);
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "হাই $name! আমাদের বটে কাজ করতে হলে অবশ্যই আমাদের অফিশিয়াল চ্যানেলে জয়েন করতে হবে।\n\nনিচের বাটনে ক্লিক করে জয়েন করুন এবং 'Check Join' এ ক্লিক করুন।",
                'reply_markup' => $join_keyboard
            ]);
        } else {
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "স্বাগতম! আপনি সফলভাবে জয়েন করেছেন। নিচের মেনু থেকে কাজ শুরু করুন।",
                'reply_markup' => $main_menu
            ]);
        }
    }

    // রেফার বাটন
    elseif ($text == 'Refer🟢') {
        $ref_code = $user_data['refer_code'];
        $bot_username = bot('getMe')['result']['username'];
        $ref_link = "https://t.me/$bot_username?start=$ref_code";
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "👥 *রেফার সিস্টেম*\n\nপ্রতি রেফারে পাবেন: {$config['refer_bonus']} টাকা!\nআপনার রেফার লিংক:\n`$ref_link`",
            'parse_mode' => 'Markdown'
        ]);
    }

    // ব্যালেন্স বাটন
    elseif ($text == 'Balance🔴') {
        $bal = $user_data['balance'];
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "👤 *আপনার একাউন্ট তথ্য:*\n\n📛 নাম: $name\n🆔 ইউজার আইডি: `$chat_id`\n🔗 ইউজারনেম: $username\n💰 বর্তমান ব্যালেন্স: *$bal টাকা*",
            'parse_mode' => 'Markdown'
        ]);
    }

    // এডমিন প্যানেল
    elseif ($text == '/admin' && $chat_id == $config['admin_id']) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "👑 *এডমিন প্যানেল*\n\nএখানে আপনি টোটাল ইউজার, টাস্ক এবং সেটিং কন্ট্রোল করতে পারবেন। (এটি আপনি আরো কাস্টমাইজ করতে পারবেন ইনলাইন বাটনের মাধ্যমে)",
            'parse_mode' => 'Markdown'
        ]);
    }
}

// ইনলাইন বাটন ক্লিক (Callback Query) হ্যান্ডেল করা
if (isset($update['callback_query'])) {
    $call_id = $update['callback_query']['id'];
    $chat_id = $update['callback_query']['message']['chat']['id'];
    $message_id = $update['callback_query']['message']['message_id'];
    $data = $update['callback_query']['data'];

    if ($data == 'check_join') {
        if (checkJoin($chat_id)) {
            // জয়েন করেছে! মেসেজ অটো ডিলিট
            bot('deleteMessage', [
                'chat_id' => $chat_id,
                'message_id' => $message_id
            ]);
            // সাকসেস মেসেজ ও কীবোর্ড দেওয়া
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🎉 ধন্যবাদ! আপনি চ্যানেলে জয়েন করেছেন। এখন কাজ শুরু করতে পারেন।",
                'reply_markup' => $main_menu
            ]);
        } else {
            // জয়েন করেনি, এলার্ট দিবে (বারবার বলবে)
            bot('answerCallbackQuery', [
                'callback_query_id' => $call_id,
                'text' => '❌ আপনি এখনো চ্যানেলে জয়েন করেননি! আগে জয়েন করুন।',
                'show_alert' => true
            ]);
        }
    }
}
?>
