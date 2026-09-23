<?php
$el = $this->el('div', [
    'class' => ['wpcb-yootheme-element', 'wpcb-yootheme-booking-form'],
]);
?>
<?= $el($props, $attrs) ?>
    <?= (new \Wpcb\Frontend\ComponentRenderer())->bookingForm() ?>
<?= $el->end() ?>
