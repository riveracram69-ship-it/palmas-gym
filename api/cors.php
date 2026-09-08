<?php
/**
 * api/cors.php — Centralized CORS Header Helper
 *
 * [R-02 FIX] Replaces wildcard "Access-Control-Allow-Origin: *" across all API endpoints.
 *
 * Why this matters:
 * - Wildcard CORS (*) allows ANY website to make cross-origin requests.
 * - If a user has a valid Bearer token stored in localStorage, a malicious site
 *   could silently call the API on their behalf.
 * - This helper restricts the allowed origin to the production domain (from APP_URL),
 *   while also allowing localhost and file:// origins required for the Capacitor app.
 *
 * Usage: require_once __DIR__ . '/cors.php';  (at the top of every api/*.php file)
 */

if (!defined('APP_URL')) {
    require_once __DIR__ . '/../config/env.php';
}

/**
 * Returns the list of allowed origins for this installation.
 */
function get_allowed_origins(): array {
    $origins = [
        // Capacitor Android app (WebView uses null or file:// origin)
        'null',
        'file://',
        // Local development origins
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:3000',
        'http://localhost:8080',
        // Ionic DevApp / Capacitor live reload
        'ionic://localhost',
        'capacitor://localhost',
    ];

    // Add the configured production origin
    if (defined('APP_URL') && !empty(APP_URL)) {
        $prod_url = rtrim(APP_URL, '/');
        $origins[] = $prod_url;
        // Also allow the bare domain without path prefix (e.g. https://palmas-gym.onrender.com)
        $parsed = parse_url($prod_url);
        if (!empty($parsed['host'])) {
            $origins[] = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
        }
    }

    return array_unique($origins);
}

/**
 * Emit CORS headers for the current request.
 * Checks the Origin header against the allowed list and reflects it back if matched.
 * Falls back to a restrictive response for unknown origins.
 */
function apply_cors_headers(): void {
    $request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = get_allowed_origins();

    // Capacitor native apps may send no Origin header — allow passthrough
    if (empty($request_origin)) {
        header('Content-Type: application/json; charset=utf-8');
        return;
    }

    if (in_array($request_origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $request_origin);
        header('Vary: Origin');
    } else {
        // Unknown origin — allow the request through but do NOT reflect origin.
        // The browser will block the response on the client side due to missing CORS header.
        // We do NOT return 403 here because the legitimate backend request (e.g. from a webhook)
        // has no Origin header and should not be blocked.
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

// Apply immediately on include
apply_cors_headers();
