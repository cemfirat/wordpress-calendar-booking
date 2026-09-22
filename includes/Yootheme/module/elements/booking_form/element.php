<?php
return [
    'name' => 'cemb_booking_form',
    'title' => 'Calendar Booking Form',
    'group' => 'Calendar Booking',
    'element' => true,
    'width' => 500,
    'templates' => [
        'render' => __DIR__ . '/templates/template.php',
        'content' => __DIR__ . '/templates/content.php',
    ],
    'fields' => [
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
                    'title' => 'Settings',
                    'fields' => ['visibility'],
                ],
                '${builder.advanced}',
            ],
        ],
    ],
];
