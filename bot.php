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
// ✅ DATABASE CONNECT (MySQLi)
// ===================
function connectDB() {
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

function getDB() {
    static $db = null;

    if ($db === null) {
        $db = connectDB();
    } elseif (!$db->ping()) {
        echo "🔄 DB connection lost, reconnecting...\n";
        $db = connectDB();
    }

    return $db;
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

    // 🔁 Keep DB alive every 2 minutes
    $discord->getLoop()->addPeriodicTimer(120, function () {
        $db = getDB();
        if ($db) {
            $db->query("SELECT 1");
            echo "🟢 DB keep-alive ping\n";
        }
    });

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
            $res = $db->query("SELECT * FROM products");
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

            $stmt = $db->prepare("SELECT * FROM products WHERE LOWER(title)=?");
            $stmt->bind_param("s", $bookName);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();

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
                    "success_url" => "http://localhost/ebook/success.php?session_id={CHECKOUT_SESSION_ID}",
                    "cancel_url"  => "http://localhost/ebook/cancel.php",
                ]);

                $stmt = $db->prepare("INSERT INTO orders (discord_id, product_id, status, stripe_session_id)
                                      VALUES (?, ?, 'pending', ?)");
                $stmt->bind_param("sis", $discordId, $product['id'], $session->id);
                $stmt->execute();

                $message->channel->sendMessage("💳 Pay here for **{$product['title']}**: {$session->url}\nAfter payment, type `!paid {$session->id}`");

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

            \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY);
            try {
                $session = \Stripe\Checkout\Session::retrieve($sessionId);
                echo '<pre>';print_r($session);die;
            } catch (Exception $e) {
                $message->channel->sendMessage("❌ Invalid session ID.");
                return;
            }

            $stmt = $db->prepare("SELECT o.status, p.title 
                                   FROM orders o 
                                   JOIN products p ON o.product_id=p.id 
                                   WHERE o.stripe_session_id=?");
            $stmt->bind_param("s", $sessionId);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();

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
                $stmt = $db->prepare("UPDATE orders SET status='paid' WHERE stripe_session_id=?");
                $stmt->bind_param("s", $sessionId);
                $stmt->execute();
                $message->channel->sendMessage("✅ Payment verified! Download **{$order['title']}** here: {$fileUrl}");
            } else {
                $message->channel->sendMessage("❌ Payment not completed yet.");
            }
        }

        // 📦 Show purchased ebooks
        if ($content === '!orders') {
            $discordId = $message->author->id;
            $stmt = $db->prepare("SELECT p.title 
                                   FROM orders o 
                                   JOIN products p ON o.product_id=p.id 
                                   WHERE o.discord_id=? AND o.status='paid'");
            $stmt->bind_param("s", $discordId);
            $stmt->execute();
            $orders = $stmt->get_result();

            if ($orders->num_rows === 0) {
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
