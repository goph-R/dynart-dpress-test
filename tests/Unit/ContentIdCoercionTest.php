<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Service\ContentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A `<select>` posts a string, and a foreign key column is `?int`
 *
 * `Content::$parent_id` and `$featured_media_id` are typed `?int`, and both are filled from a
 * control whose "nothing chosen" value is the empty string. Assigning that is a fatal, and it
 * only ever happened in a browser: every curl test sent the interesting fields and left the
 * empty ones out entirely, so `?? null` covered for it.
 *
 * @covers \Dynart\Dpress\Service\ContentService
 * @covers \Dynart\Dpress\Controller\Admin\ContentAdminController
 */
class ContentIdCoercionTest extends TestCase {

    /**
     * Both methods are protected and neither touches a dependency, so there is nothing to mock
     */
    private function call(string $className, string $method, array $arguments): mixed {
        $instance = (new ReflectionClass($className))->newInstanceWithoutConstructor();
        $reflection = new ReflectionMethod($className, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($instance, $arguments);
    }

    private function nullableId(mixed $value): ?int {
        return $this->call(ContentService::class, 'nullableId', [$value]);
    }

    private function contentData(array $values): array {
        return $this->call(ContentAdminController::class, 'contentData', [$values]);
    }

    // --- the service ---

    /**
     * The exact value that fataled: an unchosen `<select>` posts `''`
     */
    public function testAnEmptyStringIsNoId(): void {
        $this->assertNull($this->nullableId(''));
    }

    public function testNothingIsNoId(): void {
        $this->assertNull($this->nullableId(null));
        $this->assertNull($this->nullableId(false));
    }

    /**
     * `(int)''` is `0` and there is no row with that id, so it means the same as nothing
     */
    public function testZeroIsNoId(): void {
        $this->assertNull($this->nullableId(0));
        $this->assertNull($this->nullableId('0'));
        $this->assertNull($this->nullableId(-3));
    }

    public function testAnIdComesBackAsAnInt(): void {
        $this->assertSame(5, $this->nullableId('5'));
        $this->assertSame(5, $this->nullableId(5));
    }

    // --- the controller ---

    public function testTheEmptyIdFieldsBecomeNull(): void {
        $data = $this->contentData([
            'title' => 'About', 'markdown' => 'Body.',
            'parent_id' => '', 'featured_media_id' => '',
        ]);
        $this->assertNull($data['parent_id']);
        $this->assertNull($data['featured_media_id']);
    }

    public function testAChosenIdBecomesAnInt(): void {
        $data = $this->contentData(['parent_id' => '5', 'featured_media_id' => '3']);
        $this->assertSame(5, $data['parent_id']);
        $this->assertSame(3, $data['featured_media_id']);
    }

    /**
     * `update()` treats an absent key as "leave it alone", so a page editor - which has no
     * category field at all - must not arrive carrying an empty one
     */
    public function testAFieldTheFormDoesNotHaveStaysOut(): void {
        $data = $this->contentData(['title' => 'About', 'markdown' => 'Body.']);
        $this->assertArrayNotHasKey('parent_id', $data);
        $this->assertArrayNotHasKey('featured_media_id', $data);
        $this->assertArrayNotHasKey('status', $data);
    }

    /**
     * The form also carries its token, the tag string and the category boxes. None of those are
     * columns, and neither is whatever a plugin adds - a plugin that wants to write to the entity
     * does it through the service on `after_process`, not by having a field named like a column.
     */
    public function testOnlyContentColumnsGetThrough(): void {
        $data = $this->contentData([
            'title' => 'About', 'markdown' => 'Body.', 'slug' => 'about', 'status' => 'published',
            '_csrf' => 'a-token', 'tags' => 'one, two', 'categories' => ['1', '2'],
            'author_id' => '999', 'myplugin_field' => 'x',
        ]);
        $this->assertSame(
            ['title', 'markdown', 'slug', 'status'],
            array_keys($data),
            'something that is not a content column reached the entity'
        );
    }
}
