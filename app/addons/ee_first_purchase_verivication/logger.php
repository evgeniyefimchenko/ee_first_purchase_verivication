<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Registry;

/**
 * Structured JSONL diagnostics with bounded storage and sensitive-data redaction.
 *
 * @ee-align 257
 * @ee-flow 11>3>8
 * @ee-finance 9112
 * @ee-intent retain actionable promotion evidence without exposing customer secrets
 */

/**
 * Writes one structured diagnostic event.
 *
 * Warning, error, and critical events are always recorded. Debug and info events
 * require the detailed diagnostics setting.
 *
 * @param string               $ee_level   debug, info, warning, error, or critical.
 * @param string               $ee_event   Stable machine-readable event name.
 * @param string               $ee_message Human-readable event description.
 * @param array<string, mixed> $ee_context Safe diagnostic context.
 *
 * @return bool
 */
function fn_ee_first_purchase_verivication_log($ee_level, $ee_event, $ee_message, array $ee_context = [])
{
    static $ee_seen_events = [];

    $ee_level = strtolower((string) $ee_level);
    $ee_levels = ['debug', 'info', 'warning', 'error', 'critical'];
    if (!in_array($ee_level, $ee_levels, true)) {
        $ee_level = 'error';
    }

    if (
        in_array($ee_level, ['debug', 'info'], true)
        && Registry::get('addons.ee_first_purchase_verivication.ee_detailed_logging') !== 'Y'
    ) {
        return false;
    }

    $ee_context = fn_ee_first_purchase_verivication_sanitize_log_value($ee_context);
    $ee_signature = $ee_level . '|' . $ee_event . '|' . md5(serialize($ee_context));
    if (isset($ee_seen_events[$ee_signature])) {
        return true;
    }
    $ee_seen_events[$ee_signature] = true;

    $ee_timestamp = microtime(true);
    $ee_milliseconds = (int) (($ee_timestamp - floor($ee_timestamp)) * 1000);
    $ee_record = [
        'schema' => 'ee_first_purchase_verivication.log.v1',
        'timestamp' => gmdate('Y-m-d\TH:i:s', (int) $ee_timestamp)
            . sprintf('.%03dZ', $ee_milliseconds),
        'level' => $ee_level,
        'event' => preg_replace('/[^a-z0-9_.-]+/i', '_', (string) $ee_event),
        'message' => (string) $ee_message,
        'request_id' => fn_ee_first_purchase_verivication_get_log_request_id(),
        'environment' => fn_ee_first_purchase_verivication_get_log_environment(),
        'context' => $ee_context,
    ];

    $ee_json = json_encode(
        $ee_record,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    if (!is_string($ee_json) || $ee_json === '') {
        fn_ee_first_purchase_verivication_log_fallback($ee_level, $ee_event, $ee_message);

        return false;
    }

    $ee_log_directory = fn_ee_first_purchase_verivication_get_log_directory();
    $ee_storage_issue = fn_ee_first_purchase_verivication_get_log_storage_issue($ee_log_directory);
    if ($ee_storage_issue !== '') {
        fn_ee_first_purchase_verivication_log_fallback(
            $ee_level,
            $ee_event,
            $ee_message . ' Log storage issue: ' . $ee_storage_issue
        );

        return false;
    }

    $ee_log_path = $ee_log_directory . DIRECTORY_SEPARATOR . 'ee_first_purchase_verivication.jsonl';
    $ee_lock_path = $ee_log_path . '.lock';
    $ee_lock = @fopen($ee_lock_path, 'c');
    if ($ee_lock === false || !@flock($ee_lock, LOCK_EX)) {
        if (is_resource($ee_lock)) {
            @fclose($ee_lock);
        }
        fn_ee_first_purchase_verivication_log_fallback(
            $ee_level,
            $ee_event,
            $ee_message . ' Log lock is unavailable.'
        );

        return false;
    }

    $ee_line = $ee_json . "\n";
    clearstatcache(true, $ee_log_path);
    $ee_size = is_file($ee_log_path) ? (int) @filesize($ee_log_path) : 0;
    if ($ee_size > 0 && $ee_size + strlen($ee_line) > fn_ee_first_purchase_verivication_get_log_max_bytes()) {
        if (!fn_ee_first_purchase_verivication_rotate_logs($ee_log_path)) {
            fn_ee_first_purchase_verivication_log_fallback(
                'warning',
                'log_rotation_failed',
                'The add-on log could not be rotated; appending to the active file.'
            );
        }
    }

    $ee_log = @fopen($ee_log_path, 'ab');
    $ee_written = $ee_log !== false && @fwrite($ee_log, $ee_line) === strlen($ee_line);
    if (is_resource($ee_log)) {
        @fflush($ee_log);
        @fclose($ee_log);
    }
    @flock($ee_lock, LOCK_UN);
    @fclose($ee_lock);

    if ($ee_written && $ee_size === 0) {
        @chmod($ee_log_path, 0640);
        @chmod($ee_lock_path, 0640);
    }
    if (!$ee_written) {
        fn_ee_first_purchase_verivication_log_fallback(
            $ee_level,
            $ee_event,
            $ee_message . ' Writing to the add-on log failed.'
        );
    }

    return $ee_written;
}

/**
 * Adds exception metadata without request or customer payloads.
 *
 * @param string               $ee_event     Stable event name.
 * @param string               $ee_message   Human-readable description.
 * @param object               $ee_exception Exception or Error instance.
 * @param array<string, mixed> $ee_context   Additional safe context.
 *
 * @return bool
 */
function fn_ee_first_purchase_verivication_log_exception(
    $ee_event,
    $ee_message,
    $ee_exception,
    array $ee_context = []
) {
    $ee_context['exception'] = [
        'class' => is_object($ee_exception) ? get_class($ee_exception) : gettype($ee_exception),
        'message' => is_object($ee_exception) && method_exists($ee_exception, 'getMessage')
            ? $ee_exception->getMessage()
            : '',
        'code' => is_object($ee_exception) && method_exists($ee_exception, 'getCode')
            ? $ee_exception->getCode()
            : 0,
        'file' => is_object($ee_exception) && method_exists($ee_exception, 'getFile')
            ? $ee_exception->getFile()
            : '',
        'line' => is_object($ee_exception) && method_exists($ee_exception, 'getLine')
            ? $ee_exception->getLine()
            : 0,
        'trace' => is_object($ee_exception) && method_exists($ee_exception, 'getTraceAsString')
            ? $ee_exception->getTraceAsString()
            : '',
    ];

    return fn_ee_first_purchase_verivication_log('critical', $ee_event, $ee_message, $ee_context);
}

/**
 * Returns an empty string when the log directory can be used.
 *
 * @param string|null $ee_log_directory Log directory or null for the configured path.
 *
 * @return string
 */
function fn_ee_first_purchase_verivication_get_log_storage_issue($ee_log_directory = null)
{
    $ee_log_directory = $ee_log_directory !== null
        ? rtrim((string) $ee_log_directory, '/\\')
        : fn_ee_first_purchase_verivication_get_log_directory();
    if ($ee_log_directory === '') {
        return 'directory_path_empty';
    }

    if (!is_dir($ee_log_directory) && !@mkdir($ee_log_directory, 0775, true) && !is_dir($ee_log_directory)) {
        return 'directory_create_failed';
    }
    if (!is_writable($ee_log_directory)) {
        return 'directory_not_writable';
    }

    $ee_log_path = $ee_log_directory . DIRECTORY_SEPARATOR . 'ee_first_purchase_verivication.jsonl';
    if (is_file($ee_log_path) && !is_writable($ee_log_path)) {
        return 'log_file_not_writable';
    }

    $ee_lock_path = $ee_log_path . '.lock';
    if (is_file($ee_lock_path) && !is_writable($ee_lock_path)) {
        return 'lock_file_not_writable';
    }

    return '';
}

/**
 * @return string
 */
function fn_ee_first_purchase_verivication_get_log_directory()
{
    if (defined('EE_FIRST_PURCHASE_VERIVICATION_LOG_DIRECTORY')) {
        return rtrim((string) EE_FIRST_PURCHASE_VERIVICATION_LOG_DIRECTORY, '/\\');
    }

    return __DIR__ . DIRECTORY_SEPARATOR . 'logs';
}

/**
 * @return int
 */
function fn_ee_first_purchase_verivication_get_log_max_bytes()
{
    $ee_bytes = defined('EE_FIRST_PURCHASE_VERIVICATION_LOG_MAX_BYTES')
        ? (int) EE_FIRST_PURCHASE_VERIVICATION_LOG_MAX_BYTES
        : 5 * 1024 * 1024;

    return max(1024, $ee_bytes);
}

/**
 * @return int
 */
function fn_ee_first_purchase_verivication_get_log_max_files()
{
    $ee_files = defined('EE_FIRST_PURCHASE_VERIVICATION_LOG_MAX_FILES')
        ? (int) EE_FIRST_PURCHASE_VERIVICATION_LOG_MAX_FILES
        : 5;

    return max(1, min(20, $ee_files));
}

/**
 * @param string $ee_log_path Active log path.
 *
 * @return bool
 */
function fn_ee_first_purchase_verivication_rotate_logs($ee_log_path)
{
    $ee_max_files = fn_ee_first_purchase_verivication_get_log_max_files();
    $ee_oldest = $ee_log_path . '.' . $ee_max_files;
    if (is_file($ee_oldest) && !@unlink($ee_oldest)) {
        return false;
    }

    for ($ee_index = $ee_max_files - 1; $ee_index >= 1; $ee_index--) {
        $ee_source = $ee_log_path . '.' . $ee_index;
        $ee_target = $ee_log_path . '.' . ($ee_index + 1);
        if (is_file($ee_source) && !@rename($ee_source, $ee_target)) {
            return false;
        }
    }

    return !is_file($ee_log_path) || @rename($ee_log_path, $ee_log_path . '.1');
}

/**
 * Recursively removes secrets and bounds values before JSON encoding.
 *
 * @param mixed  $ee_value Value to sanitize.
 * @param string $ee_key   Parent key.
 * @param int    $ee_depth Current nesting level.
 *
 * @return mixed
 */
function fn_ee_first_purchase_verivication_sanitize_log_value($ee_value, $ee_key = '', $ee_depth = 0)
{
    if (
        $ee_key !== ''
        && preg_match(
            '/password|passwd|token|secret|authorization|cookie|session|license|api[_-]?key|email|phone|address|payment|card|pan|cvv/i',
            $ee_key
        )
    ) {
        return '[REDACTED]';
    }

    if ($ee_depth >= 6) {
        return '[MAX_DEPTH]';
    }

    if (is_array($ee_value)) {
        $ee_result = [];
        $ee_count = 0;
        foreach ($ee_value as $ee_item_key => $ee_item) {
            if ($ee_count >= 100) {
                $ee_result['__truncated_items'] = count($ee_value) - $ee_count;
                break;
            }
            $ee_result[$ee_item_key] = fn_ee_first_purchase_verivication_sanitize_log_value(
                $ee_item,
                (string) $ee_item_key,
                $ee_depth + 1
            );
            $ee_count++;
        }

        return $ee_result;
    }

    if (is_object($ee_value)) {
        return ['__object_class' => get_class($ee_value)];
    }
    if (is_resource($ee_value)) {
        return '[RESOURCE]';
    }
    if (is_string($ee_value)) {
        return strlen($ee_value) > 8000 ? substr($ee_value, 0, 8000) . '[TRUNCATED]' : $ee_value;
    }
    if (is_float($ee_value) && (is_nan($ee_value) || is_infinite($ee_value))) {
        return (string) $ee_value;
    }

    return $ee_value;
}

/**
 * @return string
 */
function fn_ee_first_purchase_verivication_get_log_request_id()
{
    static $ee_request_id;

    if ($ee_request_id === null) {
        $ee_external_id = !empty($_SERVER['HTTP_X_REQUEST_ID'])
            ? preg_replace('/[^a-zA-Z0-9_.-]+/', '', (string) $_SERVER['HTTP_X_REQUEST_ID'])
            : '';
        $ee_request_id = $ee_external_id !== ''
            ? substr($ee_external_id, 0, 80)
            : substr(hash('sha256', uniqid('', true) . mt_rand()), 0, 20);
    }

    return $ee_request_id;
}

/**
 * @return array<string, mixed>
 */
function fn_ee_first_purchase_verivication_get_log_environment()
{
    return [
        'addon_version' => '1.6.0',
        'cs_cart_version' => defined('PRODUCT_VERSION') ? PRODUCT_VERSION : null,
        'cs_cart_edition' => defined('PRODUCT_EDITION') ? PRODUCT_EDITION : null,
        'php_version' => PHP_VERSION,
        'area' => defined('AREA') ? AREA : null,
        'controller' => Registry::get('runtime.controller'),
        'mode' => Registry::get('runtime.mode'),
        'company_id' => (int) Registry::get('runtime.company_id'),
        'storefront_id' => (int) Registry::get('runtime.storefront_id'),
        'process_id' => function_exists('getmypid') ? getmypid() : null,
    ];
}

/**
 * Uses the PHP error log only when module-owned storage is unavailable.
 *
 * @return void
 */
function fn_ee_first_purchase_verivication_log_fallback($ee_level, $ee_event, $ee_message)
{
    static $ee_reported = [];

    $ee_key = (string) $ee_level . '|' . (string) $ee_event;
    if (isset($ee_reported[$ee_key])) {
        return;
    }
    $ee_reported[$ee_key] = true;

    @error_log(
        '[ee_first_purchase_verivication][' . (string) $ee_level . ']['
        . (string) $ee_event . '] ' . substr((string) $ee_message, 0, 1000)
    );
}
