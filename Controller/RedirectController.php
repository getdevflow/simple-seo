<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use App\Application\Devflow;
use Exception;
use Plugin\SimpleSeo\Repository\RedirectRepository;
use Plugin\SimpleSeo\Service\RedirectSyncService;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Qubus\Exception\Data\TypeException;
use Qubus\Http\ServerRequest;
use ReflectionException;

use function App\Shared\Helpers\admin_url;
use function Codefy\Framework\Helpers\view;
use function in_array;
use function Qubus\Routing\Helpers\redirect;
use function Qubus\Security\Helpers\t__;
use function trim;

final readonly class RedirectController
{
    public function __construct(
        private RedirectRepository $redirects,
        private RedirectSyncService $sync
    ) {
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws Exception
     */
    public function index(ServerRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $filter = (string) ($query['filter'] ?? 'all');

        $filter = in_array(
            $filter,
            [
                'all',
                'enabled',
                'disabled',
                'manual',
                'host',
                'wildcard',
                'content',
                'product',
                'page',
            ],
            true
        ) ? $filter : 'all';

        $search = trim((string) ($query['s'] ?? ''));

        $page = max(1, (int) ($query['paged'] ?? 1));

        $perPage = (int) ($query['per_page'] ?? 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true)
            ? $perPage
            : 25;

        $pagination = $this->redirects->paginated(
            filter: $filter,
            search: $search,
            page: $page,
            perPage: $perPage
        );

        $redirects = $pagination['items'];

        return view('plugin::SimpleSeo/view/redirects', [
            'title' => t__('Redirect Manager', 'simple-seo'),
            'redirects' => $redirects,
            'chainWarnings' => $this->redirects->chainWarnings($redirects),
            'filter' => $filter,
            'search' => $search,
            'page' => $pagination['page'],
            'perPage' => $pagination['perPage'],
            'total' => $pagination['total'],
            'totalPages' => $pagination['totalPages'],
            'allCount' => $this->redirects->countByFilter('all'),
            'enabledCount' => $this->redirects->countByFilter('enabled'),
            'disabledCount' => $this->redirects->countByFilter('disabled'),
            'manualCount' => $this->redirects->countByFilter('manual'),
            'contentCount' => $this->redirects->countByFilter('content'),
            'productCount' => $this->redirects->countByFilter('product'),
            'pageCount' => $this->redirects->countByFilter('page'),
            'hostCount' => $this->redirects->countByFilter('host'),
            'wildcardCount' => $this->redirects->countByFilter('wildcard'),
        ]);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     */
    public function save(ServerRequest $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        $id = trim((string) ($body['id'] ?? ''));

        $sourceHost = trim((string) ($body['source_host'] ?? ''));

        $sourceUrl = trim((string) ($body['source_url'] ?? ''));

        $targetUrl = trim((string) ($body['target_url'] ?? ''));

        $excludedPaths = trim((string) ($body['excluded_paths'] ?? ''));

        $statusCode = (int) ($body['status_code'] ?? 301);

        $enabled = isset($body['enabled']) && (string) $body['enabled'] !== '0';

        $preserveQuery = isset($body['preserve_query']) && (string) $body['preserve_query'] !== '0';

        try {
            $existing = $id !== ''
                ? $this->redirects->findById($id)
                : false;

            if ($id !== '' && $existing === false) {
                Devflow::$PHP->flash->error(
                    t__(
                        'The redirect could not be found.',
                        'simple-seo'
                    )
                );

                return redirect(
                    admin_url('plugin/simple-seo/redirects/')
                );
            }

            /*
             * Entity-generated redirects remain exact, host-less rules.
             *
             * Their source/target/status are synchronized back to
             * content/product/page SEO attributes.
             */
            if (
                $existing !== false
                && trim((string) ($existing->entity_type ?? '')) !== ''
            ) {
                $sourceHost = '';
                $excludedPaths = '';
                $preserveQuery = true;
            }

            if (
                $this->redirects->sourceExistsForDifferentRedirect(
                    sourceUrl: $sourceUrl,
                    ignoreId: $id !== '' ? $id : null,
                    sourceHost: $sourceHost
                )
            ) {
                Devflow::$PHP->flash->error(
                    t__(
                        'A redirect with the same host and source path already exists.',
                        'simple-seo'
                    )
                );

                return redirect(
                    admin_url('plugin/simple-seo/redirects/')
                );
            }

            if (
                    $this->redirects->wouldCreateLoop(
                        sourceUrl: $sourceUrl,
                        targetUrl: $targetUrl,
                        ignoreId: $id !== '' ? $id : null,
                        sourceHost: $sourceHost
                    )
            ) {
                Devflow::$PHP->flash->error(
                    t__(
                        'This redirect would create a loop.',
                        'simple-seo'
                    )
                );

                return redirect(
                    admin_url('plugin/simple-seo/redirects/')
                );
            }

            if ($id !== '') {
                $this->redirects->updateById(
                    id: $id,
                    sourceUrl: $sourceUrl,
                    targetUrl: $targetUrl,
                    statusCode: $statusCode,
                    enabled: $enabled,
                    sourceHost: $sourceHost,
                    excludedPaths: $excludedPaths,
                    preserveQuery: $preserveQuery
                );

                $redirect = $this->redirects->findById($id);

                if ($redirect !== false) {
                    $this->sync->syncEntityFromRedirect(
                        $redirect
                    );
                }
            } else {
                $this->redirects->createOrUpdate(
                    sourceUrl: $sourceUrl,
                    targetUrl: $targetUrl,
                    statusCode: $statusCode,
                    enabled: $enabled,
                    sourceHost: $sourceHost,
                    excludedPaths: $excludedPaths,
                    preserveQuery: $preserveQuery
                );
            }

            Devflow::$PHP->flash->success(
                Devflow::$PHP->flash->notice(200)
            );
        } catch (\InvalidArgumentException $exception) {
            Devflow::$PHP->flash->error(
                $exception->getMessage()
            );
        }

        return redirect(
            admin_url('plugin/simple-seo/redirects/')
        );
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     */
    public function enable(ServerRequest $request): ResponseInterface
    {
        $id = (string) (($request->getParsedBody())['id'] ?? '');

        if ($id !== '') {
            $this->enableRedirect($id);
        }

        return redirect(admin_url('plugin/simple-seo/redirects/'));
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     */
    public function disable(ServerRequest $request): ResponseInterface
    {
        $id = (string) (($request->getParsedBody())['id'] ?? '');

        if ($id !== '') {
            $this->disableRedirect($id);
        }

        return redirect(admin_url('plugin/simple-seo/redirects/'));
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     */
    public function delete(ServerRequest $request): ResponseInterface
    {
        $id = (string) (($request->getParsedBody())['id'] ?? '');

        if ($id !== '') {
            $this->deleteRedirect($id);
        }

        return redirect(admin_url('plugin/simple-seo/redirects/'));
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     */
    public function bulk(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();

        $action = (string) ($post['bulk_action'] ?? '');
        $ids = explode(',', (string) ($post['ids'] ?? ''));

        $ids = array_filter(
            array_map('trim', $ids),
            static fn ($id) => $id !== ''
        );

        foreach ($ids as $id) {
            match ($action) {
                'enable' => $this->enableRedirect($id),
                'disable' => $this->disableRedirect($id),
                'delete' => $this->deleteRedirect($id),
                default => null,
            };
        }

        return redirect(admin_url('plugin/simple-seo/redirects/'));
    }

    /**
     * @param string $id
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     */
    private function enableRedirect(string $id): void
    {
        $this->redirects->setEnabled($id, true);

        $redirect = $this->redirects->findById($id);

        if ($redirect !== false) {
            $this->sync->syncEntityFromRedirect($redirect);
        }
    }

    /**
     * @param string $id
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     */
    private function disableRedirect(string $id): void
    {
        $this->redirects->setEnabled($id, false);

        $redirect = $this->redirects->findById($id);

        if ($redirect !== false) {
            $this->sync->syncEntityFromRedirect($redirect);
        }
    }

    /**
     * @param string $id
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     */
    private function deleteRedirect(string $id): void
    {
        $redirect = $this->redirects->findById($id);

        $this->redirects->delete($id);

        if ($redirect !== false) {
            $redirect->enabled = 0;
            $redirect->source_url = '';
            $redirect->target_url = '';
            $this->sync->syncEntityFromRedirect($redirect);
        }
    }
}
