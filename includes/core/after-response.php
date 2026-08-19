<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Can this SAPI hand the response to the browser and keep working?
 *
 * LiteSpeed and PHP-FPM can; plain mod_php cannot, and there the caller has to
 * do the work inline.
 */
function ial_can_run_after_response()
{
    return function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request');
}

/**
 * Run a callback once the response has been sent.
 *
 * Registering or unbinding a serial drags in slow, external work: the
 * AcyMailing call, whatever the role plugins do, any mail that goes out over
 * SMTP. None of it changes what the customer is told, so none of it should
 * happen while they wait — that wait is what makes people click twice.
 *
 * The state the listeners read must already be committed when this is called,
 * because they run after the answer has gone out. Falls back to running the
 * callback inline where the connection cannot be closed early.
 */
function ial_run_after_response($callback)
{
    if (!is_callable($callback)) {
        return;
    }

    if (!ial_can_run_after_response()) {
        call_user_func($callback);
        return;
    }

    register_shutdown_function(function () use ($callback) {
        // Keep going once the browser is gone: nobody is waiting from here on.
        ignore_user_abort(true);

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        call_user_func($callback);
    });
}
