<?php
if (!defined('ABSPATH')) {
    exit;
}
$el = $this->el('div', [
    'class' => ['wpcb-yootheme-element', 'wpcb-yootheme-booking-calendar'],
]);
$month = isset($props['month']) && is_string($props['month']) ? trim($props['month']) : '';
$args = $month !== '' ? ['month' => $month] : [];
// YOOtheme's element helper escapes attributes; ComponentRenderer escapes its own markup.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<?= $el($props, $attrs) ?>
    <?= (new \Wpcb\Frontend\ComponentRenderer())->bookingCalendar($args) ?>
<?= $el->end() ?>
<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
