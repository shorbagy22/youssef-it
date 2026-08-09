<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Chatbot Application Configuration
|--------------------------------------------------------------------------
|
| App-wide settings for the chatbot feature itself, as distinct from the
| AI service integration config (config/ai.php).
|
*/

return [

    // Displayed version string for the dashboard's "Application version"
    // card. Bumped manually per release; not tied to git tags/composer.
    'version' => env('CHATBOT_VERSION', '0.1.0'),

    // Name of the log channel (see config/logging.php) the chatbot
    // pipeline logs to.
    'log_channel' => env('CHATBOT_LOG_CHANNEL', 'chatbot'),

];
