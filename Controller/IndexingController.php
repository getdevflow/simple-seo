<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Plugin\SimpleSeo\Service\GoogleIndexingService;
use Plugin\SimpleSeo\Service\IndexNowService;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\Factories\JsonResponseFactory;
use Qubus\Http\ServerRequest;

use function array_merge;
use function Codefy\Framework\Helpers\env;
use function Codefy\Framework\Helpers\view;
use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_string;
use function json_encode;
use function min;
use function Qubus\Security\Helpers\t__;
use function rawurlencode;
use function str_word_count;
use function strip_tags;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const JSON_UNESCAPED_SLASHES;

final readonly class IndexingController
{
    public function __construct(
        private IndexNowService $indexNow,
        private GoogleIndexingService $googleIndexing
    ) {
    }

    /**
     * @return ResponseInterface
     * @throws \Exception
     */
    public function index(): ResponseInterface
    {
        return view('plugin::SimpleSeo/view/indexing', ['title' => t__('Indexing', 'simple-seo')]);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Exception
     */
    public function submit(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();

        $url = trim((string)($post['url'] ?? ''));
        $engine = (string)($post['engine'] ?? 'indexnow');
        $ok = false;
        $message = t__('Missing URL.', 'simple-seo');

        if ($url !== '') {
            try {
                if ($engine === 'google') {
                    $this->googleIndexing->submitUrl($url);
                } else {
                    $this->indexNow->submitUrl($url);
                }
                $ok = true;
                $message = t__('URL submitted.', 'simple-seo');
            } catch (\Throwable $e) {
                $message = $e->getMessage();
            }
        }

        return JsonResponseFactory::create(
            ['ok' => $ok, 'message' => $message]
        );
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Exception
     */
    public function analyze(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();

        $title = trim((string)($post['title'] ?? ''));
        $description = trim((string)($post['description'] ?? ''));
        $body = trim(strip_tags((string)($post['body'] ?? '')));
        $focus = trim((string)($post['focus'] ?? ''));
        $score = 0;
        $checks = [];
        $this->check(
            $checks,
            $score,
            $title !== '' && mb_strlen($title) <= 70,
            t__('Meta title exists and is <= 70 characters.', 'simple-seo'),
        );
        $this->check(
            $checks,
            $score,
            mb_strlen($description) >= 120 && mb_strlen($description) <= 180,
            t__('Meta description is 120–180 characters.', 'simple-seo'),
        );
        $this->check(
            $checks,
            $score,
            str_word_count($body) >= 300,
            t__('Body has at least 300 words.', 'simple-seo'),
        );
        if ($focus !== '') {
            $all = mb_strtolower($title . ' ' . $description . ' ' . $body);
            $this->check(
                $checks,
                $score,
                str_contains($all, mb_strtolower($focus)),
                t__('Focus keyphrase appears in title, description, or body.', 'simple-seo'),
            );
        }

        $canonical = trim((string) ($post['canonical'] ?? ''));

        if ($canonical === '') {
            $canonical = trim((string) ($post['generated_canonical'] ?? ''));
        }

        $this->check(
            $checks,
            $score,
            $canonical !== '',
            t__('Canonical URL is set or will be generated.', 'simple-seo')
        );

        $socialImage = trim((string) ($post['social_image'] ?? ''));

        if ($socialImage === '') {
            $socialImage = trim((string) ($post['featured_image'] ?? ''));
        }

        if ($socialImage === '') {
            $socialImage = trim((string) SimpleSeoSettings::get('default_social_image', ''));
        }

        $this->check($checks, $score, $socialImage !== '', t__('Social image is set.', 'simple-seo'));

        return JsonResponseFactory::create(
            ['score' => min(100, $score), 'checks' => $checks]
        );
    }

    /**
     * @param string $url
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function submitIndexNow(string $url): void
    {
        $this->indexNow->submitUrl($url);
    }

    private function check(array &$checks, int &$score, bool $pass, string $label): void
    {
        $checks[] = ['pass' => $pass, 'label' => $label];
        if ($pass) {
            $score += 17;
        }
    }
}
