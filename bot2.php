<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/stripe/stripe-php/init.php';

use Discord\Discord;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use React\EventLoop\Factory;

// Environment Variables
$DB_HOST = getenv('DB_HOST');
$DB_NAME = getenv('DB_NAME');
$DB_USER = getenv('DB_USER');
$DB_PASS = getenv('DB_PASS');
$DISCORD_TOKEN = getenv('DISCORD_TOKEN');
$STRIPE_SECRET_KEY = getenv('STRIPE_SECRET_KEY');

// Database Connect
mysqli_report(MYSQLI_REPORT_OFF);
function connectDB() {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
    $conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($conn->connect_errno) { echo "❌ DB connection failed: ".$conn->connect_error.PHP_EOL; return null; }
    $conn->set_charset('utf8mb4');
    echo "✅ Database connected!\n";
    return $conn;
}
function getDB() { static $db = null; if ($db===null) $db=connectDB(); elseif (!$db->ping()) { echo "🔄 DB lost, reconnecting...\n"; $db=connectDB(); } return $db; }
function safeQuery($sql, $params=[], $types='') { 
    $db = getDB(); if(!$db){ echo "❌ DB unavailable.\n"; return false; }
    try{
        if(empty($params)) return $db->query($sql);
        $stmt=$db->prepare($sql);
        if(!$stmt) throw new Exception("Prepare failed: ".$db->error);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    } catch(Exception $e){
        echo "⚠️ DB query failed: ".$e->getMessage()."\n";
        $db=connectDB();
        if($db){
            if(empty($params)) return $db->query($sql);
            $stmt=$db->prepare($sql); $stmt->bind_param($types,...$params); $stmt->execute();
            return $stmt->get_result();
        }
        return false;
    }
}

// Discord Init
$loop = Factory::create();
$discord = new Discord([
    'token' => $DISCORD_TOKEN,
    'loop' => $loop,
    'intents' => Intents::GUILDS | Intents::GUILD_MESSAGES | Intents::MESSAGE_CONTENT | Intents::GUILD_MEMBERS,
]);

$discord->on('error', function($e){
    echo "⚠️ Discord error: " . $e->getMessage() . PHP_EOL;
});


