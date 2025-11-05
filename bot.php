<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/stripe/stripe-php/init.php';

use Discord\Discord;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;

$DISCORD_TOKEN = getenv('DISCORD_TOKEN');
$STRIPE_SECRET_KEY = getenv('STRIPE_SECRET_KEY');
// ✅ Database connection
$dsn = "mysql:host=" . getenv('DB_HOST') . ";dbname=" . getenv('DB_NAME') . ";charset=utf8mb4";
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Database connected successfully!";
} catch (PDOException $e) {
    echo "❌ Database connection failed: " . $e->getMessage();
    $pdo = null; // so later code doesn’t crash
}

// ✅ Discord bot init
$discord = new Discord([
    'token' => $DISCORD_TOKEN,
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
]);

$discord->on('ready', function ($discord) use ($pdo) {
    echo "✅ Bot is ready!", PHP_EOL;

    $discord->on(Event::MESSAGE_CREATE, function ($message, $discord) use ($pdo) {
        if ($message->author->bot) return;

        $content = trim(strtolower($message->content));

        // 👋 Greetings
        $greetings = ['hi', 'hii', 'hello', 'helo'];
        if (in_array($content, $greetings)) {
            $message->channel->sendMessage('👋 How are you? Type `!ebooks` to see available eBooks!');
            return;
        }

        // 📚 List ebooks
        if ($content === '!ebooks') {
            if (!$pdo) {
                $message->channel->sendMessage("❌ Database not connected.");
                return;
            }

            $stmt = $pdo->query("SELECT * FROM products");
            $ebooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$ebooks) {
                $message->channel->sendMessage("📚 No ebooks found yet.");
                return;
            }

            $msg = "📚 Available Ebooks:\n";
            foreach ($ebooks as $book) {
                $msg .= "{$book['id']}. {$book['title']} → `!buy {$book['title']} - {$book['price']}$`\n";
            }
            $msg .= "Enter !buy with the option you want!";
            $message->channel->sendMessage($msg);
            return;
        }

        // 💳 Buy ebook
        if (str_starts_with($content, '!buy')) {
            if (!$pdo) {
                $message->channel->sendMessage("❌ Database not connected.");
                return;
            }

            $parts = explode(" ", $content);
            $bookName = strtolower($parts[1] ?? '');
            $discordId = $message->author->id;

            if (empty($bookName)) {
                $message->channel->sendMessage("❌ Please specify the book name. Example: `!buy bookname`");
                return;
            }

            $stmt = $pdo->prepare("SELECT * FROM products WHERE LOWER(title)=?");
            $stmt->execute([$bookName]);
            $product = $stmt->fetch();

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
                    "cancel_url" => "http://localhost/ebook-bot/cancel.php",
                ]);

                $pdo->prepare("INSERT INTO orders (discord_id, product_id, status, stripe_session_id)
                               VALUES (?, ?, 'pending', ?)")
                    ->execute([$discordId, $product['id'], $session->id]);

                $message->channel->sendMessage(
                    "💳 Pay here for **{$product['title']}** ebook: {$session->url}\nAfter payment, type `!paid {$session->id}`"
                );
            } catch (Exception $e) {
                $message->channel->sendMessage("❌ Stripe error: " . $e->getMessage());
            }
            return;
        }

        // ✅ Verify payment manually
        if (str_starts_with($content, '!paid')) {
            if (!$pdo) {
                $message->channel->sendMessage("❌ Database not connected.");
                return;
            }

            $parts = explode(" ", $content);
            $sessionId = $parts[1] ?? '';

            if (empty($sessionId)) {
                $message->channel->sendMessage("❌ Please provide a session ID. Example: `!paid cs_test_12345`");
                return;
            }

            try {
                \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY);
                $session = \Stripe\Checkout\Session::retrieve($sessionId);
            } catch (Exception $e) {
                $message->channel->sendMessage("❌ Invalid Stripe session ID.");
                return;
            }

            $stmt = $pdo->prepare("SELECT o.status, p.title 
                                   FROM orders o 
                                   JOIN products p ON o.product_id=p.id 
                                   WHERE o.stripe_session_id=?");
            $stmt->execute([$sessionId]);
            $order = $stmt->fetch();

            if (!$order) {
                $message->channel->sendMessage("❌ No order found for this session ID.");
                return;
            }

            $fileUrl = "http://localhost/ebook/files/" . strtolower($order['title']) . ".pdf";

            if ($order['status'] === 'paid') {
                $message->channel->sendMessage("✅ Already paid! Here’s your **{$order['title']}** ebook: {$fileUrl}");
                return;
            }

            if ($session->payment_status === 'paid') {
                $pdo->prepare("UPDATE orders SET status='paid' WHERE stripe_session_id=?")
                    ->execute([$sessionId]);
                $message->channel->sendMessage("✅ Payment verified! Here is your **{$order['title']}** ebook: {$fileUrl}");
            } else {
                $message->channel->sendMessage("❌ Payment not completed yet.");
            }
            return;
        }

        // 📦 Show purchased ebooks
        if ($content === '!orders') {
            if (!$pdo) {
                $message->channel->sendMessage("❌ Database not connected.");
                return;
            }

            $discordId = $message->author->id;
            $stmt = $pdo->prepare("SELECT o.id, p.title 
                                   FROM orders o 
                                   JOIN products p ON o.product_id=p.id 
                                   WHERE o.discord_id=? AND o.status='paid'");
            $stmt->execute([$discordId]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$orders) {
                $message->channel->sendMessage("📦 You have not purchased any ebooks yet.");
                return;
            }

            $msg = "📦 Your Purchased Ebooks:\n";
            foreach ($orders as $o) {
                $fileUrl = "http://localhost/ebook/files/" . strtolower($o['title']) . ".pdf";
                $msg .= "- {$o['title']} → {$fileUrl}\n";
            }
            $message->channel->sendMessage($msg);
            return;
        }
    });
});

$discord->run();
