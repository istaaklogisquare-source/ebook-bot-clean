<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/stripe/stripe-php/init.php';

use Discord\Discord;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use React\EventLoop\Factory;

// ===================
// ✅ ENV VARIABLES
// ===================
$DB_HOST = getenv('DB_HOST');
$DB_NAME = getenv('DB_NAME');
$DB_USER = getenv('DB_USER');
$DB_PASS = getenv('DB_PASS');
$DISCORD_TOKEN = getenv('DISCORD_TOKEN');
$STRIPE_SECRET_KEY = getenv('STRIPE_SECRET_KEY');

// ===================
// ✅ DATABASE CONNECT (Auto Reconnect System)
// ===================
mysqli_report(MYSQLI_REPORT_OFF);

function connectDB()
{
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;

    $conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($conn->connect_errno) {
        echo "❌ DB connection failed: " . $conn->connect_error . PHP_EOL;
        return null;
    }
    $conn->set_charset('utf8mb4');
    echo "✅ Database connected!\n";
    return $conn;
}

function getDB()
{
    static $db = null;
    if ($db === null) {
        $db = connectDB();
    } elseif (!$db->ping()) {
        echo "🔄 DB connection lost, reconnecting...\n";
        $db = connectDB();
    }
    return $db;
}

function safeQuery($sql, $params = [], $types = '')
{
    $db = getDB();
    if (!$db) {
        echo "❌ DB unavailable.\n";
        return false;
    }

    try {
        if (empty($params)) {
            return $db->query($sql);
        } else {
            $stmt = $db->prepare($sql);
            if (!$stmt) throw new Exception("Prepare failed: " . $db->error);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result();
        }
    } catch (Exception $e) {
        echo "⚠️ DB query failed: " . $e->getMessage() . "\n";
        $db = connectDB();
        if ($db) {
            echo "🔁 Retrying query...\n";
            if (empty($params)) {
                return $db->query($sql);
            } else {
                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                return $stmt->get_result();
            }
        }
        return false;
    }
}

// ===================
// ✅ DISCORD BOT INIT (with Auto Reconnect)
// ===================
$loop = Factory::create();

$discord = new Discord([
    'token' => $DISCORD_TOKEN,
    'loop' => $loop,
    'intents' => Intents::GUILDS | Intents::GUILD_MESSAGES | Intents::MESSAGE_CONTENT | Intents::GUILD_MEMBERS,
    'loadAllMembers' => false,
]);

$discord->on('error', function ($e) {
    echo "⚠️ Discord error: " . $e->getMessage() . PHP_EOL;
});

// Connection health monitoring
$discord->on('disconnected', function() {
    echo "❌ Discord disconnected, trying to reconnect...\n";
});
$discord->on('reconnecting', function() {
    echo "🔁 Reconnecting to Discord...\n";
});
$discord->on('reconnected', function() {
    echo "✅ Reconnected successfully!\n";
});

$listenerAdded = false;

