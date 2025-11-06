<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/stripe/stripe-php/init.php';

use Discord\Discord;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;

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
mysqli_report(MYSQLI_REPORT_OFF); // Disable default MySQL warnings

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

// ✅ Safe Query Function (Auto Retry)
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
        $db = connectDB(); // reconnect
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
// ✅ DISCORD BOT INIT
// ===================
$discord = new Discord([
    'token' => $DISCORD_TOKEN,
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
]);

$discord->on('ready', function ($discord) use ($STRIPE_SECRET_KEY) {
    echo "✅ Bot is ready!\n";

    static $listenerAdded = false;
    if ($listenerAdded) {
        echo "⚠️ Listener already active, skipping duplicate registration.\n";
        return;
    }
    $listenerAdded = true;

    // 🔁 DB Keep-alive every 60 sec
    $discord->getLoop()->addPeriodicTimer(60, function () {
        $db = getDB();
        if ($db) {
            $db->query("SELECT 1");
            echo "🟢 DB keep-alive ping\n";
        }
    });

    // ✅ Handle messages
    $discord->on(Event::MESSAGE_CREATE, function ($message, $discord) use ($STRIPE_SECRET_KEY) {
        if ($message->author->bot) return;

        $content = trim(strtolower($message->content));
        $db = getDB();

        if (!$db) {
            $message->channel->sendMessage("❌ Database not connected. Please wait a few seconds...");
            return;
        }

        // 👋 Greetings
        $greetings = ['hi', 'hii', 'hello', 'helo'];
        if (in_array($content, $greetings)) {
            $message->channel->sendMessage('👋 How are you? Type `!ebooks` to see available eBooks!');
            return;
        }

        // 📚 List ebooks
        if ($content === '!ebooks') {
            $res = safeQuery("SELECT * FROM products");
            if (!$res || $res->num_rows === 0) {
                $message->channel->sendMessage("📚 No ebooks found yet.");
                return;
            }

            $msg = "📚 Available Ebooks:\n";
            while ($book = $res->fetch_assoc()) {
                $msg .= "{$book['id']}. {$book['title']} → `!buy {$book['title']} - {$book['price']}$`\n";
            }
            $message->channel->sendMessage($msg);
            return;
        }

        // 💳 Buy ebook
        if (str_starts_with($content, '!buy')) {
            $parts = explode(" ", $content);
            $bookName = strtolower($parts[1] ?? '');
            $discordId = $message->author->id;

            if (!$bookName) {
                $message->channel->sendMessage("❌ Please specify the book name. Example: `!buy bookname`");
                return;
            }

            $stmt = safeQuery("SELECT * FROM products WHERE LOWER(title)=?", [$bookName], "s");
            $product = $stmt ? $stmt->fetch_assoc() : null;

            if (!$product) {
                $message->channel->sendMessage("❌ Invalid ebook option.");
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
                    "success_url" => "https://ebook-bot-clean.onrender.com/ebook/success.php?session_id={CHECKOUT_SESSION_ID}",
                    "cancel_url"  => "https://ebook-bot-clean.onrender.com/ebook/cancel.php",

                ]);

                safeQuery(
                    "INSERT INTO orders (discord_id, product_id, status, stripe_session_id)
                     VALUES (?, ?, 'pending', ?)",
                    [$discordId, $product['id'], $session->id],
                    "sis"
                );

                $message->channel->sendMessage(
                    "💳 Pay here for **{$product['title']}**: {$session->url}\nAfter payment, type `!paid {$session->id}`"
                );
            } catch (Exception $e) {
                $message->channel->sendMessage("❌ Stripe error: " . $e->getMessage());
            }
            return;
        }

        // ✅ Verify payment
        if (str_starts_with($content, '!paid')) {
            $parts = explode(" ", $content);
            $sessionId = $parts[1] ?? '';

            if (!$sessionId) {
                $message->channel->sendMessage("❌ Provide session ID. Example: `!paid cs_test_12345`");
                return;
            }

            try {
                \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY);
                $session = \Stripe\Checkout\Session::retrieve($sessionId);
            } catch (Exception $e) {
                $message->channel->sendMessage("❌ Stripe error: " . $e->getMessage());
                return;
            }

            $stmt = safeQuery(
                "SELECT o.status, p.title 
                 FROM orders o 
                 JOIN products p ON o.product_id=p.id 
                 WHERE o.stripe_session_id=?",
                [$sessionId],
                "s"
            );

            $order = $stmt ? $stmt->fetch_assoc() : null;

            if (!$order) {
                $message->channel->sendMessage("❌ No order found.");
                return;
            }

            $fileUrl = "http://localhost/ebook/files/" . strtolower($order['title']) . ".pdf";

            if ($order['status'] === 'paid') {
                $message->channel->sendMessage("✅ Already paid! Here’s your **{$order['title']}** ebook: {$fileUrl}");
                return;
            }

            if ($session->payment_status === 'paid') {
                safeQuery("UPDATE orders SET status='paid' WHERE stripe_session_id=?", [$sessionId], "s");
                $message->channel->sendMessage("✅ Payment verified! Download **{$order['title']}** here: {$fileUrl}");
            } else {
                $message->channel->sendMessage("❌ Payment not completed yet.");
            }
        }

        // 📦 Show purchased ebooks
        if ($content === '!orders') {
            $discordId = $message->author->id;
            $orders = safeQuery(
                "SELECT p.title 
                 FROM orders o 
                 JOIN products p ON o.product_id=p.id 
                 WHERE o.discord_id=? AND o.status='paid'",
                [$discordId],
                "s"
            );

            if (!$orders || $orders->num_rows === 0) {
                $message->channel->sendMessage("📦 You have not purchased any ebooks yet.");
                return;
            }

            $msg = "📦 Your Purchased Ebooks:\n";
            while ($o = $orders->fetch_assoc()) {
                $fileUrl = "http://localhost/ebook/files/" . strtolower($o['title']) . ".pdf";
                $msg .= "- {$o['title']} → {$fileUrl}\n";
            }
            $message->channel->sendMessage($msg);
        }
    });
});

$discord->run();
