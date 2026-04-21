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

class GoogleFonts
{
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
    }

    /**
     * @throws Exception
     */
    public function load(string|array $options = [], bool $forceDownload = false): Fonts
    {
        ['font' => $font, 'nonce' => $nonce] = $this->parseOptions($options);

        $url = $this->resolveFont($font);

        try {
            if ($forceDownload) {
                return $this->fetch($url, $nonce);
            }

            return $this->loadLocal($url, $nonce) ?? $this->fetch($url, $nonce);
        } catch (Exception $exception) {
            if (! $this->fallback) {
                throw $exception;
            }

            return new Fonts(googleFontsUrl: $url, nonce: $nonce);
        }
    }

    /**
     * @param array<string|array> $options
     *
     * @return Fonts[]
     * @throws Exception
     */
    public function loadMany(array $options = [], bool $forceDownload = false): array
    {
        $fonts = $this->resolveFonts($options);

        try {
            if ($forceDownload) {
                return $this->fetchMany($fonts);
            }

            $loaded = $fonts->map(fn (array $font) => $this->loadLocal($font['url'], $font['nonce']));
            $missing = $fonts->keys()
                ->filter(fn (string $font) => $loaded->get($font) === null)
                ->mapWithKeys(fn (string $font) => [$font => $fonts->get($font)]);

            if ($missing->isNotEmpty()) {
                return $this->fetchMany($missing);
            }

            return $loaded->values()->all();
        } catch (Exception $exception) {
            if (! $this->fallback) {
                throw $exception;
            }

            return $fonts
                ->map(fn (array $font) => new Fonts(googleFontsUrl: $font['url'], nonce: $font['nonce']))
                ->values()
                ->all();
        }
    }

    protected function resolveFont(string $font): string
    {
        if (! isset($this->fonts[$font])) {
            throw new RuntimeException("Font `{$font}` doesn't exist");
        }

        return $this->fonts[$font];
    }

    /**
     * @param array<string|array> $options
     *
     * @return Collection<string, array{url: string, nonce: ?string}>
     */
    protected function resolveFonts(array $options): Collection
    {
        return collect($options)
            ->map(fn (string|array $o) => $this->parseOptions($o))
            ->mapWithKeys(fn (array $option) => [
                $option['font'] => [
                    'url' => $this->resolveFont($option['font']),
                    'nonce' => $option['nonce'],
                ],
            ]);
    }

    /**
     * @param Collection<array{font: string, url: string, nonce: ?string}> $fonts
     *
     * @return Fonts[]
     */
    protected function fetchMany(Collection $fonts): array
    {
        $cssResponses = $this->fetchCssResponses($fonts);
        [$fontMap, $woffUrls] = $this->buildFontMap($fonts, $cssResponses);
        $woffResponses = $this->fetchWoffResponses($woffUrls);

        return collect($fontMap)
            ->map(function ($data) use ($woffResponses) {
                [$localizedCss, $preloadMeta] = $this->localizeFonts(
                    $data['url'],
                    $data['css'],
                    $data['woff'],
                    $woffResponses
                );

                return $this->makeFontsObject($data['url'], $localizedCss, $preloadMeta, $data['nonce']);
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<string, array{url: string, nonce: ?string}> $fonts
     *
     * @return array<string, Response>
     */
    protected function fetchCssResponses(Collection $fonts): array
    {
        return $fonts
            ->chunk($this->poolSize)
            ->flatMap(function (Collection $chunk) {
                return Http::pool(function (Pool $pool) use ($chunk) {
                    foreach ($chunk as $font => $option) {
                        $pool
                            ->as((string) $font)
                            ->withHeader('User-Agent', $this->userAgent)
                            ->get($option['url']);
                    }
                });
            })
            ->map(function ($response) {
                $response->throw();

                return $response;
            })
            ->toArray();
    }

    /**
    * @param Collection $fonts
    * @param array<string, Response> $cssResponses
    *
    * @return array{
    *     0: array<string, array{
    *         url: string,
    *         nonce: ?string,
    *         css: string,
    *         woff: string[]
    *     }>,
    *     1: string[]
    * }
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
     *
     * @return array<string, Response>
     */
    protected function fetchWoffResponses(array $woffUrls): array
    {
        return collect($woffUrls)
            ->chunk($this->poolSize)
            ->flatMap(function ($chunk) {
                return Http::pool(function (Pool $pool) use ($chunk) {
                    return collect($chunk)->mapWithKeys(function ($url) use ($pool) {
                        return [
                            $url => $pool
                                ->as($url)
                                ->withHeader('User-Agent', $this->userAgent)
                                ->get($url),
                        ];
                    })->toArray();
                });
            })
            ->map(function ($response) {
                $response->throw();

                return $response;
            })
            ->toArray();
    }

    protected function loadLocal(string $url, ?string $nonce): ?Fonts
    {
        if (! $this->filesystem->exists($this->path($url, 'fonts.css'))) {
            return null;
        }

        $localizedCss = $this->filesystem->get($this->path($url, 'fonts.css'));

        $preloadMeta = $this->filesystem->exists($this->path($url, 'preload.html'))
            ? $this->filesystem->get($this->path($url, 'preload.html'))
            : null;

        return $this->makeFontsObject($url, $localizedCss, $preloadMeta, $nonce);
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    protected function fetch(string $url, ?string $nonce): Fonts
    {
        $cssResponse = Http::withHeaders([
            'User-Agent' => $this->userAgent,
        ])->get($url)->throw();

        $css = $cssResponse->body();

        $woffUrls = $this->extractFontUrls($css);

        $woffResponses = array_combine(
            $woffUrls,
            array_map(fn ($u) => Http::get($u)->throw(), $woffUrls)
        );

        [$localizedCss, $preloadMeta] = $this->localizeFonts($url, $css, $woffUrls, $woffResponses);

        return $this->makeFontsObject($url, $localizedCss, $preloadMeta, $nonce);
    }

    /**
     * @param string[] $woffUrls
     * @param array<string, Response> $responses
     *
     * @return array{0: string, 1: string}
     */
    protected function localizeFonts(
        string $url,
        string $css,
        array $woffUrls,
        array $responses
    ): array {
        $localizedCss = $css;
        $preloadMeta = '';

        foreach ($woffUrls as $woffUrl) {
            $response = $responses[$woffUrl] ?? null;

            if (! $response) {
                continue;
            }

            $file = $this->localizeFontUrl($woffUrl);

            $this->filesystem->put($this->path($url, $file), $response->body());

            $localUrl = $this->filesystem->url($this->path($url, $file));
            $preloadMeta .= $this->getPreload($localUrl) . "\n";
            $localizedCss = str_replace($woffUrl, $localUrl, $localizedCss);
        }

        $this->persistFont($url, $localizedCss, $preloadMeta);

        return [$localizedCss, $preloadMeta];
    }

    protected function persistFont(string $url, string $localizedCss, string $preloadMeta): void
    {
        $this->filesystem->put($this->path($url, 'fonts.css'), $localizedCss);
        $this->filesystem->put($this->path($url, 'preload.html'), $preloadMeta);
    }

    protected function makeFontsObject(
        string $url,
        string $localizedCss,
        ?string $preloadMeta,
        ?string $nonce
    ): Fonts {
        return new Fonts(
            googleFontsUrl: $url,
            localizedUrl: $this->filesystem->url($this->path($url, 'fonts.css')),
            localizedCss: $localizedCss,
            nonce: $nonce,
            preferInline: $this->inline,
            preloadMeta: $preloadMeta,
            preload: $this->preload,
        );
    }

    public function fontPath(string $font, string $path = ''): string
    {
        return $this->path($this->resolveFont($font), $path);
    }

    protected function extractFontUrls(string $css): array
    {
        $matches = [];
        preg_match_all('/url\((https:\/\/fonts.gstatic.com\/[^)]+)\)/', $css, $matches);

        return array_unique($matches[1] ?? []);
    }

    protected function localizeFontUrl(string $path): string
    {
        // Google Fonts seem to have recently changed their URL structure to one that no longer contains a file
        // extension (see https://github.com/spatie/laravel-google-fonts/issues/40). We account for that by falling back
        // to 'woff2' in that case.
        $pathComponents = explode('.', str_replace('https://fonts.gstatic.com/', '', $path));
        $path = $pathComponents[0];
        $extension = $pathComponents[1] ?? 'woff2';

        return implode('.', [Str::slug($path), $extension]);
    }

    protected function path(string $url, string $path = ''): string
    {
        $segments = collect([
            $this->path,
            substr(md5($url), 0, 10),
            $path,
        ]);

        return $segments->filter()->join('/');
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

    public function getPreload(string $url): string
    {
        return sprintf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>', $url);
    }
}
