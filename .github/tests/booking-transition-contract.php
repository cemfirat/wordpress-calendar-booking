<?php
/** Standalone contracts: real transition/state-machine/lock code, explicit storage doubles. */
namespace Wpcb\Payments {
    final class PaymentService {
        public static bool $paid = true;
        public function canConfirm(int $id): bool { return self::$paid; }
        public function validateSeriesCancellation(int $id, bool $whole) { return true; }
    }
}
namespace {
    class WP_Error {
        public string $code;
        public function __construct(string $code, string $message = '') { $this->code = $code; }
        public function get_error_code(): string { return $this->code; }
    }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function __($text, $domain = '') { return $text; }
    function home_url($path = '') { return 'https://example.test' . $path; }
    function do_action($hook, ...$args): void {
        $GLOBALS['effects'][] = [$hook, $args, $GLOBALS['wpdb']->held];
    }
    $root = getenv('WPCB_CONTRACT_ROOT') ?: dirname(__DIR__, 2);
    foreach (['Support/Time', 'Booking/BookingStatus', 'Booking/BookingStateMachine',
        'Booking/BookingRepository', 'Availability/SlotService', 'Resources/ResourceLock',
        'Booking/BookingTransitionService'] as $class) {
        require $root . '/includes/' . $class . '.php';
    }

