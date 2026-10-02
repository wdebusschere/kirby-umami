<?php
/**
 * Umami tracker script. Prints nothing while tracking is off (disabled, no
 * website ID, debug mode without trackInDebug, …).
 *
 * Umami is cookieless and stores no personal data, so this snippet loads
 * outside any cookie-consent flow.
 */
echo umami()->scriptTag();
