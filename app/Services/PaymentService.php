<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Database;
use PDO;

final class PaymentService
{
    public static function currency(): string
    {
        $currency = strtoupper(trim((string)SettingsService::get('payment_currency', 'INR')));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'INR';
    }

    public static function gatewayEnabled(string $provider): bool
    {
        return match ($provider) {
            'cash' => SettingsService::bool('cash_payments_enabled', true),
            'razorpay' => SettingsService::bool('razorpay_enabled', false)
                && trim((string)SettingsService::get('razorpay_key_id', '')) !== ''
                && SecretService::isConfigured((string)SettingsService::get('razorpay_key_secret', '')),
            'stripe' => SettingsService::bool('stripe_enabled', false)
                && SecretService::isConfigured((string)SettingsService::get('stripe_secret_key', '')),
            default => false,
        };
    }

    public static function invoice(int $invoiceId): ?array
    {
        $st = Database::connection()->prepare("SELECT fi.*,u.name student_name,u.email student_email,(SELECT COALESCE(SUM(amount),0) FROM fee_payments fp WHERE fp.invoice_id=fi.id) paid FROM fee_invoices fi JOIN users u ON u.id=fi.user_id WHERE fi.id=? LIMIT 1");
        $st->execute([$invoiceId]);
        $row = $st->fetch();
        if (!$row) return null;
        $row['outstanding'] = max(0, round((float)$row['amount'] - (float)$row['paid'], 2));
        return $row;
    }

    public static function createCashRequest(int $invoiceId, int $userId): array
    {
        if (!self::gatewayEnabled('cash')) throw new \RuntimeException('Cash-at-counter payments are not enabled.');
        $invoice = self::ownedOpenInvoice($invoiceId, $userId);
        $pdo = Database::connection();
        $existing = $pdo->prepare("SELECT * FROM payment_transactions WHERE invoice_id=? AND user_id=? AND provider='cash' AND status='pending' ORDER BY id DESC LIMIT 1");
        $existing->execute([$invoiceId,$userId]);
        $row = $existing->fetch();
        if ($row) return $row;
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO payment_transactions (invoice_id,user_id,provider,status,amount,currency,created_at,updated_at) VALUES (?,?, 'cash','pending',?,?,?,?)")
            ->execute([$invoiceId,$userId,(float)$invoice['outstanding'],(string)$invoice['currency'],$now,$now]);
        $id = (int)$pdo->lastInsertId();
        $order = 'cash-' . $id;
        $pdo->prepare('UPDATE payment_transactions SET provider_order_id=? WHERE id=?')->execute([$order,$id]);
        return self::transaction($id) ?? ['id'=>$id,'provider_order_id'=>$order];
    }

    public static function createStripeCheckout(int $invoiceId, int $userId): string
    {
        if (!self::gatewayEnabled('stripe')) throw new \RuntimeException('Stripe is not enabled.');
        $invoice = self::ownedOpenInvoice($invoiceId, $userId);
        $secret = SecretService::decrypt((string)SettingsService::get('stripe_secret_key', ''));
        $tx = self::newTransaction($invoice, $userId, 'stripe');
        $success = absolute_app_url('?r=payment-stripe-return&session_id={CHECKOUT_SESSION_ID}');
        $cancel = absolute_app_url('?r=fees&payment=cancelled');
        $amountMinor = self::minor((float)$invoice['outstanding']);
        try {
            $data = [
                'mode'=>'payment',
                'success_url'=>$success,
                'cancel_url'=>$cancel,
                'client_reference_id'=>(string)$tx['id'],
                'customer_email'=>(string)$invoice['student_email'],
                'line_items[0][quantity]'=>'1',
                'line_items[0][price_data][currency]'=>strtolower((string)$invoice['currency']),
                'line_items[0][price_data][unit_amount]'=>(string)$amountMinor,
                'line_items[0][price_data][product_data][name]'=>'Open LMS fee: ' . (string)$invoice['title'],
                'metadata[transaction_id]'=>(string)$tx['id'],
                'metadata[invoice_id]'=>(string)$invoiceId,
                'metadata[user_id]'=>(string)$userId,
            ];
            $session = self::httpJson('POST','https://api.stripe.com/v1/checkout/sessions',$data,[
                'Authorization: Basic ' . base64_encode($secret . ':'),
                'Content-Type: application/x-www-form-urlencoded',
            ], false);
            $sessionId = (string)($session['id'] ?? '');
            $url = (string)($session['url'] ?? '');
            if ($sessionId === '' || !str_starts_with($url, 'https://checkout.stripe.com/')) throw new \RuntimeException('Stripe did not return a Checkout URL.');
            Database::connection()->prepare('UPDATE payment_transactions SET provider_order_id=?,updated_at=? WHERE id=?')->execute([$sessionId,gmdate('Y-m-d H:i:s'),(int)$tx['id']]);
            return $url;
        } catch (\Throwable $e) {
            self::markFailed((int)$tx['id'], 'stripe_create_failed');
            throw $e;
        }
    }