    final class ContractDatabase {
        public array $held = [];
        public array $acquisitions = [];
        public $onAcquire = null;
        public bool $deny = false;
        public array $snapshot = [];
        public function prepare($sql, ...$args) {
            foreach ($args as $arg) {
                $sql = preg_replace('/%[sd]/', is_int($arg) ? (string)$arg : "'" . $arg . "'", $sql, 1);
            }
            return $sql;
        }
        public function get_var($sql) {
            if (preg_match("/GET_LOCK\('([^']+)'/", $sql, $m)) {
                $this->acquisitions[] = $m[1];
                if ($this->deny) return 0;
                $this->held[$m[1]] = ($this->held[$m[1]] ?? 0) + 1;
                if ($this->onAcquire) { $fn = $this->onAcquire; $this->onAcquire = null; $fn(); }
                return 1;
            }
            if (preg_match("/RELEASE_LOCK\('([^']+)'/", $sql, $m)) {
                if (!isset($this->held[$m[1]])) return null;
                if (--$this->held[$m[1]] === 0) unset($this->held[$m[1]]);
                return 1;
            }
            throw new \RuntimeException('Unexpected SQL in contract double');
        }
        public function query($sql) {
            if ($sql === 'START TRANSACTION') {
                $this->snapshot = unserialize(serialize($GLOBALS['repo']->rows));
            } elseif ($sql === 'ROLLBACK') {
                $GLOBALS['repo']->rows = $this->snapshot;
            } elseif ($sql !== 'COMMIT') {
                throw new \RuntimeException('Unexpected transaction SQL');
            }
            return 0;
        }
    }
    final class ContractBookings extends \Wpcb\Booking\BookingRepository {
        public array $rows = [];
        public array $expireIds = [];
        public int $failId = 0;
        public function __construct() {}
        public function find(int $id) { return isset($this->rows[$id]) ? clone $this->rows[$id] : null; }
        public function transitionStatus(int $id, string $old, string $new, array $fields,
            string $event, string $actor, string $note = ''): bool {
            check(!empty($GLOBALS['wpdb']->held), 'write must hold a resource lock');
            if ($id === $this->failId || $this->rows[$id]->status !== $old) return false;
            foreach ($fields + ['status' => $new] as $key => $value) $this->rows[$id]->$key = $value;
            return true;
        }
        public function expiredReservationIds(int $limit = 100): array { return $this->expireIds; }
        public function moveWhenPositionMatches(int $id, string $status, ?int $oldResource,
            string $oldStart, string $oldEnd, int $newResource, string $newStart, string $newEnd): bool {
            $this->rows[$id]->resource_id = $newResource;
            $this->rows[$id]->slot_start = $newStart;
            $this->rows[$id]->slot_end = $newEnd;
            return true;
        }
        public function logEvent(int $id, string $status, string $event, string $actor, string $note = ''): void {}
    }
    final class ContractSlots extends \Wpcb\Availability\SlotService {
        public bool $available = true;
        public bool $throw = false;
        public function __construct() {}
        public function slotAvailable(int $type, string $start, string $end, ?int $ignore = null,
            ?int $resource = null, int $party = 1): bool {
            check(!empty($GLOBALS['wpdb']->held), 'capacity read must hold resource lock');
            if ($this->throw) throw new \RuntimeException('injected read failure');
            return $this->available;
        }
    }
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    function errorCode($value, string $code): void {
        check(is_wp_error($value) && $value->get_error_code() === $code, 'expected ' . $code);
    }
    function row(int $id, int $resource = 1, string $status = 'reserved_unconfirmed'): object {
        return (object)['id' => $id, 'resource_id' => $resource, 'booking_type_id' => 1,
            'slot_start' => gmdate('Y-m-d 09:00:00', time() + 86400 * 7),
            'slot_end' => gmdate('Y-m-d 09:30:00', time() + 86400 * 7), 'party_size' => 1,
            'status' => $status, 'reserved_until' => gmdate('Y-m-d H:i:s', time() + 1800)];
    }
    function resetContract(): array {
        $GLOBALS['wpdb'] = new ContractDatabase();
        $GLOBALS['repo'] = $repo = new ContractBookings();
        $GLOBALS['effects'] = [];
        \Wpcb\Payments\PaymentService::$paid = true;
        $repo->rows[1] = row(1);
        $slots = new ContractSlots();
        return [$repo, $slots, new \Wpcb\Booking\BookingTransitionService($repo, null, $slots)];
    }
    $tests = [];
    $tests['confirmation is serialized and effects run after release'] = function () {
        [$repo, $slots, $service] = resetContract();
        $r = $service->apply(1, 'email_confirmed_automatic');
        check(is_array($r) && $r['changed'], 'confirmation changed');
        check(count($GLOBALS['wpdb']->acquisitions) === 1, 'one resource acquired');
        check($GLOBALS['wpdb']->held === [], 'no lock leaked');
        check(count($GLOBALS['effects']) === 1 && $GLOBALS['effects'][0][2] === [], 'dispatch after release');
    };
    $tests['expiry is checked after waiting for the resource'] = function () {
        [$repo, $slots, $service] = resetContract();
        $GLOBALS['wpdb']->onAcquire = function () use ($repo) { $repo->rows[1]->reserved_until = '2000-01-01 00:00:00'; };
        errorCode($service->apply(1, 'email_confirmed_automatic'), 'wpcb_reservation_expired');
        check($repo->rows[1]->status === 'reserved_unconfirmed' && !$GLOBALS['effects'], 'no state/effects');
    };
    $tests['approval rereads state after waiting'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->rows[1]->status = 'pending_approval';
        $GLOBALS['wpdb']->onAcquire = function () use ($repo) { $repo->rows[1]->status = 'cancelled'; };
        errorCode($service->apply(1, 'admin_approved'), 'wpcb_transition_illegal');
    };
    $tests['resource movement while waiting fails without chasing locks'] = function () {
        [$repo, $slots, $service] = resetContract();
        $GLOBALS['wpdb']->onAcquire = function () use ($repo) { $repo->rows[1]->resource_id = 2; };
        errorCode($service->apply(1, 'email_confirmed_automatic'), 'wpcb_transition_race');
    };
    $tests['lock timeout never writes or dispatches'] = function () {
        [$repo, $slots, $service] = resetContract(); $GLOBALS['wpdb']->deny = true;
        errorCode($service->apply(1, 'email_confirmed_automatic'), 'wpcb_reservation_busy');
        check(!$GLOBALS['effects'] && $repo->rows[1]->status === 'reserved_unconfirmed', 'fail closed');
    };
    $tests['unpaid confirmation remains blocked'] = function () {
        [$repo, $slots, $service] = resetContract(); \Wpcb\Payments\PaymentService::$paid = false;
        errorCode($service->apply(1, 'email_confirmed_automatic'), 'wpcb_payment_required');
        check($GLOBALS['wpdb']->held === [], 'release after payment failure');
    };
    $tests['capacity conflict releases lock'] = function () {
        [$repo, $slots, $service] = resetContract(); $slots->available = false;
        errorCode($service->apply(1, 'email_confirmed_automatic'), 'wpcb_slot_unavailable');
        check($GLOBALS['wpdb']->held === [] && !$GLOBALS['effects'], 'release without effects');
    };
    $tests['idempotent retry emits nothing'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->rows[1]->status = 'confirmed';
        $r = $service->apply(1, 'email_confirmed_automatic');
        check(is_array($r) && !$r['changed'] && !$GLOBALS['effects'], 'idempotent');
    };
    $tests['storage error releases lock'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->failId = 1;
        errorCode($service->apply(1, 'email_confirmed_automatic'), 'wpcb_transition_race');
        check($GLOBALS['wpdb']->held === [], 'release');
    };
    $tests['batch failure rolls back previous member and emits nothing'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->rows[2] = row(2, 2); $repo->failId = 2;
        check(method_exists($service, 'applyBatch'), 'batch transition API exists');
        errorCode($service->applyBatch([1, 2], 'email_confirmed_automatic'), 'wpcb_transition_race');
        check($repo->rows[1]->status === 'reserved_unconfirmed', 'first member rolled back');
        check(!$GLOBALS['effects'] && !$GLOBALS['wpdb']->held, 'no effects or leaked locks');
    };
    $tests['batch success acquires sorted resources and releases before dispatch'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->rows[1]->resource_id = 2; $repo->rows[2] = row(2, 1);
        check(method_exists($service, 'applyBatch'), 'batch transition API exists');
        $r = $service->applyBatch([1, 2], 'email_confirmed_automatic');
        check(is_array($r) && count($r) === 2 && count($GLOBALS['effects']) === 2, 'all members complete');
        $expected = 'wpcb_res_' . substr(hash('sha256', home_url('/') . '|1'), 0, 48);
        check($GLOBALS['wpdb']->acquisitions[0] === $expected, 'numeric lock order');
        foreach ($GLOBALS['effects'] as $effect) check($effect[2] === [], 'post-commit post-unlock dispatch');
    };
    $tests['expiry worker rechecks a renewed hold'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->expireIds = [1];
        check($service->expireReservations() === 0, 'renewed hold not expired');
        check($repo->rows[1]->status === 'reserved_unconfirmed', 'hold retained');
    };
    $tests['reschedule locks source and destination'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->rows[1]->status = 'pending_approval';
        $r = $service->reschedule(1, $repo->rows[1]->slot_start, $repo->rows[1]->slot_end, 'test', '', 2);
        check(is_array($r) && count($GLOBALS['wpdb']->acquisitions) === 2, 'both resources acquired');
        check(!$GLOBALS['wpdb']->held, 'both resources released');
    };
    $tests['reentrant resource acquisition is balanced'] = function () {
        resetContract(); $locks = new \Wpcb\Resources\ResourceLock();
        check($locks->acquire(1) && $locks->acquire(1), 'nested acquisition');
        $locks->release(1); check(count($GLOBALS['wpdb']->held) === 1, 'outer lock stays held');
        $locks->release(1); check($GLOBALS['wpdb']->held === [], 'last acquisition released');
    };
    $tests['releaseAll balances every nested acquisition'] = function () {
        resetContract(); $locks = new \Wpcb\Resources\ResourceLock();
        $locks->acquire(1); $locks->acquire(1); $locks->acquire(2); $locks->releaseAll();
        check($GLOBALS['wpdb']->held === [], 'all acquisition counts released');
    };
    $tests['exception in availability releases every lock'] = function () {
        [$repo, $slots, $service] = resetContract(); $slots->throw = true;
        errorCode($service->apply(1, 'email_confirmed_automatic'), 'wpcb_transition_storage');
        check(!$GLOBALS['wpdb']->held && !$GLOBALS['effects'], 'exception cleanup');
    };
    $tests['batch with one resource acquires it only once'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->rows[2] = row(2);
        check(method_exists($service, 'applyBatch'), 'batch transition API exists');
        $r = $service->applyBatch([1, 2], 'email_confirmed_approval');
        check(is_array($r) && count($GLOBALS['wpdb']->acquisitions) === 1, 'one shared lock');
    };
    $tests['invalid batch is rejected without locking'] = function () {
        [$repo, $slots, $service] = resetContract();
        check(method_exists($service, 'applyBatch'), 'batch transition API exists');
        errorCode($service->applyBatch([], 'email_confirmed_approval'), 'wpcb_transition_batch_invalid');
        check(!$GLOBALS['wpdb']->acquisitions, 'no acquisition');
    };
    $tests['legacy indefinite hold remains supported when a resource is assigned'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->rows[1]->reserved_until = null;
        $r = $service->apply(1, 'email_confirmed_approval');
        check(is_array($r) && $r['changed'], 'explicit null legacy hold is not guessed expired');
    };
    $tests['resource-less legacy row cannot gain capacity'] = function () {
        [$repo, $slots, $service] = resetContract(); $repo->rows[1]->resource_id = null;
        errorCode($service->apply(1, 'email_confirmed_approval'), 'wpcb_resource_missing');
        check(!$GLOBALS['effects'], 'no effect');
    };
    $failed = 0;
    foreach ($tests as $name => $fn) {
        try { $fn(); echo "PASS: $name\n"; }
        catch (\Throwable $e) { ++$failed; echo "FAIL: $name: {$e->getMessage()}\n"; }
    }
    echo count($tests) . " contracts; $failed failed.\n";
    exit($failed ? 1 : 0);
}
