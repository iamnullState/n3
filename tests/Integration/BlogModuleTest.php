<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\App\Identity\PdoUserRepository;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TransactionManager;
use N3\Core\Http\Request;
use N3\Core\Logging\FileLogger;
use N3\Core\Module\ModuleLifecycleService;
use N3\Core\Module\ModuleMigrationRunner;
use N3\Core\Module\PdoModuleLifecycleRepository;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Security\CurrentActor;
use N3\Core\Security\CurrentActorProvider;
use N3\Core\Session\ArraySessionStore;
use N3\Core\Session\FlashBag;
use N3\Core\View\View;
use N3\Module\Blog\BlogController;
use N3\Module\Blog\BlogModule;
use N3\Module\Blog\BlogSchema;
use N3\Module\Blog\BlogService;
use N3\Module\Blog\BlogValidator;
use N3\Module\Blog\PdoBlogRepository;
use N3\Module\CoreProbe\CoreProbeModule;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlogModuleTest extends TestCase
{
    private PDO $connection;
    private PDO $migration;
    private BlogService $blog;
    private int $adminId;
    private string $requestId;
    private string $log;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach (['N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME', 'N3_TEST_DB_USER', 'N3_TEST_DB_PASSWORD', 'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD'] as $key) {
            if (!getenv($key)) { $this->markTestSkipped(sprintf('%s is not configured.', $key)); }
        }
        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) { throw new RuntimeException('Integration database names must end in _test.'); }
        $factory = new ConnectionFactory();
        $this->connection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database,
            (string) getenv('N3_TEST_DB_USER'), (string) getenv('N3_TEST_DB_PASSWORD'),
        ));
        $this->migration = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'), (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        ));
        (new MigrationRunner($this->migration, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        (new ModuleMigrationRunner($this->migration, [new BlogModule()]))->migrate();
        $email = 'blog-admin-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->adminId = (new PdoUserRepository($this->connection))->createAdmin(
            'Blog Admin', $email, $email, password_hash('test administrator passphrase', PASSWORD_DEFAULT),
        );
        $this->requestId = bin2hex(random_bytes(8));
        $transactions = new TransactionManager($this->connection);
        $this->blog = new BlogService(new BlogValidator(), new PdoBlogRepository($this->connection, $transactions));
        $this->log = tempnam(sys_get_temp_dir(), 'n3-blog-test-');
        self::assertNotFalse($this->log);
    }

    protected function tearDown(): void
    {
        if (!isset($this->migration)) { return; }
        if (isset($this->adminId)) {
            $this->connection->prepare(sprintf('DELETE FROM `%s` WHERE actor_user_id = :id', BlogSchema::eventsTable()))->execute(['id' => $this->adminId]);
            $this->connection->prepare(sprintf('DELETE FROM `%s` WHERE author_id = :id', BlogSchema::postsTable()))->execute(['id' => $this->adminId]);
            $this->connection->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->adminId]);
        }
        $this->migration->exec(sprintf('DROP TABLE IF EXISTS `%s`', BlogSchema::eventsTable()));
        $this->migration->exec(sprintf('DROP TABLE IF EXISTS `%s`', BlogSchema::postsTable()));
        foreach (['module_migrations', 'module_events', 'modules'] as $table) {
            $this->migration->prepare('DELETE FROM ' . $table . ' WHERE module_id = :module_id')->execute(['module_id' => BlogSchema::MODULE_ID]);
        }
        if (isset($this->log) && is_file($this->log)) { unlink($this->log); }
    }

    public function testMigrationAndLifecycleAreModuleOwnedAndRuntimeCannotChangeSchema(): void
    {
        $columns = $this->migration->query(sprintf('SHOW COLUMNS FROM `%s`', BlogSchema::postsTable()))->fetchAll();
        self::assertSame(
            ['id', 'title', 'slug', 'excerpt', 'body', 'status', 'author_id', 'updated_by', 'lock_version', 'published_at', 'created_at', 'updated_at'],
            array_column($columns, 'Field'),
        );
        $eventColumns = array_column($this->migration->query(sprintf('SHOW COLUMNS FROM `%s`', BlogSchema::eventsTable()))->fetchAll(), 'Field');
        self::assertSame(['id', 'post_id', 'actor_user_id', 'event_type', 'from_status', 'to_status', 'request_id', 'occurred_at'], $eventColumns);
        foreach (['payload', 'token', 'ip_address', 'user_agent'] as $forbidden) {
            self::assertNotContains($forbidden, $eventColumns);
        }

        $lifecycle = new ModuleLifecycleService(
            new PdoModuleLifecycleRepository($this->connection), new TransactionManager($this->connection),
        );
        $enabledModules = [new CoreProbeModule(), new BlogModule()];
        $changes = $lifecycle->plan($enabledModules);
        self::assertCount(1, array_filter($changes, static fn ($change): bool => $change->moduleId === BlogSchema::MODULE_ID));
        $lifecycle->apply($changes);
        self::assertSame([], $lifecycle->plan($enabledModules));
        $stored = $this->connection->prepare('SELECT installed_version, state FROM modules WHERE module_id = :id');
        $stored->execute(['id' => BlogSchema::MODULE_ID]);
        self::assertSame(['installed_version' => '0.1.0', 'state' => 'enabled'], $stored->fetch());

        $postId = $this->blog->createDraft('Constraint probe', 'constraint-probe', '', 'Body', $this->adminId, $this->requestId)->postId;
        self::assertNotNull($postId);
        try {
            $invalid = $this->connection->prepare(sprintf(
                "INSERT INTO `%s` (post_id, actor_user_id, event_type, to_status) VALUES (:post, :actor, 'invalid', 'draft')",
                BlogSchema::eventsTable(),
            ));
            $invalid->execute(['post' => $postId, 'actor' => $this->adminId]);
            self::fail('The Blog audit vocabulary constraint accepted an invalid event.');
        } catch (\PDOException $exception) {
            self::assertSame('23000', (string) $exception->getCode());
        }

        try {
            $this->connection->exec('ALTER TABLE `' . BlogSchema::postsTable() . '` ADD forbidden_column INT NULL');
            self::fail('The runtime account changed Blog schema.');
        } catch (\PDOException $exception) {
            self::assertSame('42000', (string) $exception->getCode());
        }
    }

    public function testDraftLifecycleIsValidatedAuditedOptimisticAndPublishedOnly(): void
    {
        $created = $this->blog->createDraft(
            'Hello <script>', 'hello-blog', '<img src=x>', "First line\n<script>alert(1)</script>",
            $this->adminId, $this->requestId,
        );
        self::assertTrue($created->succeeded());
        self::assertNull($this->blog->findPublished('hello-blog'));
        self::assertArrayHasKey('slug', $this->blog->createDraft(
            'Duplicate', 'hello-blog', '', 'Body', $this->adminId, $this->requestId,
        )->errors);
        $post = $this->blog->find($created->postId);
        self::assertSame('draft', $post?->status);
        self::assertTrue($this->blog->updateDraft(
            $post->id, 'Updated', 'hello-blog', '', $post->body, $this->adminId, $post->lockVersion, $this->requestId,
        )->succeeded());
        self::assertTrue($this->blog->updateDraft(
            $post->id, 'Stale', 'hello-blog', '', 'stale', $this->adminId, $post->lockVersion, $this->requestId,
        )->conflict);
        $current = $this->blog->find($post->id);
        self::assertTrue($this->blog->publish($post->id, $this->adminId, $current->lockVersion, $this->requestId)->succeeded());
        self::assertSame('published', $this->blog->findPublished('hello-blog')?->status);
        self::assertTrue($this->blog->updateDraft(
            $post->id, 'Unsafe live edit', 'hello-blog', '', 'changed', $this->adminId,
            $this->blog->find($post->id)->lockVersion, $this->requestId,
        )->conflict);
        $published = $this->blog->find($post->id);
        self::assertTrue($this->blog->unpublish(
            $post->id, $this->adminId, $published->lockVersion, $this->requestId,
        )->succeeded());
        self::assertNull($this->blog->findPublished('hello-blog'));

        $events = $this->connection->prepare(sprintf(
            'SELECT event_type FROM `%s` WHERE actor_user_id = :id ORDER BY id', BlogSchema::eventsTable(),
        ));
        $events->execute(['id' => $this->adminId]);
        self::assertSame(['created', 'updated', 'published', 'unpublished'], $events->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testControllersEnforceAuthorityCsrfEscapingAndBoundedPagination(): void
    {
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $view = new View(dirname(__DIR__, 2) . '/resources/views');
        $anonymous = $this->controller(null, $csrf, $session, $view);
        self::assertSame('/login', $anonymous->adminIndex(Request::create('GET', '/admin/blog'))->headers()['Location']);
        $member = $this->controller(new CurrentActor($this->adminId, 'member'), $csrf, $session, $view);
        self::assertSame(403, $member->adminIndex(Request::create('GET', '/admin/blog'))->status());
        $admin = $this->controller(new CurrentActor($this->adminId, 'admin'), $csrf, $session, $view);
        self::assertSame(419, $admin->store(Request::create('POST', '/admin/blog', ['_csrf' => 'invalid']))->status());

        for ($number = 1; $number <= 12; ++$number) {
            $response = $admin->store(Request::create('POST', '/admin/blog', [
                '_csrf' => $csrf->token('blog_create'),
                'title' => $number === 1 ? '<script>Hostile title</script>' : 'Post ' . $number,
                'slug' => 'controller-post-' . $number,
                'excerpt' => $number === 1 ? '<img src=x>' : 'Summary ' . $number,
                'body' => $number === 1 ? '<script>alert(1)</script>' : 'Body ' . $number,
            ])->withAttribute('request_id', $this->requestId));
            self::assertSame(303, $response->status());
            preg_match('#/admin/blog/([0-9]+)/edit#', $response->headers()['Location'], $matches);
            $post = $this->blog->find((int) $matches[1]);
            if ($number === 1) {
                $route = ['id' => (string) $post->id];
                self::assertSame(419, $admin->update(Request::create('POST', '/admin/blog/' . $post->id, [
                    '_csrf' => 'invalid', 'lock_version' => (string) $post->lockVersion,
                ])->withAttribute('route_parameters', $route))->status());
                self::assertSame(419, $admin->publish(Request::create('POST', '/admin/blog/' . $post->id . '/publish', [
                    '_csrf' => 'invalid', 'lock_version' => (string) $post->lockVersion,
                ])->withAttribute('route_parameters', $route))->status());
                $preview = $admin->preview(Request::create('GET', '/admin/blog/' . $post->id . '/preview')->withAttribute('route_parameters', $route));
                self::assertSame('no-store', $preview->headers()['Cache-Control']);
                self::assertStringContainsString('noindex', $preview->headers()['X-Robots-Tag']);
            }
            $published = $admin->publish(Request::create('POST', '/admin/blog/' . $post->id . '/publish', [
                '_csrf' => $csrf->token('blog_publish_' . $post->id),
                'lock_version' => (string) $post->lockVersion,
            ])->withAttribute('route_parameters', ['id' => (string) $post->id])->withAttribute('request_id', $this->requestId));
            self::assertSame(303, $published->status());
        }

        $index = $admin->publicIndex(Request::create('GET', '/blog'));
        self::assertSame(200, $index->status());
        self::assertStringNotContainsString('Hostile title', $index->body());
        self::assertStringNotContainsString('alert(1)', $index->body());
        self::assertStringContainsString('Page 1 of 2', $index->body());
        self::assertLessThan(64 * 1024, strlen($index->body()));
        $second = $admin->publicIndex(Request::create('GET', '/blog?page=2'));
        self::assertStringContainsString('&lt;script&gt;Hostile title&lt;/script&gt;', $second->body());
        self::assertStringNotContainsString('<script>Hostile title</script>', $second->body());
        self::assertSame(400, $admin->publicIndex(Request::create('GET', '/blog?page[]=2'))->status());
        self::assertSame(404, $admin->publicIndex(Request::create('GET', '/blog?page=3'))->status());
        $post = $this->blog->findPublished('controller-post-1');
        $public = $admin->publicPost(Request::create('GET', '/blog/controller-post-1')->withAttribute('route_parameters', ['slug' => 'controller-post-1']));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $public->body());
        self::assertStringNotContainsString('<script>alert(1)</script>', $public->body());
        self::assertSame(404, $admin->publicPost(Request::create('GET', '/blog/CONTROLLER-POST-1')->withAttribute('route_parameters', ['slug' => 'CONTROLLER-POST-1']))->status());
        self::assertNotNull($post);
    }

    private function controller(?CurrentActor $actor, CsrfTokenManager $csrf, ArraySessionStore $session, View $view): BlogController
    {
        return new BlogController(
            $view,
            new IntegrationBlogActorProvider($actor),
            $this->blog,
            $csrf,
            new FlashBag($session),
            new FileLogger($this->log),
        );
    }
}

final readonly class IntegrationBlogActorProvider implements CurrentActorProvider
{
    public function __construct(private ?CurrentActor $actor) {}
    public function current(): ?CurrentActor { return $this->actor; }
}
