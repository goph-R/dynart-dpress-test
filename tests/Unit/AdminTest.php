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
use Dynart\Dpress\Dpress;
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
                    if (!preg_match('#/(delete|publish|unpublish|restore)/#', $route->path.'/')) {
                        continue;
                    }
                    $this->assertSame('POST', $route->method, $route->path.' changes something over '.$route->method);
                }
            }
        }
    }

    // --- the forms ---

    private function factory(): FormFactory {
        $factory = new FormFactory(new Request(), new Session(), new RecordingEvents(), new StubTranslation());
        AdminForms::register($factory);
        return $factory;
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
     * Only the title and the markdown are required: a slug is made from the title and a status
     * has a default, so demanding them would be asking for what the CMS already knows
     */
    public function testOnlyTheTitleAndTheTextAreRequired(): void {
        $form = $this->factory()->create(AdminForms::CONTENT, ['is_page' => false]);
        $this->assertTrue($form->required('title'));
        $this->assertTrue($form->required('markdown'));
        $this->assertFalse($form->required('slug'));
        $this->assertFalse($form->required('status'));
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

    public function testTheSettingsScreenOnlyWritesEditableSettings(): void {
        $this->assertSame([
            Setting::SITE_NAME,
            Setting::SITE_DESCRIPTION,
            Setting::SITE_LOGO,
            Setting::SITE_ICON,
            Setting::REGISTRATION_OPEN,
            Setting::POSTS_PER_PAGE,
        ], array_keys(SettingsAdminController::FIELDS));
    }

    /**
     * The CMS renders its own field types, and falls through to the framework for the rest
     */
    public function testTheFormUsesTheCmsInputPartial(): void {
        $this->assertSame('dpress:form-input', DpressForm::VIEW_INPUT);
    }

    // --- the sortable whitelists ---

    public function testTheSortableColumnsAreRealColumnsOfTheEntity(): void {
        $this->assertSortableAgainst(ContentAdminController::SORTABLE, Content::class);
        $this->assertSortableAgainst(MediaAdminController::SORTABLE, \Dynart\Dpress\Entity\Media::class);
        $this->assertSortableAgainst(UserAdminController::SORTABLE, \Dynart\Dpress\Entity\User::class);
        $this->assertSortableAgainst(TaxonomyAdminController::CATEGORY_SORTABLE, \Dynart\Dpress\Entity\Category::class);
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
