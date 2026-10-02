<?php

use Akibeo\Umami\Umami;

if (function_exists('umami') === false) {
    /**
     * Access the Umami plugin from templates, snippets and controllers:
     *
     *   umami()->scriptTag()              // the tracker <script>
     *   umami()->track('contact-form')    // server-side event
     *   umami()->stats('7d')              // summary from the Umami API
     */
    function umami(): Umami
    {
        return Umami::instance();
    }
}
