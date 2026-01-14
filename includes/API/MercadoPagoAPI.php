<?php

namespace MercadoPagoFluentCart\API;

use MercadoPagoFluentCart\Settings\MercadoPagoSettingsBase;

if (!defined('ABSPATH')) {
    exit;
}


class MercadoPagoAPI
{
    private static $baseUrl = 'https://api.mercadopago.com/';
    private static $settings = null;

    public static function getSettings()
    {
        if (!self::$settings) {
            self::$settings = new MercadoPagoSettingsBase();
        }
        return self::$settings;
    }


    private static function request($endpoint, $method = 'GET', $data = [])
    {
        if (empty($endpoint) || !is_string($endpoint)) {
            return new \WP_Error('invalid_endpoint', 'Invalid API endpoint provided');
        }

        
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            return new \WP_Error('invalid_method', 'Invalid HTTP method');
        }

        $url = self::$baseUrl . $endpoint;
        $accessToken = self::getSettings()->getAccessToken();

        if (!$accessToken) {
            return new \WP_Error('missing_api_key', 'Mercado Pago Access Token is not configured');
        }

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'MercadoPagoFluentCart/1.0.0 WordPress/' . get_bloginfo('version'),
                'X-Idempotency-Key' => wp_generate_uuid4(), // For payment safety
            ],
            'timeout' => 30,
            'sslverify' => true, // Always verify SSL
        ];

        if (in_array($method, ['POST', 'PUT']) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        } elseif (in_array($method, ['GET', 'DELETE']) && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $statusCode = wp_remote_retrieve_response_code($response);

        // give generic error on 500 status code
        if ($statusCode === 500) {
            return new \WP_Error('mercadopago_api_error', __('Mercado Pago API is temporarily unavailable. Please try again later.', 'mercado-pago-for-fluent-cart'), ['status' => $statusCode, 'response' => $decoded]);
        }
        else if ($statusCode >= 400) {
            $errorMessage = 'Unknown Mercado Pago API error';
            
            if (isset($decoded['message'])) {
                $errorMessage = $decoded['message'];
            } elseif (isset($decoded['error'])) {
                $errorMessage = $decoded['error'];
            } elseif (isset($decoded['cause'][0]['description'])) {
                $errorMessage = $decoded['cause'][0]['description'];
            }
            
            return new \WP_Error(
                'mercadopago_api_error',
                $errorMessage,
                ['status' => $statusCode, 'response' => $decoded]
            );
        }

        return $decoded;
    }


    public static function getMercadoPagoObject($endpoint, $params = [])
    {
        return self::request($endpoint, 'GET', $params);
    }

    public static function createMercadoPagoObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'POST', $data);
    }

    public static function updateMercadoPagoObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'PUT', $data);
    }

    public static function deleteMercadoPagoObject($endpoint, $data = [])
    {
        return self::request($endpoint, 'DELETE', $data);
    }
}

