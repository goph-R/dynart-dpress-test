<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\AbstractAdminController;
use Dynart\Dpress\Controller\Admin\AssetController;
use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Controller\Admin\DashboardController;
use Dynart\Dpress\Controller\Admin\MediaAdminController;
use Dynart\Dpress\Controller\Admin\MenuAdminController;
use Dynart\Dpress\Controller\Admin\RoleAdminController;
use Dynart\Dpress\Controller\Admin\SettingsAdminController;
use Dynart\Dpress\Controller\Admin\TaxonomyAdminController;
use Dynart\Dpress\Controller\Admin\UserAdminController;
use Dynart\Dpress\Content\Dates;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressServices;
use Dynart\Dpress\DpressWebApp;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Micro\Attribute\Authorize;
use Dynart\Micro\FormWidgets;
use Dynart\Micro\Attribute\Route;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The Phase 5 decisions: the admin, its lists, and what may reach them
 *
 * @covers \Dynart\Dpress\Query\ListRequest
 * @covers \Dynart\Dpress\Form\AdminForms
 * @covers \Dynart\Dpress\Controller\Admin\AbstractAdminController
 */
class AdminTest extends TestCase {

    /** Every concrete admin controller, plus the one that serves the assets */
    const ADMIN_CONTROLLERS = [
        DashboardController::class,
        ContentAdminController::class,
        MediaAdminController::class,
        TaxonomyAdminController::class,
        MenuAdminController::class,
        UserAdminController::class,
        RoleAdminController::class,
        SettingsAdminController::class,
    ];

