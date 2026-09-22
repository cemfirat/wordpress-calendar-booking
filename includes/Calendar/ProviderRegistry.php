<?php
namespace Cemb\Calendar;

final class ProviderRegistry {
    /** @var array<string,CalendarProviderInterface>|null */
    private ?array $providers = null;

    /** @return array<string,CalendarProviderInterface> */
    public function all(): array {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];
        /**
         * Register provider-neutral calendar adapters.
         *
         * Providers may be supplied as objects or factories returning
         * CalendarProviderInterface instances.
         */
        $candidates = apply_filters('cemb_calendar_providers', []);
        if (!is_array($candidates)) {
            $candidates = [];
        }

        foreach ($candidates as $candidate) {
            if (is_callable($candidate) && !$candidate instanceof CalendarProviderInterface) {
                $candidate = $candidate();
            }
            if (!$candidate instanceof CalendarProviderInterface) {
                continue;
            }

            $id = sanitize_key($candidate->id());
            if ($id === '') {
                continue;
            }
            $providers[$id] = $candidate;
        }

        $this->providers = $providers;
        return $providers;
    }

    public function get(string $providerId): ?CalendarProviderInterface {
        $providerId = sanitize_key($providerId);
        $providers = $this->all();
        return $providers[$providerId] ?? null;
    }

    public function supports(string $providerId, string $capability): bool {
        $provider = $this->get($providerId);
        if (!$provider) {
            return false;
        }
        return in_array(
            $capability,
            ProviderCapabilities::normalize($provider->capabilities()),
            true
        );
    }
}
