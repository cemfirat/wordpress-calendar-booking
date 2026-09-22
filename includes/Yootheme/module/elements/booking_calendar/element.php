<?php
return [
    'name' => 'cemb_booking_calendar',
    'title' => 'Calendar Booking Availability',
    'group' => 'Calendar Booking',
    'element' => true,
    'width' => 500,
    'templates' => [
        'render' => __DIR__ . '/templates/template.php',
        'content' => __DIR__ . '/templates/content.php',
    ],
    'fields' => [
        'month' => [
            'label' => 'Initial month',
            'description' => 'Optional YYYY-MM value. Leave empty for the current month.',
            'attrs' => ['placeholder' => '2026-09'],
        ],
        'class' => '${builder.cls}',
        'attributes' => '${builder.attrs}',
        'visibility' => '${builder.visibility}',
        'name' => '${builder.name}',
        'status' => '${builder.status}',
    ],
    'fieldset' => [
        'default' => [
            'type' => 'tabs',
            'fields' => [
                [
                    'title' => 'Content',
                    'fields' => ['month'],
                ],
                [
                    'title' => 'Settings',
                    'fields' => ['visibility'],
                ],
                '${builder.advanced}',
            ],
        ],
    ],
];
