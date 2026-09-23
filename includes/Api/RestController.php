<?php
namespace Wpcb\Api;

use Wpcb\Availability\SlotService;
use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Resources\ResourceRepository;
use Wpcb\Tokens\SlotTokenService;

final class RestController {
    public const NAMESPACE = 'wpcb/v1';

    public function boot(): void {
        add_action('rest_api_init', [$this, 'register']);
        add_action('wpcb_hourly_reminders', [$this, 'cleanup'], 40);
    }

    public function register(): void {
        register_rest_route(self::NAMESPACE, '/booking-types', [
            'methods' => 'GET',
            'callback' => [$this, 'bookingTypes'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/resources', [
            'methods' => 'GET',
            'callback' => [$this, 'resources'],
            'permission_callback' => '__return_true',
            'args' => [
                'booking_type_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/availability/(?P<type_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'availability'],
            'permission_callback' => '__return_true',
            'args' => [
                'type_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'days' => ['type' => 'integer', 'default' => 14, 'minimum' => 1, 'maximum' => 31],
                'party_size' => ['type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 10000],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/bookings', [
            'methods' => 'GET',
            'callback' => [$this, 'bookings'],
            'permission_callback' => [$this, 'canManage'],
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
                'status' => ['type' => 'string'],
                'booking_type_id' => ['type' => 'integer', 'minimum' => 1],
                'resource_id' => ['type' => 'integer', 'minimum' => 1],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'booking'],
            'permission_callback' => [$this, 'canManage'],
            'args' => ['id' => ['required' => true, 'type' => 'integer', 'minimum' => 1]],
        ]);
        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)/transition', [
            'methods' => 'POST',
            'callback' => [$this, 'transition'],
            'permission_callback' => [$this, 'canManage'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'event' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => [
                        BookingStateMachine::ADMIN_APPROVED,
                        BookingStateMachine::ADMIN_REJECTED,
                        BookingStateMachine::ADMIN_CANCELLED,
                    ],
                ],
            ],
        ]);
    }

    public function canManage(): bool {
        return current_user_can('manage_options');
    }

    public function bookingTypes(): \WP_REST_Response {
        $items = array_map(function (object $type): array {
            return [
                'id' => (int)$type->id,
                'slug' => (string)$type->slug,
                'name' => (string)$type->name,
                'description' => (string)$type->description,
                'duration_minutes' => (int)$type->duration_minutes,
                'buffer_before_minutes' => (int)$type->buffer_before_minutes,
                'buffer_after_minutes' => (int)$type->buffer_after_minutes,
                'capacity' => max(1, (int)($type->capacity ?? 1)),
                'shows_remaining_capacity' => !empty($type->show_remaining_capacity),
            ];
        }, (new BookingTypeRepository())->all(true));
        return new \WP_REST_Response(['items' => $items], 200);
    }

    public function resources(\WP_REST_Request $request): \WP_REST_Response {
        $typeId = (int)$request->get_param('booking_type_id');
        $type = (new BookingTypeRepository())->find($typeId);
        if (!$type || empty($type->is_active) || empty($type->is_public)) {
            return new \WP_REST_Response(['code' => 'booking_type_not_found'], 404);
        }
        $repo = new ResourceRepository();
        $items = [];
        foreach ($repo->forBookingType($typeId, true) as $resource) {
            $label = $repo->publicLabel($resource);
            if ($label === '') {
                continue;
            }
            $items[] = [
                'id' => (int)$resource->id,
                'label' => $label,
            ];
        }
        return new \WP_REST_Response(['items' => $items], 200);
    }

