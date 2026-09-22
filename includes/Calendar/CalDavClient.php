<?php
namespace Cemb\Calendar;

use Cemb\Support\Time;
use Sabre\VObject\Reader;

final class CalDavClient {
    private string $endpoint;
    private string $username;
    private string $password;

    public function __construct(string $endpoint, string $username, string $password) {
        $this->endpoint = $this->normalizeUrl($endpoint);
        $this->username = trim($username);
        $this->password = $password;
    }

    public function configured(): bool {
        return $this->endpoint !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * @return array{principal:string,home:string,calendars:array}|\WP_Error
     */
    public function discover() {
        if (!$this->configured()) {
            return new \WP_Error('cemb_caldav_config', 'CalDAV endpoint, username and password are required.');
        }

        $principalResponse = $this->propfind(
            $this->endpoint,
            '<d:prop><d:current-user-principal/></d:prop>',
            '0'
        );
        if (is_wp_error($principalResponse)) {
            return $principalResponse;
        }
        $principalHref = $this->firstHrefForProperty(
            (string)wp_remote_retrieve_body($principalResponse),
            'current-user-principal'
        );
        if ($principalHref === '') {
            return new \WP_Error('cemb_caldav_principal', 'CalDAV server did not advertise current-user-principal.');
        }
        $principal = $this->absoluteUrl($principalHref, $this->endpoint);

        $homeResponse = $this->propfind(
            $principal,
            '<d:prop><c:calendar-home-set/></d:prop>',
            '0'
        );
        if (is_wp_error($homeResponse)) {
            return $homeResponse;
        }
        $homeHref = $this->firstHrefForProperty(
            (string)wp_remote_retrieve_body($homeResponse),
            'calendar-home-set'
        );
        if ($homeHref === '') {
            return new \WP_Error('cemb_caldav_home', 'CalDAV server did not advertise calendar-home-set.');
        }
        $home = $this->absoluteUrl($homeHref, $principal);

        $calendarResponse = $this->propfind(
            $home,
            '<d:prop><d:displayname/><d:resourcetype/><c:supported-calendar-component-set/></d:prop>',
            '1'
        );
        if (is_wp_error($calendarResponse)) {
            return $calendarResponse;
        }

        $calendars = $this->parseCalendars((string)wp_remote_retrieve_body($calendarResponse), $home);
        return [
            'principal' => $principal,
            'home' => $home,
            'calendars' => $calendars,
        ];
    }

    /**
     * Return canonical UTC busy intervals. Prefer the privacy-preserving
     * free-busy-query; fall back to a bounded calendar-query when unsupported.
     *
     * @return array<int,array{start:string,end:string}>|\WP_Error
     */
    public function busyBetween(string $calendarUrl, string $fromUtc, string $toUtc) {
        $from = Time::parseUtc($fromUtc);
        $to = Time::parseUtc($toUtc);
        $calendarUrl = $this->normalizeUrl($calendarUrl);
        if (!$from || !$to || $to <= $from || $calendarUrl === '') {
            return new \WP_Error('cemb_caldav_range', 'Invalid CalDAV busy-time request.');
        }

        $start = $from->format('Ymd\THis\Z');
        $end = $to->format('Ymd\THis\Z');
        $freeBusyXml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<c:free-busy-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<c:time-range start="' . esc_attr($start) . '" end="' . esc_attr($end) . '"/>'
            . '</c:free-busy-query>';

        $response = $this->request(
            'REPORT',
            $calendarUrl,
            [
                'Depth' => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Accept' => 'text/calendar, application/xml',
            ],
            $freeBusyXml
        );

        if (!is_wp_error($response)) {
            $code = (int)wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                return $this->parseFreeBusy((string)wp_remote_retrieve_body($response), $fromUtc, $toUtc);
            }
            if (!in_array($code, [403, 404, 405, 415, 501], true)) {
                return $this->httpError('FreeBusy', $response);
            }
        }

        return $this->calendarQueryBusy($calendarUrl, $fromUtc, $toUtc);
    }

    /**
     * @return array{url:string,etag:string}|\WP_Error
     */
    public function createEvent(string $calendarUrl, string $eventPath, string $ics, string $expectedUid) {
        $url = $this->eventUrl($calendarUrl, $eventPath);
        if (is_wp_error($url)) {
            return $url;
        }

        $response = $this->request(
            'PUT',
            $url,
            [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'If-None-Match' => '*',
            ],
            $ics
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $etag = $this->responseEtag($response);
            if ($etag === '') {
                $etag = $this->fetchEtag($url);
            }
            return ['url' => $url, 'etag' => $etag];
        }

        // A retry after an uncertain successful create reaches the same
        // deterministic resource. Verify UID before treating 412 as success.
        if ($code === 412) {
            $existing = $this->request('GET', $url, ['Accept' => 'text/calendar']);
            if (!is_wp_error($existing) && (int)wp_remote_retrieve_response_code($existing) === 200) {
                $body = (string)wp_remote_retrieve_body($existing);
                if ($this->calendarUid($body) === $expectedUid) {
                    return [
                        'url' => $url,
                        'etag' => $this->responseEtag($existing),
                    ];
                }
            }
            return new \WP_Error(
                'cemb_caldav_create_conflict',
                'A different CalDAV event already exists at the deterministic booking resource.'
            );
        }

        return $this->httpError('Create event', $response);
    }

