<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Migration\CreateSchema;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\UserService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Lets the two protected halves of the author field be called without a request behind them
 */
class AuthorController extends ContentAdminController {

    public bool $mayAssign = true;

    protected function canAssignAuthor(string $type): bool {
        return $this->mayAssign;
    }

    public function author(string $type, array $values, ?Content $content = null): array {
        return $this->authorData($type, $values, $content);
    }

    public function options(?Content $content = null): array {
        return $this->authorOptions($content);
    }
}

/**
 * Whose name goes on a post, and who may decide it
 *
 * @covers \Dynart\Dpress\Controller\Admin\ContentAdminController
 * @covers \Dynart\Dpress\Form\AdminForms
 */
class ContentAuthorTest extends TestCase {

    protected function setUp(): void {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void {
        $_REQUEST = [];
    }

    private function factory(): FormFactory {
        $factory = new FormFactory(new Request(), new Session(), new RecordingEvents(), new StubTranslation());
        AdminForms::register($factory);
        return $factory;
    }

    /** @var array the context the author list was last asked for with */
    private array $askedWith = [];

    /**
     * @param int[] $known   the ids `findById()` answers for - every account
     * @param ?int[] $writers the ids holding an admin or editor role, which the list query answers;
     *                        the same as `$known` unless said
     */
    private function controller(array $known = [2], ?array $writers = null): AuthorController {
        $writers ??= $known;
        $users = $this->createMock(UserService::class);
        $users->method('findById')->willReturnCallback(function (int $id) use ($known) {
            if (!in_array($id, $known, true)) {
                return null;
            }
            $user = new User();
            $user->id = $id;
            $user->name = 'user '.$id;
            return $user;
        });
        $users->method('findAll')->willReturnCallback(function (array $context) use ($writers) {
            $this->askedWith = $context;
            return array_map(fn(int $id) => ['id' => (string)$id, 'name' => 'user '.$id], $writers);
        });
        $controller = (new ReflectionClass(AuthorController::class))->newInstanceWithoutConstructor();
        $property = (new ReflectionClass(ContentAdminController::class))->getProperty('users');
        $property->setAccessible(true);
        $property->setValue($controller, $users);
        return $controller;
    }

    // --- the field ---

    /**
     * A select that cannot do anything is worse than no select: the screen says "Saved." and the
     * name did not move. So the box exists exactly where the options do.
     */
    public function testTheAuthorBoxIsOfferedOnlyWhereThereAreAuthorsToOffer(): void {
        $offered = $this->factory()->create(AdminForms::CONTENT, [
            'is_page' => false, 'authors' => [1 => 'gopher', 2 => 'somebody'],
        ]);
        $this->assertArrayHasKey('author_id', $offered->fields());
        $this->assertFalse($offered->required('author_id'));

        $plain = $this->factory()->create(AdminForms::CONTENT, ['is_page' => false]);
        $this->assertArrayNotHasKey('author_id', $plain->fields());
    }

    public function testThePageEditorGetsTheAuthorBoxToo(): void {
        $form = $this->factory()->create(AdminForms::CONTENT, ['is_page' => true, 'authors' => [1 => 'gopher']]);
        $this->assertArrayHasKey('author_id', $form->fields());
    }

    // --- what reaches the column ---

    public function testAChosenAuthorIsWrittenWhenItIsSomebodyWhoExists(): void {
        $controller = $this->controller([2]);
        $this->assertSame(['author_id' => 2], $controller->author('post', ['author_id' => '2']));
    }

    /**
     * `author_id` is a foreign key, so an id that is not a user is an exception from the database
     * on save - the editor gone, and nothing on the screen to say what happened
     */
    public function testAnIdThatIsNotAUserIsDropped(): void {
        $controller = $this->controller([2]);
        $this->assertSame([], $controller->author('post', ['author_id' => '999']));
        $this->assertSame([], $controller->author('post', ['author_id' => '0']));
        $this->assertSame([], $controller->author('post', ['author_id' => 'gopher']));
    }

    // --- who is offered ---

    /**
     * The people who write here - admins and editors - and not every reader with an account
     */
    public function testTheSelectOffersTheAdminsAndTheEditors(): void {
        $controller = $this->controller([2, 3, 4], [2, 3]);
        $this->assertSame([2 => 'user 2', 3 => 'user 3'], $controller->options());
        $this->assertSame([Role::NAME_ADMIN, Role::NAME_EDITOR], $this->askedWith['role_names'] ?? null);
    }

    /**
     * A reader's account exists, so it would save - but it was never in the select, and a post
     * handed to somebody the select did not show is not a choice anybody made
     */
    public function testAnAccountThatIsNotAWriterIsNotAccepted(): void {
        $controller = $this->controller([2, 4], [2]);
        $this->assertSame([], $controller->author('post', ['author_id' => '4']));
        $this->assertSame(['author_id' => 2], $controller->author('post', ['author_id' => '2']));
    }

    /**
     * Whoever wrote the post stays in the list after losing the role, or the next save would
     * quietly give the post to whoever is at the top
     */
    public function testThePostsOwnAuthorIsOfferedEvenWithoutTheRole(): void {
        $controller = $this->controller([2, 4], [2]);
        $post = new Content();
        $post->author_id = 4;
        $this->assertArrayHasKey(4, $controller->options($post));
        $this->assertSame(['author_id' => 4], $controller->author('post', ['author_id' => '4'], $post));
    }

    public function testTheUserQueryKeepsToTheRolesAsked(): void {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturnCallback(fn(string $class) => '`'.basename(str_replace('\\', '/', $class)).'`');
        $query = (new CoreQueries($em))->userList(['role_names' => [Role::NAME_ADMIN, Role::NAME_EDITOR]]);
        $sql = implode(' ', $query->conditions());
        $this->assertStringContainsString('exists (select 1 from', $sql);
        $this->assertStringContainsString('`r`.`name` in (:roleName0, :roleName1)', $sql);
        $this->assertSame([Role::NAME_ADMIN, Role::NAME_EDITOR],
            [$query->variables()[':roleName0'], $query->variables()[':roleName1']]);
    }

    public function testSomebodyWhoMayNotReassignChangesNothing(): void {
        $controller = $this->controller([2]);
        $controller->mayAssign = false;
        $this->assertSame([], $controller->author('post', ['author_id' => '2']));
    }

    /**
     * "Leave it alone" is a form with no such field, which is every editor before this and every
     * plugin that builds one without it
     */
    public function testAFormWithNoAuthorFieldLeavesTheAuthorAlone(): void {
        $this->assertSame([], $this->controller()->author('post', ['title' => 'A post']));
    }

    // --- the permission ---

    public function testAssigningAnAuthorIsItsOwnPermissionAndNotAnEditors(): void {
        foreach ([Permissions::POST_ASSIGN_AUTHOR, Permissions::PAGE_ASSIGN_AUTHOR] as $permission) {
            $this->assertArrayHasKey($permission, Permissions::CORE, "$permission is not offered");
            $this->assertNotContains(
                $permission, CreateSchema::EDITOR_PERMISSIONS,
                "$permission should not be an editor's - writing a post and deciding who wrote it"
                    ." are not the same authority"
            );
        }
        $this->assertSame(Permissions::POST_ASSIGN_AUTHOR, Permissions::forContent('post', 'assign_author'));
        $this->assertSame(Permissions::PAGE_ASSIGN_AUTHOR, Permissions::forContent('page', 'assign_author'));
    }

    // --- the list ---

    /**
     * The slug is in the editor, where it is edited. On a list it was a second copy of the title
     * in a different shape, taking the width the dates and the weight want.
     */
    public function testTheContentListNoLongerSortsBySlug(): void {
        $this->assertNotContains('slug', ContentAdminController::SORTABLE);
    }
}
