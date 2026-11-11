<?php
// Settings for the GitHub-hosted live feed. There are no secrets here, so this file is safe in a public repo.
return [
    'dsn' => 'sqlite:' . dirname(__DIR__) . '/live/state.sqlite',
    'auto_publish' => true,
    'fetch_images' => true,
    // The user's BOHOL news Page. Override FB_PAGE_ID / FB_PAGE_NAME in Actions variables if needed.
    // FB_PAGE_TRUST may be "official" only for a verified government health Page.
    'facebook_pages' => [[
        'name' => getenv('FB_PAGE_NAME') ?: 'BOHOL news',
        'id' => getenv('FB_PAGE_ID') ?: '1448497761679246',
        'trust' => getenv('FB_PAGE_TRUST') === 'official' ? 'official' : 'news',
        'all_health' => true,
    ]],
    'feeds' => [
        ['name' => 'Bohol Island News', 'url' => 'https://boholislandnews.com/feed/'],
        ['name' => 'The Bohol Tribune', 'url' => 'https://theboholtribune.com/feed/'],
        // The Provincial Government's own site, so its health posts are labeled Official.
        ['name' => 'Provincial Government of Bohol', 'url' => 'https://bohol.gov.ph/feed/', 'trust' => 'official'],
        ['name' => 'Philippine Daily Inquirer', 'url' => 'https://newsinfo.inquirer.net/category/inquirer-headlines/regions/feed', 'require_bohol' => true],
        ['name' => 'SunStar', 'url' => 'https://www.sunstar.com.ph/feed/', 'require_bohol' => true],
    ],
];
