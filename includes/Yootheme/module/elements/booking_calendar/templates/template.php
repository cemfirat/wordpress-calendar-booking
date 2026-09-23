<?php
$el = $this->el('div', [
    'class' => ['wpcb-yootheme-element', 'wpcb-yootheme-booking-calendar'],
]);
$month = isset($props['month']) && is_string($props['month']) ? trim($props['month']) : '';
$args = $month !== '' ? ['month' => $month] : [];
?>
<?= $el($props, $attrs) ?>
    <?= (new \Wpcb\Frontend\ComponentRenderer())->bookingCalendar($args) ?>
<?= $el->end() ?>
