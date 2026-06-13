<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Exception;
use Plugin\SimpleSeo\Service\CrawlDiagnosticsService;
use Plugin\SimpleSeo\Support\GoogleTokenStore;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function App\Shared\Helpers\admin_url;
use function Codefy\Framework\Helpers\view;
use function Qubus\Routing\Helpers\redirect;
use function Qubus\Security\Helpers\t__;

final readonly class SettingsController
{
    public function __construct(private CrawlDiagnosticsService $diagnostics)
    {
    }

    /**
     * @return ResponseInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     * @throws Exception
     */
    public function index(): ResponseInterface
    {
        return view('plugin::SimpleSeo/view/settings', [
            'title' => t__('Simple SEO', 'simple-seo'),
            'settings' => SimpleSeoSettings::all(),
            'googleConnected' => trim((string) GoogleTokenStore::get('refresh_token', '')) !== '',
            'diagnostics' => $this->diagnostics->report(),
        ]);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function save(ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $tab = (string) ($body['seo_settings']['_active_tab'] ?? '#seo-global');

        SimpleSeoSettings::save($body['seo_settings'] ?? []);

        return redirect(admin_url('plugin/simple-seo/') . $tab);
    }
}
