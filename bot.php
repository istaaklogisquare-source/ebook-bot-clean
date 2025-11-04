<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/stripe/stripe-php/init.php';

require __DIR__ . '/config.php';

use Discord\Discord;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;

$pdo = new PDO("mysql:host=localhost;dbname=discord_ebook", "root", ""); // XAMPP default

$discord = new Discord([
    'token' =>  $DISCORD_TOKEN,
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
]);


$discord->on('ready', function($discord) use ($pdo) {
    echo "✅ Bot is ready!", PHP_EOL;

    $discord->on(Event::MESSAGE_CREATE, function ($message, $discord) use ($pdo) {
        if ($message->author->bot) return;

        
        $content = trim(strtolower($message->content));

        // 1️⃣ Greetings handling
        $greetings = ['hi', 'hii', 'hello', 'helo'];
        if (in_array($content, $greetings)) {
            $message->channel->sendMessage('👋 How are you? Type `!ebooks` to see available eBooks!');
            return;
        }

        // 1️⃣ List all ebooks
        if($content === '!ebooks'){
            $stmt = $pdo->query("SELECT * FROM products");
            $ebooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $msg = "📚 Available Ebooks:\n";
            foreach($ebooks as $index => $book){
                //echo '<pre>';print_r($book);die;
                $msg .= ($book['id']).". {$book['title']} → `!buy {$book['title']} - {$book['price']}$`\n";
            }
            $msg .= 'Enter !buy which option you want!';
            $message->channel->sendMessage($msg);
        }

        // 2️⃣ Buy ebook
        elseif(str_starts_with($content, '!buy')){
            $args = explode(" ", $content);
            $bookName = strtolower($args[1] ?? '');
            $discordId = $message->author->id;

            // Fetch product
            $stmt = $pdo->prepare("SELECT * FROM products WHERE title=?");
            $stmt->execute([$bookName]);
            $product = $stmt->fetch();

            if(!$product){
                $message->channel->sendMessage("Invalid ebook option.");
                return;
            }

            // Create Stripe Checkout session
            \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY);
            $session = \Stripe\Checkout\Session::create([
                "payment_method_types" => ["card"],
                "line_items" => [[
                    "price_data" => [
                        "currency" => "usd",   // ya db se lao
                        "product_data" => [
                            "name" => $product['title'], // DB se title
                        ],
                        "unit_amount" => $product['price'] * 100, // cents me
                    ],
                    "quantity" => 1
                ]],
                "mode" => "payment",
                "success_url" => "http://localhost/ebook/success.php?session_id={CHECKOUT_SESSION_ID}",
                "cancel_url"  => "http://localhost/ebook-bot/cancel.php"
            ]);


            // Save order
            $pdo->prepare("INSERT INTO orders (discord_id, product_id, status, stripe_session_id) 
                           VALUES (?, ?, 'pending', ?)")->execute([$discordId, $product['id'], $session->id]);

            $message->channel->sendMessage("💳 Pay here for **{$product['name']}** ebook: ".$session->url."\nAfter payment, type `!paid {$session->id}`");
        }
        // 3️⃣ Verify payment manually
        elseif(str_starts_with($content, '!paid')){
            $args = explode(" ", $content);
            $sessionId = $args[1] ?? '';

            if(!$sessionId){
                $message->channel->sendMessage("❌ Please provide a session ID. Example: `!paid cs_test_12345`");
                return;
            }

            \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY);
            try {
                $session = \Stripe\Checkout\Session::retrieve($sessionId);
            } catch(Exception $e){
                $message->channel->sendMessage("❌ Invalid session ID.");
                return;
            }

            // Check order status in DB first
            $stmt = $pdo->prepare("SELECT o.status, p.title 
                                   FROM orders o 
                                   JOIN products p ON o.product_id=p.id 
                                   WHERE o.stripe_session_id=?");
            $stmt->execute([$sessionId]);
            $order = $stmt->fetch();

            if(!$order){
                $message->channel->sendMessage("❌ No order found for this session ID.");
                return;
            }

            $fileUrl = "http://localhost/ebook/files/" . strtolower($order['title']) . ".pdf";

            // Already marked as paid in DB
            if($order['status'] === 'paid'){
                $message->channel->sendMessage("✅ This order was already paid earlier! Here’s your **{$order['title']}** ebook: {$fileUrl}");
                return;
            }

            // Fresh payment check with Stripe
            if($session->payment_status === 'paid'){
                $pdo->prepare("UPDATE orders SET status='paid' WHERE stripe_session_id=?")
                    ->execute([$sessionId]);

                $message->channel->sendMessage("✅ Payment verified and confirmed! Here is your **{$order['title']}** ebook: {$fileUrl}");
            } else {
                $message->channel->sendMessage("❌ Payment not completed yet. Please check your payment.");
            }
        }

        // 4️⃣ Show purchased ebooks
        elseif($content === '!orders'){
            $discordId = $message->author->id;
            $stmt = $pdo->prepare("SELECT o.id, p.title FROM orders o JOIN products p ON o.product_id=p.id WHERE o.discord_id=? AND o.status='paid'");
            $stmt->execute([$discordId]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if(!$orders){
                $message->channel->sendMessage("📦 You have not purchased any ebooks yet.");
                return;
            }

            $msg = "📦 Your Purchased Ebooks:\n";
            foreach($orders as $o){
                $fileUrl  = "http://localhost/ebook/files/" . strtolower($o['title']) . ".pdf";
                $msg .= "- {$o['title']} → {$fileUrl}\n";
            }
            $message->channel->sendMessage($msg);
        }

    });
});

$discord->run();
