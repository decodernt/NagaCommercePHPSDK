<?php

declare(strict_types=1);

namespace NagaCommerce\SDK\Tests\Contract;

/**
 * Parses NagaCommerce controller files to build the catalog of routes the
 * server actually exposes. Used by the cross-repo contract test to verify
 * every SDK call's URL matches at least one registered route.
 *
 * Why parse instead of hardcoding a manifest? A manifest decouples from the
 * source of truth — when someone adds a route in the controller and forgets
 * to update the manifest, the contract test silently passes garbage. Parsing
 * the controller directly means the catalog can't drift.
 *
 * Scope of the parser:
 *  - $router->mount('/X', function ... { ... });   builds the prefix stack
 *  - $router->get/post/put/delete/match('/Y', ...) emits a route
 *  - Nested mount() blocks (we currently see up to 4 levels deep in /system)
 *  - Both single-quoted and double-quoted route literals
 *  - Backslash-escaped \\d / \\w in route regexes
 *
 * NOT supported (none of the controllers use these — assert if they appear):
 *  - Routes built from variables
 *  - Conditional mount() calls
 */
final class RouteParser
{
    /**
     * Parse a controller file. Returns an array of routes:
     *   [ ['method' => 'GET', 'pattern' => '/products/product/(\\d+)'], ... ]
     */
    public static function parseFile(string $path): array
    {
        $src = file_get_contents($path);
        if ($src === false) {
            return [];
        }
        return self::parse($src);
    }

    public static function parse(string $src): array
    {
        $routes = [];
        self::walk($src, 0, strlen($src), '', $routes);
        return $routes;
    }

    /**
     * Recursive walker. Iterates the [$start, $end) substring of $src,
     * pulling out top-level $router->X(...) calls. When it sees a mount(),
     * it recursively walks the body with the accumulated prefix.
     */
    private static function walk(string $src, int $start, int $end, string $prefix, array &$routes): void
    {
        $i = $start;
        while ($i < $end) {
            $pos = self::findNextRouterCall($src, $i, $end);
            if ($pos === null) {
                return;
            }
            ['call_start' => $callStart, 'verb' => $verb, 'path_arg' => $pathArg, 'after_path' => $afterPath] = $pos;

            if ($verb === 'mount') {
                // Find the opening `{` of the function body, then the matching `}`,
                // then recurse with the extended prefix.
                $bodyStart = self::findBodyOpenBrace($src, $afterPath, $end);
                if ($bodyStart === null) {
                    return;
                }
                $bodyEnd = self::findMatchingCloseBrace($src, $bodyStart, $end);
                if ($bodyEnd === null) {
                    return;
                }
                self::walk($src, $bodyStart + 1, $bodyEnd, $prefix . $pathArg, $routes);
                $i = $bodyEnd + 1;
                continue;
            }

            // Leaf route registration: get/post/put/delete/match.
            $fullPath = $prefix . $pathArg;
            if ($verb === 'match') {
                // $router->match('GET|POST', '/path', fn). We've already
                // consumed the first arg as $pathArg, but for match() the
                // first arg is actually the method list — re-read.
                $methods = strtoupper($pathArg);
                $secondArg = self::readNextStringArg($src, $afterPath, $end);
                if ($secondArg !== null) {
                    foreach (explode('|', $methods) as $m) {
                        $routes[] = ['method' => trim($m), 'pattern' => $prefix . $secondArg['value']];
                    }
                    $i = $secondArg['end'];
                    continue;
                }
            } else {
                $routes[] = [
                    'method'  => strtoupper($verb),
                    'pattern' => $fullPath,
                ];
            }
            $i = $afterPath;
        }
    }

