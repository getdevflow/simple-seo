<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Tests;

use App\Shared\Services\Registry;
use Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware;
use DOMDocument;
use DOMXPath;
use Gettext\Translator;
use Gettext\TranslatorFunctions;
use PHPUnit\Framework\TestCase;
use Qubus\View\Native\NativeLoader;

use function Codefy\Framework\Helpers\app;

final class PaginationTest extends TestCase
{
    public function testBothScreensRenderTheSelectedPageAndPreserveFilters(): void
    {
        if (!function_exists('d__')) {
            TranslatorFunctions::register(new Translator());
        }
        app(CsrfTokenMiddleware::class);
        $originalHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'cms.test';
        $registry = Registry::getInstance();
        $originalRegistry = $registry->getAll();
        $registry->set('simple-seo', ['id' => 'simple-seo']);

        $layoutRoot = sys_get_temp_dir() . '/simple-seo-pagination-' . bin2hex(random_bytes(8));
        mkdir($layoutRoot . '/backend/layouts', 0700, true);
        file_put_contents(
            $layoutRoot . '/backend/layouts/admin-layout.phtml',
            '<?php $this->block("backend");'
        );

        try {
            $loader = new NativeLoader([
                'plugin' => dirname(__DIR__, 2),
                'framework' => $layoutRoot,
            ]);

            foreach (['404-monitor', 'redirects'] as $screen) {
                foreach ([1, 2, 6, 10] as $page) {
                    $html = $loader->render('plugin::SimpleSeo/view/' . $screen, [
                        'page' => $page,
                        'totalPages' => 10,
                        'total' => 100,
                        'perPage' => 10,
                        'filter' => 'all',
                        'search' => '/missing path/?a=1&b=<test>',
                        'items' => [],
                        'redirects' => [],
                    ]);
                    $document = new DOMDocument();
                    @$document->loadHTML($html);
                    $xpath = new DOMXPath($document);
                    $pagination = '//ul[contains(@class, "pagination")]';
                    $active = $xpath->query($pagination . '/li[@class="active"]/a');

                    self::assertCount(1, $active, $screen);
                    self::assertSame((string) $page, trim($active->item(0)->textContent), $screen);
                    self::assertSame('page', $active->item(0)->getAttribute('aria-current'));
                    $numbers = [];

                    foreach ($xpath->query($pagination . '/li/a') as $link) {
                        parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                        self::assertSame('all', $query['filter']);
                        self::assertSame('10', $query['per_page']);
                        self::assertSame('/missing path/?a=1&b=<test>', $query['s']);
                        self::assertStringContainsString('/simple-seo/' . $screen . '/', $link->getAttribute('href'));

                        if (ctype_digit(trim($link->textContent))) {
                            $numbers[] = (int) trim($link->textContent);
                            self::assertSame((string) end($numbers), $query['paged']);
                        }
                    }

                    self::assertContains(1, $numbers);
                    self::assertContains(10, $numbers);
                    self::assertContains($page, $numbers);
                    self::assertGreaterThanOrEqual(5, count($numbers));
                    self::assertLessThanOrEqual(7, count($numbers));
                    self::assertStringContainsString('Showing page ' . $page, $html);
                    self::assertCount($page === 6 ? 2 : 1, $xpath->query($pagination . '/li[span="…"]'));
                    if ($page === 1) {
                        self::assertCount(0, $xpath->query($pagination . '/li[1]/a'));
                        self::assertSame([1, 2, 3, 4, 5, 10], $numbers);
                    }
                    if ($page === 10) {
                        self::assertCount(0, $xpath->query($pagination . '/li[last()]/a'));
                        self::assertSame([1, 6, 7, 8, 9, 10], $numbers);
                    }
                }
            }
        } finally {
            if ($originalHost === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $originalHost;
            }
            $registry->clear();
            $registry->add($originalRegistry);
            unlink($layoutRoot . '/backend/layouts/admin-layout.phtml');
            rmdir($layoutRoot . '/backend/layouts');
            rmdir($layoutRoot . '/backend');
            rmdir($layoutRoot);
        }
    }

    public function testSinglePageDoesNotRenderNavigation(): void
    {
        $loader = new NativeLoader(['plugin' => dirname(__DIR__, 2)]);

        self::assertSame('', $loader->render('plugin::SimpleSeo/view/partial/pagination', [
            'currentPage' => 1,
            'totalPages' => 1,
        ]));
    }
}
