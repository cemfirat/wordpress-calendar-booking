<?php
namespace Wpcb\VideoMeetings;

abstract class AbstractBearerProvider implements VideoMeetingProviderInterface {
    protected function request(string $method, string $url, string $token, ?array $body = null): array {
        $args = [
            'method' => $method,
            'timeout' => 15,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['ok'=>false,'message'=>$response->get_error_message()];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = (string)wp_remote_retrieve_body($response);
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        if ($code < 200 || $code >= 300) {
            return ['ok'=>false,'message'=>'Provider HTTP ' . $code];
        }
        return ['ok'=>true,'data'=>is_array($decoded)?$decoded:[],'status'=>$code];
    }

    protected function token(object $connection): string {
        $credentials = is_array($connection->credentials ?? null) ? $connection->credentials : [];
        return trim((string)($credentials['access_token'] ?? ''));
    }

    protected function topic(array $booking): string {
        return 'WordPress booking #' . (int)($booking['id'] ?? 0);
    }
}
