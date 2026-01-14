<?php

namespace MercadoPagoFluentCart\Refund;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;
use FluentCart\Api\CurrencySettings;
use MercadoPagoFluentCart\API\MercadoPagoAPI;
use MercadoPagoFluentCart\MercadoPagoHelper;


if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class MercadoPagoRefund
{

    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $mercadoPagoPaymentId = $transaction->vendor_charge_id;

        if (!$mercadoPagoPaymentId) {
            return new \WP_Error(
                'mercadopago_refund_error',
                __('Payment ID not found for refund', 'mercado-pago-for-fluent-cart')
            );
        }


        if ($amount <= 0) {
            return new \WP_Error(
                'mercadopago_refund_error',
                __('Refund amount must be greater than zero', 'mercado-pago-for-fluent-cart')
            );
        }

        $payment = MercadoPagoAPI::getMercadoPagoObject('v1/payments/' . $mercadoPagoPaymentId);
        
        if (is_wp_error($payment)) {
            return new \WP_Error(
                'mercadopago_refund_error',
                __('Failed to fetch payment details: ', 'mercado-pago-for-fluent-cart') . $payment->get_error_message()
            );
        }

        $paymentStatus = Arr::get($payment, 'status');

        if ($paymentStatus !== 'approved') {
            return new \WP_Error(
                'mercadopago_refund_error',
                sprintf(
                    __('Payment must be approved to process refund. Current status: %s', 'mercado-pago-for-fluent-cart'),
                    $paymentStatus
                )
            );
        }

        $currency = $transaction->currency ?: CurrencySettings::get('currency');
        $formattedAmount = MercadoPagoHelper::formatAmount($amount, $currency);

        $totalPaid = (float) Arr::get($payment, 'transaction_amount', 0);
        $totalRefunded = (float) Arr::get($payment, 'transaction_details.total_refunded', 0);
        $availableRefund = $totalPaid - $totalRefunded;

        if ($formattedAmount > $availableRefund) {
            return new \WP_Error(
                'mercadopago_refund_error',
                sprintf(
                    __('Refund amount (%.2f) exceeds available refund amount (%.2f)', 'mercado-pago-for-fluent-cart'),
                    $formattedAmount,
                    $availableRefund
                )
            );
        }

        $refundData = [
            'amount' => $formattedAmount,
        ];


        $refund = MercadoPagoAPI::createMercadoPagoObject(
            'v1/payments/' . $mercadoPagoPaymentId . '/refunds',
            $refundData
        );

        if (is_wp_error($refund)) {
            $errorMessage = $refund->get_error_message();
            fluent_cart_add_log(
                'MercadoPago Refund Error',
                'ERROR processing refund on Mercado_pago: ' . $errorMessage,
                'error',
                [
                    'log_type' => 'payment',
                    'payment_id' => $mercadoPagoPaymentId,
                    'amount' => $formattedAmount,
                    'currency' => $currency
                ]
            );
            return $refund;
        }

        $refundId = Arr::get($refund, 'id');
        $refundStatus = Arr::get($refund, 'status');

        if (!$refundId) {
            return new \WP_Error(
                'refund_failed', 
                __('Refund could not be processed in Mercado Pago. Please check your Mercado Pago account', 'mercado-pago-for-fluent-cart')
            );
        }

        $acceptedStatus = ['approved', 'in_process', 'authorized'];

        if (!in_array($refundStatus, $acceptedStatus)) {
            return new \WP_Error(
                'refund_failed', 
                sprintf(
                    __('Refund was rejected with status: %s. Please check your Mercado Pago account', 'mercado-pago-for-fluent-cart'),
                    $refundStatus
                )
            );
        }

        fluent_cart_add_log(
            'MercadoPago Refund Success',
            'Refund processed successfully. Refund ID: ' . $refundId,
            'info',
            [
                'log_type' => 'payment',
                'payment_id' => $mercadoPagoPaymentId,
                'refund_id' => $refundId,
                'amount' => $formattedAmount,
                'status' => $refundStatus
            ]
        );

        return $refundId;
    }

    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            $createdRefund = OrderTransaction::query()->create($refundData);
            return $createdRefund instanceof OrderTransaction ? $createdRefund : null;
        }

        $currentRefundMercadoPagoId = Arr::get($refundData, 'vendor_charge_id', '');

        $existingLocalRefund = null;
        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }

                return $refund;
            }

            if (!$refund->vendor_charge_id) { // This is a local refund without vendor charge id
                $refundMercadoPagoId = Arr::get($refund->meta, 'mercadopago_refund_id', '');
                $isRefundMatched = $refundMercadoPagoId == $currentRefundMercadoPagoId;

                // This is a local refund without vendor charge id, we will update it
                if ($refund->total == $refundData['total'] && $isRefundMatched) {
                    $existingLocalRefund = $refund;
                }
            }
        }

        if ($existingLocalRefund) {
            $existingLocalRefund->fill($refundData);
            $existingLocalRefund->save();
            return $existingLocalRefund;
        }

        $createdRefund = OrderTransaction::query()->create($refundData);
        PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);

        return $createdRefund;
    }

}

