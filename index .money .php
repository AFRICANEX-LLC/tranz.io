<?php
// === CONFIG & DATABASE ===
$db = new PDO("mysql:host=localhost", "root", "");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE DATABASE IF NOT EXISTS momo");
$db->exec("USE momo");

// Agents table
$db->exec("CREATE TABLE IF NOT EXISTS agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) UNIQUE,
    password VARCHAR(255),
    balance INT DEFAULT 0,
    session_token VARCHAR(64)
)");

// Transactions table
$db->exec("CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT,
    phone VARCHAR(20),
    amount INT,
    tx_ref VARCHAR(64),
    status VARCHAR(20) DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// === SESSION LOGIN ===
session_start();

// Session timeout (30 min)
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
    session_unset();
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
$_SESSION['last_activity'] = time();

// Handle logout
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// === LOGIN HANDLER ===
if (!isset($_SESSION['agent_id'])) {
    if (isset($_GET['login'])) {
        $phone = preg_replace('/\D/', '', $_GET['login']);
        if (!$phone || strlen($phone) < 10) {
            exit("Invalid phone");
        }

        $stmt = $db->prepare("SELECT * FROM agents WHERE phone = ?");
        $stmt->execute([$phone]);
        $agent = $stmt->fetch();

        if (!$agent) {
            $defaultPass = password_hash("otp" . rand(1000, 9999), PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO agents (phone, password) VALUES (?, ?)");
            $stmt->execute([$phone, $defaultPass]);
            $agent_id = $db->lastInsertId();
        } else {
            $agent_id = $agent['id'];
        }

        $_SESSION['agent_id'] = $agent_id;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Login form
    echo "<!DOCTYPE html><html><head><title>Agent Login</title></head><body style='font-family:sans-serif;background:#eef;padding:50px'>
    <form method='get' style='max-width:400px;margin:auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 0 10px #ccc'>
        <h2 style='color:#333'>📲 Agent Login</h2>
        <label>Phone:</label><br>
        <input name='login' type='tel' placeholder='2567XXXXXXXX' required style='width:100%;padding:10px;margin:10px 0'><br>
        <button style='padding:10px 20px;background:#0066cc;color:#fff;border:none;border-radius:5px'>Login</button>
    </form></body></html>";
    exit;
}

// === IPN HANDLER ===
if (isset($_GET['ipn'])) {
    $xml = file_get_contents("php://input");
    libxml_use_internal_errors(true);
    $data = simplexml_load_string($xml);
    if (!$data) {
        http_response_code(400);
        exit("Invalid XML");
    }

    $tx = (string)$data->tx;
    $status = strtolower((string)$data->status);

    if ($status === "success") {
        $stmt = $db->prepare("UPDATE transactions SET status='completed' WHERE tx_ref=?");
        $stmt->execute([$tx]);

        $stmt = $db->prepare("SELECT agent_id, amount FROM transactions WHERE tx_ref=?");
        $stmt->execute([$tx]);
        if ($row = $stmt->fetch()) {
            $db->prepare("UPDATE agents SET balance = balance + ? WHERE id = ?")
               ->execute([$row['amount'], $row['agent_id']]);
        }
    }
    echo "<ok/>";
    exit;
}

// === JPESA API FUNCTION ===
function sendJpesaCredit($db, $agent_id, $phone, $amount) {
    $tx = uniqid("tx_");
    $desc = "Agent $agent_id deposit";

    $stmt = $db->prepare("INSERT INTO transactions (agent_id, phone, amount, tx_ref, status)
                          VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$agent_id, $phone, $amount, $tx]);

    $callback = "http://localhost/money.php?ipn=1"; // TODO: Change to real domain
    $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>
    <g7bill>
      <_key_>FED3AEC348B94A426817E05071A279B6</_key_>
      <cmd>account</cmd>
      <action>credit</action>
      <pt>mm</pt>
      <mobile>$phone</mobile>
      <amount>$amount</amount>
      <callback>$callback</callback>
      <tx>$tx</tx>
      <description>$desc</description>
    </g7bill>";

    $ch = curl_init("https://my.jpesa.com/api/");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => ["Content-Type: text/xml"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// === GET AGENT DETAILS ===
$agent_id = $_SESSION['agent_id'];
$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$agent_id]);
$agent = $stmt->fetch();

// === HANDLE DEPOSIT REQUEST ===
if (isset($_POST['deposit'])) {
    $phone = preg_replace('/\D/', '', $_POST['phone']);
    $amount = intval($_POST['amount']);
    $resp = sendJpesaCredit($db, $agent_id, $phone, $amount);
    $resp_msg = htmlentities($resp);
}

// === DASHBOARD UI ===
echo "<!DOCTYPE html><html><head><title>Dashboard</title></head><body style='font-family:sans-serif;background:#f4f4f4;padding:30px'>
<div style='max-width:700px;margin:auto;background:#fff;padding:30px;border-radius:15px;box-shadow:0 0 15px #ccc'>
    <form method='post' style='float:right'><button name='logout' style='background:#999;color:#fff;border:none;padding:5px 10px;border-radius:5px'>Logout</button></form>
    <h2 style='color:#333'>Welcome, admin 🧾</h2>
    <p><b>📱 Phone:</b> {$agent['phone']}<br>
       <b>💰 Balance:</b> UGX " . number_format($agent['balance']) . "</p>

    <form method='post' style='margin-top:20px;background:#f9f9f9;padding:20px;border-radius:10px'>
        <h3 style='color:#0066cc'>📥 Deposit via Mobile Money</h3>
        Phone: <input name='phone' type='tel' value='256' required style='width:100%;padding:10px;margin:10px 0'><br>
        Amount: <input name='amount' type='number' value='1000' required style='width:100%;padding:10px;margin:10px 0'><br>
        <button name='deposit' style='padding:10px 20px;background:#0066cc;color:#fff;border:none;border-radius:5px'>Send Deposit Request</button>
    </form>";

if (isset($resp_msg)) {
    echo "<div style='background:#e0ffe0;margin-top:10px;padding:10px;border-radius:5px'>
            <b>Response from JPesa:</b><br><pre>$resp_msg</pre></div>";
}

// === SHOW RECENT TRANSACTIONS ===
echo "<h3 style='margin-top:30px;color:#444'>🧾 Recent Transactions</h3><div style='font-size:14px'>";
$stmt = $db->prepare("SELECT * FROM transactions WHERE agent_id = ? ORDER BY id DESC LIMIT 10");
$stmt->execute([$agent_id]);
foreach ($stmt as $row) {
    echo "🧾 TX: {$row['tx_ref']} | UGX " . number_format($row['amount']) . " | <b>{$row['status']}</b> | {$row['created_at']}<br>";
}
echo "</div></div></body></html>";
?>
