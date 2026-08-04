<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Mail\AbstractMailer;
use Dynart\Dpress\Mail\Mail;
use Dynart\Dpress\Mail\NativeMailer;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubView;
use Dynart\Micro\MicroException;
use PHPUnit\Framework\TestCase;

/**
 * A mailer that keeps what it was handed instead of sending it
 */
class SpyMailer extends AbstractMailer {
    public ?Mail $delivered = null;
    public bool $result = true;
    protected function deliver(Mail $mail): bool {
        $this->delivered = $mail;
        return $this->result;
    }
}

/**
 * @covers \Dynart\Dpress\Mail\AbstractMailer
 * @covers \Dynart\Dpress\Mail\Mail
 * @covers \Dynart\Dpress\Mail\NativeMailer
 */
class MailerTest extends TestCase {

    private RecordingEvents $events;

    protected function setUp(): void {
        $this->events = new RecordingEvents();
    }

    private function mailer(array $templates, array $config = []): SpyMailer {
        return new SpyMailer(new StubView($templates), new StubConfig($config), $this->events);
    }

    private function bothBodies(): array {
        return [
            'mail/reset'     => '<p>Hello {name}</p>',
            'mail/reset.txt' => 'Hello {name}',
        ];
    }

    // --- Rendering ---

    public function testRendersTheHtmlBody(): void {
        $mailer = $this->mailer(['mail/reset' => '<p>Hello {name}</p>']);
        $mail = $mailer->create('Joe', 'joe@example.com', 'Subject', 'mail/reset', ['name' => 'Joe']);
        $this->assertSame('<p>Hello Joe</p>', $mail->htmlBody);
    }

    /**
     * The text body is `<template>.txt.phtml`, which the view resolves once it appends the
     * extension - so the suffix added here is just `.txt`.
     */
    public function testRendersTheTextBodyFromTheTxtTemplate(): void {
        $mailer = $this->mailer($this->bothBodies());
        $mail = $mailer->create('Joe', 'joe@example.com', 'Subject', 'mail/reset', ['name' => 'Joe']);
        $this->assertSame('Hello Joe', $mail->textBody);
        $this->assertTrue($mail->hasTextBody());
    }

    public function testTheTextBodyIsOptional(): void {
        $mailer = $this->mailer(['mail/reset' => '<p>Hi</p>']);
        $mail = $mailer->create('Joe', 'joe@example.com', 'Subject', 'mail/reset');
        $this->assertNull($mail->textBody);
        $this->assertFalse($mail->hasTextBody());
    }

    public function testBothTemplatesGetTheSameVariables(): void {
        $view = new StubView($this->bothBodies());
        $mailer = new SpyMailer($view, new StubConfig(), $this->events);
        $mailer->create('Joe', 'joe@example.com', 'Subject', 'mail/reset', ['name' => 'Joe']);
        $this->assertCount(2, $view->fetched);
        $this->assertSame(['name' => 'Joe'], $view->fetched[0]['vars']);
        $this->assertSame(['name' => 'Joe'], $view->fetched[1]['vars']);
    }

    public function testAMissingHtmlTemplateThrows(): void {
        $mailer = $this->mailer([]);
        $this->expectException(MicroException::class);
        $mailer->create('Joe', 'joe@example.com', 'Subject', 'mail/nosuch');
    }

    // --- Addresses ---

    public function testFromComesFromTheConfig(): void {
        $mailer = $this->mailer(['t' => 'x'], [
            AbstractMailer::CONFIG_FROM_EMAIL => 'site@example.com',
            AbstractMailer::CONFIG_FROM_NAME  => 'The Site',
        ]);
        $mail = $mailer->create('Joe', 'joe@example.com', 'Subject', 't');
        $this->assertSame('The Site <site@example.com>', $mail->from());
    }

    public function testAddressWithoutANameIsJustTheEmail(): void {
        $this->assertSame('joe@example.com', Mail::address('joe@example.com'));
    }

    /**
     * An unencoded non-ASCII name arrives as mojibake.
     */
    public function testANonAsciiNameIsEncoded(): void {
        $address = Mail::address('joe@example.com', 'Gábor');
        $this->assertStringStartsWith('=?UTF-8?B?', $address);
        $this->assertStringEndsWith('<joe@example.com>', $address);
    }

    public function testAnAsciiNameIsLeftAlone(): void {
        $this->assertSame('Joe <joe@example.com>', Mail::address('joe@example.com', 'Joe'));
    }

    public function testReplyToIsEmptyWhenNotConfigured(): void {
        $mailer = $this->mailer(['t' => 'x']);
        $this->assertSame('', $mailer->create('Joe', 'joe@example.com', 'S', 't')->replyTo());
    }

