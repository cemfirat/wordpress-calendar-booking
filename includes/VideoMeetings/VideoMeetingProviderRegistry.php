<?php
namespace Wpcb\VideoMeetings;

final class VideoMeetingProviderRegistry {
    public function get(string $code): ?VideoMeetingProviderInterface {
        $provider = match ($code) {
            'zoom' => new ZoomProvider(),
            'google_meet' => new GoogleMeetProvider(),
            'microsoft_teams' => new TeamsProvider(),
            default => null,
        };
        return apply_filters('wpcb_video_meeting_provider', $provider, $code);
    }

    public function codes(): array {
        return ['zoom','google_meet','microsoft_teams'];
    }
}
