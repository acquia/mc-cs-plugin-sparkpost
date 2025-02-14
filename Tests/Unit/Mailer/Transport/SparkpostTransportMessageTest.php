<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Unit\Mailer\Transport;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\SparkpostBundle\Mailer\Transport\SparkpostTransport;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SparkpostTransportMessageTest extends TestCase
{
    public function testCcAndBccFields(): void
    {
        $emailId           = 1;
        $internalEmailName = '202211_シナリオメール②内視鏡機器提案のご案内';

        // As $internalEmailName is already contain 64 bytes and after prepend $emailId, string bytes will be exceed
        // so for maintain 64 bytes last char will be trimmed.
        $expectedInternalEmailName = '202211_シナリオメール②内視鏡機器提案のご案';

        $transportCallbackMock = $this->createMock(TransportCallback::class);
        $httpClientMock        = $this->createMock(HttpClientInterface::class);
        $eventDispatcherMock   = $this->createMock(EventDispatcherInterface::class);
        $loggerMock            = $this->createMock(LoggerInterface::class);

        $sparkpost = new SparkpostTransport(
            '1234',
            'us',
            $transportCallbackMock,
            $httpClientMock,
            $eventDispatcherMock,
            $loggerMock
        );

        $message = new MauticMessage();
        $message->addFrom('from@xx.xx');

        $message->addTo('to1@xx.xx');
        $message->addTo('to2@xx.xx');

        $message->addCc('cc1@xx.xx');
        $message->addCc('cc2@xx.xx');

        $message->addBcc('bcc1@xx.xx');
        $message->addBcc('bcc2@xx.xx');

        $message->subject('Test subject');
        $message->html('First Name: {formfield=first_name}');
        $message->addReplyTo('reply-to1@xx.xx');

        $message->addMetadata(
            'to1@xx.xx',
            ['tokens' => ['{formfield=first_name}' => '1'], 'emailId' => $emailId, 'emailName' => $internalEmailName] // @phpstan-ignore-line
        );

        $message->addMetadata('to2@xx.xx', ['tokens' => ['{formfield=first_name}' => '2']]); // @phpstan-ignore-line

        $sentMessageMock = $this->createMock(SentMessage::class);
        $sentMessageMock->method('getOriginalMessage')
            ->willReturn($message);

        $payload = $this->invokeInaccessibleMethod($sparkpost, 'getSparkpostPayload', [$sentMessageMock]);
        Assert::assertEquals(sprintf('%s:%s', $emailId, $expectedInternalEmailName), $payload['campaign_id']);
        Assert::assertEquals('from@xx.xx', $payload['content']['from']);
        Assert::assertEquals('Test subject', $payload['content']['subject']);
        Assert::assertEquals('First Name: {{{ FORMFIELDFIRSTNAME }}}', $payload['content']['html']);
        Assert::assertCount(10, $payload['recipients']);

        // CC and BCC fields has to be included as normal recipient with same data as TO fields has
        $recipients = [
            [
                'address'           => [
                    'email' => 'to1@xx.xx',
                    'name'  => null,
                ],
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '1',
                ],
                'metadata'          => [
                    'emailId'   => $emailId,
                    'emailName' => $internalEmailName,
                ],
            ],
            [
                'address'           => [
                    'email' => 'cc1@xx.xx',
                ],
                'header_to'         => 'to1@xx.xx',
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '1',
                ],
            ],
            [
                'address'           => [
                    'email' => 'cc2@xx.xx',
                ],
                'header_to'         => 'to1@xx.xx',
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '1',
                ],
            ],
            [
                'address'           => [
                    'email' => 'bcc1@xx.xx',
                ],
                'header_to'         => 'to1@xx.xx',
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '1',
                ],
            ],
            [
                'address'           => [
                    'email' => 'bcc2@xx.xx',
                ],
                'header_to'         => 'to1@xx.xx',
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '1',
                ],
            ],
            [
                'address'           => [
                    'email' => 'to2@xx.xx',
                    'name'  => null,
                ],
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '2',
                ],
            ],
            [
                'address'           => [
                    'email' => 'cc1@xx.xx',
                ],
                'header_to'         => 'to2@xx.xx',
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '2',
                ],
            ],
            [
                'address'           => [
                    'email' => 'cc2@xx.xx',
                ],
                'header_to'         => 'to2@xx.xx',
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '2',
                ],
            ],
            [
                'address'           => [
                    'email' => 'bcc1@xx.xx',
                ],
                'header_to'         => 'to2@xx.xx',
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '2',
                ],
            ],
            [
                'address'           => [
                    'email' => 'bcc2@xx.xx',
                ],
                'header_to'         => 'to2@xx.xx',
                'substitution_data' => [
                    'FORMFIELDFIRSTNAME' => '2',
                ],
            ],
        ];

        Assert::assertEquals($recipients, $payload['recipients']);
    }

    /**
     * @param array<mixed> $args
     *
     * @throws \ReflectionException
     */
    private function invokeInaccessibleMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method     = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
