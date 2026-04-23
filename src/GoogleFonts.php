<?php

namespace Spatie\GoogleFonts;

use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\GoogleFonts\Enums\FetchMode;

class GoogleFonts
{
    protected array $cssFonts;
    protected array $ttfFonts;

    public function __construct(
        protected Filesystem $filesystem,
        protected string $path,
        protected bool $inline,
        protected bool $fallback,
        protected string $userAgent,
        protected array $fonts,
        protected bool $preload,
        protected int $poolSize,
    ) {
        $this->cssFonts = $this->pluckFonts('css');
        $this->ttfFonts = $this->pluckFonts('ttf');
    }

    /**
     * @throws Exception
     */
    public function load(string|array $options = [], bool $forceDownload = false, FetchMode $mode = FetchMode::All): Fonts
    {
        ['font' => $font, 'nonce' => $nonce] = $this->parseOptions($options);

        if ($mode->shouldFetchTtf()) {
            $this->downloadTtfFonts([$font], $forceDownload);
        }

        $cssUrl = $this->resolveCssFont($font);

        try {
            if (! $mode->shouldFetchCss()) {
                return new Fonts(googleFontsUrl: $cssUrl, nonce: $nonce);
            }

            return $forceDownload
                ? $this->fetch($font, $cssUrl, $nonce)
                : $this->loadLocal($font, $cssUrl, $nonce) ?? $this->fetch($font, $cssUrl, $nonce);
        } catch (Exception $exception) {
            return $this->handleException($exception, $cssUrl, $nonce);
        }
    }

    /**
     * @param array<string|array> $options
     * @return Fonts[]
     * @throws Exception
     */
    public function loadMany(array $options = [], bool $forceDownload = false, FetchMode $mode = FetchMode::All): array
    {
        $fonts = $this->resolveCssFonts($options);

        if ($mode->shouldFetchTtf()) {
            $this->downloadTtfFonts($fonts->keys()->all(), $forceDownload);
        }

        try {
            if (! $mode->shouldFetchCss()) {
                return $fonts->map(fn (array $font) => new Fonts(googleFontsUrl: $font['url'], nonce: $font['nonce']))->toArray();
            }

            return $forceDownload
                ? $this->fetchMany($fonts)
                : $this->loadManyFromLocalOrFetch($fonts);
        } catch (Exception $exception) {
            return $this->handleManyException($exception, $fonts);
        }
    }

    public function fontPath(string $font, string $path = ''): string
    {
        return $this->path($font, $path);
    }

    public function getPreload(string $url): string
    {
        return sprintf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>', $url);
    }

    protected function loadManyFromLocalOrFetch(Collection $fonts): array
    {
        $loaded = $fonts->mapWithKeys(
            fn (array $config, string $font) => [$font => $this->loadLocal($font, $config['url'], $config['nonce'])]
        );

        $missing = $fonts->keys()
            ->filter(fn (string $font) => $loaded->get($font) === null)
            ->flatMap(fn (string $font) => [$font => $fonts->get($font)]);

        return $missing->isNotEmpty()
            ? $this->fetchMany($missing)
            : $loaded->values()->all();
    }

    protected function loadLocal(string $font, string $url, ?string $nonce): ?Fonts
    {
        if (! $this->filesystem->exists($this->path($font, 'fonts.css'))) {
            return null;
        }

        $localizedCss = $this->filesystem->get($this->path($font, 'fonts.css'));
        $preloadMeta = $this->readPreloadMeta($font);

        return $this->makeFontsObject($font, $url, $localizedCss, $preloadMeta, $nonce);
    }

