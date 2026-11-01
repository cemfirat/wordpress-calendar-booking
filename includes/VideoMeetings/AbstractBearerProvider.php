<?php
namespace Wpcb\VideoMeetings;

abstract class AbstractBearerProvider implements VideoMeetingProviderInterface {
    protected function request(string $method, string $url, string $token, array|object|null $body = null): array {
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
            return ['ok'=>false,'message'=>'Provider HTTP ' . $code,'status'=>$code];
        }
        return ['ok'=>true,'data'=>is_array($decoded)?$decoded:[],'status'=>$code];
    }

    /** Authenticated call; renews expired tokens and retries once after a 401. */
    protected function call(object $connection, string $method, string $url, array|object|null $body = null): array {
        $tokens = new VideoTokenManager();
        $access = $tokens->access($connection);
        if (empty($access['ok'])) return $access;

        $result = $this->request($method, $url, $access['token'], $body);
        if (empty($result['ok']) && ($result['status'] ?? 0) === 401) {
            if ($tokens->renewable($connection)) {
                $access = $tokens->access($connection, $access['token']);
                if (empty($access['ok'])) return $access;
                $result = $this->request($method, $url, $access['token'], $body);
            }
            if (empty($result['ok']) && ($result['status'] ?? 0) === 401) {
                $message = 'Provider HTTP 401. The video connection was rejected; reconnect it.';
                if ((int)($connection->id ?? 0) > 0) {
                    (new VideoMeetingConnectionRepository())->setAuthHealth((int)$connection->id, 'reauth_required', $message);
                }
                return ['ok'=>false,'message'=>$message,'status'=>401];
            }
        }
        return $result;
    }

    protected function topic(array $booking): string {
        return 'WordPress booking #' . (int)($booking['id'] ?? 0);
    }
}
