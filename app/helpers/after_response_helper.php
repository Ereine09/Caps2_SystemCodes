<?php
/**
 * Run slow work (sending an email) AFTER the page has been sent to the browser.
 *
 * Placing an order used to send the e-receipt email in the middle of the request; when the mail
 * server was slow the customer waited on it and Hostinger's nginx gave up with "504 Gateway
 * Time-out". app_after_response() queues the job, the page is sent and the connection closed,
 * and then the job runs. On the command line (or if the server cannot close the connection
 * early) the job still runs, just at the end of the request.
 */

if (!function_exists('app_after_response')) {
    function app_after_response(callable $job): void
    {
        if (PHP_SAPI === 'cli') {
            app_after_response_run_one($job);
            return;
        }
        if (empty($GLOBALS['__app_after_response'])) {
            $GLOBALS['__app_after_response'] = [];
            register_shutdown_function('app_after_response_flush');
        }
        $GLOBALS['__app_after_response'][] = $job;
    }

    function app_after_response_flush(): void
    {
        $jobs = $GLOBALS['__app_after_response'] ?? [];
        $GLOBALS['__app_after_response'] = [];
        if (!$jobs) {
            return;
        }
        ignore_user_abort(true);
        @set_time_limit(120);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // release the session so the customer's next page isn't blocked
        }
        if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            function_exists('fastcgi_finish_request') ? fastcgi_finish_request() : litespeed_finish_request();
        } else {
            // Plain Apache module (local XAMPP): send the buffered page with its exact length and
            // "Connection: close", so the browser is done before the jobs start.
            $out = '';
            while (ob_get_level() > 0) {
                $out = (string) ob_get_clean() . $out;
            }
            if (function_exists('staff_portal_url_rewrite')) {
                $out = staff_portal_url_rewrite($out); // ob_get_clean() skips modules/shared/staff_mirror.php's buffer callback
            }
            if (!headers_sent()) {
                header('Content-Length: ' . strlen($out));
                header('Connection: close');
            }
            echo $out;
            @flush();
        }
        foreach ($jobs as $job) {
            app_after_response_run_one($job);
        }
    }

    function app_after_response_run_one(callable $job): void
    {
        try {
            $job();
        } catch (Throwable $e) {
            error_log('[after_response] job failed: ' . $e->getMessage());
        }
    }
}
