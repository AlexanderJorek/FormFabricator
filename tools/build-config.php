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
 *     vendorExclude: string[],
 *     reviewExceptions: array<int, array{file: string, sniff: string, code: string, reason: string}>
 * }
 */

return [
    // Entries of the repository root that never reach the package: dev tooling, docs, tests, local state.
    'exclude' => [
        '.git', '.claude', '.vscode', '.gitignore', '.gitattributes', '.github', '.phpcs.xml', '.phpcs-security.xml',
        '.phpcs-review.xml',
        'build', 'node_modules', 'vendor', 'tools', 'docs', 'tests', 'build.cmd', 'build.sh', 'CLAUDE.md',
        'package.json', 'package-lock.json',
    ],

    // Dev-only files inside shipped folders: the translation tools, kept beside the files they write. Other dev-only
    // files live in docs/ or tools/, which never ship.
    'nestedExclude' => [
        'languages/compile-mo.php',
        'languages/make-pot.php',
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

    // The .phpcs-review.xml findings the release accepts (file, sniff, a piece of the line), each with the reason the
    // reviewers get. Any other finding fails the build, as does an entry that matches nothing.
    'reviewExceptions' => [
        [
            'file'   => 'includes/Plugin.php',
            'sniff'  => 'WordPress.Security.NonceVerification.Missing',
            'code'   => "\$_POST['form_id']",
            'reason' => 'Plugin::ajaxGetToken() is the anonymous endpoint that hands a visitor the form nonce right '
                . 'before a submission, so pages cached for days still send. It cannot check a nonce before issuing one. '
                . 'It reads one integer (absint), writes nothing, and answers only for a form that exists; the '
                . 'submission it prepares is checked by nonce, signed token and rate limit.',
        ],
        [
            'file'   => 'includes/Admin/FormEditor.php',
            'sniff'  => 'WordPress.Security.NonceVerification.Recommended',
            'code'   => "\$_GET['form_id']",
            'reason' => 'FormEditor::render() reads which form to open from its link (admin.php?page=fabricator-forms-'
                . 'editor&form_id=…), as WordPress\'s own post.php?post= does: a page load that only displays, gated by '
                . 'the edit_forms capability; the value is absint()ed, and every save carries its own nonce.',
        ],
        [
            'file'   => 'includes/Plugin.php',
            'sniff'  => 'FormFabricatorReview.Security.NonceShape.HandlerWithoutNonce',
            'code'   => "'ajaxGetToken'",
            'reason' => 'The registration of Plugin::ajaxGetToken(), the endpoint that issues the form nonce (see above).',
        ],
        [
            'file'   => 'includes/Utils/Assets.php',
            'sniff'  => 'FormFabricatorReview.Security.LateEscape.InlineAssetNotEscaped',
            'code'   => '$literal',
            'reason' => 'Assets::ensureFrontAssets() adds the field types\' validation and setup functions to front.js with '
                . 'wp_add_inline_script(). Each is JavaScript code read from the plugin\'s own assets/js/fields/ files, '
                . 'never from input or the database, keyed by wp_json_encode()d field types; code has no escaping '
                . 'function, and esc_js() would turn it into a string.',
        ],
        [
            'file'   => 'includes/PDF/templates/layout.php',
            'sniff'  => 'FormFabricatorReview.Security.LateEscape.BuiltWrongContext',
            'code'   => 'esc_attr($img_path)',
            'reason' => 'The PDF template\'s header image: mPDF reads a local file, the path get_attached_file() gives for '
                . 'a Media Library attachment, never a URL or input; esc_url() would rewrite the path (a Windows drive '
                . 'letter, backslashes), so esc_attr() escapes it for the attribute. This markup goes to mPDF, not a browser.',
        ],
        [
            'file'   => 'includes/PDF/templates/layout.php',
            'sniff'  => 'FormFabricatorReview.Security.LateEscape.BuiltWrongContext',
            'code'   => 'esc_attr($fabricator_logo_path)',
            'reason' => 'The PDF template\'s logo, as the header image above.',
        ],
        [
            'file'   => 'includes/Form/FormProcessor.php',
            'sniff'  => 'FormFabricatorReview.Security.RequestInput.NotSanitizedInPlace',
            'code'   => 'wp_unslash($_POST)',
            'reason' => 'FormProcessor::handle() reads a form submission\'s answers unsanitized, after its nonce check, so each is '
                . 'recorded as the visitor typed it: a sanitizer would change it ("a<b and c>d" to "ad", "20%AB" to "20"), '
                . 'and a changed answer is a different statement (a direct debit mandate\'s account holder, a signed '
                . 'declaration). Each answer is validated at its field instead (Utils\\Text\\AnswerText refuses invalid UTF-8, '
                . 'control and invisible characters; each field checks its own format), never stored, and escaped wherever '
                . 'it is shown: in the email, the PDF and the verification page.',
        ],
    ],
];
