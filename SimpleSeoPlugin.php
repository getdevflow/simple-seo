<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo;

use App\Application\Devflow;
use App\Domain\Content\Model\Content;
use App\Domain\Product\Model\Product;
use App\Infrastructure\Services\Plugin;
use App\Shared\Services\Registry;
use Codefy\QueryBus\UnresolvableQueryHandlerException;
use Plugin\SimpleSeo\Controller\GoogleOAuthController;
use Plugin\SimpleSeo\Controller\ImportExportController;
use Plugin\SimpleSeo\Controller\IndexingController;
use Plugin\SimpleSeo\Controller\ManualIndexingController;
use Plugin\SimpleSeo\Controller\NotFoundController;
use Plugin\SimpleSeo\Controller\RedirectController;
use Plugin\SimpleSeo\Controller\RobotsController;
use Plugin\SimpleSeo\Controller\RouteSeoController;
use Plugin\SimpleSeo\Controller\SearchConsoleController;
use Plugin\SimpleSeo\Controller\SettingsController;
use Plugin\SimpleSeo\Controller\SitemapController;
use Plugin\SimpleSeo\Repository\RouteSeoRepository;
use Plugin\SimpleSeo\Routing\SeoRedirectBootManager;
use Plugin\SimpleSeo\Service\AutoSubmissionService;
use Plugin\SimpleSeo\Service\EntityUrlResolver;
use Plugin\SimpleSeo\Service\Indexing\ContentIndexSubmission;
use Plugin\SimpleSeo\Service\MetaRendererService;
use Plugin\SimpleSeo\Service\NotFoundMonitor;
use Plugin\SimpleSeo\Service\RedirectSyncService;
use Plugin\SimpleSeo\Service\SubmissionQueueProcessor;
use Plugin\SimpleSeo\Service\TrackingCodeRendererService;
use Plugin\SimpleSeo\Support\AdminFields;
use Plugin\SimpleSeo\Support\GoogleTokenStore;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\EventDispatcher\ActionFilter\Action;
use Qubus\EventDispatcher\ActionFilter\Filter;
use Qubus\Exception\Data\TypeException;
use Qubus\Exception\Exception;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Http\Factories\JsonResponseFactory;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Exceptions\TooLateToAddNewRouteException;
use Qubus\Routing\Psr7Router;
use ReflectionException;

use function App\Shared\Helpers\add_admin_menu;
use function App\Shared\Helpers\add_admin_submenu;
use function App\Shared\Helpers\cms_enqueue_css;
use function App\Shared\Helpers\cms_enqueue_js;
use function App\Shared\Helpers\compare_releases;
use function App\Shared\Helpers\delete_content_attribute;
use function App\Shared\Helpers\delete_option;
use function App\Shared\Helpers\delete_page_attribute;
use function App\Shared\Helpers\delete_product_attribute;
use function App\Shared\Helpers\get_content;
use function App\Shared\Helpers\get_content_by;
use function App\Shared\Helpers\get_page_by;
use function App\Shared\Helpers\get_pages;
use function App\Shared\Helpers\get_product_by;
use function App\Shared\Helpers\get_products;
use function App\Shared\Helpers\plugin_basename;
use function App\Shared\Helpers\plugin_dir_path;
use function App\Shared\Helpers\plugin_url;
use function dirname;
use function get_class;
use function is_array;
use function Qubus\Security\Helpers\esc_html__;

