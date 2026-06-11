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

        $filter = in_array($filter, [
            'all',
            'enabled',
            'disabled',
            'manual',
            'content',
            'product',
            'page',
        ], true) ? $filter : 'all';

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
        ]);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws TypeException
     * @throws \Qubus\Exception\Exception
     * @throws ReflectionException
     */
    public function save(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();

        $id = trim((string) ($post['id'] ?? ''));
        $source = trim((string) ($post['source_url'] ?? ''));
        $target = trim((string) ($post['target_url'] ?? ''));
        $status = (int) ($post['status_code'] ?? 301);
        $enabled = !empty($post['enabled']);

        if ($this->redirects->wouldCreateLoop($source, $target, $id !== '' ? $id : null)) {
            Devflow::$PHP->flash->error(
                t__(
                    'Redirect loop detected. Source and target cannot redirect back to each other or to themselves.',
                    'simple-seo'
                )
            );

            return redirect(admin_url('plugin/simple-seo/redirects/'));
        }

        if ($this->redirects->sourceExistsForDifferentRedirect($source, $id !== '' ? $id : null)) {
            Devflow::$PHP->flash->error(
                t__('A redirect already exists for this source URL.', 'simple-seo')
            );

            return redirect(admin_url('plugin/simple-seo/redirects/'));
        }

        if (!in_array($status, [301, 302, 307, 308], true)) {
            $status = 301;
        }

        if ($source !== '' && $target !== '') {
            if ($id !== '') {
                $this->redirects->updateById(
                    id: $id,
                    sourceUrl: $source,
                    targetUrl: $target,
                    statusCode: $status,
                    enabled: $enabled
                );

                $redirect = $this->redirects->findById($id);

                if ($redirect !== false) {
                    $this->sync->syncEntityFromRedirect($redirect);
                }
            } else {
                $this->redirects->createOrUpdate(
                    sourceUrl: $source,
                    targetUrl: $target,
                    statusCode: $status,
                    entityType: null,
                    entityId: null,
                    enabled: $enabled
                );
            }
        }

        Devflow::$PHP->flash->success(Devflow::$PHP->flash->notice(200));
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
