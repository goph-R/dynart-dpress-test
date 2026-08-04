<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressException;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Form\FormFactory
 * @covers \Dynart\Dpress\Form\DpressForm
 */
class FormFactoryTest extends TestCase {

    private RecordingEvents $events;
    private FormFactory $factory;

    protected function setUp(): void {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->events = new RecordingEvents();
        $this->factory = new FormFactory(
            new Request(),
            new Session(),
            $this->events,
            new StubTranslation()
        );
    }

    protected function tearDown(): void {
        $_REQUEST = [];
    }

    private function addLoginForm(): void {
        $this->factory->add('user_login', function(DpressForm $form, array $context) {
            $form->addFields([
                'email'    => ['type' => 'text', 'label' => 'Email'],
                'password' => ['type' => 'password', 'label' => 'Password'],
            ]);
        });
    }

    // --- Registry ---

    public function testHasIsFalseForAnUnregisteredName(): void {
        $this->assertFalse($this->factory->has('user_login'));
    }

    public function testHasIsTrueAfterAdd(): void {
        $this->addLoginForm();
        $this->assertTrue($this->factory->has('user_login'));
    }

    public function testNames(): void {
        $this->addLoginForm();
        $this->factory->add('user_profile', function(DpressForm $f, array $c) {});
        $this->assertSame(['user_login', 'user_profile'], $this->factory->names());
    }

    public function testCreateThrowsForAnUnknownName(): void {
        $this->expectException(DpressException::class);
        $this->factory->create('nosuchform');
    }

    // --- Building ---

    public function testCreateReturnsAFormWithTheRegisteredFields(): void {
        $this->addLoginForm();
        $form = $this->factory->create('user_login');
        $this->assertInstanceOf(DpressForm::class, $form);
        $this->assertSame(['email', 'password'], array_keys($form->fields()));
    }

    public function testTheFormKeepsItsName(): void {
        $this->addLoginForm();
        $form = $this->factory->create('user_login');
        $this->assertSame('user_login', $form->name());
        $this->assertSame('user_login[email]', $form->inputName('email'));
        $this->assertSame('form.user_login.csrf', $form->csrfSessionName());
    }

    public function testCsrfCanBeTurnedOff(): void {
        $this->addLoginForm();
        $this->assertFalse($this->factory->create('user_login', [], false)->csrf());
    }

    public function testTheBuilderReceivesTheContext(): void {
        $received = null;
        $this->factory->add('with_context', function(DpressForm $form, array $context) use (&$received) {
            $received = $context;
        });
        $this->factory->create('with_context', ['id' => 5]);
        $this->assertSame(['id' => 5], $received);
    }

    public function testTheFormKeepsTheContext(): void {
        $this->addLoginForm();
        $this->assertSame(['id' => 5], $this->factory->create('user_login', ['id' => 5])->context());
    }

    // --- Events ---

    public function testEventName(): void {
        $this->assertSame('form.user_login:created', FormFactory::eventName('user_login'));
        $this->assertSame('form.user_login:validated', FormFactory::eventName('user_login', 'validated'));
    }

    public function testBothCreatedEventsAreEmitted(): void {
        $this->addLoginForm();
        $this->factory->create('user_login');
        $this->assertContains('form.user_login:created', $this->events->emitted);
        $this->assertContains(FormFactory::EVENT_CREATED, $this->events->emitted);
    }

    public function testTheGenericEventCarriesTheNameFirst(): void {
        $this->addLoginForm();
        $form = $this->factory->create('user_login');
        $args = $this->events->args[FormFactory::EVENT_CREATED];
        $this->assertSame('user_login', $args[0]);
        $this->assertSame($form, $args[1]);
    }

    /**
     * The point of the whole thing: a plugin adds a field to a form it did not write, and it
     * renders without any template change because `Form::fetch()` walks `fields()`.
     */
    public function testASubscriberCanAddAField(): void {
        $this->addLoginForm();
        $this->events->subscribe('form.user_login:created', function(DpressForm $form, array $context) {
            $form->addFields(['remember' => ['type' => 'checkbox', 'required' => false]]);
        });
        $form = $this->factory->create('user_login');
        $this->assertSame(['email', 'password', 'remember'], array_keys($form->fields()));
        $this->assertFalse($form->required('remember'));
    }

