<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use App\Application\Devflow;
use Exception;
use Plugin\SimpleSeo\Repository\RouteSeoRepository;
use Psr\Http\Message\ResponseInterface;
use Qubus\EventDispatcher\ActionFilter\Action;
use Qubus\Http\ServerRequest;
use ReflectionException;

use function App\Shared\Helpers\admin_url;
use function App\Shared\Helpers\site_url;
use function Codefy\Framework\Helpers\view;
use function Qubus\Routing\Helpers\redirect;
use function Qubus\Security\Helpers\t__;
use function trim;

final readonly class RouteSeoController
{
    public function __construct(private RouteSeoRepository $routes)
    {
    }

    /**
     * @return ResponseInterface
     * @throws \Exception
     */
    public function index(): ResponseInterface
    {
        return view('plugin::SimpleSeo/view/routes', [
            'title' => t__('Custom Route SEO', 'simple-seo'),
            'routes' => $this->routes->all(),
        ]);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Exception
     */
    public function create(ServerRequest $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            return $this->save($request);
        }

        return view('plugin::SimpleSeo/view/route-form', $this->formData());
    }

    /**
     * @param ServerRequest $request
     * @param string $id
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function edit(ServerRequest $request, string $id): ResponseInterface
    {
        $route = $this->routes->findById($id);

        if ($route === false) {
            Devflow::$PHP->flash->error(t__('Not found', 'simple-seo'));
            return redirect(admin_url('plugin/simple-seo/routes/'));
        }

        if ($request->getMethod() === 'POST') {
            return $this->save($request, $id);
        }

        return view(
            'plugin::SimpleSeo/view/route-form',
            $this->formData($route)
        );
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     */
    public function delete(ServerRequest $request): ResponseInterface
    {
        $id = trim((string) (($request->getParsedBody())['id'] ?? ''));

        if ($id !== '') {
            $this->routes->delete($id);
        }

        return redirect(admin_url('plugin/simple-seo/routes/'));
    }

    /**
     * @param ServerRequest $request
     * @param string|null $id
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws ReflectionException
     */
    private function save(ServerRequest $request, ?string $id = null): ResponseInterface
    {
        $post = $request->getParsedBody();

        $routePath = trim((string) ($post['route_path'] ?? ''));
        $label = trim((string) ($post['route_label'] ?? ''));
        $enabled = !empty($post['enabled']);

        $seo = isset($post['seo']) && is_array($post['seo'])
            ? $post['seo']
            : [];

        if ($label === '') {
            $label = $routePath;
        }

        if ($routePath !== '') {
            $id = $this->routes->save(
                routePath: $routePath,
                label: $label,
                seo: $seo,
                id: $id,
                enabled: $enabled
            );

            $result = $this->routes->findById($id);

            if ($result !== false) {
                Action::getInstance()->doAction(
                    $id === null ? 'create_seo_route' : 'update_seo_route',
                    $result,
                );
            }

            match ($result) {
                false => Devflow::$PHP->flash->error(t__('Error occurred.', 'simple-seo')),
                default => Devflow::$PHP->flash->success(Devflow::$PHP->flash->notice(200)),
            };
        }

        return redirect(admin_url('plugin/simple-seo/routes/'));
    }

    /**
     * @param object|null $route
     * @return array
     * @throws \Qubus\Exception\Exception
     */
    private function formData(?object $route = null): array
    {
        $seo = $route !== null
            ? $this->routes->seoData($route)
            : [];

        $routePath = (string) ($route->route_path ?? '');
        $previewUrl = $routePath !== ''
            ? rtrim(site_url(), '/') . $this->routes->normalizeRoute($routePath)
            : rtrim(site_url(), '/') . '/custom-route/';

        return [
            'title' => $route ? 'Edit Custom Route SEO' : 'Add Custom Route SEO',
            'route' => $route,
            'seo' => $seo,
            'type' => 'route',
            'fieldPrefix' => 'seo',
            'previewUrl' => $previewUrl,
            'entity' => $route,
        ];
    }
}
