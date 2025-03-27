<?php

declare(strict_types=1);

namespace OBMS\PaymentGateways\PayPal;

use Exception;
use Illuminate\Support\Collection;
use OBMS\ModuleSDK\Payments\Gateway;
use OBMS\ModuleSDK\Payments\Traits\HasSettings;
use OBMS\PaymentGateways\PayPal\Helpers\PaypalIPNClient;
use OBMS\PaymentGateways\PayPal\Helpers\PaypalMerchantClient;

/**
 * Class Handler.
 *
 * This class is a payment method handler for PayPal (SOAP).
 *
 * @author Marcel Menk <marcel.menk@ipvx.io>
 */
class Handler implements Gateway
{
    use HasSettings;

    /**
     * Register the parameters which are being used by the payment method
     * (e.g. to authenticate against the API).
     */
    public function parameters(): Collection
    {
        return collect([
            'username'   => __('paypal.username'),
            'publickey'  => __('paypal.public_key'),
            'privatekey' => __('paypal.private_key'),
            'api_type'   => __('paypal.api_type'),
        ]);
    }

    /**
     * Get payment method technical name.
     */
    public function technicalName(): string
    {
        return 'paypal';
    }

    /**
     * Get payment method path.
     *
     * @return string
     */
    public function folderName(): string
    {
        return dirname(__FILE__);
    }

    /**
     * Get payment method name.
     */
    public function name(): string
    {
        return 'PayPal';
    }

    /**
     * Get payment method icon src.
     */
    public function icon(): ?string
    {
        return null;
    }

    /**
     * Get payment method status.
     */
    public function status(): bool
    {
        return true;
    }

    /**
     * Initialize a new payment. This should either return a result array or
     * redirect the user directly.
     *
     * @param mixed  $type
     * @param mixed  $method
     * @param mixed  $client
     * @param mixed  $description
     * @param mixed  $identification
     * @param mixed  $payment          Either an invoice object or the amount which the user has to pay
     * @param mixed  $invoice
     * @param mixed  $returnCheckUrl
     * @param mixed  $returnSuccessUrl
     * @param mixed  $returnFailedUrl
     * @param mixed  $returnNeutral
     * @param string $pingbackUrl
     */
    public function initialize($type, $method, $client, $description, $identification, $payment, $invoice, $returnCheckUrl, $returnSuccessUrl, $returnFailedUrl, $returnNeutral, $pingbackUrl): ?array
    {
        $mtid           = $description . '_' . rand('1', '9999999');
        $get            = '?payment=' . $mtid . '&amount=' . $payment;
        $returnCheckUrl = $returnCheckUrl . $get;

        $api   = new PaypalMerchantClient($method);
        $query = $api->buildQuery([
            'PAYMENTACTION' => 'Sale',
            'AMT'           => $payment,
            'RETURNURL'     => $returnCheckUrl,
            'CANCELURL'     => $returnCheckUrl,
            'DESC'          => $description,
            'NOSHIPPING'    => '1',
            'ALLOWNOTE'     => '1',
            'CURRENCYCODE'  => 'EUR',
            'METHOD'        => 'SetExpressCheckout',
            'INVNUM'        => $description,
            'CUSTOM'        => $mtid,
        ]);
        $result = $api->response($query);

        if (! $result) {
            return null;
        }
        $response = $result->getContent();
        $return   = $api->responseParse($response);

        if ($return['ACK'] !== 'Success') {
            return [
                'status'         => 'success',
                'redirect'       => $returnFailedUrl,
                'payment_id'     => $mtid,
                'payment_status' => 'failed',
            ];
        } else {
            $paymentPanel = $api->getGateway() . 'cmd=_express-checkout&useraction=commit&token=' . $return['TOKEN'];

            return [
                'status'         => 'success',
                'redirect'       => $paymentPanel,
                'payment_id'     => $mtid,
                'payment_status' => 'waiting',
            ];
        }
    }

    /**
     * This function is called when the user returns to the page. It may already
     * check for the current payment status.
     *
     * @param mixed $type
     * @param mixed $method
     * @param mixed $client
     */
    public function return($type, $method, $client): array
    {
        $api    = new PaypalMerchantClient($method);
        $return = $api->doPayment();

        if ($return['ACK'] == 'Success') {
            return [
                'status'         => 'success',
                'payment_id'     => $_GET['payment'],
                'payment_status' => 'success',
            ];
        } else {
            return [
                'status'         => 'success',
                'payment_id'     => $_GET['payment'],
                'payment_status' => 'failed',
            ];
        }
    }

    /**
     * This function is called when a pingback is received by the payment service provider.
     * It may already check for the current payment status. Since PayPal doesn't provide
     * pingback functionality this is disabled.
     *
     * @param mixed $type
     * @param mixed $method
     * @param mixed $client
     *
     * @throws Exception
     */
    public function pingback($type, $method, $client): array
    {
        $ipn = new PaypalIPNClient();

        if ($method->api_type == 'test') {
            $ipn->useSandbox();
        }
        $verified = $ipn->verifyIPN();

        if ($verified) {
            if (
                $_POST['payment_status'] == 'Failed' ||
                $_POST['payment_status'] == 'Denied' ||
                $_POST['payment_status'] == 'Expired'
            ) {
                return [
                    'status'         => 'success',
                    'payment_id'     => $_POST['custom'],
                    'payment_status' => 'failed',
                ];
            } elseif (
                $_POST['payment_status'] == 'Refunded' ||
                $_POST['payment_status'] == 'Reversed' ||
                $_POST['payment_status'] == 'Voided'
            ) {
                return [
                    'status'         => 'success',
                    'payment_id'     => $_POST['custom'],
                    'payment_status' => 'revoked',
                ];
            } elseif (
                $_POST['payment_status'] == 'Canceled_Reversal' ||
                $_POST['payment_status'] == 'Completed' ||
                $_POST['payment_status'] == 'Processed'
            ) {
                return [
                    'status'         => 'success',
                    'payment_id'     => $_POST['custom'],
                    'payment_status' => 'success',
                ];
            }
        }

        return [
            'status'         => 'false',
            'payment_id'     => null,
            'payment_status' => null,
        ];
    }
}
