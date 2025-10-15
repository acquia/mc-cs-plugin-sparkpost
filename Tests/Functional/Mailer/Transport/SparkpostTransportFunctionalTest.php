<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Functional\Mailer\Transport;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\SparkpostBundle\Mailer\Transport\SparkpostTransport;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SparkpostTransportFunctionalTest extends MauticMysqlTestCase
{
    private SparkpostTransport $sparkpostTranport;
    private HttpClientInterface $mockHttpClient;

    protected function setUp(): void
    {
        $this->configParams['mailer_dsn']          = 'mautic+sparkpost+api://:test_api_key@default?region=us';
        $this->configParams['messenger_dsn_email'] = 'sync://';

        parent::setUp();

        $this->mockHttpClient = self::getContainer()->get(HttpClientInterface::class);

        // Create the transport directly since it's not registered as a service
        $callback             = self::getContainer()->get('mautic.email.model.transport_callback');
        $coreParametersHelper = self::getContainer()->get('mautic.helper.core_parameters');

        $this->sparkpostTranport = new SparkpostTransport(
            'test_api_key',
            'us',
            $callback,
            $coreParametersHelper,
            $this->mockHttpClient
        );
    }

    public function testTemplateValidationRequestFailsDuringSendWithNull(): void
    {
        /** @var MockHttpClient $mockHttpClient */
        $mockHttpClient = $this->mockHttpClient;
        $mockHttpClient->setResponseFactory([
            function (string $method, string $url, array $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/utils/content-previewer/', $url);
                Assert::assertSame('{"content":{"from":"John Doe \u003Cjohn@doe.email\u003E","subject":"Subject line","headers":{},"html":"Hello, John","text":"Hello, John","reply_to":"john@doe.email","attachments":[]},"inline_css":null,"tags":[],"campaign_id":"","options":{"open_tracking":false,"click_tracking":false},"substitution_data":{}}', $options['body']);

                return new MockResponse('invalid json');
            },
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Invalid Sparkpost JSON response. JSON error: "Syntax error", HTTP status code: 200, HTTP response string: "invalid json"');

        $this->sparkpostTranport->send($this->createTestEmail());
    }

    /**
     * Creates a test email using Mautic's MauticMessage.
     */
    private function createTestEmail(): MauticMessage
    {
        $email = new MauticMessage();
        $email->from(new Address('john@doe.email', 'John Doe'))
            ->to(new Address('recipient@example.com'))
            ->subject('Subject line')
            ->html('Hello, John')
            ->text('Hello, John');

        return $email;
    }
}
