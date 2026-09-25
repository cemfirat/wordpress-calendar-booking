<?php
namespace Wpcb\Api;

use Wpcb\Availability\SlotService;
use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Resources\ResourceRepository;
use Wpcb\Security\AvailabilityRequestGuard;
use Wpcb\Webhooks\WebhookDeliveryRepository;
use Wpcb\Webhooks\WebhookEndpointRepository;

final class RestController {
    private const NS = 'wpcb/v1';

    public function boot(): void {
        add_action('rest_api_init', [$this, 'register']);
    }

    public function register(): void {
        register_rest_route(self::NS, '/booking-types', [
            'methods' => 'GET',
            'callback' => [$this, 'bookingTypes'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/resources', [
            'methods' => 'GET',
            'callback' => [$this, 'resources'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/availability', [
            'methods' => 'GET',
            'callback' => [$this, 'availability'],
            'permission_callback' => '__return_true',
            'args' => [
                'booking_type_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => AvailabilityRequestGuard::MAX_DAYS, 'default' => 14],
                'party_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => AvailabilityRequestGuard::MAX_PARTY_SIZE, 'default' => 1],
            ],
        ]);

        register_rest_route(self::NS, '/bookings', [
            'methods' => 'GET',
            'callback' => [$this, 'bookings'],
            'permission_callback' => [$this, 'adminPermission'],
            'args' => [
                'status' => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
                'booking_type_id' => ['type' => 'integer', 'minimum' => 1],
                'resource_id' => ['type' => 'integer', 'minimum' => 1],
                'from' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'to' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            ],
        ]);

        register_rest_route(self::NS, '/bookings/(?P<id>\d+)/transition', [
            'methods' => 'POST',
            'callback' => [$this, 'transition'],
            'permission_callback' => [$this, 'adminPermission'],
            'args' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'event' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key'],
                'note' => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NS, '/webhooks/endpoints', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'webhookEndpoints'],
                'permission_callback' => [$this, 'adminPermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createWebhookEndpoint'],
                'permission_callback' => [$this, 'adminPermission'],
            ],
        ]);

        register_rest_route(self::NS, '/webhooks/endpoints/(?P<id>\d+)', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateWebhookEndpoint'],
                'permission_callback' => [$this, 'adminPermission'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteWebhookEndpoint'],
                'permission_callback' => [$this, 'adminPermission'],
            ],
        ]);

