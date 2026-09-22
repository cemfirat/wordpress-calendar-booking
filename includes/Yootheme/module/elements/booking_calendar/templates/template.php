<?php
$el = $this->el('div', [
    'class' => ['cemb-yootheme-element', 'cemb-yootheme-booking-calendar'],
]);
$month = isset($props['month']) && is_string($props['month']) ? trim($props['month']) : '';
$args = $month !== '' ? ['month' => $month] : [];
?>
<?= $el($props, $attrs) ?>
    <?= (new \Cemb\Frontend\ComponentRenderer())->bookingCalendar($args) ?>
<?= $el->end() ?>
