<?php
namespace Cemb\Calendar;

use Cemb\Support\Time;

final class CalDavClient {
    private string $endpoint;
    private string $username;
    private string $password;

    public function __construct(string $endpoint, string $username, string $password) {
        $normalized = $this->normalizeUrl($endpoint);
        $this->endpoint = $normalized !== '' ? rtrim($normalized, '/') . '/' : '';
        $this->username = trim($username);
        $this->password = $password;
    }

    public function configured(): bool {
        return $this->endpoint !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * @return array<int,array{url:string,name:string}>|\WP_Error
     */
    public function discoverCalendars() {
        if (!$this->configured()) {
            return new \WP_Error('cemb_caldav_credentials', 'CalDAV endpoint, username and password are required.');
        }

        $principal = $this->currentUserPrincipal();
        if (is_wp_error($principal)) {
            return $principal;
        }

        $home = $this->calendarHomeSet($principal);
        if (is_wp_error($home)) {
            return $home;
        }

        return $this->listCalendars($home);
    }

    /**
     * @return array<int,array{href:string,etag:string,ics:string}>|\WP_Error
     */
    public function calendarQuery(string $calendarUrl, string $fromUtc, string $toUtc) {
        $from = Time::parseUtc($fromUtc);
        $to = Time::parseUtc($toUtc);
        if (!$from || !$to || $to <= $from) {
            return new \WP_Error('cemb_caldav_range', 'Invalid CalDAV time range.');
        }

        $calendarUrl = $this->absoluteUrl($calendarUrl);
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
            . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            . '<c:time-range start="' . esc_attr($from->format('Ymd\THis\Z')) . '" end="' . esc_attr($to->format('Ymd\THis\Z')) . '"/>'
            . '</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';

        $response = $this->request('REPORT', $calendarUrl, [
            'Depth' => '1',
            'Content-Type' => 'application/xml; charset=utf-8',
        ], $body);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code !== 207 && ($code < 200 || $code >= 300)) {
            return new \WP_Error('cemb_caldav_query_http', 'CalDAV calendar query returned HTTP ' . $code . '.');
        }

        return $this->parseCalendarDataResponses((string)wp_remote_retrieve_body($response));
    }

