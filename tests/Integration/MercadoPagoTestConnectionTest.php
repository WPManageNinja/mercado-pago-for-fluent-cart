<?php

namespace Tests\Integration;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;
use MercadoPagoFluentCart\MercadoPagoGateway;

class MercadoPagoTestConnectionTest extends WPTestCase
{
    private const TEST_TOKEN = 'APP_USR-1111111111111111-111111-11111111111111111111111111111111-111111111';
    private const LIVE_TOKEN = 'APP_USR-2222222222222222-222222-22222222222222222222222222222222-222222222';

    private $httpMock;

    /** @var array<int, array{url:string, method:string, auth:string}> */
    private array $calls = [];

    /** @var array{0:int, 1:array} */
    private array $reply = [200, []];

    public function setUp(): void
    {
        parent::setUp();

        $this->httpMock = function ($preempt, $args, $url) {
            $this->calls[] = [
                'url'    => $url,
                'method' => (string) ($args['method'] ?? ''),
                'auth'   => (string) ($args['headers']['Authorization'] ?? ''),
            ];

            return [
                'headers'  => [],
                'body'     => wp_json_encode($this->reply[1]),
                'response' => ['code' => $this->reply[0], 'message' => ''],
                'cookies'  => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $this->httpMock, 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->httpMock, 10);
        parent::tearDown();
    }

    private function gateway(string $testToken, string $liveToken): MercadoPagoGateway
    {
        $gateway = new MercadoPagoGateway();
        $gateway->settings->settings['test_access_token'] = $testToken === '' ? '' : Helper::encryptKey($testToken);
        $gateway->settings->settings['live_access_token'] = $liveToken === '' ? '' : Helper::encryptKey($liveToken);

        return $gateway;
    }

    public function test_core_shows_the_button_for_mercado_pago(): void
    {
        $this->assertTrue(GlobalPaymentHandler::canTestConnection(new MercadoPagoGateway()));
    }

    public function test_accepts_the_saved_token_of_the_mode(): void
    {
        $this->reply = [200, [['id' => 'pix', 'payment_type_id' => 'bank_transfer', 'status' => 'active']]];
        $gateway = $this->gateway(self::TEST_TOKEN, self::LIVE_TOKEN);

        $test = $gateway->testConnection('test');
        $live = $gateway->testConnection('live');

        $this->assertSame('Mercado Pago accepted the test access token.', $test['message']);
        $this->assertSame('Mercado Pago accepted the live access token.', $live['message']);
        $this->assertCount(2, $this->calls);
        $this->assertSame('https://api.mercadopago.com/v1/payment_methods', $this->calls[0]['url']);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('Bearer ' . self::TEST_TOKEN, $this->calls[0]['auth']);
        $this->assertSame('Bearer ' . self::LIVE_TOKEN, $this->calls[1]['auth']);
    }

    public function test_reports_a_missing_token_without_calling_mercado_pago(): void
    {
        $gateway = $this->gateway('', '');

        $live = $gateway->testConnection('live');
        $test = $gateway->testConnection('test');

        $this->assertWPError($live);
        $this->assertSame('missing_credentials', $live->get_error_code());
        $this->assertSame('No live access token is saved.', $live->get_error_message());
        $this->assertSame('No test access token is saved.', $test->get_error_message());

        unset($gateway->settings->settings['live_access_token']);
        $this->assertSame('missing_credentials', $gateway->testConnection('live')->get_error_code());
        $this->assertSame([], $this->calls);
    }

    public function test_reports_a_token_that_cannot_be_decrypted_without_calling_mercado_pago(): void
    {
        $gateway = new MercadoPagoGateway();
        $gateway->settings->settings['live_access_token'] = base64_encode(str_repeat('x', 64));

        $result = $gateway->testConnection('live');

        $this->assertWPError($result);
        $this->assertSame('unreadable_credentials', $result->get_error_code());
        $this->assertSame('The saved live access token cannot be read. Paste it again and save.', $result->get_error_message());
        $this->assertSame([], $this->calls);
    }

    public function test_passes_on_the_api_error(): void
    {
        $this->reply = [400, ['message' => 'invalid_token', 'error' => 'bad_request', 'status' => 400, 'cause' => []]];

        $result = $this->gateway(self::TEST_TOKEN, '')->testConnection('test');

        $this->assertWPError($result);
        $this->assertSame('invalid_token', $result->get_error_message());
        $this->assertCount(1, $this->calls);
    }

    public function test_rejects_a_test_token_saved_as_the_live_token(): void
    {
        $result = $this->gateway('', 'TEST-3333333333333333-333333-33333333333333333333333333333333-333333333')->testConnection('live');

        $this->assertWPError($result);
        $this->assertSame('mode_mismatch', $result->get_error_code());
        $this->assertSame([], $this->calls);
    }
}
