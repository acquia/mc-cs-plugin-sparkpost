<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Unit\Mailer\Factory;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostTransportFactory;
use MauticPlugin\SparkpostBundle\Mailer\Transport\SparkpostTransport;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SparkpostTransportFactoryTest extends TestCase
{
    private SparkpostTransportFactory $sparkpostTransportFactory;

    /**
     * @var TranslatorInterface&MockObject
     */
    private MockObject $translatorMock;

    /**
     * @var CoreParametersHelper&MockObject
     */
    private MockObject $coreParametersHelper;

    protected function setUp(): void
    {
        $eventDispatcherMock        = $this->createMock(EventDispatcherInterface::class);
        $this->translatorMock       = $this->createMock(TranslatorInterface::class);
        $transportCallbackMock      = $this->createMock(TransportCallback::class);
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $httpClientMock             = $this->createMock(HttpClientInterface::class);
        $loggerMock                 = $this->createMock(LoggerInterface::class);

        $this->sparkpostTransportFactory = new SparkpostTransportFactory(
            $transportCallbackMock,
            $this->translatorMock,
            $this->coreParametersHelper,
            $eventDispatcherMock,
            $httpClientMock,
            $loggerMock
        );
    }

    public function testCreateTransport(): void
    {
        $dsn = new Dsn(
            SparkpostTransport::MAUTIC_SPARKPOST_API_SCHEME,
            'host',
            null,
            'sparkpost_api_key',
            null,
            ['region' => 'us']
        );
        $sparkpostTransport = $this->sparkpostTransportFactory->create($dsn);
        Assert::assertInstanceOf(SparkpostTransport::class, $sparkpostTransport);
    }

    public function testUnsupportedScheme(): void
    {
        $this->expectException(UnsupportedSchemeException::class);
        $this->expectExceptionMessage('The "some+unsupported+scheme" scheme is not supported; supported schemes for mailer "sparkpost" are: "mautic+sparkpost+api".');
        $dsn = new Dsn(
            'some+unsupported+scheme',
            'host',
            null,
            'sparkpost_api_key',
            null,
            ['region' => 'us']
        );
        $this->sparkpostTransportFactory->create($dsn);
    }

    public function testEmptySparkpostRegion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Sparkpost region is empty. Add 'region' as a option.");

        $this->translatorMock->expects(self::once())
            ->method('trans')
            ->with('mautic.sparkpost.plugin.region.empty', [], 'validators')
            ->willReturn("Sparkpost region is empty. Add 'region' as a option.");

        $dsn = new Dsn(
            'mautic+sparkpost+api',
            'host',
            null,
            'sparkpost_api_key',
            null,
        );
        $this->sparkpostTransportFactory->create($dsn);
    }

    public function testInvalidSparkpostRegion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Sparkpost region is invalid. Add 'us' or 'eu' as a suitable region.");

        $this->translatorMock->expects(self::once())
            ->method('trans')
            ->with('mautic.sparkpost.plugin.region.invalid', [], 'validators')
            ->willReturn("Sparkpost region is invalid. Add 'us' or 'eu' as a suitable region.");

        $dsn = new Dsn(
            'mautic+sparkpost+api',
            'host',
            null,
            'sparkpost_api_key',
            null,
            ['region' => 'some_invalid_region']
        );
        $this->sparkpostTransportFactory->create($dsn);
    }
}