    public function testASubscriberCanMakeAFieldOptional(): void {
        $this->addLoginForm();
        $this->events->subscribe('form.user_login:created', function(DpressForm $form) {
            $form->setRequired('password', false);
        });
        $this->assertFalse($this->factory->create('user_login')->required('password'));
    }

    // --- Validation ---

    public function testValidatedEventIsEmittedOnProcess(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['user_login'] = ['email' => 'a@b.c', 'password' => 'secret'];
        $this->addLoginForm();
        $form = $this->factory->create('user_login', [], false);
        $this->assertTrue($form->process('POST'));
        $this->assertContains('form.user_login:validated', $this->events->emitted);
        $this->assertTrue($this->events->args['form.user_login:validated'][1]);
    }

    public function testValidatedEventReportsAnInvalidForm(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['user_login'] = ['email' => '', 'password' => ''];
        $this->addLoginForm();
        $form = $this->factory->create('user_login', [], false);
        $this->assertFalse($form->process('POST'));
        $this->assertFalse($this->events->args['form.user_login:validated'][1]);
    }

    public function testNoValidatedEventWhenTheHttpMethodDoesNotMatch(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->addLoginForm();
        $form = $this->factory->create('user_login', [], false);
        $form->process('POST');
        $this->assertNotContains('form.user_login:validated', $this->events->emitted);
    }

    /**
     * A subscriber gets to add its own errors after the built in validation ran, which is how a
     * plugin rejects a value the core knows nothing about.
     */
    public function testASubscriberCanAddAnErrorDuringValidation(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['user_login'] = ['email' => 'spam@example.com', 'password' => 'secret'];
        $this->addLoginForm();
        $this->events->subscribe('form.user_login:validated', function(DpressForm $form, bool $valid) {
            if ($form->value('email') === 'spam@example.com') {
                $form->addFieldError('email', 'Blocked.');
            }
        });
        $form = $this->factory->create('user_login', [], false);
        $form->process('POST');
        $this->assertSame('Blocked.', $form->error('email'));
        $this->assertTrue($form->hasErrors());
    }

    // --- handle() ---

    public function testHandleWrapsTheHandlerInTheProcessEvents(): void {
        $this->addLoginForm();
        $form = $this->factory->create('user_login', [], false);
        $result = $form->handle(fn(DpressForm $f) => 'done');
        $this->assertSame('done', $result);
        $this->assertContains('form.user_login:before_process', $this->events->emitted);
        $this->assertContains('form.user_login:after_process', $this->events->emitted);
    }

    public function testHandleEmitsBeforeThenAfter(): void {
        $this->addLoginForm();
        $form = $this->factory->create('user_login', [], false);
        $order = [];
        $this->events->subscribe('form.user_login:before_process', function() use (&$order) { $order[] = 'before'; });
        $this->events->subscribe('form.user_login:after_process', function() use (&$order) { $order[] = 'after'; });
        $form->handle(function() use (&$order) { $order[] = 'handler'; return null; });
        $this->assertSame(['before', 'handler', 'after'], $order);
    }

    public function testTheAfterProcessEventCarriesTheHandlerResult(): void {
        $this->addLoginForm();
        $form = $this->factory->create('user_login', [], false);
        $form->handle(fn() => 42);
        $this->assertSame(42, $this->events->args['form.user_login:after_process'][1]);
    }

    // --- Without an event service ---

    public function testAFormWithoutAnEventServiceStillWorks(): void {
        $form = new DpressForm(new Request(), new Session(), 'standalone', false);
        $form->addFields(['name' => ['type' => 'text']]);
        $form->setValues(['name' => 'Joe']);
        $this->assertTrue($form->validate());
        $this->assertSame('done', $form->handle(fn() => 'done'));
    }
}