    /**
     * Find the next `$router->{verb}('...path...'` call. Returns null when
     * no more calls remain in the window. Verb is one of mount/get/post/
     * put/delete/match.
     */
    private static function findNextRouterCall(string $src, int $start, int $end): ?array
    {
        // Regex hits everything up to the first arg's closing quote.
        if (!preg_match(
            '/\$router\s*->\s*(mount|get|post|put|delete|match)\s*\(\s*([\'"])(.*?)(?<!\\\\)\2/s',
            substr($src, $start, $end - $start),
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }
        $verb = $m[1][0];
        $pathArg = self::unescapePhpStringLiteral($m[3][0], $m[2][0]);
        $matchStart = $start + $m[0][1];
        $matchEnd = $matchStart + strlen($m[0][0]);
        return [
            'call_start' => $matchStart,
            'verb'       => $verb,
            'path_arg'   => $pathArg,
            'after_path' => $matchEnd,
        ];
    }

    /**
     * Resolve a PHP string literal as it appeared in the file source to its
     * runtime value. Without this, a route declared as '/product/(\\d+)' in
     * the source — i.e. 2 backslashes + d in the raw bytes — would be stored
     * with both backslashes and PCRE would later interpret `\\d` as literal
     * `\d` instead of a digit class.
     *
     * Single-quoted: only `\\` and `\'` are escapes.
     * Double-quoted: many more, but the controllers don't use them — we
     * still cover `\\` and `\"` pragmatically.
     */
    private static function unescapePhpStringLiteral(string $raw, string $quote): string
    {
        if ($quote === "'") {
            return strtr($raw, ['\\\\' => '\\', "\\'" => "'"]);
        }
        return strtr($raw, ['\\\\' => '\\', '\\"' => '"']);
    }

    /**
     * For mount() calls, find the `{` that opens the function body. Walks
     * forward from the first arg's end, skipping `,`, ` function() use ($router)`, etc.
     */
    private static function findBodyOpenBrace(string $src, int $start, int $end): ?int
    {
        for ($i = $start; $i < $end; $i++) {
            if ($src[$i] === '{') {
                return $i;
            }
        }
        return null;
    }

    /**
     * Brace-balanced search for the `}` that matches an opening `{` at $openPos.
     * Skips over string literals so braces inside quoted regexes don't confuse the count.
     */
    private static function findMatchingCloseBrace(string $src, int $openPos, int $end): ?int
    {
        $depth = 0;
        $i = $openPos;
        $inSingle = false;
        $inDouble = false;
        while ($i < $end) {
            $c = $src[$i];
            $prev = $i > 0 ? $src[$i - 1] : '';
            if ($inSingle) {
                if ($c === "'" && $prev !== '\\') {
                    $inSingle = false;
                }
            } elseif ($inDouble) {
                if ($c === '"' && $prev !== '\\') {
                    $inDouble = false;
                }
            } else {
                if ($c === "'") { $inSingle = true; }
                elseif ($c === '"') { $inDouble = true; }
                elseif ($c === '{') { $depth++; }
                elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) { return $i; }
                }
            }
            $i++;
        }
        return null;
    }

    /**
     * For match('GET|POST', '/path', fn) — after consuming the first arg, we
     * need the second string argument.
     */
    private static function readNextStringArg(string $src, int $start, int $end): ?array
    {
        if (!preg_match(
            '/([\'"])(.+?)(?<!\\\\)\1/s',
            substr($src, $start, $end - $start),
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }
        return [
            'value' => self::unescapePhpStringLiteral($m[2][0], $m[1][0]),
            'end'   => $start + $m[0][1] + strlen($m[0][0]),
        ];
    }

    /**
     * Walk a directory of controller files and return one flat catalog of
     * all routes, each with a `source_file` tag for diagnostics.
     */
    public static function parseDir(string $dir): array
    {
        $routes = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            $name = basename((string)$file);
            if ($name[0] === '.') { continue; }
            if (!preg_match('/controller\.class\.php$/', $name)) { continue; }
            foreach (self::parseFile((string)$file) as $route) {
                $route['source_file'] = $name;
                $routes[] = $route;
            }
        }
        return $routes;
    }
}
