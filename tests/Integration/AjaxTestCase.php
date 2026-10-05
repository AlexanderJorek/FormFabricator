<?php

namespace FabricatorForms\Tests\Integration;

use FabricatorForms\Form\FormModel;
use FabricatorForms\Tests\Integration\Support\PhpUnit10Compat;
use FabricatorForms\Tests\Support\Overrides;
use FabricatorForms\Utils\SingleUseToken;

/**
 * Base case for tests that submit a form as the browser does, with the nonce and one-time token front.js fetches.
 * submit() returns the decoded JSON; sentMail() reads MockPHPMailer.
 */
abstract class AjaxTestCase extends \WP_Ajax_UnitTestCase
{
    use PhpUnit10Compat;

    protected const VISITOR_IP = '203.0.113.10';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function set_up(): void
    {
        parent::set_up();
        // _handleAjax() fires admin_init, where core's wp_admin_headers() calls header() after the test runner's own
        // output: "headers already sent". WP_Ajax_UnitTestCase hides that by lowering error_reporting, which PHPUnit 10
        // no longer honours. The Referrer-Policy header is not what these tests are about.
        remove_action('admin_init', 'wp_admin_headers');
        reset_phpmailer_instance();
        $_SERVER['REMOTE_ADDR'] = self::VISITOR_IP;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides WP_UnitTestCase's snake_case fixture method.
    public function tear_down(): void
    {
        Overrides::reset();
        $_FILES = [];
        parent::tear_down();
    }

    /**
     * Saves a form as an administrator would in the builder, and returns its id.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $notifications
     * @param array<string, mixed>             $settings
     */
    protected function createForm(array $fields, array $notifications, array $settings = []): int
    {
        $previous = get_current_user_id();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $id = FormModel::save(['title' => 'Test form', 'fields' => $fields, 'notifications' => $notifications, 'settings' => $settings], 0, true);
        wp_set_current_user($previous);
        self::assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : 'form saved');
        return $id;
    }

    /**
     * One notification with sensible defaults; $overrides replaces any key.
     *
     * @return array<string, mixed>
     */
    protected static function notification(array $overrides = []): array
    {
        return $overrides + [
            'slug'           => 'admin',
            'enabled'        => true,
            'recipient_mode' => 'single',
            'to'             => 'owner@example.org',
            'subject'        => 'New submission',
            'body'           => '{all_fields}',
            'attach_pdf'     => false,
            'attach_uploads' => false,
        ];
    }

    /**
     * Submits $values to form $form_id as a logged-out visitor and returns the JSON response.
     *
     * @param array<string, mixed> $values Field values, as the browser posts them.
     * @param string|null          $token  A token to reuse (for replay tests); a fresh one by default.
     * @return array{success: bool, data: mixed}
     */
    protected function submit(int $form_id, array $values, ?string $token = null, ?string $nonce = null): array
    {
        wp_set_current_user(0);
        $_POST = $values + [
            'form_id'                     => (string) $form_id,
            'fabricator_nonce'            => $nonce ?? wp_create_nonce('fabricator_forms_submit_' . $form_id),
            'fabricator_submission_token' => $token ?? SingleUseToken::issue($form_id),
        ];
        $this->_last_response = '';
        try {
            $this->_handleAjax('fabricator_forms_submit');
        } catch (\WPAjaxDieContinueException | \WPAjaxDieStopException $e) {
            // wp_send_json_*() always ends the request; the response is in $this->_last_response.
        }
        $response = json_decode($this->_last_response, true);
        self::assertIsArray($response, 'a JSON response, got: ' . $this->_last_response);
        return ['success' => (bool) $response['success'], 'data' => $response['data'] ?? null];
    }

    /**
     * Calls an admin AJAX action as admin-ajax.php would, with $post as the request, and returns the response it ends
     * with: 'success' and 'data' from the JSON, 'died' for a wp_die() without one (check_ajax_referer()'s "-1").
     *
     * Unlike _handleAjax(), this closes only buffers it opened and reads the first response sent: a handler's catch-all
     * (FormEditor::ajaxSave()) may catch the test's throwing wp_die() and answer twice.
     *
     * @param array<string, mixed> $post
     * @return array{success: bool, data: mixed, died: string, raw: string}
     */
    protected function ajax(string $action, array $post = []): array
    {
        $_POST          = $post + ['action' => $action];
        $_GET['action'] = $action;
        $_REQUEST       = array_merge($_POST, $_GET);
        $handler        = static fn(): callable => static function ($message = ''): void {
            throw new \WPAjaxDieStopException(is_scalar($message) ? (string) $message : '0');
        };
        add_filter('wp_die_ajax_handler', $handler, 99);
        $level = ob_get_level();
        ob_start();
        $died = '';
        $raw  = '';
        try {
            do_action('admin_init');
            do_action('wp_ajax_' . $action, null);
        } catch (\WPAjaxDieStopException $e) {
            $died = $e->getMessage();
        } finally {
            remove_filter('wp_die_ajax_handler', $handler, 99);
            while (ob_get_level() > $level) {
                $raw = (string) ob_get_clean() . $raw;
            }
        }
        $response = self::firstJsonObject($raw);
        return ['success' => (bool) ($response['success'] ?? false), 'data' => $response['data'] ?? null, 'died' => $died, 'raw' => $raw];
    }

    /**
     * The first complete JSON object in $text, decoded; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private static function firstJsonObject(string $text): ?array
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $depth    = 0;
        $inString = false;
        for ($i = $start, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($inString) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $inString = false;
                }
            } elseif ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                $decoded = json_decode(substr($text, $start, $i - $start + 1), true);
                return is_array($decoded) ? $decoded : null;
            }
        }
        return null;
    }

    /**
     * Every mail sent so far, as MockPHPMailer recorded it.
     *
     * @return array<int, object>
     */
    protected static function sentMail(): array
    {
        return tests_retrieve_phpmailer_instance()->mock_sent ? array_map(static fn($m) => (object) $m, tests_retrieve_phpmailer_instance()->mock_sent) : [];
    }

    /**
     * The addresses of one recipient list (to, cc, bcc) of a recorded mail.
     *
     * @return string[]
     */
    protected static function addresses(object $mail, string $list): array
    {
        return array_map(static fn($pair) => $pair[0], $mail->{$list});
    }
}