        register_rest_route(self::NS, '/webhooks/deliveries', [
            'methods' => 'GET',
            'callback' => [$this, 'webhookDeliveries'],
            'permission_callback' => [$this, 'adminPermission'],
            'args' => [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
            ],
        ]);
    }

    public function adminPermission(): bool {
        return current_user_can('manage_options');
    }

    public function bookingTypes(\WP_REST_Request $request): \WP_REST_Response {
        $items = [];
        foreach ((new BookingTypeRepository())->all(true) as $type) {
            $items[] = [
                'id' => (int)$type->id,
                'name' => (string)$type->name,
                'slug' => (string)$type->slug,
                'description' => (string)$type->description,
                'duration_minutes' => (int)$type->duration_minutes,
                'capacity' => max(1, (int)($type->capacity ?? 1)),
                'show_remaining_capacity' => !empty($type->show_remaining_capacity),
            ];
        }
        return new \WP_REST_Response(['data' => $items], 200);
    }

    public function resources(\WP_REST_Request $request): \WP_REST_Response {
        $items = [];
        $repo = new ResourceRepository();
        foreach ($repo->all(true) as $resource) {
            $label = $repo->publicLabel($resource);
            if ($label === '') {
                continue;
            }
            $items[] = [
                'id' => (int)$resource->id,
                'label' => $label,
                'description' => (string)$resource->description,
            ];
        }
        return new \WP_REST_Response(['data' => $items], 200);
    }

    public function availability(\WP_REST_Request $request) {
        $guard = new AvailabilityRequestGuard();
        $budget = $guard->restBudget();
        if (empty($budget['allowed'])) {
            return $this->availabilityRateLimited($budget);
        }

        $typeId = (int)$request->get_param('booking_type_id');
        $query = $guard->validateQuery(
            $typeId,
            max(1, (int)$request->get_param('days')),
            max(1, (int)$request->get_param('party_size'))
        );
        if (is_wp_error($query)) {
            return $query;
        }

        $slots = (new SlotService())->getSlotsResult(
            $typeId,
            (int)$query['days'],
            null,
            (int)$query['party_size']
        );
        if (is_wp_error($slots)) {
            return $slots;
        }

        $maxSlots = (int)$query['max_slots'];
        $truncated = count($slots) > $maxSlots;
        if ($truncated) {
            $slots = array_slice($slots, 0, $maxSlots);
        }

        $safe = [];
        foreach ($slots as $slot) {
            $item = [
                'start' => (string)$slot['start'],
                'end' => (string)$slot['end'],
                'label' => (string)$slot['label'],
                'timezone' => (string)$slot['timezone'],
            ];
            if (array_key_exists('remaining_capacity', $slot)) {
                $item['remaining_capacity'] = max(0, (int)$slot['remaining_capacity']);
            }
            if (!empty($slot['resource_label'])) {
                $item['resource_label'] = (string)$slot['resource_label'];
            }
            $safe[] = $item;
        }

        $response = new \WP_REST_Response([
            'data' => $safe,
            'meta' => [
                'truncated' => $truncated,
                'max_results' => $maxSlots,
            ],
        ], 200);
        $response->header('Cache-Control', 'no-store');
        return $this->availabilityRateHeaders($response, $budget);
    }

    public function bookings(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_bookings';
        $where = ['1=1'];
        $params = [];

        foreach (['status' => '%s', 'booking_type_id' => '%d', 'resource_id' => '%d'] as $field => $placeholder) {
            $value = $request->get_param($field);
            if ($value !== null && $value !== '') {
                $where[] = $field . ' = ' . $placeholder;
                $params[] = $placeholder === '%d' ? (int)$value : sanitize_key((string)$value);
            }
        }
        if ($request->get_param('from')) {
            $where[] = 'slot_start >= %s';
            $params[] = sanitize_text_field((string)$request->get_param('from'));
        }
        if ($request->get_param('to')) {
            $where[] = 'slot_start <= %s';
            $params[] = sanitize_text_field((string)$request->get_param('to'));
        }

        $perPage = max(1, min(100, (int)$request->get_param('per_page')));
        $page = max(1, (int)$request->get_param('page'));
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
        $total = (int)($params
            ? $wpdb->get_var($wpdb->prepare($countSql, ...$params))
            : $wpdb->get_var($countSql));

        $sql = "SELECT id, booking_uuid, booking_type_id, resource_id, slot_start, slot_end, status,
                       party_size, full_name, email, phone, source, lang, created_at, updated_at
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY slot_start ASC, id ASC
                LIMIT %d OFFSET %d";
        $queryParams = array_merge($params, [$perPage, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$queryParams), ARRAY_A);

        return new \WP_REST_Response([
            'data' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 0,
            ],
        ], 200);
    }

    public function transition(\WP_REST_Request $request) {
        $event = sanitize_key((string)$request->get_param('event'));
        if (!in_array($event, [
            BookingStateMachine::ADMIN_APPROVED,
            BookingStateMachine::ADMIN_REJECTED,
            BookingStateMachine::ADMIN_CANCELLED,
        ], true)) {
            return new \WP_Error('wpcb_api_event_invalid', 'Unsupported administrator transition.', ['status' => 400]);
        }

        return $this->idempotent($request, function () use ($request, $event) {
            $result = (new BookingTransitionService())->apply(
                (int)$request['id'],
                $event,
                'api',
                sanitize_textarea_field((string)$request->get_param('note'))
            );
            if (is_wp_error($result)) {
                return $result;
            }
            return new \WP_REST_Response(['data' => $result], 200);
        });
    }

    public function webhookEndpoints(\WP_REST_Request $request): \WP_REST_Response {
        $repo = new WebhookEndpointRepository();
        $data = [];
        foreach ($repo->all() as $endpoint) {
            $data[] = [
                'id' => (int)$endpoint->id,
                'name' => (string)$endpoint->name,
                'url' => (string)$endpoint->url,
                'events' => $repo->events($endpoint),
                'is_active' => !empty($endpoint->is_active),
                'created_at' => (string)$endpoint->created_at,
                'updated_at' => (string)$endpoint->updated_at,
            ];
        }
        return new \WP_REST_Response(['data' => $data], 200);
    }

    public function createWebhookEndpoint(\WP_REST_Request $request) {
        return $this->idempotent($request, function () use ($request) {
            $repo = new WebhookEndpointRepository();
            $saved = $repo->save($request->get_json_params());
            if (is_wp_error($saved)) {
                return $saved;
            }
            $endpoint = $repo->find((int)$saved['id']);
            return new \WP_REST_Response([
                'data' => [
                    'id' => (int)$endpoint->id,
                    'name' => (string)$endpoint->name,
                    'url' => (string)$endpoint->url,
                    'events' => $repo->events($endpoint),
                    'is_active' => !empty($endpoint->is_active),
                    'secret' => (string)$saved['secret'],
                ],
            ], 201);
        });
    }

    public function updateWebhookEndpoint(\WP_REST_Request $request) {
        return $this->idempotent($request, function () use ($request) {
            $repo = new WebhookEndpointRepository();
            $saved = $repo->save($request->get_json_params(), (int)$request['id']);
            if (is_wp_error($saved)) {
                return $saved;
            }
            $endpoint = $repo->find((int)$saved['id']);
            $data = [
                'id' => (int)$endpoint->id,
                'name' => (string)$endpoint->name,
                'url' => (string)$endpoint->url,
                'events' => $repo->events($endpoint),
                'is_active' => !empty($endpoint->is_active),
            ];
            if ((string)$saved['secret'] !== '') {
                $data['secret'] = (string)$saved['secret'];
            }
            return new \WP_REST_Response(['data' => $data], 200);
        });
    }

    public function deleteWebhookEndpoint(\WP_REST_Request $request) {
        return $this->idempotent($request, function () use ($request) {
            $ok = (new WebhookEndpointRepository())->delete((int)$request['id']);
            return new \WP_REST_Response(['deleted' => $ok], $ok ? 200 : 500);
        });
    }

    public function webhookDeliveries(\WP_REST_Request $request): \WP_REST_Response {
        $rows = (new WebhookDeliveryRepository())->recent((int)$request->get_param('limit'));
        return new \WP_REST_Response(['data' => array_map(static fn($row) => (array)$row, $rows)], 200);
    }

    private function availabilityRateLimited(array $budget): \WP_REST_Response {
        $response = new \WP_REST_Response([
            'code' => 'wpcb_availability_rate_limited',
            'message' => __('Too many availability requests. Please retry shortly.', 'wordpress-calendar-booking'),
            'data' => [
                'status' => 429,
                'retry_after' => (int)$budget['retry_after'],
            ],
        ], 429);
        $response->header('Retry-After', (string)(int)$budget['retry_after']);
        $response->header('Cache-Control', 'no-store');
        return $this->availabilityRateHeaders($response, $budget);
    }

    private function availabilityRateHeaders(\WP_REST_Response $response, array $budget): \WP_REST_Response {
        $response->header('X-RateLimit-Limit', (string)(int)$budget['limit']);
        $response->header('X-RateLimit-Remaining', (string)(int)$budget['remaining']);
        $response->header('X-RateLimit-Reset', (string)(int)$budget['reset_at']);
        return $response;
    }

    private function idempotent(\WP_REST_Request $request, callable $callback) {
        $key = trim((string)$request->get_header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 190) {
            return new \WP_Error('wpcb_api_idempotency_required', 'A valid Idempotency-Key header is required.', ['status' => 400]);
        }

        $json = $request->get_json_params();
        $body = is_array($json) ? $json : $request->get_body_params();
        $requestHash = hash('sha256', wp_json_encode($body));
        $scope = hash('sha256', get_current_user_id() . '|' . $request->get_method() . '|' . $request->get_route() . '|' . $key);
        $repo = new IdempotencyRepository();
        $existing = $repo->find($scope);
        if ($existing) {
            if (!hash_equals((string)$existing->request_hash, $requestHash)) {
                return new \WP_Error('wpcb_api_idempotency_conflict', 'Idempotency key was already used with a different request.', ['status' => 409]);
            }
            $decoded = json_decode((string)$existing->response_json, true);
            return new \WP_REST_Response($decoded, (int)$existing->status_code);
        }

        $response = rest_ensure_response($callback());
        if (is_wp_error($response)) {
            return $response;
        }

        $data = $response->get_data();
        $status = $response->get_status();
        $repo->store($scope, $requestHash, $status, $data);
        return $response;
    }
}