    public static function verifyStripeSession(string $sessionId, ?int $requiredUserId = null): array
    {
        if (!self::gatewayEnabled('stripe') || !preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) throw new \RuntimeException('Stripe session is invalid.');
        $secret = SecretService::decrypt((string)SettingsService::get('stripe_secret_key', ''));
        $session = self::httpJson('GET','https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId),null,[
            'Authorization: Basic ' . base64_encode($secret . ':'),
        ], false);
        if (($session['payment_status'] ?? '') !== 'paid') throw new \RuntimeException('Stripe payment is not marked paid.');
        $txId = (int)($session['metadata']['transaction_id'] ?? $session['client_reference_id'] ?? 0);
        $tx = self::transaction($txId);
        if (!$tx || $tx['provider'] !== 'stripe' || !hash_equals((string)$tx['provider_order_id'], $sessionId)) throw new \RuntimeException('Stripe transaction does not match this invoice.');
        if ($requiredUserId !== null && (int)$tx['user_id'] !== $requiredUserId) throw new \RuntimeException('Payment session access denied.');
        self::validateProviderAmount($tx, (int)($session['amount_total'] ?? -1), strtoupper((string)($session['currency'] ?? '')));
        $paymentIntent = is_string($session['payment_intent'] ?? null) ? (string)$session['payment_intent'] : null;
        return self::completeTransaction((int)$tx['id'], $paymentIntent, $sessionId);
    }

    public static function createRazorpayOrder(int $invoiceId, int $userId): array
    {
        if (!self::gatewayEnabled('razorpay')) throw new \RuntimeException('Razorpay is not enabled.');
        $invoice = self::ownedOpenInvoice($invoiceId, $userId);
        $keyId = trim((string)SettingsService::get('razorpay_key_id', ''));
        $secret = SecretService::decrypt((string)SettingsService::get('razorpay_key_secret', ''));
        $tx = self::newTransaction($invoice, $userId, 'razorpay');
        try {
            $payload = [
                'amount'=>self::minor((float)$invoice['outstanding']),
                'currency'=>(string)$invoice['currency'],
                'receipt'=>'fee-' . $invoiceId . '-' . $tx['id'],
                'notes'=>['transaction_id'=>(string)$tx['id'],'invoice_id'=>(string)$invoiceId,'user_id'=>(string)$userId],
            ];
            $order = self::httpJson('POST','https://api.razorpay.com/v1/orders',$payload,[
                'Authorization: Basic ' . base64_encode($keyId . ':' . $secret),
                'Content-Type: application/json',
            ], true);
            $orderId = (string)($order['id'] ?? '');
            if (!str_starts_with($orderId, 'order_')) throw new \RuntimeException('Razorpay did not return an order ID.');
            Database::connection()->prepare('UPDATE payment_transactions SET provider_order_id=?,updated_at=? WHERE id=?')->execute([$orderId,gmdate('Y-m-d H:i:s'),(int)$tx['id']]);
            return [
                'transaction_id'=>(int)$tx['id'],
                'order_id'=>$orderId,
                'key_id'=>$keyId,
                'amount'=>self::minor((float)$invoice['outstanding']),
                'currency'=>(string)$invoice['currency'],
                'name'=>(string)$invoice['student_name'],
                'email'=>(string)$invoice['student_email'],
                'description'=>(string)$invoice['title'],
            ];
        } catch (\Throwable $e) {
            self::markFailed((int)$tx['id'], 'razorpay_create_failed');
            throw $e;
        }
    }

    public static function verifyRazorpayPayment(int $transactionId, int $userId, string $orderId, string $paymentId, string $signature): array
    {
        if (!self::gatewayEnabled('razorpay')) throw new \RuntimeException('Razorpay is not enabled.');
        $tx = self::transaction($transactionId);
        if (!$tx || $tx['provider'] !== 'razorpay' || (int)$tx['user_id'] !== $userId || !hash_equals((string)$tx['provider_order_id'], $orderId)) throw new \RuntimeException('Razorpay transaction is invalid.');
        if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId) || !preg_match('/^[a-f0-9]{64}$/i', $signature)) throw new \RuntimeException('Razorpay response is invalid.');
        $secret = SecretService::decrypt((string)SettingsService::get('razorpay_key_secret', ''));
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
        if (!hash_equals($expected, strtolower($signature))) throw new \RuntimeException('Razorpay payment signature verification failed.');

        $keyId = trim((string)SettingsService::get('razorpay_key_id', ''));
        $payment = self::httpJson('GET','https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId),null,[
            'Authorization: Basic ' . base64_encode($keyId . ':' . $secret),
        ], false);
        if (($payment['status'] ?? '') !== 'captured') throw new \RuntimeException('Razorpay payment is not captured yet.');
        if (!hash_equals($orderId, (string)($payment['order_id'] ?? ''))) throw new \RuntimeException('Razorpay order mismatch.');
        self::validateProviderAmount($tx, (int)($payment['amount'] ?? -1), strtoupper((string)($payment['currency'] ?? '')));
        return self::completeTransaction((int)$tx['id'], $paymentId, $orderId);
    }

    public static function confirmCash(int $transactionId, int $adminUserId, string $reference): array
    {
        $tx = self::transaction($transactionId);
        if (!$tx || $tx['provider'] !== 'cash' || $tx['status'] !== 'pending') throw new \RuntimeException('Pending cash request not found.');
        $reference = mb_substr(trim($reference),0,190);
        if ($reference === '') $reference = 'Cash counter';
        return self::completeTransaction($transactionId, $reference, (string)$tx['provider_order_id'], $adminUserId);
    }

    public static function cancelCash(int $transactionId): void
    {
        $tx = self::transaction($transactionId);
        if (!$tx || $tx['provider'] !== 'cash' || $tx['status'] !== 'pending') throw new \RuntimeException('Pending cash request not found.');
        Database::connection()->prepare("UPDATE payment_transactions SET status='cancelled',updated_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s'),$transactionId]);
    }

    public static function recordManual(int $invoiceId, float $amount, string $reference, int $adminUserId): void
    {
        $invoice = self::invoice($invoiceId);
        if (!$invoice) throw new \RuntimeException('Invoice not found.');
        $amount = round($amount,2);
        if ($amount <= 0 || $amount > (float)$invoice['outstanding'] + 0.00001) throw new \RuntimeException('Payment must be positive and cannot exceed the outstanding amount.');
        $now = gmdate('Y-m-d H:i:s');
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO fee_payments (invoice_id,amount,reference,paid_at,recorded_by,provider,provider_reference) VALUES (?,?,?,?,?,'cash',?)")
            ->execute([$invoiceId,$amount,mb_substr(trim($reference),0,190),$now,$adminUserId,mb_substr(trim($reference),0,190)]);
        self::refreshInvoiceStatus($invoiceId);
    }

    public static function handleStripeWebhook(string $payload, string $signatureHeader): bool
    {
        $secret = SecretService::decrypt((string)SettingsService::get('stripe_webhook_secret', ''));
        if ($secret === '' || $signatureHeader === '') return false;
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$k,$v] = array_pad(explode('=',trim($part),2),2,'');
            if ($k !== '') $parts[$k][] = $v;
        }
        $timestamp = isset($parts['t'][0]) ? (int)$parts['t'][0] : 0;
        if ($timestamp < 1 || abs(time()-$timestamp) > 300) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($parts['v1'] ?? [] as $sig) if (hash_equals($expected, $sig)) { $valid=true; break; }
        if (!$valid) return false;
        $event = json_decode($payload,true);
        if (!is_array($event)) return false;
        if (!in_array((string)($event['type'] ?? ''), ['checkout.session.completed','checkout.session.async_payment_succeeded'], true)) return true;
        $session = $event['data']['object'] ?? null;
        if (!is_array($session) || ($session['payment_status'] ?? '') !== 'paid') return true;
        $txId=(int)($session['metadata']['transaction_id']??$session['client_reference_id']??0);$tx=self::transaction($txId);if(!$tx||$tx['provider']!=='stripe')return true;
        self::validateProviderAmount($tx,(int)($session['amount_total']??-1),strtoupper((string)($session['currency']??'')));
        self::completeTransaction($txId,is_string($session['payment_intent']??null)?(string)$session['payment_intent']:null,(string)($session['id']??''));
        return true;
    }

    public static function handleRazorpayWebhook(string $payload, string $signature): bool
    {
        $webhookSecret = SecretService::decrypt((string)SettingsService::get('razorpay_webhook_secret', ''));
        if ($webhookSecret === '' || !preg_match('/^[a-f0-9]{64}$/i',$signature)) return false;
        if (!hash_equals(hash_hmac('sha256',$payload,$webhookSecret),strtolower($signature))) return false;
        $event=json_decode($payload,true);if(!is_array($event))return false;
        if ((string)($event['event']??'')!=='payment.captured') return true;
        $payment=$event['payload']['payment']['entity']??null;if(!is_array($payment))return true;
        $orderId=(string)($payment['order_id']??'');$paymentId=(string)($payment['id']??'');if($orderId===''||$paymentId==='')return true;
        $pdo=Database::connection();$st=$pdo->prepare("SELECT * FROM payment_transactions WHERE provider='razorpay' AND provider_order_id=? LIMIT 1");$st->execute([$orderId]);$tx=$st->fetch();if(!$tx)return true;
        self::validateProviderAmount($tx,(int)($payment['amount']??-1),strtoupper((string)($payment['currency']??'')));
        self::completeTransaction((int)$tx['id'],$paymentId,$orderId);
        return true;
    }

    public static function transaction(int $id): ?array
    {
        $st=Database::connection()->prepare('SELECT * FROM payment_transactions WHERE id=? LIMIT 1');$st->execute([$id]);$r=$st->fetch();return $r?:null;
    }

    private static function ownedOpenInvoice(int $invoiceId,int $userId):array
    {
        $invoice=self::invoice($invoiceId);if(!$invoice||(int)$invoice['user_id']!==$userId)throw new \RuntimeException('Invoice not found.');
        if((float)$invoice['outstanding']<=0.00001||$invoice['status']==='paid')throw new \RuntimeException('This invoice is already paid.');
        return $invoice;
    }

    private static function newTransaction(array $invoice,int $userId,string $provider):array
    {
        $now=gmdate('Y-m-d H:i:s');$pdo=Database::connection();
        $pdo->prepare('INSERT INTO payment_transactions (invoice_id,user_id,provider,status,amount,currency,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(int)$invoice['id'],$userId,$provider,'pending',(float)$invoice['outstanding'],(string)$invoice['currency'],$now,$now]);
        return self::transaction((int)$pdo->lastInsertId())??throw new \RuntimeException('Unable to create payment transaction.');
    }

    private static function completeTransaction(int $transactionId, ?string $paymentReference, ?string $orderReference, ?int $recordedBy=null): array
    {
        $pdo=Database::connection();
        $driver=(string)\OpenLMS\Core\Config::get('database.driver','mysql');
        if($driver==='sqlite')$pdo->exec('BEGIN IMMEDIATE');else $pdo->beginTransaction();
        try{
            $sql='SELECT * FROM payment_transactions WHERE id=? LIMIT 1' . ($driver==='mysql'?' FOR UPDATE':'');
            $st=$pdo->prepare($sql);$st->execute([$transactionId]);$tx=$st->fetch();if(!$tx)throw new \RuntimeException('Payment transaction not found.');
            if($tx['status']==='paid'){ self::commitWrite($pdo,$driver); return $tx; }
            if(!in_array($tx['status'],['pending','processing'],true))throw new \RuntimeException('Payment transaction is not payable.');
            $invoice=self::invoice((int)$tx['invoice_id']);if(!$invoice)throw new \RuntimeException('Invoice not found.');
            $amount=round((float)$tx['amount'],2);$outstanding=round((float)$invoice['outstanding'],2);
            if($amount>$outstanding+0.00001){$pdo->prepare("UPDATE payment_transactions SET status='review',provider_payment_id=?,reference=?,updated_at=? WHERE id=?")->execute([$paymentReference,$orderReference,gmdate('Y-m-d H:i:s'),$transactionId]);self::commitWrite($pdo,$driver);throw new \RuntimeException('Payment was received but requires administrator review because the invoice balance changed.');}
            $now=gmdate('Y-m-d H:i:s');$recorder=$recordedBy??(int)$tx['user_id'];
            $pdo->prepare('INSERT INTO fee_payments (invoice_id,amount,reference,paid_at,recorded_by,provider,provider_reference) VALUES (?,?,?,?,?,?,?)')
                ->execute([(int)$tx['invoice_id'],$amount,$paymentReference?:$orderReference,$now,$recorder,(string)$tx['provider'],$paymentReference?:$orderReference]);
            $pdo->prepare("UPDATE payment_transactions SET status='paid',provider_payment_id=?,reference=?,updated_at=?,completed_at=? WHERE id=?")
                ->execute([$paymentReference,$orderReference,$now,$now,$transactionId]);
            self::refreshInvoiceStatus((int)$tx['invoice_id'],$pdo);
            self::commitWrite($pdo,$driver);
            notify_user((int)$tx['user_id'],'Payment received',(string)$invoice['title'].' · '.number_format($amount,2).' '.(string)$tx['currency'],'fee',app_url('?r=fees'));
            return self::transaction($transactionId)??$tx;
        }catch(\Throwable $e){self::rollbackWrite($pdo,$driver);throw $e;}
    }

    private static function commitWrite(PDO $pdo,string $driver):void
    {
        if($driver==='sqlite'){try{$pdo->exec('COMMIT');}catch(\Throwable){}}
        elseif($pdo->inTransaction())$pdo->commit();
    }

    private static function rollbackWrite(PDO $pdo,string $driver):void
    {
        if($driver==='sqlite'){try{$pdo->exec('ROLLBACK');}catch(\Throwable){}}
        elseif($pdo->inTransaction())$pdo->rollBack();
    }

    private static function refreshInvoiceStatus(int $invoiceId, ?PDO $pdo=null):void
    {
        $pdo??=Database::connection();$st=$pdo->prepare('SELECT amount,(SELECT COALESCE(SUM(amount),0) FROM fee_payments WHERE invoice_id=fee_invoices.id) paid FROM fee_invoices WHERE id=?');$st->execute([$invoiceId]);$row=$st->fetch();if(!$row)return;
        $paid=(float)$row['paid'];$amount=(float)$row['amount'];$status=$paid<=0.00001?'unpaid':($paid+0.00001>=$amount?'paid':'partial');$pdo->prepare('UPDATE fee_invoices SET status=?,updated_at=? WHERE id=?')->execute([$status,gmdate('Y-m-d H:i:s'),$invoiceId]);
    }

    private static function validateProviderAmount(array $tx,int $minorAmount,string $currency):void
    {
        if($minorAmount!==self::minor((float)$tx['amount']))throw new \RuntimeException('Payment amount does not match the invoice transaction.');
        if(strtoupper($currency)!==strtoupper((string)$tx['currency']))throw new \RuntimeException('Payment currency does not match the invoice transaction.');
    }

    private static function minor(float $amount):int{return (int)round($amount*100);}
    private static function markFailed(int $id,string $reason):void{try{Database::connection()->prepare("UPDATE payment_transactions SET status='failed',reference=?,updated_at=? WHERE id=? AND status='pending'")->execute([$reason,gmdate('Y-m-d H:i:s'),$id]);}catch(\Throwable){}}

    private static function httpJson(string $method,string $url,?array $data,array $headers,bool $jsonBody):array
    {
        $method=strtoupper($method);$body=null;if($data!==null)$body=$jsonBody?json_encode($data,JSON_UNESCAPED_SLASHES):http_build_query($data);
        if(function_exists('curl_init')){
            $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);if($raw===false)throw new \RuntimeException('Payment provider connection failed: '.$err);
        }else{
            $opts=['http'=>['method'=>$method,'timeout'=>20,'ignore_errors'=>true,'header'=>implode("\r\n",$headers)."\r\n"]];if($body!==null)$opts['http']['content']=$body;$raw=@file_get_contents($url,false,stream_context_create($opts));$line=$http_response_header[0]??'';preg_match('/\s(\d{3})\s/',$line,$m);$code=(int)($m[1]??0);if($raw===false)throw new \RuntimeException('Payment provider connection failed.');
        }
        $decoded=json_decode((string)$raw,true);if(!is_array($decoded))throw new \RuntimeException('Payment provider returned an invalid response.');
        if($code<200||$code>=300){$msg=(string)($decoded['error']['message']??$decoded['error']['description']??$decoded['error']['reason']??'Payment provider rejected the request.');throw new \RuntimeException(mb_substr($msg,0,240));}
        return $decoded;
    }
}
