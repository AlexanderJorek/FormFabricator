<?php

/**
 * What the release build leaves out, keeps and checks, as data only, so that every reader uses the same lists: the
 * build (tools/build.php), languages/make-pot.php (no strings from files that never ship) and the tests that pin the
 * mPDF font trim. Dev tooling, never shipped.
 *
 * @return array{
 *     exclude: string[],
 *     nestedExclude: string[],
 *     handVendored: array<int, array{path: string, npm: string, licenses: string[]}>,
 *     keepFonts: string[],
 *     vendorExclude: string[]
 * }
 */

return [
    // Entries of the repository root that never reach the package: dev tooling, docs, tests, local state.
    'exclude' => [
        '.git', '.claude', '.vscode', '.gitignore', '.gitattributes', '.github', '.phpcs.xml', '.phpcs-security.xml',
        'build', 'node_modules', 'vendor', 'tools', 'docs', 'tests', 'build.cmd', 'build.sh', 'CLAUDE.md',
        'package.json', 'package-lock.json',
    ],

    // Dev-only files inside shipped folders. The example field is a template that is never loaded, and WordPress.org
    // asks plugins not to ship unreachable code.
    'nestedExclude' => [
        'includes/PDF/templates/HEADER-RENDERING.md',
        'languages/compile-mo.php',
        'languages/make-pot.php',
        'includes/Fields/_ExampleField.php',
        'assets/js/fabricator-perf-debug.js',
        'assets/js/fields/ExampleField.clientInitClickHandler.js',
        'assets/js/fields/ExampleField.clientValidationZip.js',
        'assets/css/fields/ExampleField.stylesComposite.css',
    ],

    // npm packages placed by hand (Composer doesn't manage them), each pinned by the SHA-256 list in its VERSION file.
    // The build checks them before staging and again in the package. Those under vendor/ are copied in after the
    // check, since the stage's vendor/ is Composer's; the others are staged with the folder they live in.
    'handVendored' => [
        ['path' => 'vendor/altcha', 'npm' => 'altcha', 'licenses' => ['MIT']],
        ['path' => 'assets/vendor/fontawesome', 'npm' => '@fortawesome/fontawesome-free', 'licenses' => ['CC-BY-4.0', 'OFL-1.1', 'MIT']],
    ],

    // mPDF ships ~88 MB of fonts. Kept: the four selectable families (layout.php's font_family match) and the regular
    // files of Generator::FALLBACK_FONTS; no Condensed DejaVu (Generator::fontConfig()). A new selectable PDF font must
    // be added here, or PDFs fatal on "font file not found".
    'keepFonts' => [
        'DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'DejaVuSans-Oblique.ttf', 'DejaVuSans-BoldOblique.ttf',
        'DejaVuSerif.ttf', 'DejaVuSerif-Bold.ttf', 'DejaVuSerif-Italic.ttf', 'DejaVuSerif-BoldItalic.ttf',
        'DejaVuSansMono.ttf', 'DejaVuSansMono-Bold.ttf', 'DejaVuSansMono-Oblique.ttf', 'DejaVuSansMono-BoldOblique.ttf',
        'FreeMono.ttf', 'FreeMonoBold.ttf', 'FreeMonoOblique.ttf', 'FreeMonoBoldOblique.ttf',
        'FreeSerif.ttf', 'Quivira.otf',
        'DejaVuinfo.txt', 'GNUFreeFontinfo.txt',
    ],

    // Dev-only tooling that dependencies bundle in their own dist (--no-dev can't strip it): CI and analysis configs,
    // an unused mPDF tmp/ (Generator always passes a tempDir), and a shell script Plugin Check rejects.
    'vendorExclude' => [
        'vendor/paragonie/random_compat/build-phar.sh',
        'vendor/paragonie/random_compat/dist',
        'vendor/paragonie/random_compat/other',
        'vendor/paragonie/random_compat/psalm-autoload.php',
        'vendor/paragonie/random_compat/psalm.xml',
        'vendor/mpdf/mpdf/.github',
        'vendor/mpdf/mpdf/.gitignore',
        'vendor/mpdf/mpdf/tmp',
        'vendor/mpdf/mpdf/phpstan-baseline.neon',
        'vendor/mpdf/mpdf/phpstan.neon',
        'vendor/mpdf/mpdf/phpunit.xml',
        'vendor/mpdf/mpdf/ruleset.xml',
        'vendor/mpdf/psr-log-aware-trait/.gitignore',
    ],
];
