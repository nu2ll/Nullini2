<?php

return [
    'bot_token'    => env('BOT_TOKEN', ''),
    'bot_username' => env('BOT_USERNAME', ''),
    'bot_tokens'    => [env('BOT_TOKEN', ''), env('BOT_TOKEN_2', '')],
    'bot_usernames' => [env('BOT_USERNAME', ''), env('BOT_USERNAME_2', '')],
    'premium_packs' => ['RestrictedEmoji'],
    'bot_name'     => 'نالینی | Nullini',
    'bot_version'  => '4.9.7',
    'admins'       => array_values(array_unique(array_filter(array_map('intval', array_merge(envList('ADMIN_IDS'), envList('OWNER_ID')))))),

    'polling' => [
        'timeout'      => (int) env('POLL_TIMEOUT', 10),
        'workers'      => env('POLL_WORKERS', 'auto'),
        'max_workers'  => (int) env('POLL_MAX_WORKERS', 24),
        'drop_pending' => filter_var(env('DROP_PENDING', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],

    'ai' => [
        'keys'        => envList('GEMINI_KEYS'),
        'models'      => envList('GEMINI_MODELS', [
            'gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3-flash-preview', 'gemini-flash-latest',
            'gemma-4-31b-it',
            'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-flash-lite-latest',
            'gemma-4-26b-a4b-it',
        ]),
        'lite_models' => envList('GEMINI_LITE_MODELS', [
            'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-flash-lite-latest',
            'gemma-4-26b-a4b-it', 'gemma-4-31b-it', 'gemini-3.8-flash', 'gemini-3.6-flash',
        ]),
        'free_stt'    => [
            'groq' => envList('GROQ_STT_MODELS', ['whisper-large-v3-turbo', 'whisper-large-v3']),
        ],
        'free_order'  => [
            'groq' => envList('GROQ_MODELS'),
            'toptools' => envList('TOPTOOLS_MODELS', ['claude-opus-5', 'gpt-5.6-sol', 'DeepSeek-V4-Flash-Vision-Exp']),
            'tokenharbor' => envList('TOKENHARBOR_MODELS', ['deepseek-v4.1-flash:free', 'mimo-v2.6-flash:free']),
            'apinex' => envList('APINEX_MODELS', ['free/gpt-6-luna', 'free/deepseek-v4.1-flash', 'free/gemini-3.8-flash', 'free/minimax-m3']),
        ],
        'free_keys'   => [
            'groq'       => envList('GROQ_KEYS'),
            'toptools'   => envList('TOPTOOLS_KEYS'),
            'tokenharbor' => envList('TOKENHARBOR_KEYS'),
            'apinex'     => envList('APINEX_KEYS'),
        ],
    ],
    'storage_chat' => env('STORAGE_CHAT_ID', ''),
    'ai_memory_messages' => (int) env('AI_MEMORY_MESSAGES', 20),
    'ai_typing_effect'   => true,
    'ai_stream'          => env('AI_STREAM', '1') !== '0',

    'image_model'          => env('IMAGE_MODEL', 'flux'),
    'image_size'           => 1024,
    'pollinations_api_key' => env('POLLINATIONS_KEY', ''),

    'funstat_url'   => env('FUNSTAT_URL', ''),

    'backup' => [
        'token'  => env('GITHUB_TOKEN', ''),
        'repo'   => env('GITHUB_REPO', ''),
        'branch' => env('DATA_BRANCH', 'data'),
    ],

    'required_channels' => env('REQUIRED_CHANNELS', '@NullHubs') === '-' ? [] : array_map(
        static fn(string $c) => ['title' => ltrim($c, '@'), 'chat_id' => '@' . ltrim($c, '@'), 'url' => 'https://t.me/' . ltrim($c, '@')],
        envList('REQUIRED_CHANNELS', ['@NullHubs'])
    ),

    'creator_name'     => env('CREATOR_NAME', 'Arown'),
    'creator_username' => env('CREATOR_USERNAME', 'nxqyz'),
    'channel_url'      => env('CHANNEL_URL', 'https://t.me/nullini'),
];