    protected function readPreloadMeta(string $font): ?string
    {
        $path = $this->path($font, 'preload.html');

        return $this->filesystem->exists($path)
            ? $this->filesystem->get($path)
            : null;
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    protected function fetch(string $font, string $url, ?string $nonce): Fonts
    {
        $css = $this->fetchCss($url);
        $woffUrls = $this->extractFontUrls($css);
        $woffResponses = $this->fetchWoffFiles($woffUrls);

        [$localizedCss, $preloadMeta] = $this->localizeFonts($font, $css, $woffUrls, $woffResponses);

        return $this->makeFontsObject($font, $url, $localizedCss, $preloadMeta, $nonce);
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    protected function fetchCss(string $url): string
    {
        return Http::withHeaders(['User-Agent' => $this->userAgent])
            ->get($url)
            ->throw()
            ->body();
    }

    /**
     * @param string[] $woffUrls
     * @return array<string, Response>
     * @throws ConnectionException
     * @throws RequestException
     */
    protected function fetchWoffFiles(array $woffUrls): array
    {
        return array_combine(
            $woffUrls,
            array_map(fn (string $u) => Http::get($u)->throw(), $woffUrls)
        );
    }

    /**
     * @param Collection<string, array{url: string, nonce: ?string}> $fonts
     * @return Fonts[]
     */
    protected function fetchMany(Collection $fonts): array
    {
        $cssResponses = $this->fetchCssResponses($fonts);
        [$fontMap, $woffUrls] = $this->buildFontMap($fonts, $cssResponses);
        $woffResponses = $this->fetchWoffResponses($woffUrls);

        return collect($fontMap)
            ->mapWithKeys(fn (array $data, string $font) => [
                $font => $this->buildFontFromMap($font, $data, $woffResponses),
            ])
            ->values()
            ->all();
    }

    protected function buildFontFromMap(string $font, array $data, array $woffResponses): Fonts
    {
        [$localizedCss, $preloadMeta] = $this->localizeFonts(
            $font,
            $data['css'],
            $data['woff'],
            $woffResponses
        );

        return $this->makeFontsObject($font, $data['url'], $localizedCss, $preloadMeta, $data['nonce']);
    }

    /**
     * @param Collection<string, array{url: string, nonce: ?string}> $fonts
     * @return array<string, Response>
     */
    protected function fetchCssResponses(Collection $fonts): array
    {
        return $this->poolRequests($fonts, fn (string $font, array $option) => [$font, $option['url']])
            ->map(fn (Response $response) => $response->throw())
            ->toArray();
    }

    /**
     * @param string[] $woffUrls
     * @return array<string, Response>
     */
    protected function fetchWoffResponses(array $woffUrls): array
    {
        $keyed = collect($woffUrls)->mapWithKeys(fn (string $url) => [$url => $url]);

        return $this->poolRequests($keyed, fn (string $url) => [$url, $url])
            ->map(fn (Response $response) => $response->throw())
            ->toArray();
    }

    /**
     * @param Collection<string, array{url: string, nonce: ?string}> $fonts
     * @param array<string, Response> $cssResponses
     * @return array{0: array<string, array{url: string, nonce: ?string, css: string, woff: string[]}>, 1: string[]}
     */
    protected function buildFontMap(Collection $fonts, array $cssResponses): array
    {
        $fontMap = [];
        $woffUrls = [];

        foreach ($cssResponses as $font => $response) {
            $css = $response->body();
            $woff = $this->extractFontUrls($css);

            $fontMap[$font] = [
                'url' => $fonts[$font]['url'],
                'nonce' => $fonts[$font]['nonce'],
                'css' => $css,
                'woff' => $woff,
            ];

            foreach ($woff as $url) {
                $woffUrls[$url] = $url;
            }
        }

        return [$fontMap, array_values($woffUrls)];
    }

    /**
     * @param string[] $woffUrls
     * @param array<string, Response> $responses
     * @return array{0: string, 1: string}
     */
    protected function localizeFonts(string $font, string $css, array $woffUrls, array $responses): array
    {
        $localizedCss = $css;
        $preloadMeta = '';

        foreach ($woffUrls as $woffUrl) {
            [$localizedCss, $preloadMeta] = $this->localizeFont(
                $font,
                $woffUrl,
                $responses[$woffUrl] ?? null,
                $localizedCss,
                $preloadMeta
            );
        }

        $this->persistFont($font, $localizedCss, $preloadMeta);

        return [$localizedCss, $preloadMeta];
    }

    protected function localizeFont(
        string $font,
        string $woffUrl,
        ?Response $response,
        string $css,
        string $preloadMeta
    ): array {
        if (! $response) {
            return [$css, $preloadMeta];
        }

        $file = $this->localizeFontUrl($woffUrl);
        $this->filesystem->put($this->path($font, $file), $response->body());

        $localUrl = $this->filesystem->url($this->path($font, $file));

        return [
            str_replace($woffUrl, $localUrl, $css),
            $preloadMeta . $this->getPreload($localUrl) . "\n",
        ];
    }

    protected function persistFont(string $font, string $localizedCss, string $preloadMeta): void
    {
        $this->filesystem->put($this->path($font, 'fonts.css'), $localizedCss);
        $this->filesystem->put($this->path($font, 'preload.html'), $preloadMeta);
    }

    /**
     * @throws RequestException
     */
    protected function downloadTtfFonts(array $fonts, bool $forceDownload = false): void
    {
        $map = $this->resolveTtfMap($fonts, $forceDownload);

        if ($map->isEmpty()) {
            return;
        }

        $responses = $this->poolRequests($map, fn (string $font, string $url) => [$font, $url]);

        foreach ($map->keys() as $font) {
            $this->saveTtfFont($font, $responses[$font] ?? null);
        }
    }

    protected function resolveTtfMap(array $fonts, bool $forceDownload): Collection
    {
        $map = collect($fonts)
            ->mapWithKeys(fn (string $font) => [$font => $this->ttfFonts[$font] ?? null])
            ->filter();

        if ($forceDownload) {
            return $map;
        }

        return $map->filter(
            fn (string $url, string $font) => ! $this->filesystem->exists($this->path($font, 'font.ttf'))
        );
    }

    /**
     * @throws RequestException
     */
    protected function saveTtfFont(string $font, ?Response $response): void
    {
        if (! $response) {
            return;
        }

        $response->throw();
        $this->filesystem->put($this->path($font, 'font.ttf'), $response->body());
    }

    protected function poolRequests(Collection $items, callable $resolver): Collection
    {
        return $items
            ->chunk($this->poolSize)
            ->flatMap(fn (Collection $chunk) => $this->sendPoolChunk($chunk, $resolver));
    }

    protected function sendPoolChunk(Collection $chunk, callable $resolver): array
    {
        return Http::pool(function (Pool $pool) use ($chunk, $resolver) {
            $requests = [];

            foreach ($chunk as $key => $item) {
                [$id, $url] = $resolver($key, $item);

                $requests[$id] = $pool
                    ->as($id)
                    ->withHeader('User-Agent', $this->userAgent)
                    ->get($url);
            }

            return $requests;
        });
    }

    protected function resolveCssFont(string $font): string
    {
        if (! isset($this->cssFonts[$font])) {
            throw new RuntimeException("Font `$font` doesn't exist");
        }

        return $this->cssFonts[$font];
    }

    /**
     * @param array<string|array> $options
     * @return Collection<string, array{url: string, nonce: ?string}>
     */
    protected function resolveCssFonts(array $options): Collection
    {
        return collect($options)
            ->map(fn (string|array $o) => $this->parseOptions($o))
            ->mapWithKeys(fn (array $option) => [
                $option['font'] => [
                    'url' => $this->resolveCssFont($option['font']),
                    'nonce' => $option['nonce'],
                ],
            ]);
    }

    protected function parseOptions(string|array $options): array
    {
        if (is_string($options)) {
            $options = ['font' => $options, 'nonce' => null];
        }

        return [
            'font' => $options['font'] ?? 'default',
            'nonce' => $options['nonce'] ?? null,
        ];
    }

    /**
     * @throws Exception
     */
    protected function handleException(Exception $exception, string $cssUrl, ?string $nonce): Fonts
    {
        if (! $this->fallback) {
            throw $exception;
        }

        return new Fonts(googleFontsUrl: $cssUrl, nonce: $nonce);
    }

    /**
     * @param Collection<string, array{url: string, nonce: ?string}> $fonts
     * @return Fonts[]
     * @throws Exception
     */
    protected function handleManyException(Exception $exception, Collection $fonts): array
    {
        if (! $this->fallback) {
            throw $exception;
        }

        return $fonts
            ->map(fn (array $font) => new Fonts(googleFontsUrl: $font['url'], nonce: $font['nonce']))
            ->values()
            ->all();
    }

    protected function makeFontsObject(
        string $font,
        string $url,
        string $localizedCss,
        ?string $preloadMeta,
        ?string $nonce
    ): Fonts {
        return new Fonts(
            googleFontsUrl: $url,
            localizedUrl: $this->filesystem->url($this->path($font, 'fonts.css')),
            localizedCss: $localizedCss,
            nonce: $nonce,
            preferInline: $this->inline,
            preloadMeta: $preloadMeta,
            preload: $this->preload,
        );
    }

    /**
     * @return array<int, string>
     */
    protected function extractFontUrls(string $css): array
    {
        preg_match_all('/url\((https:\/\/fonts.gstatic.com\/[^)]+)\)/', $css, $matches);

        return array_unique($matches[1] ?? []);
    }

    protected function localizeFontUrl(string $path): string
    {
        // Google Fonts changed their URL structure to no longer contain a file
        // extension (see https://github.com/spatie/laravel-google-fonts/issues/40).
        // We fall back to 'woff2' in that case.
        $pathComponents = explode('.', str_replace('https://fonts.gstatic.com/', '', $path));
        $path = $pathComponents[0];
        $extension = $pathComponents[1] ?? 'woff2';

        return implode('.', [Str::slug($path), $extension]);
    }

    protected function path(string $font, string $path = ''): string
    {
        return collect([$this->path, substr(md5($font), 0, 10), $path])
            ->filter()
            ->join('/');
    }

    /**
     * @return array<string, string>
     */
    protected function pluckFonts(string $by): array
    {
        return collect($this->fonts)
            ->mapWithKeys(fn (array $config, string $font) => [$font => $config[$by] ?? null])
            ->filter()
            ->toArray();
    }
}