    public function availability(\WP_REST_Request $request): \WP_REST_Response {
        $typeId = (int)$request->get_param('type_id');
        $type = (new BookingTypeRepository())->find($typeId);
        if (!$type || empty($type->is_active) || empty($type->is_public)) {
            return new \WP_REST_Response(['code' => 'booking_type_not_found'], 404);
        }

        $days = max(1, min(31, (int)$request->get_param('days')));
        $partySize = max(1, min(10000, (int)$request->get_param('party_size')));
        $slots = (new SlotService())->getSlots($typeId, $days, null, $partySize);
        $tokens = new SlotTokenService();
        $items = [];
        foreach ($slots as $slot) {
            $item = [
                'start' => (string)$slot['start'],
                'end' => (string)$slot['end'],
                'label' => (string)$slot['label'],
                'timezone' => (string)$slot['timezone'],
                'slot_token' => $tokens->issue(
                    $typeId,
                    (string)$slot['start'],
                    (string)$slot['end'],
                    (int)$slot['resource_id']
                ),
            ];
            if (!empty($slot['resource_label'])) {
                $item['resource_label'] = (string)$slot['resource_label'];
            }
            if (array_key_exists('remaining_capacity', $slot)) {
                $item['remaining_capacity'] = max(0, (int)$slot['remaining_capacity']);
            }
            $items[] = $item;
        }
        return new \WP_REST_Response(['items' => $items], 200);
    }

    public function bookings(\WP_REST_Request $request): \WP_REST_Response {
        $page = max(1, (int)$request->get_param('page'));
        $perPage = max(1, min(100, (int)$request->get_param('per_page')));
        $args = [
            'status' => sanitize_key((string)$request->get_param('status')),
            'booking_type_id' => (int)$request->get_param('booking_type_id'),
            'resource_id' => (int)$request->get_param('resource_id'),
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
        $items = array_map([$this, 'bookingView'], (new BookingRepository())->all($args));
        return new \WP_REST_Response([
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
        ], 200);
    }

    public function booking(\WP_REST_Request $request): \WP_REST_Response {
        $row = (new BookingRepository())->find((int)$request->get_param('id'));
        return $row
            ? new \WP_REST_Response($this->bookingView($row), 200)
            : new \WP_REST_Response(['code' => 'booking_not_found'], 404);
    }

    public function transition(\WP_REST_Request $request): \WP_REST_Response {
        $bookingId = (int)$request->get_param('id');
        $event = sanitize_key((string)$request->get_param('event'));
        $key = trim((string)$request->get_header('Idempotency-Key'));
        $idempotency = new IdempotencyRepository();
        $claim = $idempotency->begin($key, 'booking-transition:' . $bookingId . ':' . $event);
        if (empty($claim['ok'])) {
            return new \WP_REST_Response(['code' => 'invalid_idempotency_key'], 400);
        }
        if (empty($claim['owner'])) {
            if (!empty($claim['complete'])) {
                return new \WP_REST_Response((array)$claim['response'], (int)$claim['code']);
            }
            return new \WP_REST_Response(['code' => 'idempotency_in_progress'], 409);
        }

        $result = (new BookingTransitionService())->apply($bookingId, $event, 'api', 'REST API transition');
        if (is_wp_error($result)) {
            $payload = ['code' => $result->get_error_code(), 'message' => $result->get_error_message()];
            $idempotency->complete((int)$claim['id'], 409, $payload);
            return new \WP_REST_Response($payload, 409);
        }

        $booking = (new BookingRepository())->find($bookingId);
        $payload = [
            'transition' => $result,
            'booking' => $booking ? $this->bookingView($booking) : null,
        ];
        $idempotency->complete((int)$claim['id'], 200, $payload);
        return new \WP_REST_Response($payload, 200);
    }

    public function cleanup(): void {
        (new IdempotencyRepository())->cleanup();
    }

    public function bookingView(object $booking): array {
        return [
            'id' => (int)$booking->id,
            'uuid' => (string)$booking->booking_uuid,
            'booking_type_id' => (int)$booking->booking_type_id,
            'resource_id' => !empty($booking->resource_id) ? (int)$booking->resource_id : null,
            'status' => (string)$booking->status,
            'slot_start' => (string)$booking->slot_start,
            'slot_end' => (string)$booking->slot_end,
            'party_size' => max(1, (int)($booking->party_size ?? 1)),
            'contact' => [
                'name' => (string)($booking->full_name ?? ''),
                'email' => (string)($booking->email ?? ''),
                'phone' => (string)($booking->phone ?? ''),
            ],
            'created_at' => (string)$booking->created_at,
            'updated_at' => (string)$booking->updated_at,
        ];
    }
}
