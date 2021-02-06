<?php
/**
 * payro24 payment gateway
 *
 * @developer JMDMahdi
 * @publisher payro24
 * @copyright (C) 2018 payro24
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://payro24.ir
 */
namespace payro24\payro24;

use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class payro24 extends AbstractProvider
{
    public function getTitle()
    {
        return 'payro24';
    }

    public function verifyConfig(array &$options, &$errors = [])
    {
        if (empty($options['payro24_api_key'])) {
            $errors[] = \XF::phrase('you_must_provide_payro24_api_key');
        }
        return (empty($errors) ? false : true);
    }

    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $api_key = $purchase->paymentProfile->options['payro24_api_key'];
        $sandbox = !empty($purchase->paymentProfile->options['payro24_sandbox'])
        && $purchase->paymentProfile->options['payro24_sandbox'] == 1
            ? 'true' : 'false';
        $amount = intval($purchase->cost);
        $desc = ($purchase->title ?: ('Invoice#' . $purchaseRequest->request_key));
        $callback = $this->getCallbackUrl();

        if (empty($amount)) {
            return 'واحد پول انتخاب شده پشتیبانی نمی شود.';
        }

        $data = array(
            'order_id' => $purchaseRequest->request_key,
            'amount' => $amount,
            'name' => $purchase->purchaser->username,
            'phone' => '',
            'mail' => $purchase->purchaser->email,
            'desc' => $desc,
            'callback' => $callback,
        );

        $ch = curl_init('https://api.payro24.ir/v1.1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'P-TOKEN:' . $api_key,
            'P-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 201) {
            if ( !empty($result->error_message) && !empty($result->error_code)) {
                $message = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
                return $controller->error($message);
            }
            $message = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s', $http_status);
            return $controller->error($message);

        } else {
            @session_start();
            $_SESSION[$result->id . '1'] = $purchase->returnUrl;
            $_SESSION[$result->id . '2'] = $purchase->cancelUrl;
            setcookie($result->id . '1', $purchase->returnUrl, time() + 1200, '/');
            setcookie($result->id . '2', $purchase->cancelUrl, time() + 1200, '/');
            return $controller->redirect($result->link, '');
        }
    }

    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
    {
        return false;
    }

    public function setupCallback(\XF\Http\Request $request)
    {
        $state = new CallbackState();
        $state->transactionId = $request->filter('id', 'str');
        $state->costAmount = $request->filter('amount', 'unum');
        if (empty($state->costAmount)) {
            $state->noAmount = true;
        }
        $state->taxAmount = 0;
        $state->costCurrency = 'IRR';
        $state->paymentStatus = $request->filter('status', 'unum');
        $state->trackId = $request->filter('track_id', 'unum');
        $state->requestKey = $request->filter('order_id', 'str');
        $state->ip = $request->getIp();
        $state->_POST = $_REQUEST;
        return $state;
    }

    public function validateTransaction(CallbackState $state)
    {
        if (!$state->requestKey) {
            $state->logType = 'info';
            $state->logMessage = 'No purchase request key. Unrelated payment, no action to take.';
            return false;
        }
        if (!$state->getPurchaseRequest()) {
            $state->logType = 'info';
            $state->logMessage = 'Invalid request key. Unrelated payment, no action to take.';
            return false;
        }
        if (!$state->transactionId) {
            $state->logType = 'info';
            $state->logMessage = 'No transaction or subscriber ID. No action to take.';
            return false;
        }
        $paymentRepo = \XF::repository('XF:Payment');
        $matchingLogsFinder = $paymentRepo->findLogsByTransactionId($state->transactionId);
        if ($matchingLogsFinder->total()) {
            $state->logType = 'info';
            $state->logMessage = 'Transaction already processed. Skipping.';
            return false;
        }
        return parent::validateTransaction($state);
    }

    public function validateCost(CallbackState $state)
    {
        $purchaseRequest = $state->getPurchaseRequest();
        $cost = $purchaseRequest->cost_amount;
        $currency = $purchaseRequest->cost_currency;
        if(!empty($state->noAmount) && empty($state->costAmount)) {
            return true;
        }
        $costValidated = (round(($state->costAmount - $state->taxAmount), 2) == round($cost, 2) && $state->costCurrency == $currency);
        if (!$costValidated) {
            $state->logType = 'error';
            $state->logMessage = 'Invalid cost amount. please check amount and currency to be correct.';
            return false;
        }
        return true;
    }

    public function getPaymentResult(CallbackState $state)
    {
        // Just Do It.
    }

    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = $state->_POST;
    }

    public function completeTransaction(CallbackState $state) {
        @session_start();
        $router    = \XF::app()->router( 'public' );
        $returnUrl = !empty($_SESSION[$state->transactionId . '1']) ? $_SESSION[$state->transactionId . '1'] : '';
        $cancelUrl = !empty($_SESSION[$state->transactionId . '2']) ? $_SESSION[$state->transactionId . '2'] : '';
        if ( empty( $returnUrl ) )
        {
            $returnUrl = $_COOKIE[$state->transactionId . '1'];
        }
        if ( empty( $cancelUrl ) )
        {
            $cancelUrl = $_COOKIE[$state->transactionId . '2'];
        }
        if ( empty( $returnUrl ) )
        {
            $returnUrl = $router->buildLink( 'canonical:account/upgrade-purchase' );
        }
        if ( empty( $cancelUrl ) )
        {
            $cancelUrl = $router->buildLink( 'canonical:account/upgrades' );
        }
        unset( $_SESSION[$state->transactionId . '1'], $_SESSION[$state->transactionId . '2'] );
        setcookie( $state->transactionId . '1', './?', time(), '/' );
        setcookie( $state->transactionId . '2', './?', time(), '/' );

        if ($state->paymentStatus == 10)
        {
            $api_key = $state->paymentProfile->options['payro24_api_key'];
            $sandbox = $state->paymentProfile->options['payro24_sandbox'] == 1 ? 'true' : 'false';
            $url     = $cancelUrl;
            $data    = array(
                'id'       => $state->transactionId,
                'order_id' => $state->requestKey
            );

            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, 'https://api.payro24.ir/v1.1/payment/verify' );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'P-TOKEN:' . $api_key,
                'P-SANDBOX:' . $sandbox,
            ) );

            $result      = curl_exec( $ch );
            $result      = json_decode( $result );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );


            if ( $http_status != 200 )
            {
                $state->logType    = 'error';
                $state->logMessage = sprintf( 'خطا هنگام بررسی وضعیت تراکنش. وضعیت خطا: %s - کدخطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message );
            }
            else
            {
                $verify_status   = empty( $result->status ) ? NULL : $result->status;
                $verify_track_id = empty( $result->track_id ) ? NULL : $result->track_id;
                $verify_order_id = empty( $result->order_id ) ? NULL : $result->order_id;
                $verify_amount   = empty( $result->amount ) ? NULL : $result->amount;

                $state->transactionId = $verify_track_id;

                if ( empty( $verify_status ) || empty( $verify_track_id ) || empty( $verify_amount ) || $verify_status < 100 )
                {
                    $state->paymentResult = CallbackState::PAYMENT_REINSTATED;
                    $state->logType    = 'error';
                    $state->logMessage = $this->payro24_get_failed_message( $state->paymentProfile->options['payro24_failed_message'], $verify_track_id, $verify_order_id );
                    $url = $cancelUrl;
                }
                else
                {
                    $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
                    $state->logType    = 'success';
                    $state->logMessage = $this->payro24_get_success_message( $state->paymentProfile->options['payro24_success_message'], $verify_track_id, $verify_order_id );
                    parent::completeTransaction( $state );
                    $url = $returnUrl;
                }
            }
        }
        else {
            $state->paymentResult = CallbackState::PAYMENT_REINSTATED;
            $state->transactionId = $state->trackId;
            $state->logType    = 'error';
            $state->logMessage = $this->payro24_get_failed_message( $state->paymentProfile->options['payro24_failed_message'], $state->trackId, $state->requestKey );
            $url = $cancelUrl;
        }

        @header('location: ' . $url);
        exit;
    }


    public function payro24_get_failed_message($failed_massage, $track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $failed_massage);
    }

    public function payro24_get_success_message($success_massage, $track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $success_massage);
    }

}
