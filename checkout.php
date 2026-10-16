<?php
require __DIR__ . '/config.php';


function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}



/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */

// TODO: Implement the system integration here.

// TODO: Display the appropriate checkout result.


$idempotencyKey = 'cart-order-' . $orderId;

$paymentData = [
    'order_id'        => $orderId,
    'amount'          => round($total, 2),
    'customer_name'   => $customer,
    'payment_method'  => $method,
    'idempotency_key' => $idempotencyKey
    
];


$requestPayload = json_encode($paymentData);

if ($requestPayload === false) {
    finish(
        'Payment Error',
        'Could not prepare the payment request. '
        . 'Your order remains pending payment.'
    );
}


//send  req using cURL to the payment gateway and handle the response.

$gatewayUrl = 'http://localhost/payment-gateway/api/payments.php';



$curl = curl_init();

if ($curl === false) {
    finish(
        'Payment Error',
        'Could not initialize the payment connection. '
        . 'Your order remains pending payment.'
    );
}

curl_setopt_array($curl, [
    CURLOPT_URL            => $gatewayUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $requestPayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Idempotency-Key: ' . $idempotencyKey
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15
]);

$response = curl_exec($curl);

$curlErrorNumber = curl_errno($curl);
$curlErrorMessage = curl_error($curl);
$httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

curl_close($curl);


// handle errors safely.

if ($response === false || $curlErrorNumber !== 0) {
    error_log(
        'Gateway communication error for order '
        . $orderId . ': ' . $curlErrorMessage
    );

    finish(
        'Payment Status Unconfirmed',
        'We could not confirm your payment. '
        . 'Your order remains pending payment. '
        . 'Check your order status before attempting payment again.'
    );
}


//validate gateway response
if ($httpStatus < 200 || $httpStatus >= 300) {
    error_log(
        'Gateway HTTP error ' . $httpStatus
        . ' for order ' . $orderId
    );

    finish(
        'Payment Status Unconfirmed',
        'The gateway returned an error. '
        . 'Check your order status before trying again.'
    );
}

$gatewayResponse = json_decode($response, true);

if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($gatewayResponse)
) {
    finish(
        'Payment Status Unconfirmed',
        'The gateway returned an invalid response. '
        . 'Check your order status before trying again.'
    );
}

if (($gatewayResponse['success'] ?? null) !== true) {
    finish(
        'Payment Status Unconfirmed',
        'The gateway did not confirm the payment request. '
        . 'Check your order status before trying again.'
    );
}


//verify order ID and amount

$responseOrderId = filter_var(
    $gatewayResponse['order_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (
    $responseOrderId === false ||
    $responseOrderId !== $orderId
) {
    finish(
        'Payment Status Unconfirmed',
        'The payment response does not match your order.'
    );
}

$responseAmount = $gatewayResponse['amount'] ?? null;

if (
    !is_numeric($responseAmount) ||
    !is_finite((float) $responseAmount) ||
    abs((float) $responseAmount - $total) >= 0.01
) {
    finish(
        'Payment Status Unconfirmed',
        'The payment amount could not be verified.'
    );
}


//validate transaction id

$transactionId = $gatewayResponse['transaction_id'] ?? null;

if (
    $transactionId !== null &&
    (
        !is_string($transactionId) ||
        trim($transactionId) === ''
    )
) {
    finish(
        'Payment Status Unconfirmed',
        'The gateway returned invalid transaction information.'
    );
}

if (is_string($transactionId)) {
    $transactionId = trim($transactionId);
}


//handle payment outcome

$gatewayStatus = strtoupper(
    (string) ($gatewayResponse['status'] ?? '')
);

$statusMap = [
    'SUCCESS' => 'PAID',
    'FAILED'  => 'PAYMENT_FAILED',
    'PENDING' => 'PAYMENT_PENDING'
];

if (!isset($statusMap[$gatewayStatus])) {
    finish(
        'Payment Status Unconfirmed',
        'The gateway returned an unknown payment status.'
    );
}

$orderStatus = $statusMap[$gatewayStatus];


//update the shopping cart database

try {
    $updateStmt = $pdo->prepare(
        'UPDATE orders
         SET status = ?, gateway_transaction_id = ?
         WHERE id = ?'
    );

    $updateStmt->execute([
        $orderStatus,
        $transactionId,
        $orderId
    ]);

    // Confirm that the order contains the expected values.
    $checkStmt = $pdo->prepare(
        'SELECT id, status, gateway_transaction_id
         FROM orders
         WHERE id = ?'
    );

    $checkStmt->execute([$orderId]);

    $savedOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$savedOrder ||
        $savedOrder['status'] !== $orderStatus ||
        $savedOrder['gateway_transaction_id'] !== $transactionId
    ) {
        throw new RuntimeException(
            'Could not verify the updated order.'
        );
    }

} catch (Throwable $e) {
    error_log(
        'Order update error for order ' . $orderId
        . ': ' . $e->getMessage()
    );

    finish(
        'Payment Status Unconfirmed',
        'The payment response was received, but the order '
        . 'database could not be updated. Check your order '
        . 'status before attempting payment again.'
    );
}


//display payment result

switch ($gatewayStatus) {
    case 'SUCCESS':
        finish(
            'Payment Successful',
            'Your payment was successful! '
            . 'Order ID: ' . $orderId
            . '. Transaction ID: '
            . ($transactionId ?? 'Not provided')
        );

    case 'FAILED':
        finish(
            'Payment Failed',
            'Your payment failed. '
            . 'Order ID: ' . $orderId
            . '. Please check your order status.'
        );

    case 'PENDING':
        finish(
            'Payment Pending',
            'Your payment is awaiting confirmation. '
            . 'Order ID: ' . $orderId
            . '. Please check your order status for updates.'
        );
}