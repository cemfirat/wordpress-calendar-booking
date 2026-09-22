<?php
$el = $this->el('div', [
    'class' => ['cemb-yootheme-element', 'cemb-yootheme-booking-form'],
]);
?>
<?= $el($props, $attrs) ?>
    <?= (new \Cemb\Frontend\ComponentRenderer())->bookingForm() ?>
<?= $el->end() ?>