    /**
     * @return array{url:string,etag:string}|\WP_Error
     */
    public function updateEvent(string $eventUrl, string $ics, string $etag) {
        $eventUrl = $this->normalizeUrl($eventUrl);
        if ($eventUrl === '') {
            return new \WP_Error('cemb_caldav_event_url', 'CalDAV event URL is invalid.');
        }

        if ($etag === '') {
            $etag = $this->fetchEtag($eventUrl);
        }
        if ($etag === '') {
            return new \WP_Error(
                'cemb_caldav_etag_missing',
                'CalDAV server did not provide an ETag; safe event update was not attempted.'
            );
        }

        $response = $this->request(
            'PUT',
            $eventUrl,
            [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'If-Match' => $etag,
            ],
            $ics
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code === 412) {
            return new \WP_Error(
                'cemb_caldav_etag_conflict',
                'The CalDAV event changed remotely. Refresh the connection before overwriting it.'
            );
        }
        if ($code < 200 || $code >= 300) {
            return $this->httpError('Update event', $response);
        }

        $newEtag = $this->responseEtag($response);
        if ($newEtag === '') {
            $newEtag = $this->fetchEtag($eventUrl);
        }
        return ['url' => $eventUrl, 'etag' => $newEtag];
    }

    /** @return true|\WP_Error */
    public function deleteEvent(string $eventUrl, string $etag = '') {
        $eventUrl = $this->normalizeUrl($eventUrl);
        if ($eventUrl === '') {
            return true;
        }

        $headers = [];
        if ($etag !== '') {
            $headers['If-Match'] = $etag;
        }

        $response = $this->request('DELETE', $eventUrl, $headers);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return true;
        }
        if ($code === 412) {
            return new \WP_Error(
                'cemb_caldav_etag_conflict',
                'The CalDAV event changed remotely. It was not deleted.'
            );
        }
        if ($code < 200 || $code >= 300) {
            return $this->httpError('Delete event', $response);
        }
        return true;
    }

    private function calendarQueryBusy(string $calendarUrl, string $fromUtc, string $toUtc) {
        $from = Time::parseUtc($fromUtc);
        $to = Time::parseUtc($toUtc);
        if (!$from || !$to) {
            return [];
        }

        $start = $from->format('Ymd\THis\Z');
        $end = $to->format('Ymd\THis\Z');
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
            . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            . '<c:time-range start="' . esc_attr($start) . '" end="' . esc_attr($end) . '"/>'
            . '</c:comp-filter></c:comp-filter></c:filter>'
            . '</c:calendar-query>';

        $response = $this->request(
            'REPORT',
            $calendarUrl,
            [
                'Depth' => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Accept' => 'application/xml',
            ],
            $xml
        );
        if (is_wp_error($response)) {
            return $response;
        }
        if ((int)wp_remote_retrieve_response_code($response) !== 207) {
            return $this->httpError('Calendar query', $response);
        }

        $calendarBodies = $this->calendarDataBodies((string)wp_remote_retrieve_body($response));
        $parser = new Parser();
        $busy = [];
        foreach ($calendarBodies as $body) {
            foreach ($parser->parse($body, $fromUtc, $toUtc) as $event) {
                $busy[] = [
                    'start' => (string)$event['start'],
                    'end' => (string)$event['end'],
                ];
            }
        }
        return $busy;
    }

    private function parseFreeBusy(string $body, string $fromUtc, string $toUtc): array {
        if ($body === '') {
            return [];
        }

        try {
            $calendar = Reader::read($body, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            return [];
        }

        $from = Time::parseUtc($fromUtc);
        $to = Time::parseUtc($toUtc);
        if (!$from || !$to) {
            return [];
        }

        $busy = [];
        foreach ($calendar->select('VFREEBUSY') as $component) {
            foreach ($component->select('FREEBUSY') as $property) {
                $periods = explode(',', (string)$property);
                foreach ($periods as $period) {
                    [$rawStart, $rawEnd] = array_pad(explode('/', trim($period), 2), 2, '');
                    $start = $this->icalUtc($rawStart);
                    $end = $this->icalUtc($rawEnd);
                    if (!$start || !$end || $end <= $start || $start >= $to || $end <= $from) {
                        continue;
                    }
                    $busy[] = [
                        'start' => Time::formatUtc($start),
                        'end' => Time::formatUtc($end),
                    ];
                }
            }
        }
        return $busy;
    }

    private function icalUtc(string $value): ?\DateTimeImmutable {
        if (!preg_match('/^\d{8}T\d{6}Z$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, Time::utc());
        return $date ?: null;
    }

    private function propfind(string $url, string $propXml, string $depth) {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . $propXml
            . '</d:propfind>';

        $response = $this->request(
            'PROPFIND',
            $url,
            ['Depth' => $depth, 'Content-Type' => 'application/xml; charset=utf-8'],
            $xml
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if (!in_array($code, [200, 207], true)) {
            return $this->httpError('CalDAV discovery', $response);
        }
        return $response;
    }

    private function request(string $method, string $url, array $headers = [], ?string $body = null) {
        $url = $this->normalizeUrl($url);
        if ($url === '') {
            return new \WP_Error('cemb_caldav_url', 'CalDAV URL is invalid or unsafe.');
        }

        $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        return wp_safe_remote_request($url, [
            'method' => $method,
            'timeout' => 20,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'headers' => $headers,
            'body' => $body,
            'user-agent' => 'WordPress-Calendar-Booking/' . CEMB_VERSION,
        ]);
    }

    private function parseCalendars(string $xmlBody, string $baseUrl): array {
        $xml = $this->xml($xmlBody);
        if (!$xml) {
            return [];
        }
        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $calendars = [];
        foreach ($xml->xpath('//d:response') ?: [] as $response) {
            $hrefNodes = $response->xpath('./d:href') ?: [];
            $href = isset($hrefNodes[0]) ? (string)$hrefNodes[0] : '';
            if ($href === '' || empty($response->xpath('.//c:calendar'))) {
                continue;
            }

            $displayNodes = $response->xpath('.//d:displayname') ?: [];
            $componentNodes = $response->xpath('.//c:supported-calendar-component-set/c:comp') ?: [];
            $supportsEvents = !$componentNodes;
            foreach ($componentNodes as $component) {
                $attrs = $component->attributes();
                if (strtoupper((string)($attrs['name'] ?? '')) === 'VEVENT') {
                    $supportsEvents = true;
                    break;
                }
            }
            if (!$supportsEvents) {
                continue;
            }

            $url = $this->absoluteUrl($href, $baseUrl);
            $calendars[] = [
                'id' => $url,
                'url' => $url,
                'name' => isset($displayNodes[0]) && trim((string)$displayNodes[0]) !== ''
                    ? trim((string)$displayNodes[0])
                    : basename(trim((string)wp_parse_url($url, PHP_URL_PATH), '/')),
            ];
        }

        return $calendars;
    }

    private function firstHrefForProperty(string $xmlBody, string $property): string {
        $xml = $this->xml($xmlBody);
        if (!$xml) {
            return '';
        }
        $nodes = $xml->xpath(
            '//*[local-name()="' . $property . '"]/*[local-name()="href"]'
        );
        return $nodes && isset($nodes[0]) ? trim((string)$nodes[0]) : '';
    }

    private function calendarDataBodies(string $xmlBody): array {
        $xml = $this->xml($xmlBody);
        if (!$xml) {
            return [];
        }
        $nodes = $xml->xpath('//*[local-name()="calendar-data"]') ?: [];
        return array_values(array_filter(array_map(
            static fn($node): string => trim((string)$node),
            $nodes
        )));
    }

    private function calendarUid(string $ics): string {
        if (preg_match('/(?:^|\r?\n)UID:([^\r\n]+)/', $ics, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    private function eventUrl(string $calendarUrl, string $eventPath) {
        $calendarUrl = $this->normalizeUrl($calendarUrl);
        $eventPath = basename(str_replace('\\', '/', $eventPath));
        if ($calendarUrl === '' || $eventPath === '' || !str_ends_with(strtolower($eventPath), '.ics')) {
            return new \WP_Error('cemb_caldav_event_path', 'CalDAV event path is invalid.');
        }
        return trailingslashit($calendarUrl) . rawurlencode($eventPath);
    }

    private function fetchEtag(string $url): string {
        $response = $this->request('GET', $url, ['Accept' => 'text/calendar']);
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        return $this->responseEtag($response);
    }

    private function responseEtag($response): string {
        return trim((string)wp_remote_retrieve_header($response, 'etag'));
    }

    private function absoluteUrl(string $href, string $baseUrl): string {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $href)) {
            return $this->normalizeUrl($href);
        }

        $base = wp_parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return '';
        }

        $origin = $base['scheme'] . '://' . $base['host'];
        if (!empty($base['port'])) {
            $origin .= ':' . (int)$base['port'];
        }

        if (str_starts_with($href, '/')) {
            return $this->normalizeUrl($origin . $href);
        }

        $path = (string)($base['path'] ?? '/');
        $directory = trailingslashit(dirname($path));
        return $this->normalizeUrl($origin . $directory . $href);
    }

    private function normalizeUrl(string $url): string {
        $url = esc_url_raw(trim($url), ['https', 'http']);
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string)wp_parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !apply_filters('cemb_allow_insecure_caldav', false, $url)) {
            return '';
        }
        return $url;
    }

    private function xml(string $body): ?\SimpleXMLElement {
        if ($body === '' || !function_exists('simplexml_load_string')) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $xml ?: null;
    }

    private function httpError(string $operation, $response): \WP_Error {
        $code = (int)wp_remote_retrieve_response_code($response);
        return new \WP_Error(
            'cemb_caldav_http',
            $operation . ' failed with HTTP ' . $code . '. Check the CalDAV connection and permissions.'
        );
    }
}