    // --- Events ---

    public function testSendEmitsBeforeAndSent(): void {
        $mailer = $this->mailer(['t' => 'x']);
        $this->assertTrue($mailer->send('Joe', 'joe@example.com', 'Subject', 't'));
        $this->assertContains(AbstractMailer::EVENT_BEFORE_SEND, $this->events->emitted);
        $this->assertContains(AbstractMailer::EVENT_SENT, $this->events->emitted);
    }

    public function testSendEmitsFailedWhenTheTransportRefuses(): void {
        $mailer = $this->mailer(['t' => 'x']);
        $mailer->result = false;
        $this->assertFalse($mailer->send('Joe', 'joe@example.com', 'Subject', 't'));
        $this->assertContains(AbstractMailer::EVENT_FAILED, $this->events->emitted);
        $this->assertNotContains(AbstractMailer::EVENT_SENT, $this->events->emitted);
    }

    /**
     * The before event is where a subscriber gets to change the mail, so it has to carry the
     * rendered one and fire before the transport sees it.
     */
    public function testASubscriberCanChangeTheMailBeforeItGoesOut(): void {
        $mailer = $this->mailer(['t' => 'x']);
        $this->events->subscribe(AbstractMailer::EVENT_BEFORE_SEND, function(Mail $mail) {
            $mail->subject = '[tagged] '.$mail->subject;
        });
        $mailer->send('Joe', 'joe@example.com', 'Subject', 't');
        $this->assertSame('[tagged] Subject', $mailer->delivered->subject);
    }

    public function testCreateDoesNotEmitOrDeliver(): void {
        $mailer = $this->mailer(['t' => 'x']);
        $mailer->create('Joe', 'joe@example.com', 'Subject', 't');
        $this->assertSame([], $this->events->emitted);
        $this->assertNull($mailer->delivered);
    }

    // --- NativeMailer MIME ---

    private function nativeMailer(array $templates): NativeMailer {
        return new NativeMailer(new StubView($templates), new StubConfig(), $this->events);
    }

    public function testHtmlOnlyMailIsNotMultipart(): void {
        $mailer = $this->nativeMailer(['t' => '<p>Hi</p>']);
        [$headers, $body] = $mailer->build($mailer->create('Joe', 'joe@example.com', 'S', 't'));
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $headers);
        $this->assertStringNotContainsString('multipart/alternative', $headers);
        $this->assertSame('<p>Hi</p>', base64_decode($body));
    }

    public function testMailWithBothBodiesIsMultipartAlternative(): void {
        $mailer = $this->nativeMailer(['t' => '<p>Hi</p>', 't.txt' => 'Hi']);
        [$headers, $body] = $mailer->build($mailer->create('Joe', 'joe@example.com', 'S', 't'));
        $this->assertStringContainsString('multipart/alternative; boundary="', $headers);
        $this->assertStringContainsString('text/plain; charset=UTF-8', $body);
        $this->assertStringContainsString('text/html; charset=UTF-8', $body);
    }

    /**
     * A client shows the last part it can display, so the HTML has to come after the text -
     * otherwise everyone reads the plain text version.
     */
    public function testTheTextPartComesBeforeTheHtmlPart(): void {
        $mailer = $this->nativeMailer(['t' => '<p>Hi</p>', 't.txt' => 'Hi']);
        [, $body] = $mailer->build($mailer->create('Joe', 'joe@example.com', 'S', 't'));
        $this->assertLessThan(
            strpos($body, 'text/html'),
            strpos($body, 'text/plain'),
            'the text part has to come first'
        );
    }

    public function testTheMultipartBodyEndsWithTheClosingBoundary(): void {
        $mailer = $this->nativeMailer(['t' => '<p>Hi</p>', 't.txt' => 'Hi']);
        [$headers, $body] = $mailer->build($mailer->create('Joe', 'joe@example.com', 'S', 't'));
        preg_match('/boundary="([^"]+)"/', $headers, $matches);
        $this->assertStringContainsString('--'.$matches[1].'--', $body);
    }

    public function testReplyToHeaderIsAddedWhenConfigured(): void {
        $mailer = new NativeMailer(
            new StubView(['t' => 'x']),
            new StubConfig([AbstractMailer::CONFIG_REPLY_TO_EMAIL => 'reply@example.com']),
            $this->events
        );
        [$headers] = $mailer->build($mailer->create('Joe', 'joe@example.com', 'S', 't'));
        $this->assertStringContainsString('Reply-To: reply@example.com', $headers);
    }
}
