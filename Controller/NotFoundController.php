<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Exception;
use Plugin\SimpleSeo\Repository\NotFoundRepository;
use Plugin\SimpleSeo\Repository\RedirectRepository;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function App\Shared\Helpers\admin_url;
use function Codefy\Framework\Helpers\view;
use function in_array;
use function Qubus\Routing\Helpers\redirect;
use function Qubus\Security\Helpers\t__;
use function trim;

final readonly class NotFoundController
{
    public function __construct(
        private NotFoundRepository $notFound,
        private RedirectRepository $redirects
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

        $filter = (string) ($query['filter'] ?? 'active');
        $filter = in_array($filter, ['active', 'ignored', 'all'], true)
            ? $filter
            : 'active';

        $search = trim((string) ($query['s'] ?? ''));

        $page = max(1, (int) ($query['paged'] ?? 1));

        $perPage = (int) ($query['per_page'] ?? 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true)
            ? $perPage
            : 25;

        $pagination = $this->notFound->paginated(
            filter: $filter,
            search: $search,
            page: $page,
            perPage: $perPage
        );

        return view('plugin::SimpleSeo/view/404-monitor', [
            'title' => t__('404 Monitor', 'simple-seo'),
            'items' => $pagination['items'],
            'filter' => $filter,
            'search' => $search,
            'page' => $pagination['page'],
            'perPage' => $pagination['perPage'],
            'total' => $pagination['total'],
            'totalPages' => $pagination['totalPages'],
            'activeCount' => $this->notFound->activeCount(),
            'ignoredCount' => $this->notFound->ignoredCount(),
            'totalCount' => $this->notFound->count(true),
        ]);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     */
    public function ignore(ServerRequest $request): ResponseInterface
    {
        $id = (string) (($request->getParsedBody())['id'] ?? '');

        if ($id !== '') {
            $this->notFound->ignore($id);
        }

        return redirect(admin_url('plugin/simple-seo/404-monitor/'));
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     */
    public function delete(ServerRequest $request): ResponseInterface
    {
        $id = (string) (($request->getParsedBody())['id'] ?? '');

        if ($id !== '') {
            $this->notFound->delete($id);
        }

        return redirect(admin_url('plugin/simple-seo/404-monitor/'));
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     */
    public function createRedirect(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();

        $id = (string) ($post['id'] ?? '');
        $source = trim((string) ($post['source_url'] ?? ''));
        $target = trim((string) ($post['target_url'] ?? ''));
        $status = (int) ($post['status_code'] ?? 301);

        if (!in_array($status, [301, 302, 307, 308], true)) {
            $status = 301;
        }

        if ($source !== '' && $target !== '') {
            $this->redirects->createOrUpdate(
                sourceUrl: $source,
                targetUrl: $target,
                statusCode: $status,
                entityType: null,
                entityId: null,
                enabled: true
            );

            if ($id !== '') {
                $this->notFound->delete($id);
            } else {
                $this->notFound->deleteByPath($source);
            }
        }

        return redirect(admin_url('plugin/simple-seo/404-monitor/'));
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     */
    public function restore(ServerRequest $request): ResponseInterface
    {
        $id = (string) (($request->getParsedBody())['id'] ?? '');

        if ($id !== '') {
            $this->notFound->unignore($id);
        }

        return redirect(admin_url('plugin/simple-seo/404-monitor/?filter=ignored'));
    }

    public function bulk(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();

        $action = (string) ($post['bulk_action'] ?? '');
        $ids = explode(',', (string) ($post['ids'] ?? ''));

        $ids = array_filter(
            array_map('trim', $ids),
            static fn ($id) => $id !== ''
        );

        if (!is_array($ids)) {
            $ids = [];
        }

        foreach ($ids as $id) {
            $id = (string) $id;

            if ($id === '') {
                continue;
            }

            match ($action) {
                'ignore' => $this->notFound->ignore($id),
                'restore' => $this->notFound->unignore($id),
                'delete' => $this->notFound->delete($id),
                default => null,
            };
        }

        return redirect(admin_url('plugin/simple-seo/404-monitor/'));
    }
}
