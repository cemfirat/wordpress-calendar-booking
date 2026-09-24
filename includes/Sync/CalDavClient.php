<?php
namespace Wpcb\Sync;

use Wpcb\Admin\Settings;

class CalDavClient {
    private string $appleId;
    private string $password;

    public function __construct() {
        $settings = Settings::get();
        $this->appleId = (string)($settings['icloud_sync_apple_id'] ?? '');
        $this->password = Settings::getIcloudSyncPassword();
    }

    public function configured(): bool {
        return $this->appleId !== '' && $this->password !== '';
    }

    public function testConnection(?string $directCalendarUrl = null): array {
        if (!$this->configured()) {
            return ['ok' => false, 'message' => 'Apple-ID oder app-spezifisches Passwort fehlen.'];
        }
        $directCalendarUrl = Settings::normalizeCalendarUrl((string)$directCalendarUrl);
        if ($directCalendarUrl !== '') {
            $probe = $this->probeCalendarUrl($directCalendarUrl);
            if ($probe['ok']) {
                return ['ok' => true, 'message' => 'Direkte Zielkalender-URL ist erreichbar.'];
            }
        }
        $principal = $this->currentUserPrincipal();
        if (!$principal) {
            return ['ok' => false, 'message' => 'Principal konnte nicht ermittelt werden. Bitte direkte Zielkalender-URL eintragen.'];
        }
        $home = $this->calendarHomeSet($principal);
        if (!$home) {
            return ['ok' => false, 'message' => 'calendar-home-set konnte nicht ermittelt werden.'];
        }
        $calendars = $this->listCalendars($home);
        if (!$calendars) {
            return ['ok' => false, 'message' => 'Keine Kalender gefunden oder Zugriff verweigert.'];
        }
        return ['ok' => true, 'message' => 'Verbindung erfolgreich. ' . count($calendars) . ' Kalender gefunden.'];
    }

    public function resolveTargetCalendarUrl(string $directCalendarUrl, string $calendarName): ?string {
        $directCalendarUrl = Settings::normalizeCalendarUrl($directCalendarUrl);
        if ($directCalendarUrl !== '') {
            $probe = $this->probeCalendarUrl($directCalendarUrl);
            if ($probe['ok']) {
                return untrailingslashit($directCalendarUrl) . '/';
            }
        }
        $principal = $this->currentUserPrincipal();
        if (!$principal) return null;
        $home = $this->calendarHomeSet($principal);
        if (!$home) return null;
        $calendars = $this->listCalendars($home);
        foreach ($calendars as $calendar) {
            if (mb_strtolower(trim((string)$calendar['name'])) === mb_strtolower(trim($calendarName))) {
                return untrailingslashit((string)$calendar['url']) . '/';
            }
        }
        return null;
    }

    public function upsertEvent(string $calendarUrl, string $eventPath, string $ics): array {
        $url = untrailingslashit($calendarUrl) . '/' . ltrim($eventPath, '/');
        $response = $this->request('PUT', $url, ['Content-Type' => 'text/calendar; charset=utf-8'], $ics);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        return [
            'ok' => $code >= 200 && $code < 300,
            'message' => wp_remote_retrieve_response_message($response) ?: ('HTTP ' . $code),
            'code' => $code,
            'etag' => (string)wp_remote_retrieve_header($response, 'etag'),
            'url' => $url,
        ];
    }

    public function deleteEvent(string $eventUrl): array {
        $response = $this->request('DELETE', $eventUrl);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        return ['ok' => ($code >= 200 && $code < 300) || $code === 404, 'message' => wp_remote_retrieve_response_message($response) ?: ('HTTP ' . $code), 'code' => $code];
    }

    private function probeCalendarUrl(string $calendarUrl): array {
        $xml = '<?xml version="1.0"?><propfind xmlns="DAV:" xmlns:cd="urn:ietf:params:xml:ns:caldav"><prop><displayname/><resourcetype/></prop></propfind>';
        $response = $this->request('PROPFIND', $calendarUrl, ['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8'], $xml);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        return ['ok' => in_array($code, [200, 207], true), 'message' => wp_remote_retrieve_response_message($response) ?: ('HTTP ' . $code)];
    }

    private function currentUserPrincipal(): ?string {
        $xml = '<?xml version="1.0"?><propfind xmlns="DAV:"><prop><current-user-principal/></prop></propfind>';
        $response = $this->request('PROPFIND', 'https://caldav.icloud.com/', ['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8'], $xml);
        if (is_wp_error($response)) return null;
        return $this->extractHref((string)wp_remote_retrieve_body($response), 'current-user-principal');
    }

    private function calendarHomeSet(string $principalUrl): ?string {
        $xml = '<?xml version="1.0"?><propfind xmlns="DAV:" xmlns:cd="urn:ietf:params:xml:ns:caldav"><prop><cd:calendar-home-set/></prop></propfind>';
        $response = $this->request('PROPFIND', $principalUrl, ['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8'], $xml);
        if (is_wp_error($response)) return null;
        return $this->extractHref((string)wp_remote_retrieve_body($response), 'calendar-home-set');
    }

    private function listCalendars(string $homeUrl): array {
        $xml = '<?xml version="1.0"?><propfind xmlns="DAV:" xmlns:cd="urn:ietf:params:xml:ns:caldav"><prop><displayname/><resourcetype/></prop></propfind>';
        $response = $this->request('PROPFIND', $homeUrl, ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'], $xml);
        if (is_wp_error($response)) return [];
        $body = (string)wp_remote_retrieve_body($response);
        if ($body === '') return [];
        $xmlObj = @simplexml_load_string($body);
        if (!$xmlObj) return [];
        $xmlObj->registerXPathNamespace('d', 'DAV:');
        $xmlObj->registerXPathNamespace('cd', 'urn:ietf:params:xml:ns:caldav');
        $items = [];
        foreach ($xmlObj->xpath('//d:response') as $responseNode) {
            $href = (string)($responseNode->xpath('./d:href')[0] ?? '');
            $display = (string)($responseNode->xpath('.//d:displayname')[0] ?? '');
            $isCalendar = !empty($responseNode->xpath('.//cd:calendar'));
            if ($isCalendar && $href !== '') {
                $items[] = ['url' => untrailingslashit($this->absoluteUrl($href)) . '/', 'name' => $display ?: basename(trim($href, '/'))];
            }
        }
        return $items;
    }

    private function extractHref(string $xmlBody, string $elementName): ?string {
        $xml = @simplexml_load_string($xmlBody);
        if (!$xml) return null;
        $xml->registerXPathNamespace('d', 'DAV:');
        $nodes = $xml->xpath('//*[local-name()="' . $elementName . '"]/*[local-name()="href"]');
        if (!$nodes || empty($nodes[0])) return null;
        return $this->absoluteUrl((string)$nodes[0]);
    }

    private function absoluteUrl(string $href): string {
        if (preg_match('#^https?://#i', $href)) return $href;
        return 'https://caldav.icloud.com' . $href;
    }

    private function request(string $method, string $url, array $headers = [], ?string $body = null) {
        $headers['Authorization'] = 'Basic ' . base64_encode($this->appleId . ':' . $this->password);
        return OutboundUrlPolicy::request($method, $url, [
            'timeout' => 20,
            'redirection' => 5,
            'headers' => $headers,
            'body' => $body,
            'user-agent' => 'WPCB/' . WPCB_VERSION,
        ]);
    }
}