final class SimpleSeoPlugin extends Plugin
{
    /**
     * @return array
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function meta(): array
    {
        $plugin = [
            'name' => esc_html__(string: 'Simple Seo', domain: 'simple-seo'),
            'id' => 'simple-seo',
            'slug' => 'SimpleSeo',
            'author' => 'Joshua Parker',
            'version' => '1.2.5',
            'description' => esc_html__('Simple SEO is an SEO management suite for Devflow CMS covering on-page SEO, technical SEO, indexing, crawl management, 404 monitoring, and so much more.', 'simple-seo'),
            'basename' => plugin_basename(dirname(__FILE__)),
            'path' => plugin_dir_path(dirname(__FILE__)),
            'url' => plugin_url('', __CLASS__),
            'pluginUri' => 'https://github.com/getdevflow/simple-seo',
            'authorUri' => 'https://joshuaparker.dev/',
            'className' => get_class($this),
            'screenshot' => plugin_url('SimpleSeo/images/screenshot.png'),
        ];

        Registry::getInstance()->set('simple-seo', $plugin);

        return $plugin;
    }

    /**
     * @return void
     * @throws Exception
     * @throws ReflectionException
     * @throws TooLateToAddNewRouteException
     */
    public function handle(): void
    {
        if (compare_releases(Devflow::release(), '2.3.1', '<')) {
            $this->registerAdminNotice();
            return;
        }

        $action = Action::getInstance();

        $this->registerAdminFields();
        $this->registerFrontendOutput();
        $this->registerRoutes();
        $this->registerAdminMenu();
        $this->registerSeoRedirectSyncs();
        $this->register404Monitor();
        $this->registerTrackingOutput();
        $this->registerAutoSubmissionHooks();
        $action->addAction(hook: 'cms_admin_head', callback: [$this, 'registerAdminCss'], priority: 1);
        $action->addAction(hook: 'cms_admin_footer', callback: [$this, 'registerAdminJs'], priority: 1);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    private function registerAdminMenu(): void
    {
        Action::getInstance()->addAction('extension_plugins_menu', function (): void {
            echo add_admin_menu(
                location: $this->id(),
                menuTitle: $this->meta()['name'],
                screen: 'simple-seo',
                icon: 'fa fa-search',
                permission: 'manage:plugins',
                children: function () {
                    return add_admin_submenu(
                        location: $this->id(),
                        menuTitle: $this->meta()['name'],
                        menuRoute: 'plugin/' . $this->meta()['id'],
                        screen: $this->meta()['id'],
                        permission: 'manage:plugins'
                    ) .
                    add_admin_submenu(
                        location: $this->id(),
                        menuTitle: esc_html__(string: 'SEO Routes', domain: 'simple-seo'),
                        menuRoute: 'plugin/' . $this->meta()['id'] . '/routes',
                        screen: $this->meta()['id'] . '-routes',
                        permission: 'manage:plugins'
                    );
                }
            );
        });
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    private function registerAdminNotice(): void
    {
        Action::getInstance()->addAction('admin_notices', function () {
            echo '<div class="alert dismissable alert-danger center sticky">' .
                esc_html__(
                    'You must upgrade your system to at least v2.3 in order to use the Simple SEO plugin.',
                    $this->id()
                ) .
            '</div>';
        });
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    private function registerAdminFields(): void
    {
        $fields = new AdminFields();
        $filter = Filter::getInstance();

        $filter->addFilter(
            'content.attribute.box.extended',
            function ($html, string $type, ?string $id = null) use ($fields) {
                return $fields->contentExtended($html, $type, $id);
            },
            10,
            3
        );

        $filter->addFilter(
            'content.attribute.box.side',
            function ($html, string $type, ?string $id = null) use ($fields) {
                return $fields->contentSide($html, $type, $id);
            },
            10,
            3
        );

        $filter->addFilter(
            'product.attribute.box.extended',
            function ($html, ?string $id = null) use ($fields) {
                return $fields->productExtended($html, $id);
            },
            10,
            2
        );

        $filter->addFilter(
            'product.attribute.box.side',
            function ($html, ?string $id = null) use ($fields) {
                return $fields->productSide($html, $id);
            },
            10,
            2
        );

        $filter->addFilter(
            'page.attribute.box.extended',
            function ($html, string|int|null $id = null) use ($fields) {
                return $fields->pageExtended($html, $id);
            },
            10,
            2
        );

        $filter->addFilter(
            'page.attribute.box.side',
            function ($html, string|int|null $id = null) use ($fields) {
                return $fields->pageSide($html, $id);
            },
            10,
            2
        );
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     */
    public function registerAdminCss(): void
    {
        if (
            !self::isPath([
                '/admin/plugin/' . $this->id() . '/',
                '/admin/content-type',
                '/admin/product',
                '/admin/manager',
            ])
        ) {
            return;
        }

        cms_enqueue_css(
            config: 'plugin',
            asset: $this->url() . '/css/seo-admin.css',
            slug: $this->id()
        );
    }

    /**
     * @return void
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws TypeException
     */
    public function registerAdminJs(): void
    {
        if (
            !self::isPath([
                '/admin/plugin/' . $this->id() . '/',
                '/admin/content-type',
                '/admin/product',
                '/admin/manager',
            ])
        ) {
            return;
        }

        cms_enqueue_js(
            config: 'plugin',
            asset: $this->url() . '/js/seo-admin.js',
            slug: $this->id()
        );
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function register404Monitor(): void
    {
        Action::getInstance()->addAction('http.404', [$this, 'recordNotFound'], 10);
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    private function registerFrontendOutput(): void
    {
        Action::getInstance()->addAction('cms_head', function (): void {
            echo $this->renderSeoForCurrentRequest();
        });
    }

    public function recordNotFound(): void
    {
        Devflow::$PHP->make(name: NotFoundMonitor::class)
            ->recordCurrentRequest();
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    private function registerSeoRedirectSyncs(): void
    {
        /** @var RedirectSyncService $sync */
        $sync = Devflow::$PHP->make(name: RedirectSyncService::class);
        $action = Action::getInstance();

        $action->addAction('create_content', function (Content $content) use ($sync) {
            $sync->syncContent($content->id);
        });
        $action->addAction('update_content', function (string $id, Content $content) use ($sync) {
            $sync->syncContent($id);
        }, arguments: 2);

        $action->addAction('create_product', function (Product $product) use ($sync) {
            $sync->syncProduct($product->id);
        });
        $action->addAction('update_product', function (string $id, Product $product) use ($sync) {
            $sync->syncProduct($id);
        }, arguments: 2);

        $action->addAction('update_page', function ($page) use ($sync) {
            $sync->syncPage($page->getId());
        });
    }

    /**
     * @return string
     * @throws ReflectionException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     */
    private function renderSeoForCurrentRequest(): string
    {
        $renderer = new MetaRendererService();
        $filter = Filter::getInstance()->applyFilter('pagebuilder.support', true);

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = trim($path, '/');

        $page = get_page_by(field: 'slug', value: $path);
        if ($page === false) {
            $page = get_page_by(field: 'slug', value: '/' . $path);
        }

        if ($page !== false && $filter) {
            return $renderer->renderPage($page->id);
        }

        if ($path === '') {
            return $renderer->renderHome();
        }

        $segments = explode('/', $path);
        $slug = end($segments);

        if ($slug === false || $slug === '') {
            return $renderer->renderHome();
        }

        if (($segments[0] ?? '') === 'product') {
            $product = get_product_by('slug', $slug);

            if ($product !== false) {
                return $renderer->renderProduct($product->id);
            }
        }

        $content = get_content_by('slug', $slug);

        if ($content !== false) {
            return $renderer->renderContent($content->id);
        }

        $product = get_product_by('slug', $slug);

        if ($product !== false) {
            return $renderer->renderProduct($product->id);
        }

        $page = get_page_by('slug', $slug);
        if ($page !== false) {
            return $renderer->renderPage($page->id);
        }

        $routeSeo = Devflow::$PHP->make(name: RouteSeoRepository::class)
            ->findByRoute('/' . $path);

        if ($routeSeo !== false) {
            return $renderer->renderCustomRoute($routeSeo);
        }

        return $renderer->renderHome();
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    private function registerTrackingOutput(): void
    {
        $action = Action::getInstance();

        $action->addAction('cms_head', function (): void {
            echo Devflow::$PHP->make(name: TrackingCodeRendererService::class)->renderHead();
        }, 1);

        $action->addAction('cms_footer', function (): void {
            echo Devflow::$PHP->make(name: TrackingCodeRendererService::class)->renderFooter();
        }, 99);

        $action->addAction('cms_body_open', function (): void {
            echo Devflow::$PHP->make(name: TrackingCodeRendererService::class)->renderBodyOpen();
        }, 1);
    }

    /**
     * @throws ReflectionException
     */
    private function registerAutoSubmissionHooks(): void
    {
        $action = Action::getInstance();

        $action->addAction('create_content', function ($content) {
            $this->autoSubmitContent($content);
        }, 20, 1);
        $action->addAction('update_content', function ($id, $content) {
            $this->autoSubmitContent($content);
        }, 20, 2);

        $action->addAction('create_product', function ($product) {
            $this->autoSubmitProduct($product);
        }, 20, 1);
        $action->addAction('update_product', function ($id, $product) {
            $this->autoSubmitProduct($product);
        }, 20, 2);

        $action->addAction('update_page', [$this, 'autoSubmitPage'], 20, 1);

        $action->addAction('create_seo_route', [$this, 'autoSubmitCustomRoute'], 20, 1);
        $action->addAction('update_seo_route', [$this, 'autoSubmitCustomRoute'], 20, 1);
    }

    public function autoSubmitContent(mixed $content): void
    {
        $contentId = is_object($content)
            ? (string) ($content->id ?? $content->content_id ?? '')
            : (string) $content;

        $contentId = trim($contentId);

        if ($contentId === '') {
            return;
        }

        Devflow::$PHP
            ->make(name: ContentIndexSubmission::class)
            ->enqueue($contentId);
    }

    public function autoSubmitProduct(mixed $product): void
    {
        $id = is_object($product)
            ? (string) ($product->id ?? $product->product_id ?? '')
            : (string) ($product['id'] ?? $product['product_id'] ?? '');

        $id = trim($id);

        if ($id === '') {
            return;
        }

        $url = Devflow::$PHP
            ->make(name: EntityUrlResolver::class)
            ->productUrl($id);

        if ($url === null) {
            return;
        }

        Devflow::$PHP
            ->make(name: AutoSubmissionService::class)
            ->enqueueUrl(
                url: $url,
                entityType: 'product',
                entityId: $id
            );
    }

    public function autoSubmitPage(mixed $page): void
    {
        $id = is_object($page)
            ? (string) ($page->getId() ?? '')
            : (string) ($page['id'] ?? '');

        $id = trim($id);

        if ($id === '') {
            return;
        }

        $url = Devflow::$PHP
            ->make(name: EntityUrlResolver::class)
            ->pageUrl($id);

        if ($url === null) {
            return;
        }

        Devflow::$PHP
            ->make(name: AutoSubmissionService::class)
            ->enqueueUrl(
                url: $url,
                entityType: 'page',
                entityId: $id
            );
    }

    public function autoSubmitCustomRoute(mixed $route): void
    {
        $id = is_object($route)
            ? (string) ($route->id ?? '')
            : (string) ($route['id'] ?? '');

        $id = trim($id);

        if ($id === '') {
            return;
        }

        $url = Devflow::$PHP
            ->make(name: EntityUrlResolver::class)
            ->customRouteUrl($id);

        if ($url === null) {
            return;
        }

        Devflow::$PHP
            ->make(name: AutoSubmissionService::class)
            ->enqueueUrl(
                url: $url,
                entityType: 'route',
                entityId: $id
            );
    }

    /**
     * @return void
     * @throws ReflectionException
     * @throws TooLateToAddNewRouteException
     */
    private function registerRoutes(): void
    {
        Filter::getInstance()->addFilter('plugin.route', function (Psr7Router $router) {
            $router->addBootManager(
                Devflow::$PHP->make(name: SeoRedirectBootManager::class)
            );
        });

        $router = Devflow::$PHP->router;

        $router->get(uri: '/sitemap.xml', callback: function (SitemapController $controller) {
            return $controller->index();
        });
        $router->get(uri: '/sitemap-pages.xml', callback: function (SitemapController $controller) {
            return $controller->pages();
        });
        $router->get(uri: '/sitemap-content.xml', callback: function (SitemapController $controller) {
            return $controller->content();
        });
        $router->get(uri: '/sitemap-products.xml', callback: function (SitemapController $controller) {
            return $controller->products();
        });
        $router->get(uri: '/sitemap-news.xml', callback: function (SitemapController $controller) {
            return $controller->news();
        });
        $router->get(uri: '/sitemap-images.xml', callback: function (SitemapController $controller) {
            return $controller->images();
        });
        $router->get(uri: '/sitemap-videos.xml', callback: function (SitemapController $controller) {
            return $controller->videos();
        });
        $router->get(uri: '/sitemap.xsl', callback: function (SitemapController $controller) {
            return $controller->stylesheet();
        });

        $router->get(
            uri: '/sitemap-{contentType}.xml',
            callback: function (SitemapController $controller, string $contentType) {
                return $controller->contentType($contentType);
            }
        );

        $router->get(uri: '/admin/plugin/simple-seo/', callback: function (SettingsController $controller) {
            return $controller->index();
        })->middleware(['gate:manage:plugins, /admin/']);

        $router->post(
            uri: '/admin/plugin/simple-seo/',
            callback: function (SettingsController $controller, ServerRequest $request) {
                return $controller->save($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->get(
            uri: '/admin/plugin/simple-seo/search-console/',
            callback: function (SearchConsoleController $controller) {
                return $controller->index();
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/search-console/test/',
            callback: function (SearchConsoleController $controller) {
                return $controller->test();
            }
        )->middleware(['gate:manage:plugins, /admin/']);

        $router->get(
            uri: '/admin/plugin/simple-seo/indexing/',
            callback: function (IndexingController $controller) {
                return $controller->index();
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/indexing/submit/',
            callback: function (IndexingController $controller, ServerRequest $request) {
                return $controller->submit($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/indexing/manual/url/',
            callback: function (ManualIndexingController $controller, ServerRequest $request) {
                    return $controller->submitUrl($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/indexing/manual/urls/',
            callback: function (ManualIndexingController $controller, ServerRequest $request) {
                    return $controller->submitUrls($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/indexing/manual/sitemap/',
            callback: function (ManualIndexingController $controller, ServerRequest $request) {
                return $controller->submitSitemap($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);

        $router->post(
            uri: '/admin/plugin/simple-seo/analyze/',
            callback: function (IndexingController $controller, ServerRequest $request) {
                return $controller->analyze($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);

        $router->get(
            uri: '/admin/plugin/simple-seo/redirects/',
            callback: function (RedirectController $controller, ServerRequest $request) {
                    return $controller->index($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/redirects/save/',
            callback: function (RedirectController $controller, ServerRequest $request) {
                return $controller->save($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/redirects/enable/',
            callback: function (RedirectController $controller, ServerRequest $request) {
                return $controller->enable($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/redirects/disable/',
            callback: function (RedirectController $controller, ServerRequest $request) {
                return $controller->disable($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/redirects/delete/',
            callback: function (RedirectController $controller, ServerRequest $request) {
                return $controller->delete($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/redirects/bulk/',
            callback: function (RedirectController $controller, ServerRequest $request) {
                return $controller->bulk($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);

        $router->get(
            uri: '/admin/plugin/simple-seo/google/connect/',
            callback: function (GoogleOAuthController $controller) {
                    return $controller->connect();
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->get(
            uri: '/admin/plugin/simple-seo/google/callback/',
            callback: function (GoogleOAuthController $controller, ServerRequest $request) {
                return $controller->callback($request);
            }
        );
        $router->post(
            uri: '/admin/plugin/simple-seo/google/disconnect/',
            callback: function (GoogleOAuthController $controller) {
                return $controller->disconnect();
            }
        )->middleware(['gate:manage:plugins, /admin/']);

        $router->get(
            uri: '/admin/plugin/simple-seo/export/',
            callback: function (ImportExportController $controller) {
                return $controller->export();
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/import/',
            callback: function (ImportExportController $controller, ServerRequest $request) {
                return $controller->import($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);

        $router->get(
            uri: '/admin/plugin/simple-seo/404-monitor/',
            callback: function (NotFoundController $controller, ServerRequest $request) {
                return $controller->index($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/404-monitor/ignore/',
            callback: function (NotFoundController $controller, ServerRequest $request) {
                return $controller->ignore($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/404-monitor/delete/',
            callback: function (NotFoundController $controller, ServerRequest $request) {
                return $controller->delete($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/404-monitor/create-redirect/',
            callback: function (NotFoundController $controller, ServerRequest $request) {
                return $controller->createRedirect($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/404-monitor/restore/',
            callback: function (NotFoundController $controller, ServerRequest $request) {
                return $controller->restore($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/404-monitor/bulk/',
            callback: function (NotFoundController $controller, ServerRequest $request) {
                return $controller->bulk($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);

        $router->get(
            uri: '/admin/plugin/simple-seo/routes/',
            callback: function (RouteSeoController $controller) {
                return $controller->index();
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->map(
            ['GET', 'POST'],
            '/admin/plugin/simple-seo/routes/create/',
            callback: function (RouteSeoController $controller, ServerRequest $request) {
                return $controller->create($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->post(
            uri: '/admin/plugin/simple-seo/routes/delete/',
            callback: function (RouteSeoController $controller, ServerRequest $request) {
                return $controller->delete($request);
            }
        )->middleware(['gate:manage:plugins, /admin/']);
        $router->map(
            ['GET', 'POST'],
            '/admin/plugin/simple-seo/routes/{id}/',
            callback: function (RouteSeoController $controller, ServerRequest $request, string $id) {
                return $controller->edit($request, $id);
            }
        )->middleware(['gate:manage:plugins, /admin/']);

        $router->get(
            uri: '/admin/plugin/simple-seo/process-submission-queue/',
            callback: function (SubmissionQueueProcessor $processor) {
                return JsonResponseFactory::create($processor->process(10));
            }
        );

        $router->get(uri: '/robots.txt', callback: function (RobotsController $controller) {
            return $controller->index();
        });

        $router->get(uri: '/{key}.txt', callback: function (SitemapController $controller, string $key) {
            return $controller->indexNowKey($key);
        });
    }

    /**
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function onActivation(): void
    {
        SimpleSeoSettings::installDefaults();

        $this->migrateUp();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws UnresolvableQueryHandlerException
     * @throws \Exception
     */
    public function onDeactivation(): void
    {
        $settings = new SimpleSeoSettings();

        if ((bool) $settings->all()['remove_settings'] === false) {
            return;
        }

        delete_option(key: $settings::OPTION_KEY);
        GoogleTokenStore::clear();

        $this->migrateDown();

        $pages = get_pages();
        $contents = get_content();
        $products = get_products();

        if ($pages) {
            foreach ($pages as $page) {
                delete_page_attribute($page->id, 'seo');
            }
        }

        if ($products) {
            foreach ($products as $product) {
                $id = is_array($product) ? ($product['id'] ?? $product['product_id'] ?? null) : ($product->id ?? null);

                if ($id !== null) {
                    delete_product_attribute($id, 'seo');
                }
            }
        }

        if ($contents) {
            foreach ($contents as $content) {
                $id = is_array($content) ? ($content['id'] ?? $content['content_id'] ?? null) : ($content->id ?? null);

                if ($id !== null) {
                    delete_content_attribute($id, 'seo');
                }
            }
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    private function migrateUp(): void
    {
        if (!$this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'seo_redirect')) {
            $this->dfdb->schema()
                ->create(
                    table: $this->dfdb->prefix . 'seo_redirect',
                    callback: function (CreateTable $table) {
                        $table->string(name: 'id', length: 36)
                            ->primary();
                        $table->string(name: 'source_url', length: 191)->notNull()->unique();
                        $table->string(name: 'target_url', length: 191)->notNull();
                        $table->integer(name: 'status_code')
                            ->size('small')
                            ->notNull()
                            ->defaultValue(301);
                        $table->string(name: 'entity_type', length: 50);
                        $table->string(name: 'entity_id', length: 191);
                        $table->integer(name: 'hits')
                            ->size('small')
                            ->notNull()
                            ->defaultValue(0);
                        $table->integer(name: 'enabled')
                            ->size('small')
                            ->notNull()
                            ->defaultValue(1);
                        $table->dateTime(name: 'created_at')->notNull();
                        $table->dateTime(name: 'updated_at')->notNull();
                    }
                );
        };

        if (!$this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'seo_404_log')) {
            $this->dfdb->schema()
                ->create(
                    table: $this->dfdb->prefix . 'seo_404_log',
                    callback: function (CreateTable $table) {
                        $table->string(name: 'id', length: 36)
                            ->primary();
                        $table->string(name: 'request_path', length: 191)->notNull()->unique();
                        $table->string(name: 'referrer', length: 500);
                        $table->string(name: 'user_agent', length: 500);
                        $table->integer(name: 'hits')
                                ->size('small')
                                ->notNull()
                                ->defaultValue(1);
                        $table->integer(name: 'ignored')
                                ->size('small')
                                ->notNull()
                                ->defaultValue(0);
                        $table->dateTime(name: 'first_seen')->notNull();
                        $table->dateTime(name: 'last_seen')->notNull();
                    }
                );
        };

        if (!$this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'seo_route')) {
            $this->dfdb->schema()
                ->create(
                    table: $this->dfdb->prefix . 'seo_route',
                    callback: function (CreateTable $table) {
                        $table->string(name: 'id', length: 36)
                            ->primary();
                        $table->string(name: 'route_path', length: 191)->notNull()->unique();
                        $table->string(name: 'route_label', length: 191)->notNull();
                        $table->text(name: 'seo_data')->size('big');
                        $table->integer(name: 'enabled')
                            ->size('small')
                            ->notNull()
                            ->defaultValue(1);
                        $table->dateTime(name: 'created_at')->notNull();
                        $table->dateTime(name: 'updated_at')->notNull();
                    }
                );
        };

        if (!$this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'seo_submission_queue')) {
            $this->dfdb->schema()
                ->create(
                    table: $this->dfdb->prefix . 'seo_submission_queue',
                    callback: function (CreateTable $table) {
                        $table->string(name: 'id', length: 36)
                            ->primary();
                        $table->string(name: 'url', length: 500)->notNull();
                        $table->string(name: 'engine', length: 50)->notNull()->defaultValue('both');
                        $table->string(name: 'entity_type', length: 50)->index();
                        $table->string(name: 'entity_id', length: 36)->index();
                        $table->string(name: 'status', length: 50)->notNull()->defaultValue('pending');
                        $table->integer(name: 'attempts')
                            ->size('small')
                            ->notNull()
                            ->defaultValue(0);
                        $table->text(name: 'last_error')->size('big');
                        $table->dateTime(name: 'created_at')->notNull();
                        $table->dateTime(name: 'updated_at')->notNull();
                        $table->dateTime(name: 'processed_at');
                        $table->unique(['url', 'engine', 'status']);
                    }
                );
        };
    }

    /**
     * @return void
     * @throws Exception
     */
    private function migrateDown(): void
    {
        if ($this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'seo_redirect')) {
            $this->dfdb->schema()->drop(table: $this->dfdb->prefix . 'seo_redirect');
        }

        if ($this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'seo_404_log')) {
            $this->dfdb->schema()->drop(table: $this->dfdb->prefix . 'seo_404_log');
        }

        if ($this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'seo_route')) {
            $this->dfdb->schema()->drop(table: $this->dfdb->prefix . 'seo_route');
        }

        if ($this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'seo_submission_queue')) {
            $this->dfdb->schema()->drop(table: $this->dfdb->prefix . 'seo_submission_queue');
        }
    }
}
