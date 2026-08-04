<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Form\CoreForms;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Form\Validator\EmailValidator;
use Dynart\Dpress\Form\Validator\MatchFieldValidator;
use Dynart\Dpress\Form\Validator\MinLengthValidator;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Form\CoreForms
 * @covers \Dynart\Dpress\Form\Validator\EmailValidator
 * @covers \Dynart\Dpress\Form\Validator\MinLengthValidator
 * @covers \Dynart\Dpress\Form\Validator\MatchFieldValidator
 */
class CoreFormsTest extends TestCase {

    private FormFactory $factory;

    protected function setUp(): void {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->factory = new FormFactory(
            new Request(), new Session(), new RecordingEvents(), new StubTranslation()
        );
        CoreForms::register($this->factory);
    }

    protected function tearDown(): void {
        $_REQUEST = [];
    }

    public function testEveryCoreFormIsRegistered(): void {
        foreach ([CoreForms::LOGIN, CoreForms::REGISTER, CoreForms::FORGOT_PASSWORD,
                  CoreForms::RESET_PASSWORD, CoreForms::PROFILE] as $name) {
            $this->assertTrue($this->factory->has($name), "$name is not registered");
        }
    }

    public function testFormNamesHaveNoDots(): void {
        foreach ($this->factory->names() as $name) {
            $this->assertStringNotContainsString('.', $name);
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $name);
        }
    }

    public function testLoginFields(): void {
        $form = $this->factory->create(CoreForms::LOGIN, [], false);
        $this->assertSame(['email', 'password'], array_keys($form->fields()));
    }

    public function testRegisterFields(): void {
        $form = $this->factory->create(CoreForms::REGISTER, [], false);
        $this->assertSame(['name', 'email', 'password', 'password_confirm'], array_keys($form->fields()));
    }

    /**
     * An empty pair means "keep the current password", so these two cannot be required.
     */
    public function testTheProfilePasswordFieldsAreOptional(): void {
        $form = $this->factory->create(CoreForms::PROFILE, [], false);
        $this->assertFalse($form->required('password'));
        $this->assertFalse($form->required('password_confirm'));
        $this->assertTrue($form->required('name'));
        $this->assertTrue($form->required('email'));
    }

    public function testTheResetFormCarriesTheTokenFromTheContext(): void {
        $form = $this->factory->create(CoreForms::RESET_PASSWORD, ['token' => 'abc123'], false);
        $this->assertSame('abc123', $form->value('token'));
    }

    // --- Validation ---

    private function submit(string $name, array $values, array $context = []): DpressForm {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST[$name] = $values;
        $form = $this->factory->create($name, $context, false);
        $form->process('POST');
        return $form;
    }

    public function testLoginRejectsAMalformedEmail(): void {
        $form = $this->submit(CoreForms::LOGIN, ['email' => 'not-an-email', 'password' => 'whatever']);
        $this->assertNotNull($form->error('email'));
    }

    public function testLoginAcceptsAValidEmail(): void {
        $form = $this->submit(CoreForms::LOGIN, ['email' => 'joe@example.com', 'password' => 'whatever']);
        $this->assertFalse($form->hasErrors());
    }

    public function testRegisterRejectsMismatchedPasswords(): void {
        $form = $this->submit(CoreForms::REGISTER, [
            'name' => 'Joe', 'email' => 'joe@example.com',
            'password' => 'secret123', 'password_confirm' => 'different',
        ]);
        $this->assertNotNull($form->error('password_confirm'));
    }

    public function testRegisterRejectsAShortPassword(): void {
        $form = $this->submit(CoreForms::REGISTER, [
            'name' => 'Joe', 'email' => 'joe@example.com',
            'password' => 'short', 'password_confirm' => 'short',
        ]);
        $this->assertNotNull($form->error('password'));
    }

    public function testRegisterAcceptsAValidSubmission(): void {
        $form = $this->submit(CoreForms::REGISTER, [
            'name' => 'Joe', 'email' => 'joe@example.com',
            'password' => 'secret123', 'password_confirm' => 'secret123',
        ]);
        $this->assertFalse($form->hasErrors());
    }

    /**
     * Both empty means "leave the password alone", so it has to validate.
     */
    public function testTheProfileAcceptsEmptyPasswords(): void {
        $form = $this->submit(CoreForms::PROFILE, [
            'name' => 'Joe', 'email' => 'joe@example.com',
            'password' => '', 'password_confirm' => '',
        ]);
        $this->assertFalse($form->hasErrors());
    }

    public function testTheProfileStillChecksAPasswordThatWasTyped(): void {
        $form = $this->submit(CoreForms::PROFILE, [
            'name' => 'Joe', 'email' => 'joe@example.com',
            'password' => 'short', 'password_confirm' => 'short',
        ]);
        $this->assertNotNull($form->error('password'));
    }

    // --- The validators themselves ---

    public function testEmailValidator(): void {
        $validator = new EmailValidator();
        $this->assertTrue($validator->validate('joe@example.com'));
        $this->assertFalse($validator->validate('joe@'));
        $this->assertFalse($validator->validate(''));
    }

    public function testMinLengthValidator(): void {
        $validator = new MinLengthValidator(8);
        $this->assertTrue($validator->validate('12345678'));
        $this->assertFalse($validator->validate('1234567'));
    }

    public function testMinLengthCountsCharactersNotBytes(): void {
        $validator = new MinLengthValidator(4);
        $this->assertTrue($validator->validate('áéíő'));
    }

    public function testMatchFieldValidatorReadsTheOtherFieldFromTheForm(): void {
        $form = $this->factory->create(CoreForms::REGISTER, [], false);
        $form->setValues(['password' => 'secret123']);
        $validator = new MatchFieldValidator('password');
        $validator->setForm($form);
        $this->assertTrue($validator->validate('secret123'));
        $this->assertFalse($validator->validate('different'));
    }
}
