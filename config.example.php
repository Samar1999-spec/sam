<?php
// Copy to config.php on the server (config.php is git-ignored) and fill in your details.
return [
    // Where signup / application notifications are sent.
    'notify_email' => 'you@example.com',
    // A mailbox on your own domain (e.g. hello@yourdomain.com) so mail isn't flagged as spam.
    'from_email'   => 'hello@yourdomain.com',
    // Private folder for the CSVs. Default: "mms-data" next to public_html, outside the web root.
    // 'data_dir'  => __DIR__ . '/../mms-data',
];
