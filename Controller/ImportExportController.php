<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Plugin\SimpleSeo\Service\SettingsImportExportService;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\Factories\TextResponseFactory;
use Qubus\Http\ServerRequest;

use function App\Shared\Helpers\admin_url;
use function Qubus\Routing\Helpers\redirect;

final readonly class ImportExportController
{
    public function __construct(private SettingsImportExportService $service)
    {
    }

    /**
     * @return ResponseInterface
     * @throws \Exception
     */
    public function export(): ResponseInterface
    {
        return TextResponseFactory::create(
            $this->service->export(),
            200,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="simple-seo-settings.json"',
            ]
        );
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     */
    public function import(ServerRequest $request): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['settings_file'] ?? null;

        if ($file !== null && $file->getError() === UPLOAD_ERR_OK) {
            $json = (string) $file->getStream()->getContents();
            $this->service->import($json);
        }

        return redirect(admin_url('plugin/simple-seo/'));
    }
}
