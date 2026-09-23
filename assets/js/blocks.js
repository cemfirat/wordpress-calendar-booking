(function (blocks, element, components, i18n) {
    'use strict';

    var el = element.createElement;
    var Placeholder = components.Placeholder;
    var TextControl = components.TextControl;
    var __ = i18n.__;

    blocks.registerBlockType('wpcb/booking-form', {
        edit: function () {
            return el(
                Placeholder,
                {
                    icon: 'calendar-alt',
                    label: __('Booking Form', 'wordpress-calendar-booking'),
                    instructions: __('The live booking form is rendered on the frontend. The editor preview is intentionally read-only.', 'wordpress-calendar-booking')
                }
            );
        },
        save: function () {
            return null;
        }
    });

    blocks.registerBlockType('wpcb/availability-calendar', {
        edit: function (props) {
            return el(
                Placeholder,
                {
                    icon: 'calendar',
                    label: __('Availability Calendar', 'wordpress-calendar-booking'),
                    instructions: __('The live availability calendar is rendered on the frontend. The editor preview never creates bookings or loads private calendar data.', 'wordpress-calendar-booking')
                },
                el(TextControl, {
                    label: __('Initial month (YYYY-MM)', 'wordpress-calendar-booking'),
                    value: props.attributes.month || '',
                    placeholder: '2026-09',
                    onChange: function (value) {
                        props.setAttributes({ month: value });
                    }
                })
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.i18n);
