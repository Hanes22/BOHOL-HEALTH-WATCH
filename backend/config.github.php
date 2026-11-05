<?php
// Settings for the GitHub-hosted live feed. There are no secrets here, so this file is safe in a public repo.
return [
    'dsn' => 'sqlite:' . dirname(__DIR__) . '/live/state.sqlite',
    'auto_publish' => true,
    'fetch_images' => true,
    // Read only when the FB_ACCESS_TOKEN secret is set, which needs Meta's approval.
    'facebook_pages' => [
        ['name' => 'Department of Health Philippines', 'id' => 'OfficialDOHgov'],
        ['name' => 'DOH Central Visayas', 'id' => '341360329665812'],
    ],
    'feeds' => [
        ['name' => 'Bohol Island News', 'url' => 'https://boholislandnews.com/feed/'],
        ['name' => 'The Bohol Tribune', 'url' => 'https://theboholtribune.com/feed/'],
        // The Provincial Government's own site, so its health posts are labeled Official.
        ['name' => 'Provincial Government of Bohol', 'url' => 'https://bohol.gov.ph/feed/', 'trust' => 'official'],
        ['name' => 'Philippine Daily Inquirer', 'url' => 'https://newsinfo.inquirer.net/category/inquirer-headlines/regions/feed', 'require_bohol' => true],
        ['name' => 'SunStar', 'url' => 'https://www.sunstar.com.ph/feed/', 'require_bohol' => true],
    ],
];