    /**
     * @return array{ok:bool,url:string,etag:string,code:int}|\WP_Error
     */
    public function putEvent(string $eventUrl, string $ics, ?string $etag = null, bool $create = false) {
        $headers = [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ];
        if ($create) {
            $headers['If-None-Match'] = '*';
        } elseif ($etag !== null && $etag !== '') {
            $headers['If-Match'] = $etag;
        }

        $eventUrl = $this->absoluteUrl($eventUrl);
        $response = $this->request('PUT', $eventUrl, $headers, $ics);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code === 409 || $code === 412) {
            return new \WP_Error('cemb_caldav_conflict', 'The remote calendar event changed. Refresh before retrying the write.');
        }
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('cemb_caldav_write_http', 'CalDAV event write returned HTTP ' . $code . '.');
        }

        return [
            'ok' => true,
            'url' => $eventUrl,
            'etag' => (string)wp_remote_retrieve_header($response, 'etag'),
            'code' => $code,
        ];
    }

    /** @return array{ok:bool,code:int}|\WP_Error */
    public function deleteEvent(string $eventUrl, ?string $etag = null) {
        $headers = [];
        if ($etag !== null && $etag !== '') {
            $headers['If-Match'] = $etag;
        }

        $response = $this->request('DELETE', $this->absoluteUrl($eventUrl), $headers);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return ['ok' => true, 'code' => $code];
        }
        if ($code === 409 || $code === 412) {
            return new \WP_Error('cemb_caldav_conflict', 'The remote calendar event changed. Refresh before retrying the delete.');
        }
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('cemb_caldav_delete_http', 'CalDAV event delete returned HTTP ' . $code . '.');
        }
        return ['ok' => true, 'code' => $code];
    }

    private function currentUserPrincipal() {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>';
        $response = $this->request('PROPFIND', $this->endpoint, [
            'Depth' => '0',
            'Content-Type' => 'application/xml; charset=utf-8',
        ], $body);
        if (is_wp_error($response)) {
            return $response;
        }
        $href = $this->extractHref((string)wp_remote_retrieve_body($response), 'current-user-principal');
        return $href ?: new \WP_Error('cemb_caldav_principal', 'CalDAV current-user-principal could not be discovered.');
    }

    private function calendarHomeSet(string $principalUrl) {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><c:calendar-home-set/></d:prop></d:propfind>';
        $response = $this->request('PROPFIND', $principalUrl, [
            'Depth' => '0',
            'Content-Type' => 'application/xml; charset=utf-8',
        ], $body);
        if (is_wp_error($response)) {
            return $response;
        }
        $href = $this->extractHref((string)wp_remote_retrieve_body($response), 'calendar-home-set');
        return $href ?: new \WP_Error('cemb_caldav_home', 'CalDAV calendar-home-set could not be discovered.');
    }

    private function listCalendars(string $homeUrl) {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:displayname/><d:resourcetype/></d:prop></d:propfind>';
        $response = $this->request('PROPFIND', $homeUrl, [
            'Depth' => '1',
            'Content-Type' => 'application/xml; charset=utf-8',
        ], $body);
        if (is_wp_error($response)) {
            return $response;
        }

        $xpath = $this->domXPath((string)wp_remote_retrieve_body($response));
        if (!$xpath) {
            return new \WP_Error('cemb_caldav_xml', 'CalDAV discovery returned unreadable XML.');
        }

        $out = [];
        foreach ($xpath->query('//*[local-name()="response"]') ?: [] as $node) {
            $hrefNode = $xpath->query('./*[local-name()="href"]', $node)->item(0);
            $calendarNode = $xpath->query('.//*[local-name()="calendar"]', $node)->item(0);
            if (!$hrefNode || !$calendarNode) {
                continue;
            }
            $displayNode = $xpath->query('.//*[local-name()="displayname"]', $node)->item(0);
            $url = $this->absoluteUrl(trim((string)$hrefNode->textContent));
            if ($url === '') {
                continue;
            }
            $name = $displayNode ? trim((string)$displayNode->textContent) : '';
            $out[] = [
                'url' => rtrim($url, '/') . '/',
                'name' => $name !== '' ? $name : basename(trim($url, '/')),
            ];
        }
        return $out;
    }

    private function parseCalendarDataResponses(string $body): array {
        $xpath = $this->domXPath($body);
        if (!$xpath) {
            return [];
        }

        $out = [];
        foreach ($xpath->query('//*[local-name()="response"]') ?: [] as $node) {
            $hrefNode = $xpath->query('./*[local-name()="href"]', $node)->item(0);
            $dataNode = $xpath->query('.//*[local-name()="calendar-data"]', $node)->item(0);
            if (!$hrefNode || !$dataNode) {
                continue;
            }
            $etagNode = $xpath->query('.//*[local-name()="getetag"]', $node)->item(0);
            $out[] = [
                'href' => $this->absoluteUrl(trim((string)$hrefNode->textContent)),
                'etag' => $etagNode ? trim((string)$etagNode->textContent) : '',
                'ics' => (string)$dataNode->textContent,
            ];
        }
        return $out;
    }

    private function domXPath(string $body): ?\DOMXPath {
        if ($body === '' || !class_exists('DOMDocument')) {
            return null;
        }
        $old = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        return $loaded ? new \DOMXPath($document) : null;
    }

    private function extractHref(string $body, string $element): ?string {
        $xml = $this->xml($body);
        if (!$xml) {
            return null;
        }
        $nodes = $xml->xpath('//*[local-name()="' . $element . '"]/*[local-name()="href"]');
        if (!$nodes || empty($nodes[0])) {
            return null;
        }
        return $this->absoluteUrl((string)$nodes[0]);
    }

    private function xml(string $body): ?\SimpleXMLElement {
        if ($body === '') {
            return null;
        }
        $old = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        return $xml instanceof \SimpleXMLElement ? $xml : null;
    }

    private function request(string $method, string $url, array $headers = [], ?string $body = null) {
        $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        return wp_remote_request($url, [
            'method' => $method,
            'timeout' => 20,
            'redirection' => 3,
            'headers' => $headers,
            'body' => $body,
            'user-agent' => 'CEMB/' . CEMB_VERSION,
        ]);
    }

    private function absoluteUrl(string $href): string {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $href)) {
            return $this->normalizeUrl($href);
        }

        $parts = wp_parse_url($this->endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
        return $origin . '/' . ltrim($href, '/');
    }

    private function normalizeUrl(string $url): string {
        $url = trim($url);
        if (stripos($url, 'webcal://') === 0) {
            $url = 'https://' . substr($url, 9);
        }
        return esc_url_raw($url);
    }
}