// Ready Event
$discord->on('ready', function($discord) use($STRIPE_SECRET_KEY){
    echo "✅ Bot Ready!\n";

    // Startup Message
    $guild = $discord->guilds->first();
    if($guild){
        foreach($guild->channels as $ch){
            if($ch->type===0){
                $ch->sendMessage("👋 Hi everyone! I’m **eBook Bot** 🤖\nType `!ebooks` to browse available books!");
                echo "📢 Sent startup message in #{$ch->name}\n";
                break;
            }
        }
    }

    // DM Owner
    $ownerId='1400354937690656892';
    $discord->users->fetch($ownerId)->then(fn($user)=> $user->sendMessage("✅ eBook Bot online! 🚀") );

    // DB Keep Alive every 60s
    $discord->getLoop()->addPeriodicTimer(60, function() {
        $db=getDB(); if($db){ $db->query("SELECT 1"); echo "🟢 DB keep-alive\n"; }
    });

    // Discord Keep Alive every 5min
    $discord->getLoop()->addPeriodicTimer(300, function() use($discord){
        echo "🔄 Discord keep-alive ping\n";
        $discord->api->get('/gateway')->then(fn()=> print("✅ Gateway ping OK\n"), fn($e)=> print("⚠️ Gateway ping failed: {$e->getMessage()}\n"));
    });

    // Welcome New Members
    $discord->on(Event::GUILD_MEMBER_ADD, function($member){
        $ch=$member->guild->system_channel;
        if($ch) $ch->sendMessage("👋 Hey {$member->user->username}! Welcome! Type `!ebooks` 📚");
    });

    // Messages
    $discord->on(Event::MESSAGE_CREATE, function($msg) use($STRIPE_SECRET_KEY){
        if($msg->author->bot) return;
        $db=getDB(); if(!$db){ $msg->channel->sendMessage("❌ DB offline."); return; }
        $c=strtolower(trim($msg->content));

        // Greetings
        if(in_array($c,['hi','hello','hii','helo'])){ $msg->channel->sendMessage("👋 Hi! Type `!ebooks`"); return; }

        // List ebooks
        if($c==='!ebooks'){
            $res=safeQuery("SELECT * FROM products");
            if(!$res || $res->num_rows===0){ $msg->channel->sendMessage("📚 No ebooks yet."); return; }
            $m="📚 **Available Ebooks:**\n";
            while($b=$res->fetch_assoc()) $m.="**{$b['id']}. {$b['title']}** → `!buy {$b['title']}` 💵 {$b['price']}$\n";
            $msg->channel->sendMessage($m); return;
        }

        // Buy Ebook
        if(str_starts_with($c,'!buy')){
            $parts=explode(" ", $msg->content,2); $book=strtolower(trim($parts[1]??'')); $uid=$msg->author->id;
            if(!$book){ $msg->channel->sendMessage("❌ Specify book. Example: `!buy bookname`"); return; }
            $stmt=safeQuery("SELECT * FROM products WHERE LOWER(title)=?",[$book],"s");
            $prod=$stmt?$stmt->fetch_assoc():null;
            if(!$prod){ $msg->channel->sendMessage("❌ Invalid book."); return; }
            try{
                \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY);
                $session=\Stripe\Checkout\Session::create([
                    "payment_method_types"=>["card"],
                    "line_items"=>[["price_data"=>["currency"=>"usd","product_data"=>["name"=>$prod['title']],"unit_amount"=>$prod['price']*100],"quantity"=>1]],
                    "mode"=>"payment",
                    "success_url"=>"http://localhost/ebook/success.php?session_id={CHECKOUT_SESSION_ID}",
                    "cancel_url"=>"http://localhost/ebook/cancel.php",
                ]);
                safeQuery("INSERT INTO orders (discord_id, product_id, status, stripe_session_id) VALUES (?,?, 'pending', ?)", [$uid,$prod['id'],$session->id], "sis");
                $msg->channel->sendMessage("💳 Click to pay **{$prod['title']}**: {$session->url}\nAfter payment: `!paid {$session->id}`");
            } catch(Exception $e){ $msg->channel->sendMessage("❌ Stripe error: ".$e->getMessage()); }
            return;
        }

        // Verify payment
        if(str_starts_with($c,'!paid')){
            $parts=explode(" ", $msg->content); $sid=trim($parts[1]??'');
            if(!$sid){ $msg->channel->sendMessage("❌ Provide session ID"); return; }
            try{ \Stripe\Stripe::setApiKey($STRIPE_SECRET_KEY); $s=\Stripe\Checkout\Session::retrieve($sid); }
            catch(Exception $e){ $msg->channel->sendMessage("❌ Stripe error: ".$e->getMessage()); return; }
            $stmt=safeQuery("SELECT o.status,p.title FROM orders o JOIN products p ON o.product_id=p.id WHERE LOWER(o.stripe_session_id)=LOWER(?)", [$sid], "s");
            $o=$stmt?$stmt->fetch_assoc():null; if(!$o){ $msg->channel->sendMessage("❌ No order found."); return; }
            $file="http://localhost/ebook/files/".strtolower($o['title']).".pdf";
            if($o['status']==='paid'){ $msg->channel->sendMessage("✅ Already paid! {$file}"); return; }
            if($s->payment_status==='paid'){ safeQuery("UPDATE orders SET status='paid' WHERE LOWER(stripe_session_id)=LOWER(?)",[$sid],"s"); $msg->channel->sendMessage("✅ Purchase complete! {$file}"); }
            else $msg->channel->sendMessage("❌ Payment not completed.");
        }

        // User Orders
        if($c==='!orders'){
            $uid=$msg->author->id;
            $res=safeQuery("SELECT p.title FROM orders o JOIN products p ON o.product_id=p.id WHERE o.discord_id=? AND o.status='paid'",[$uid],"s");
            if(!$res || $res->num_rows===0){ $msg->channel->sendMessage("📦 No purchased ebooks."); return; }
            $m="📦 **Your Purchased Ebooks:**\n";
            while($b=$res->fetch_assoc()) $m.="- {$b['title']} → http://localhost/ebook/files/".strtolower($b['title']).".pdf\n";
            $msg->channel->sendMessage($m);
        }

    });

});

$discord->run();