    protected function setUp(): void {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void {
        $_REQUEST = [];
    }

    // --- ListRequest ---

    private function listRequest(array $request): ListRequest {
        $_REQUEST = $request;
        return new ListRequest(new Request());
    }

    /**
     * The sort column ends up in the SQL, so a name the screen did not offer is dropped rather
     * than passed on
     */
    public function testAnUnknownSortColumnIsDropped(): void {
        $list = $this->listRequest(['sort' => 'password_hash']);
        $this->assertSame('', $list->sort(['title', 'slug']));
        $this->assertArrayNotHasKey('order_by', $list->context(['title', 'slug']));
    }

    public function testAnInjectionAttemptInTheSortIsDropped(): void {
        $list = $this->listRequest(['sort' => 'title; drop table dp_content']);
        $this->assertSame('', $list->sort(['title']));
    }

    public function testAKnownSortColumnIsKept(): void {
        $context = $this->listRequest(['sort' => 'title', 'order' => 'desc'])->context(['title']);
        $this->assertSame('title', $context['order_by']);
        $this->assertSame('desc', $context['order_dir']);
    }

    public function testAnythingOtherThanDescIsAscending(): void {
        $this->assertSame('asc', $this->listRequest(['order' => 'sideways'])->direction());
        $this->assertSame('asc', $this->listRequest([])->direction());
        $this->assertSame('desc', $this->listRequest(['order' => 'DESC'])->direction());
    }

    /**
     * A browser asking for everything gets a page, not an error and not the whole table
     */
    public function testThePageSizeIsClamped(): void {
        $this->assertSame(ListRequest::MAX_MAX, $this->listRequest(['max' => '100000'])->max());
        $this->assertSame(ListRequest::DEFAULT_MAX, $this->listRequest([])->max());
        $this->assertSame(ListRequest::DEFAULT_MAX, $this->listRequest(['max' => '0'])->max());
        $this->assertSame(ListRequest::DEFAULT_MAX, $this->listRequest(['max' => '-5'])->max());
        $this->assertSame(10, $this->listRequest(['max' => '10'])->max());
    }

    public function testANegativeOffsetIsTheFirstPage(): void {
        $this->assertSame(0, $this->listRequest(['offset' => '-40'])->offset());
        $this->assertSame(50, $this->listRequest(['offset' => '50'])->offset());
    }

    public function testOnlyFiltersWithAValueReachTheContext(): void {
        $context = $this->listRequest(['search' => 'hello', 'status' => ''])
            ->context([], ['search', 'status']);
        $this->assertSame('hello', $context['search']);
        $this->assertArrayNotHasKey('status', $context);
    }

    /**
     * The parameter names are the defaults of `dynamic-list.js`; changing one here without
     * changing it there would leave every list silently unsorted
     */
    public function testTheParameterNamesMatchTheListScript(): void {
        $this->assertSame('sort', ListRequest::SORT);
        $this->assertSame('order', ListRequest::ORDER);
        $this->assertSame('offset', ListRequest::OFFSET);
        $this->assertSame('max', ListRequest::MAX);
    }

    // --- authorization ---

    /**
     * The base declares it once. PHP attributes are not inherited, so this only holds because
     * `AttributeProcessor` looks up the chain - and if it stopped, every admin screen would be
     * anonymous with nothing to show for it.
     */
    public function testTheAdminBaseRequiresALogin(): void {
        $attributes = (new ReflectionClass(AbstractAdminController::class))->getAttributes(Authorize::class);
        $this->assertNotEmpty($attributes, 'the admin base has no #[Authorize]');
    }

    public function testEveryAdminControllerInheritsFromTheAuthorizedBase(): void {
        foreach (self::ADMIN_CONTROLLERS as $className) {
            $this->assertTrue(
                is_subclass_of($className, AbstractAdminController::class),
                "$className does not extend the admin base, so nothing requires a login"
            );
        }
    }

    /**
     * The assets are static files with nothing in them, and they are the one thing under `/admin`
     * that is deliberately open
     */
    public function testTheAssetControllerIsNotAnAdminController(): void {
        $this->assertFalse(is_subclass_of(AssetController::class, AbstractAdminController::class));
    }

    public function testTheAssetControllerServesOnlyNamedFiles(): void {
        foreach (array_keys(AssetController::ASSETS) as $name) {
            $this->assertFileExists(dirname(Dpress::viewsPath()).'/assets/'.$name);
        }
    }

    public function testEveryAdminControllerIsRegistered(): void {
        foreach (array_merge(self::ADMIN_CONTROLLERS, [AssetController::class]) as $className) {
            $this->assertContains($className, DpressWebApp::CONTROLLERS, "$className is not registered");
        }
    }

    /**
     * A `#[Route]` on an action that changes something has to be POST: a link that deletes can be
     * followed by a prefetcher or an `<img>` on somebody else's page
     */
    public function testTheStateChangingActionsAreNotReachableByGet(): void {
        foreach (self::ADMIN_CONTROLLERS as $className) {
            foreach ((new ReflectionClass($className))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    $route = $attribute->newInstance();
                    // `delete-selected` before `delete`, or the alternation matches the prefix
                    // and the group routes - the ones that remove many rows at once - go
                    // unchecked, which is the wrong way round
                    if (!preg_match('#/(delete-selected|delete|publish|unpublish|restore)/#', $route->path.'/')) {
                        continue;
                    }
                    $this->assertSame('POST', $route->method, $route->path.' changes something over '.$route->method);
                }
            }
        }
    }

