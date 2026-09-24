<?php
namespace Wpcb\Resources;

final class ResourceLock {
    /** @var array<string,int> Acquisition count owned by this instance. */
    private array $held = [];

    public function acquire(int $resourceId, int $timeoutSeconds = 5): bool {
        if ($resourceId < 1) {
            return false;
        }

        $name = $this->name($resourceId);
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $name,
            max(0, $timeoutSeconds)
        ));
        if ((int)$result !== 1) {
            return false;
        }
        $this->held[$name] = ($this->held[$name] ?? 0) + 1;
        return true;
    }

    public function release(int $resourceId): void {
        $name = $this->name($resourceId);
        if (!isset($this->held[$name])) {
            return;
        }
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        if (--$this->held[$name] === 0) {
            unset($this->held[$name]);
        }
    }

    public function releaseAll(): void {
        foreach (array_keys($this->held) as $name) {
            global $wpdb;
            for ($remaining = $this->held[$name]; $remaining > 0; --$remaining) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
            unset($this->held[$name]);
        }
    }

    private function name(int $resourceId): string {
        return 'wpcb_res_' . substr(hash('sha256', home_url('/') . '|' . $resourceId), 0, 48);
    }
}
