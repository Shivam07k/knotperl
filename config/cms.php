<?php

return [
    'local_mode' => (bool) env('CMS_LOCAL_MODE', false),
    'local_fixture_source' => env('CMS_CONTENT_JSON', base_path('tests/Fixtures/sheet-data.json')),
    'local_fixture_work' => storage_path('app/generated/local-fixture/sheet-data.json'),
    'local_r2_path' => storage_path('app/generated/local-fixture/r2'),
    'schema_path' => resource_path('cms/schema.json'),
    'template_path' => resource_path('cms/template.json'),
    'editable_tabs' => ['Settings', 'Pages', 'Products', 'Sections', 'Navigation', 'Categories'],
    'admin_password_hash' => env('ADMIN_PASSWORD_HASH'),
    // Media library export from the admin (alt text per picture). Set by the publish workflow.
    'media_alt_json' => env('CMS_MEDIA_JSON'),
];