$discord->on('ready', function ($discord) use ($STRIPE_SECRET_KEY, &$listenerAdded) {
    echo "✅ Bot is ready and connected to Discord Gateway!\n";

    // 🟢 Send startup message
    $firstGuild = $discord->guilds->first();
    if ($firstGuild) {
        foreach ($firstGuild->channels as $channel) {
            if ($channel->type === 0) {
                $channel->sendMessage("👋 Hi everyone! I’m **eBook Bot** 🤖\nType `!ebooks` to browse available books!");
                echo "📢 Sent startup message in #{$channel->name}\n";
                break;
            }
        }
    }

    // Notify owner
    $ownerId = '1400354937690656892';
    $discord->users->fetch($ownerId)->then(function ($user) {
        $user->sendMessage("✅ Hey! Your eBook bot is now online and ready! 🚀");
    });

    if ($listenerAdded) {
        echo "⚠️ Listener already added — skipping duplicate setup.\n";
        return;
    }
    $listenerAdded = true;

    // 🔄 Keep DB alive every 60s
    $discord->getLoop()->addPeriodicTimer(60, function () {
        $db = getDB();
        if ($db) {
            $db->query("SELECT 1");
            echo "🟢 DB keep-alive ping\n";
        }
    });

    // 🔄 Keep Discord alive every 5 min
    $discord->getLoop()->addPeriodicTimer(300, function() use ($discord) {
        echo "🔄 Discord keep-alive ping\n";
        $discord->api->get('/gateway')->then(
            fn() => print("✅ Gateway ping OK\n"),
            fn($e) => print("⚠️ Gateway ping failed: {$e->getMessage()}\n")
        );
    });

    // 👋 Welcome new members
    $discord->on(Event::GUILD_MEMBER_ADD, function ($member) {
        $channel = $member->guild->system_channel;
        if ($channel) {
            $channel->sendMessage("👋 Hey {$member->user->username}! Welcome to the server!\nType `!ebooks` to see available eBooks 📚");
        }
    });

    // 💬 Handle messages
    $discord->on(Event::MESSAGE_CREATE, function ($message) use ($STRIPE_SECRET_KEY) {
        if ($message->author->bot) return;

        $content = trim($message->content);
        $lower = strtolower($content);
        $db = getDB();

        if (!$db) {
            $message->channel->sendMessage("❌ Database not connected. Try again later...");
            return;
        }

        if (in_array($lower, ['hi', 'hii', 'hello', 'helo'])) {
            $message->channel->sendMessage("👋 Hi! Type `!ebooks` to see available eBooks!");
            return;
        }

        // List ebooks
        if ($lower === '!ebooks') {
            $res = safeQuery("SELECT * FROM products");
            if (!$res || $res->num_rows === 0) {
                $message->channel->sendMessage("📚 No ebooks found yet.");
                return;
            }

            $msg = "📚 **Available Ebooks:**\n";
            while ($b = $res->fetch_assoc()) {
                $msg .= "**{$b['id']}. {$b['title']}** → `!buy {$b['title']}` 💵 {$b['price']}$\n";
            }
            $message->channel->sendMessage($msg);
            return;
        }

        // Buy ebook
        if (str_starts_with($lower, '!buy')) {
            $parts = explode(" ", $content, 2);
            $book = strtolower(trim($parts[1] ?? ''));
            $id = $message->author->id;

            if (!$book) {
                $message->channel->sendMessage("❌ Please specify book name. Example: `!buy bookname`");
                return;
            }

            $stmt = safeQuery("SELECT * FROM products WHERE LOWER(title)=?", [$book], "s");
            $product = $stmt ? $stmt->fetch_assoc() : null;

            if (!$product) {
                $message->channel->sendMessage("❌ Invalid ebook name.");
                return;
            }

            try {
                \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY);
                $session = \Stripe\Checkout\Session::create([
                    "payment_method_types" => ["card"],
                    "line_items" => [[
                        "price_data" => [
                            "currency" => "usd",
                            "product_data" => ["name" => $product['title']],
                            "unit_amount" => $product['price'] * 100,
                        ],
                        "quantity" => 1
                    ]],
                    "mode" => "payment",
                    "success_url" => "http://localhost/ebook/success.php?session_id={CHECKOUT_SESSION_ID}",
                    "cancel_url"  => "http://localhost/ebook/cancel.php",
                ]);

                safeQuery("INSERT INTO orders (discord_id, product_id, status, stripe_session_id) VALUES (?, ?, 'pending', ?)", [$id, $product['id'], $session->id], "sis");

                $message->channel->sendMessage("💳 Click to pay for **{$product['title']}**: {$session->url}\nAfter payment, type `!paid {$session->id}`");
            } catch (Exception $e) {
                $message->channel->sendMessage("❌ Stripe error: " . $e->getMessage());
            }
            return;
        }

        // Verify payment
        if (str_starts_with($lower, '!paid')) {
            $parts = explode(" ", $content);
            $sid = trim($parts[1] ?? '');

            if (!$sid) {
                $message->channel->sendMessage("❌ Provide session ID. Example: `!paid cs_test_12345`");
                return;
            }

            try {
                \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY);
                $session = \Stripe\Checkout\Session::retrieve($sid);
            } catch (Exception $e) {
                $message->channel->sendMessage("❌ Stripe error: " . $e->getMessage());
                return;
            }

            $stmt = safeQuery("SELECT o.status, p.title FROM orders o JOIN products p ON o.product_id=p.id WHERE LOWER(o.stripe_session_id)=LOWER(?)", [$sid], "s");
            $order = $stmt ? $stmt->fetch_assoc() : null;

            if (!$order) {
                $message->channel->sendMessage("❌ No order found for this session ID.");
                return;
            }

            $fileUrl = "http://localhost/ebook/files/" . strtolower($order['title']) . ".pdf";

            if ($order['status'] === 'paid') {
                $message->channel->sendMessage("✅ Already paid! Here's your **{$order['title']}** ebook: {$fileUrl}");
                return;
            }

            if ($session->payment_status === 'paid') {
                safeQuery("UPDATE orders SET status='paid' WHERE LOWER(stripe_session_id)=LOWER(?)", [$sid], "s");
                $message->channel->sendMessage("✅ Thank you for your purchase! Download your **{$order['title']}** here: {$fileUrl}");
            } else {
                $message->channel->sendMessage("❌ Payment not completed yet.");
            }
        }

        // View orders
        if ($lower === '!orders') {
            $id = $message->author->id;
            $orders = safeQuery("SELECT p.title FROM orders o JOIN products p ON o.product_id=p.id WHERE o.discord_id=? AND o.status='paid'", [$id], "s");

            if (!$orders || $orders->num_rows === 0) {
                $message->channel->sendMessage("📦 You haven't purchased any ebooks yet.");
                return;
            }

            $msg = "📦 **Your Purchased Ebooks:**\n";
            while ($o = $orders->fetch_assoc()) {
                $file = "http://localhost/ebook/files/" . strtolower($o['title']) . ".pdf";
                $msg .= "- {$o['title']} → {$file}\n";
            }
            $message->channel->sendMessage($msg);
        }
    });
});

$discord->run();
