<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Functional\Mailer\Transport;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SparkpostTransportTest extends MauticMysqlTestCase
{
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        $this->configParams['mailer_dsn']            = 'mautic+sparkpost+api://:some_api@some_host:25?region=us';
        $this->configParams['messenger_dsn_email']   = 'sync://';
        $this->configParams['mailer_custom_headers'] = ['x-global-custom-header' => 'value123'];
        $this->configParams['mailer_from_email']     = 'admin@mautic.test';
        $this->configParams['mailer_from_name']      = 'Admin';
        parent::setUp();
        $this->translator = self::getContainer()->get('translator');
    }

    public function testEmailSendToContactSync(): void
    {
        $expectedResponses = [
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/utils/content-previewer/', $url);
                $this->assertSparkpostRequestBody($options['body']);

                return new MockResponse('{"results": {"subject": "Hello there!", "html": "This is test body for {contactfield=email}!"}}');
            },
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/transmissions/', $url);
                $this->assertSparkpostRequestBody($options['body']);

                return new MockResponse('{"results": {"total_rejected_recipients": 0, "total_accepted_recipients": 1, "id": "11668787484950529"}}');
            },
        ];

        /** @var MockHttpClient $mockHttpClient */
        $mockHttpClient = self::getContainer()->get(HttpClientInterface::class);
        $mockHttpClient->setResponseFactory($expectedResponses);

        $contact = $this->createContact('contact@an.email');
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, "/s/contacts/email/{$contact->getId()}");
        Assert::assertTrue($this->client->getResponse()->isOk());
        $newContent = json_decode($this->client->getResponse()->getContent(), true)['newContent'];
        $crawler    = new Crawler($newContent, $this->client->getInternalRequest()->getUri());
        $form       = $crawler->selectButton('Send')->form();
        $form->setValues(
            [
                'lead_quickemail[subject]' => 'Hello there!',
                'lead_quickemail[body]'    => 'This is test body for {contactfield=email}!',
            ]
        );
        $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk());
        self::assertQueuedEmailCount(1);

        $email      = self::getMailerMessage();
        $userHelper = static::getContainer()->get(UserHelper::class);
        $user       = $userHelper->getUser();

        Assert::assertSame('Hello there!', $email->getSubject());
        Assert::assertStringContainsString('This is test body for {contactfield=email}!', $email->getHtmlBody());
        Assert::assertSame('This is test body for {contactfield=email}!', $email->getTextBody());
        /** @phpstan-ignore-next-line */
        Assert::assertSame('contact@an.email', $email->getMetadata()['contact@an.email']['tokens']['{contactfield=email}']);
        Assert::assertCount(1, $email->getFrom());
        Assert::assertSame($user->getName(), $email->getFrom()[0]->getName());
        Assert::assertSame($user->getEmail(), $email->getFrom()[0]->getAddress());
        Assert::assertCount(1, $email->getTo());
        Assert::assertSame('', $email->getTo()[0]->getName());
        Assert::assertSame($contact->getEmail(), $email->getTo()[0]->getAddress());
        Assert::assertCount(1, $email->getReplyTo());
        Assert::assertSame('', $email->getReplyTo()[0]->getName());
    }

    public function testTestTransportButton(): void
    {
        $expectedResponses = [
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/utils/content-previewer/', $url);
                $this->assertSparkpostTestRequestBody($options['body']);

                return new MockResponse('{"results": {"subject": "Hello there!", "html": "This is test body for {contactfield=email}!"}}');
            },
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/transmissions/', $url);
                $this->assertSparkpostTestRequestBody($options['body']);

                return new MockResponse('{"results": {"total_rejected_recipients": 0, "total_accepted_recipients": 1, "id": "11668787484950529"}}');
            },
        ];

        /** @var MockHttpClient $mockHttpClient */
        $mockHttpClient = self::getContainer()->get(HttpClientInterface::class);
        $mockHttpClient->setResponseFactory($expectedResponses);
        $this->client->request(Request::METHOD_GET, '/s/ajax?action=email:sendTestEmail');
        Assert::assertTrue($this->client->getResponse()->isOk());
        Assert::assertSame('{"success":1,"message":"Success!"}', $this->client->getResponse()->getContent());
    }

    private function assertSparkpostTestRequestBody(string $body): void
    {
        $bodyArray = json_decode($body, true);
        Assert::assertSame('Admin <admin@mautic.test>', $bodyArray['content']['from']);
        Assert::assertNull($bodyArray['content']['html']);
        Assert::assertSame('admin@mautic.test', $bodyArray['content']['reply_to']);
        Assert::assertSame('Mautic test email', $bodyArray['content']['subject']);
        Assert::assertSame('Hi! This is a test email from Mautic. Testing...testing...1...2...3!', $bodyArray['content']['text']);
    }

    private function assertSparkpostRequestBody(string $body): void
    {
        $bodyArray = json_decode($body, true);
        Assert::assertSame('Admin User <admin@yoursite.com>', $bodyArray['content']['from']);
        Assert::assertSame('value123', $bodyArray['content']['headers']['x-global-custom-header']);
        Assert::assertSame('This is test body for {{{ CONTACTFIELDEMAIL }}}!<img height="1" width="1" src="{{{ TRACKINGPIXEL }}}" alt="" />', $bodyArray['content']['html']);
        Assert::assertSame('admin@mautic.test', $bodyArray['content']['reply_to']);
        Assert::assertSame('Hello there!', $bodyArray['content']['subject']);
        Assert::assertSame('This is test body for {{{ CONTACTFIELDEMAIL }}}!', $bodyArray['content']['text']);
        Assert::assertSame(['open_tracking' => false, 'click_tracking' => false], $bodyArray['options']);
        Assert::assertSame('contact@an.email', $bodyArray['substitution_data']['CONTACTFIELDEMAIL']);
        Assert::assertSame('Hello there!', $bodyArray['substitution_data']['SUBJECT']);
        Assert::assertArrayHasKey('SIGNATURE', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('TRACKINGPIXEL', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('UNSUBSCRIBETEXT', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('UNSUBSCRIBEURL', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('WEBVIEWTEXT', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('WEBVIEWURL', $bodyArray['substitution_data']);
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);

        $this->em->persist($lead);

        return $lead;
    }

    /**
     * @dataProvider dataInvalidDsn
     *
     * @param array<string, string> $data
     */
    public function testInvalidDsn(array $data, string $expectedMessage): void
    {
        // Request config edit page
        $crawler = $this->client->request(Request::METHOD_GET, '/s/config/edit');
        Assert::assertTrue($this->client->getResponse()->isOk());

        // Set form data
        $form = $crawler->selectButton('config[buttons][save]')->form();
        $form->setValues($data + ['config[leadconfig][contact_columns]' => ['name', 'email', 'id']]);

        // Check if there is the given validation error
        $crawler = $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk());
        Assert::assertStringContainsString($this->translator->trans($expectedMessage, [], 'validators'), $crawler->text());
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataInvalidDsn(): iterable
    {
        yield 'Empty region' => [
            [
                'config[emailconfig][mailer_dsn][options][list][0][value]' => '',
            ],
            'mautic.sparkpost.plugin.region.empty',
        ];

        yield 'Invalid region' => [
            [
                'config[emailconfig][mailer_dsn][options][list][0][value]' => 'invalid_region',
            ],
            'mautic.sparkpost.plugin.region.invalid',
        ];
    }
}
