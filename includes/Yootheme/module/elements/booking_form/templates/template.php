<?php
if (!defined('ABSPATH')) {
    exit;
}
$el = $this->el('div', [
    'class' => ['wpcb-yootheme-element', 'wpcb-yootheme-booking-form'],
]);
// YOOtheme's element helper escapes attributes; ComponentRenderer escapes its own markup.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<?= $el($props, $attrs) ?>
    <?= (new \Wpcb\Frontend\ComponentRenderer())->bookingForm() ?>
<?= $el->end() ?>
<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