    /**
     * Every list removes things a row at a time, and none of them in bulk
     *
     * The buttons went the other way once. 0.21.0 took the row deletes out and put *Delete
     * selected* in; 0.31.0 put them back and took bulk delete out again, because it cost a
     * checkbox column on every screen and a bar above every list for the most dangerous operation
     * in the admin behind a single confirm - and the case for it, a library full of files
     * uploaded by mistake, is the case `media:delete` and a shell loop already answer.
     *
     * A row action is a URL in a JSON blob and its endpoint is an attribute on a method, and
     * nothing connects the two until somebody clicks it. A typo in either is a 404 *after* the
     * confirm said yes, which reads as "it deleted it and lost the page".
     */
    public function testEveryListDeletesARowAtATime(): void {
        $expected = [
            '/admin/content/?/delete/?',
            '/admin/media/delete/?',
            '/admin/categories/delete/?',
            '/admin/tags/delete/?',
            '/admin/menus/delete/?',
            '/admin/menus/items/delete/?',
            '/admin/users/delete/?',
            '/admin/roles/delete/?',
        ];
        $found = [];
        foreach (self::ADMIN_CONTROLLERS as $className) {
            foreach ((new ReflectionClass($className))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    $path = $attribute->newInstance()->path;
                    $this->assertStringNotContainsString(
                        'delete-selected', $path,
                        "$path is a bulk delete, and there is no way left in the admin to reach one"
                    );
                    if (str_ends_with($path, '/delete/?')) {
                        $found[] = $path;
                    }
                }
            }
        }
        sort($expected);
        sort($found);
        $this->assertSame($expected, $found);
    }

    /**
     * ...and each of those routes is on the method that does the deleting
     *
     * The other half of the test above, and the half that was missing. An attribute binds to the
     * declaration that *follows* it, so one written above the next method's docblock belongs to
     * that method - which is how `/admin/menus/items/delete/?` came to sit on `moveItem()`.
     * Deleting a menu item moved it to the top of the menu instead and answered with the move
     * endpoint's JSON, `{"csrf": "..."}`, printed into the browser as the whole page. Every test
     * there was passed: the route existed, it was a POST, it was not a bulk delete. Nothing asked
     * where it went.
     */
    public function testEveryDeleteRouteIsOnADeletingMethod(): void {
        foreach (self::ADMIN_CONTROLLERS as $className) {
            foreach ((new ReflectionClass($className))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    $path = $attribute->newInstance()->path;
                    if (!str_contains($path, '/delete/')) {
                        continue;
                    }
                    $this->assertStringStartsWith(
                        'delete', $method->getName(),
                        "$path is served by {$className}::{$method->getName()}(), which is not a delete"
                    );
                }
            }
        }
    }

    /**
     * A `#[Route]` may not be separated from its method by a docblock
     *
     * The shape that produced the bug above, caught where it happens rather than one route at a
     * time. Attributes and docblocks may legally be written in either order, but a docblock
     * describes the declaration under it - so an attribute written *above* one reads as belonging
     * to whatever came before, while PHP binds it to whatever comes after. The two readings
     * disagree silently, and the file looks right.
     */
    public function testNoRouteAttributeIsSeparatedFromItsMethodByADocblock(): void {
        foreach (array_merge(self::ADMIN_CONTROLLERS, [AssetController::class]) as $className) {
            $lines = file((new ReflectionClass($className))->getFileName());
            foreach ($lines as $number => $line) {
                if (!str_starts_with(trim($line), '#[Route(')) {
                    continue;
                }
                for ($i = $number + 1; $i < count($lines); $i++) {
                    $next = trim($lines[$i]);
                    if (str_contains($next, 'function ')) {
                        break;
                    }
                    $this->assertStringStartsNotWith(
                        '/**', $next,
                        $className.' line '.($number + 1).': this route binds to the method after'
                            .' the docblock below it, not to the one it is written under'
                    );
                }
            }
        }
    }

    /**
     * Selecting rows is still there, for the one thing it is good at
     *
     * Enabling six plugins at once is a real act; deleting six things at once is a mistake
     * waiting for somewhere to happen.
     */
    public function testThePluginsScreenKeepsItsGroupActions(): void {
        $source = file_get_contents(Dpress::path('src/Controller/Admin/PluginAdminController.php'));
        $this->assertStringContainsString("'groupActions' => \$this->groupActions()", $source);
    }

    // --- the forms ---

    private function factory(): FormFactory {
        $factory = new FormFactory(new Request(), new Session(), new RecordingEvents(), new StubTranslation());
        AdminForms::register($factory);
        return $factory;
    }

    /**
     * An action that answers with data hands back the token for the next one
     *
     * `Form::process()` mints a fresh token every time it runs and stores it in the session, so
     * validating one action spends the one printed on the page. That is invisible while every
     * action reloads the page, and fatal for two in a row without one - uploading a file and
     * then attaching it was refused as a forgery, and the message blamed the attach.
     */
    public function testAnActionAnswerCarriesTheNextToken(): void {
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $property = (new ReflectionClass(AbstractAdminController::class))->getProperty('forms');
        $property->setAccessible(true);
        $property->setValue($controller, $this->factory());

        $method = new ReflectionMethod(AbstractAdminController::class, 'answer');
        $method->setAccessible(true);
        $answer = $method->invoke($controller, ['item' => 'something']);

        $this->assertSame('something', $answer['item'], 'the answer lost what it was answering');
        $this->assertArrayHasKey('csrf', $answer);
        $this->assertNotSame('', $answer['csrf']);
    }

    public function testEveryAdminFormIsRegistered(): void {
        $factory = $this->factory();
        foreach ([AdminForms::CONTENT, AdminForms::CATEGORY, AdminForms::TAG, AdminForms::MEDIA,
                  AdminForms::USER, AdminForms::ROLE, AdminForms::SETTINGS, AdminForms::MENU,
                  AdminForms::MENU_ITEM, AdminForms::UPLOAD, AdminForms::ACTION] as $name) {
            $this->assertTrue($factory->has($name), "the '$name' form is not registered");
        }
    }

    /**
     * A post gets categories and tags, a page gets a parent - the two are one table but not one
     * editing job
     */
    public function testThePostEditorOffersTaxonomyAndThePageEditorAParent(): void {
        $factory = $this->factory();
        $post = $factory->create(AdminForms::CONTENT, ['is_page' => false, 'categories' => [1 => 'News']]);
        $this->assertArrayHasKey('categories', $post->fields());
        $this->assertArrayHasKey('tags', $post->fields());
        $this->assertArrayNotHasKey('parent_id', $post->fields());

        $page = $factory->create(AdminForms::CONTENT, ['is_page' => true]);
        $this->assertArrayHasKey('parent_id', $page->fields());
        $this->assertArrayNotHasKey('categories', $page->fields());
    }

    public function testANewPostStartsAsADraft(): void {
        $form = $this->factory()->create(AdminForms::CONTENT, ['is_page' => false]);
        $this->assertSame(Content::STATUS_DRAFT, $form->value('status'));
    }

    /**
     * The status select is only for somebody who may publish this kind of content
     *
     * The stock `editor` role holds `post.publish` but not `page.publish`, so this is not a
     * hypothetical: without the check that role got a select on the page editor that the server
     * then ignored, and the screen said "Saved." while nothing moved.
     */
    public function testTheStatusFieldIsOnlyOfferedToSomebodyWhoMayPublish(): void {
        $factory = $this->factory();
        $allowed = $factory->create(AdminForms::CONTENT, ['is_page' => false, 'can_publish' => true]);
        $this->assertArrayHasKey('status', $allowed->fields());

        $refused = $factory->create(AdminForms::CONTENT, ['is_page' => false, 'can_publish' => false]);
        $this->assertArrayNotHasKey('status', $refused->fields());
        // and the rest of the editor is untouched - this hides one field, it does not lock the screen
        $this->assertArrayHasKey('title', $refused->fields());
        $this->assertArrayHasKey('markdown', $refused->fields());
        $this->assertArrayHasKey('featured_media_id', $refused->fields());
    }

    /**
     * And the published date goes with it, because it is the same decision
     *
     * The public queries ask for `published_at <= now`, so a date is as much a say in whether
     * something is visible as the select beside it - dating a post forward hides it. Offering it
     * to somebody who cannot publish would be the same silent no-op the status select was.
     */
    public function testThePublishedDateIsOfferedOnTheSameTermsAsTheStatus(): void {
        $factory = $this->factory();
        $allowed = $factory->create(AdminForms::CONTENT, ['is_page' => false, 'can_publish' => true]);
        $this->assertArrayHasKey('published_at', $allowed->fields());
        // empty means "the moment it is published", so it cannot be required
        $this->assertFalse($allowed->required('published_at'));

        $refused = $factory->create(AdminForms::CONTENT, ['is_page' => false, 'can_publish' => false]);
        $this->assertArrayNotHasKey('published_at', $refused->fields());
    }

    /**
     * A date that cannot be read stops the save rather than being dropped
     *
     * `done()` redirects, so a message put on the form after the save is a message nobody sees -
     * and half a save, the text stored and the date silently refused, is worse than none.
     */
    public function testADateThatCannotBeReadIsRefusedOnItsOwnField(): void {
        $readable = new ReflectionMethod(ContentAdminController::class, 'publishedAtIsReadable');
        $readable->setAccessible(true);
        $controller = (new ReflectionClass(ContentAdminController::class))->newInstanceWithoutConstructor();
        $dates = (new ReflectionClass(ContentAdminController::class))->getProperty('dates');
        $dates->setAccessible(true);
        $dates->setValue($controller, new Dates(new PlacesSettings()));

        $form = $this->factory()->create(AdminForms::CONTENT, ['is_page' => false]);
        $form->addValues(['published_at' => '2014-03-09']);
        $this->assertTrue($readable->invoke($controller, $form));
        $this->assertNull($form->error('published_at'));

        // the field left alone is not a failure to read - it means "the moment it is published"
        $form->addValues(['published_at' => '']);
        $this->assertTrue($readable->invoke($controller, $form));

        $form->addValues(['published_at' => 'last tuesday']);
        $this->assertFalse($readable->invoke($controller, $form));
        $this->assertStringContainsString('1999-01-02', (string)$form->error('published_at'));
    }

    /**
     * What the editor's status select has to do to the content behind it
     *
     * `ContentService::update()` ignores `status` on purpose - becoming visible sets
     * `published_at` and is what a feed or a plugin listens for, so it goes through
     * `publish()` / `unpublish()`. The editor used to hand `status` to `update()` anyway, which
     * meant the select did nothing at all: "Saved.", still a draft.
     */
    public function testTheStatusSelectDecidesBetweenPublishingAndNothing(): void {
        $change = new ReflectionMethod(ContentAdminController::class, 'statusChange');
        $change->setAccessible(true);
        $controller = (new ReflectionClass(ContentAdminController::class))->newInstanceWithoutConstructor();

        $draft = Content::STATUS_DRAFT;
        $published = Content::STATUS_PUBLISHED;
        $this->assertSame('publish', $change->invoke($controller, $draft, $published));
        $this->assertSame('unpublish', $change->invoke($controller, $published, $draft));
        // saving a published post without touching the select must not re-publish it: that would
        // move `published_at` and announce it again on every typo fix
        $this->assertNull($change->invoke($controller, $published, $published));
        $this->assertNull($change->invoke($controller, $draft, $draft));
        // and a status nobody offered is not a third state to move to
        $this->assertNull($change->invoke($controller, $draft, 'archived'));
        $this->assertNull($change->invoke($controller, $published, ''));
    }

    /**
     * `status` must not travel with the ordinary fields
     *
     * `ContentService::create()` honours whatever it is given, so a status that reached it
     * through this array published a new post without anybody checking the permission.
     */
    public function testTheEditedFieldsDoNotCarryTheStatus(): void {
        $method = new ReflectionMethod(ContentAdminController::class, 'contentData');
        $method->setAccessible(true);
        $controller = (new ReflectionClass(ContentAdminController::class))->newInstanceWithoutConstructor();
        $data = $method->invoke($controller, [
            'title' => 'A title', 'markdown' => 'text', 'slug' => 'a-title',
            'status' => Content::STATUS_PUBLISHED, 'featured_media_id' => '',
        ]);
        $this->assertArrayNotHasKey('status', $data);
        $this->assertSame('A title', $data['title']);
        $this->assertNull($data['featured_media_id']);
    }

    /**
     * Only the title and the markdown are required: a slug is made from the title and a status
     * has a default, so demanding them would be asking for what the CMS already knows
     */
    public function testOnlyTheTitleAndTheTextAreRequired(): void {
        foreach ([true, false] as $isPage) {
            $form = $this->factory()->create(AdminForms::CONTENT, ['is_page' => $isPage]);
            // the whole set, not a sample. `addFields()` makes a field required unless it is
            // told otherwise, so a new one put in the wrong group is required by accident -
            // and a *hidden* one then fails every save with nowhere to say why, which is
            // exactly what `cursor_line` did for one release.
            $required = array_keys(array_filter(
                $form->fields(), fn($name) => $form->required($name), ARRAY_FILTER_USE_KEY
            ));
            sort($required);
            $this->assertSame(['markdown', 'title'], $required, $isPage ? 'page' : 'post');
        }
    }

    /**
     * A password on an existing user is optional, because empty means "leave it alone" - making
     * it required would force whoever fixes a typo in a name to also reset the password
     */
    public function testThePasswordIsOptionalWhenEditingAnExistingUser(): void {
        $factory = $this->factory();
        $this->assertTrue($factory->create(AdminForms::USER)->required('password'));

        $user = new \Dynart\Dpress\Entity\User();
        $user->name = 'Someone';
        $user->email = 'someone@example.com';
        $this->assertFalse($factory->create(AdminForms::USER, ['user' => $user])->required('password'));
    }

    /**
     * The role editor is generated from the registry, which is what lets a plugin's permission
     * appear with no migration and no template change
     */
    public function testTheRoleFormTakesItsPermissionsFromTheGroups(): void {
        $form = $this->factory()->create(AdminForms::ROLE, [
            'permission_groups' => ['post' => ['post.view', 'post.create']],
        ]);
        $field = $form->fields()['permissions'];
        $this->assertSame('permissions', $field['type']);
        $this->assertSame(['post' => ['post.view', 'post.create']], $field['groups']);
    }

    /**
     * The row actions post through one hidden form, so this exists for its token and nothing else
     */
    public function testTheActionFormHasNothingButItsToken(): void {
        $form = $this->factory()->create(AdminForms::ACTION);
        $this->assertSame([], $form->fields());
        $form->generateCsrf();
        $this->assertSame([$form->csrfName()], array_keys($form->fields()));
    }

    public function testTheSettingsFormCoversTheSettingsTheScreenWrites(): void {
        $form = $this->factory()->create(AdminForms::SETTINGS, ['themes' => ['' => 'Built in']]);
        foreach (array_keys(SettingsAdminController::FIELDS) as $name) {
            $this->assertArrayHasKey($name, $form->fields(), "the settings form has no '$name' field");
        }
        $this->assertArrayHasKey('theme', $form->fields());
    }

    /**
     * The logo and the icon are chosen from the library
     *
     * What used to argue against it - a logo is chrome, it renders on pages with no content on
     * them, and deleting a library item must not take the header down - is answered by the
     * fallback in `AbstractController::brandingAsset()` rather than by refusing to use the
     * picker. See `Setting::CONFIG_DEFAULT_LOGO`.
     */
    public function testTheBrandingFieldsUseTheMediaChooser(): void {
        $fields = $this->factory()->create(AdminForms::SETTINGS, ['themes' => []])->fields();
        foreach ([Setting::SITE_LOGO, Setting::SITE_ICON] as $name) {
            $this->assertSame('media', $fields[$name]['type'] ?? '', "'$name' should be chosen from the library");
        }
    }

    /**
     * Nothing chosen is stored as nothing, not as `0`
     *
     * The field shows its Remove button whenever the value is not `''`, so a `0` read back from
     * an unset setting would offer to remove a file nobody had chosen.
     */
    public function testAnUnchosenBrandingSettingIsEmptyRatherThanZero(): void {
        $this->assertSame('', $this->coerce('media', 0));
        $this->assertSame('', $this->coerce('media', ''));
        $this->assertSame('7', $this->coerce('media', 7));
    }

    private function coerce(string $type, mixed $value): string {
        // the same `match` the controller saves through, exercised without a database behind it
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int'  => (string)(int)$value,
            'media' => (int)$value > 0 ? (string)(int)$value : '',
            default => trim((string)$value),
        };
    }

    public function testTheSettingsScreenOnlyWritesEditableSettings(): void {
        $this->assertSame([
            Setting::SITE_NAME,
            Setting::SITE_DESCRIPTION,
            Setting::SITE_LOGO,
            Setting::SITE_ICON,
            Setting::REGISTRATION_OPEN,
            Setting::POSTS_PER_PAGE,
            Setting::FEATURED_TAG,
            Setting::DATE_FORMAT,
            Setting::TIMEZONE,
            Setting::CODE_THEME,
        ], array_keys(SettingsAdminController::FIELDS));
    }

    /**
     * The CMS registers its field types the way a plugin registers one
     *
     * It used to point `DpressForm::VIEW_INPUT` at a template holding all four, which worked
     * exactly once: the override was spent, and nothing after the CMS could add a fifth. Now they
     * go through `FormWidgets` — and the reason to assert it here is that the core has to keep
     * eating its own mechanism, or nobody notices the day it breaks for somebody else's code.
     */
    public function testTheCmsFieldTypesAreRegisteredWidgetsWithTemplatesThatExist(): void {
        $widgets = new FormWidgets();
        DpressServices::registerWidgets($widgets);
        // over the registry rather than over a list written out here, so a widget added later is
        // covered the moment it exists - a template that is not there renders an HTML comment and
        // says nothing to anybody looking at the screen
        $this->assertNotEmpty(DpressServices::WIDGETS);
        foreach (array_keys(DpressServices::WIDGETS) as $type) {
            $this->assertTrue($widgets->has($type), "'$type' is not registered");
            $template = str_replace(Dpress::VIEW_NAMESPACE.':', '', $widgets->view($type));
            $this->assertFileExists(Dpress::viewsPath().'/'.$template.'.phtml');
        }
        foreach (['markdown', 'media', 'checkboxes', 'permissions'] as $type) {
            $this->assertArrayHasKey($type, DpressServices::WIDGETS, "the CMS stopped offering '$type'");
        }
        // the framework's own seven are still there, so `text` did not stop working
        $this->assertTrue($widgets->has('select'));
    }

    public function testTheOldCmsInputPartialIsGone(): void {
        $this->assertFileDoesNotExist(
            Dpress::viewsPath().'/form-input.phtml',
            'the if/elseif chain is still there, so there are two ways to add a field type'
        );
        $this->assertFalse(
            defined(DpressForm::class.'::VIEW_INPUT'),
            'VIEW_INPUT is back, and with it the one-contributor limit it imposes'
        );
    }

    // --- the sortable whitelists ---

    /**
     * Categories have no list to sort: that screen is a tree table now, and a tree in name order
     * is not a tree
     */
    public function testTheSortableColumnsAreRealColumnsOfTheEntity(): void {
        $this->assertSortableAgainst(ContentAdminController::SORTABLE, Content::class);
        $this->assertSortableAgainst(MediaAdminController::SORTABLE, \Dynart\Dpress\Entity\Media::class);
        $this->assertSortableAgainst(UserAdminController::SORTABLE, \Dynart\Dpress\Entity\User::class);
        $this->assertSortableAgainst(TaxonomyAdminController::TAG_SORTABLE, \Dynart\Dpress\Entity\Tag::class);
    }

    private function assertSortableAgainst(array $sortable, string $entityClass): void {
        $this->assertNotEmpty($sortable);
        foreach ($sortable as $name) {
            $this->assertTrue(
                property_exists($entityClass, $name),
                "$entityClass has no '$name' column, so sorting by it would be an SQL error"
            );
        }
    }
}
