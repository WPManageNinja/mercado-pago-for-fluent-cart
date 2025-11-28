<?php

namespace MercadoPagoFluentCart\Refund;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;
use MercadoPagoFluentCart\API\MercadoPagoAPI;


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

        $refundData = [
            'amount' => $amount,
        ];

        // Add metadata if available
        if (!empty($args['note'])) {
            $refundData['metadata'] = [
                'reason' => $args['note']
            ];
        }

        // Create refund via Mercado Pago API
        $refund = MercadoPagoAPI::createMercadoPagoObject(
            'v1/payments/' . $mercadoPagoPaymentId . '/refunds',
            $refundData
        );

        if (is_wp_error($refund)) {
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

        // Mercado Pago refund statuses: approved, pending, rejected
        $acceptedStatus = ['approved', 'pending'];

        if (!in_array($refundStatus, $acceptedStatus)) {
            return new \WP_Error(
                'refund_failed', 
                __('Refund was rejected. Please check your Mercado Pago account', 'mercado-pago-for-fluent-cart')
            );
        }

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

