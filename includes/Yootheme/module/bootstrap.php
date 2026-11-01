<?php
if (!defined('ABSPATH')) {
    exit;
}

use YOOtheme\Builder;
use YOOtheme\Path;

return [
    'extend' => [
        Builder::class => function (Builder $builder) {
            $builder->addTypePath(Path::get('./elements/*/element.php'));
        },
    ],
];
